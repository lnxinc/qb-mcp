<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    /**
     * Require the configured MCP access token as a bearer token.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.mcp.token');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }
}
