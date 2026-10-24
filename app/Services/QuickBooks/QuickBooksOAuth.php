<?php

namespace App\Services\QuickBooks;

use App\Data\QuickBooksTokens;
use App\Exceptions\QuickBooksApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class QuickBooksOAuth
{
    public const string AUTHORIZATION_URL = 'https://appcenter.intuit.com/connect/oauth2';

    public const string TOKEN_URL = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public const string REVOCATION_URL = 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke';

    public const string ACCOUNTING_SCOPE = 'com.intuit.quickbooks.accounting';

    /**
     * Determine if the Intuit app credentials have been configured.
     */
    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Build the URL that sends a user to Intuit to authorize a company.
     */
    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZATION_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'scope' => self::ACCOUNTING_SCOPE,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Exchange the authorization code returned by Intuit for a token set.
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): QuickBooksTokens
    {
        return $this->requestTokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Obtain a new token set using a refresh token.
     */
    public function refreshTokens(string $refreshToken): QuickBooksTokens
    {
        return $this->requestTokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Revoke a refresh token so the company is disconnected on the Intuit side as well.
     */
    public function revoke(string $refreshToken): void
    {
        $this->client()
            ->asJson()
            ->post(self::REVOCATION_URL, ['token' => $refreshToken])
            ->throw();
    }

    /**
     * Call the Intuit token endpoint with the given grant parameters.
     *
     * @param  array<string, string>  $parameters
     */
    protected function requestTokens(array $parameters): QuickBooksTokens
    {
        $response = $this->client()->asForm()->post(self::TOKEN_URL, $parameters);

        if ($response->failed()) {
            $reason = $response->json('error_description') ?? $response->json('error') ?? "HTTP {$response->status()}";

            throw new QuickBooksApiException("QuickBooks token request failed: {$reason}", $response->status());
        }

        return QuickBooksTokens::fromTokenResponse($response->json());
    }

    /**
     * Get a request pre-configured for the Intuit OAuth endpoints.
     */
    protected function client(): PendingRequest
    {
        return Http::withBasicAuth($this->clientId(), $this->clientSecret())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    protected function clientId(): string
    {
        return (string) config('services.quickbooks.client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('services.quickbooks.client_secret');
    }
}
