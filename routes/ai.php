<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\QuickBooksServer;
use Laravel\Mcp\Facades\Mcp;

// Local (stdio) transport for Claude Code: `php artisan mcp:start quickbooks`.
Mcp::local('quickbooks', QuickBooksServer::class);

// Remote (HTTP) transport for claude.ai connectors and `claude mcp add --transport http`.
Mcp::web('/mcp/quickbooks', QuickBooksServer::class)
    ->middleware(AuthenticateMcpToken::class);
