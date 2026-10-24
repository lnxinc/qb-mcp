<?php

namespace App\Mcp\Tools;

use App\Models\QuickBooksConnection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_companies')]
#[Description('List every QuickBooks Online company connected to this server. Call this first to learn the company slugs, then pass a slug as the "company" argument to the other tools.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCompaniesTool extends QuickBooksTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $companies = $this->companies->all($this->user($request))
            ->map(fn (QuickBooksConnection $connection): array => [
                'slug' => $connection->slug,
                'company_name' => $connection->company_name,
                'realm_id' => $connection->realm_id,
                'team' => $connection->team->name,
                'status' => $connection->refreshTokenHasExpired() ? 'reconnect_required' : 'connected',
                'connection_expires_on' => $connection->refresh_token_expires_at->toDateString(),
            ])
            ->values();

        if ($companies->isEmpty()) {
            return Response::text('No QuickBooks companies are connected yet. Connect one from Settings → QuickBooks in the web app.');
        }

        return Response::json(['companies' => $companies]);
    }
}
