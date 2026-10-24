<?php

namespace App\Mcp\Tools;

use App\Exceptions\QuickBooksApiException;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Services\QuickBooks\CompanyResolver;
use App\Services\QuickBooks\QuickBooksApi;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class QuickBooksTool extends Tool
{
    public function __construct(
        protected CompanyResolver $companies,
        protected QuickBooksApi $api,
    ) {
        //
    }

    /**
     * The schema for the argument that selects which company a tool acts on.
     */
    protected function companySchema(JsonSchema $schema): StringType
    {
        return $schema->string()
            ->description('Which QuickBooks company to use: the slug, realm ID, or company name as returned by list_companies.')
            ->required();
    }

    /**
     * Resolve the company named in the request, or an error response explaining what is available.
     */
    protected function resolveCompany(Request $request): QuickBooksConnection|Response
    {
        $identifier = (string) $request->get('company', '');
        $user = $this->user($request);

        $connection = $this->companies->resolve($identifier, $user);

        if ($connection) {
            return $connection;
        }

        $available = $this->companies->all($user)
            ->map(fn (QuickBooksConnection $connection): string => "{$connection->slug} ({$connection->company_name})")
            ->implode(', ');

        if ($available === '') {
            return Response::error('No QuickBooks companies are connected yet. Connect one from Settings → QuickBooks in the web app.');
        }

        return Response::error("Unknown company [{$identifier}]. Available companies: {$available}. Pass the slug as the \"company\" argument.");
    }

    /**
     * Run a QuickBooks API call and convert failures into tool error responses.
     *
     * @param  Closure(): array<string, mixed>  $callback
     */
    protected function respondWith(Closure $callback): Response
    {
        try {
            return Response::json($callback());
        } catch (QuickBooksApiException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /**
     * Get the authenticated application user, if the transport authenticated one.
     */
    protected function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
