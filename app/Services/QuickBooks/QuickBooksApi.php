<?php

namespace App\Services\QuickBooks;

use App\Exceptions\QuickBooksApiException;
use App\Models\QuickBooksConnection;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class QuickBooksApi
{
    public const string PRODUCTION_BASE_URL = 'https://quickbooks.api.intuit.com';

    public const string SANDBOX_BASE_URL = 'https://sandbox-quickbooks.api.intuit.com';

    public function __construct(protected QuickBooksOAuth $oauth)
    {
        //
    }

    /**
     * Run a QuickBooks query against the company.
     *
     * @return array<string, mixed>
     */
    public function query(QuickBooksConnection $connection, string $query): array
    {
        return $this->get($connection, 'query', ['query' => $query]);
    }

    /**
     * Run a report for the company.
     *
     * @param  array<string, scalar>  $parameters
     * @return array<string, mixed>
     */
    public function report(QuickBooksConnection $connection, string $report, array $parameters = []): array
    {
        return $this->get($connection, 'reports/'.$report, $parameters);
    }

    /**
     * Fetch a single entity by its ID.
     *
     * @return array<string, mixed>
     */
    public function find(QuickBooksConnection $connection, string $entity, string $id): array
    {
        return $this->get($connection, Str::lower($entity).'/'.rawurlencode($id));
    }

    /**
     * Fetch the company information record.
     *
     * @return array<string, mixed>
     */
    public function companyInfo(QuickBooksConnection $connection): array
    {
        return $this->get($connection, 'companyinfo/'.$connection->realm_id);
    }

    /**
     * Create an entity in the company.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(QuickBooksConnection $connection, string $entity, array $payload): array
    {
        return $this->post($connection, Str::lower($entity), $payload);
    }

    /**
     * Apply a sparse update to an existing entity. The payload must include Id and SyncToken.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(QuickBooksConnection $connection, string $entity, array $payload): array
    {
        return $this->post($connection, Str::lower($entity), ['sparse' => true, ...$payload]);
    }

    /**
     * Get an access token for the connection, refreshing it first when it is expiring.
     */
    public function freshAccessToken(QuickBooksConnection $connection, bool $forceRefresh = false): string
    {
        if (! $forceRefresh && ! $connection->accessTokenIsExpiring()) {
            return $connection->access_token;
        }

        if (! $connection->exists) {
            return $connection->access_token;
        }

        return Cache::lock("quickbooks:refresh:{$connection->id}", 30)->block(15, function () use ($connection, $forceRefresh): string {
            $connection->refresh();

            if (! $forceRefresh && ! $connection->accessTokenIsExpiring()) {
                return $connection->access_token;
            }

            if ($connection->refreshTokenHasExpired()) {
                throw new QuickBooksApiException(
                    "The QuickBooks connection for \"{$connection->company_name}\" has expired. Reconnect the company from the QuickBooks settings page.",
                );
            }

            $connection->update($this->oauth->refreshTokens($connection->refresh_token)->toConnectionAttributes());

            return $connection->access_token;
        });
    }

    /**
     * Send an idempotent GET request with retries for transient failures.
     *
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    protected function get(QuickBooksConnection $connection, string $path, array $query = []): array
    {
        return $this->send($connection, fn (PendingRequest $request): Response => $request
            ->retry([200, 1000], 0, fn (Throwable $exception): bool => $this->isTransient($exception), throw: false)
            ->get($path, $this->withMinorVersion($query)));
    }

    /**
     * Send a state-changing POST request. These are never retried.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function post(QuickBooksConnection $connection, string $path, array $payload): array
    {
        return $this->send($connection, fn (PendingRequest $request): Response => $request
            ->withQueryParameters($this->withMinorVersion())
            ->post($path, $payload));
    }

    /**
     * Execute the request, retrying once with a fresh token if QuickBooks rejects the current one.
     *
     * @param  Closure(PendingRequest): Response  $callback
     * @return array<string, mixed>
     */
    protected function send(QuickBooksConnection $connection, Closure $callback): array
    {
        $response = $callback($this->request($connection));

        if ($response->status() === 401 && $connection->exists) {
            $response = $callback($this->request($connection, forceRefresh: true));
        }

        if ($response->failed()) {
            throw QuickBooksApiException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    /**
     * Build a request for the company's API base URL.
     */
    protected function request(QuickBooksConnection $connection, bool $forceRefresh = false): PendingRequest
    {
        return Http::baseUrl($this->baseUrl().'/v3/company/'.$connection->realm_id)
            ->withToken($this->freshAccessToken($connection, $forceRefresh))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    /**
     * Add the API minor version to the query parameters.
     *
     * @param  array<string, scalar>  $query
     * @return array<string, scalar>
     */
    protected function withMinorVersion(array $query = []): array
    {
        return ['minorversion' => (string) config('services.quickbooks.minor_version'), ...$query];
    }

    protected function isTransient(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->serverError() || $exception->response->status() === 429));
    }

    protected function baseUrl(): string
    {
        return config('services.quickbooks.environment') === 'production'
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }
}
