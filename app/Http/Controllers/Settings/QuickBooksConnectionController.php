<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TeamPermission;
use App\Exceptions\QuickBooksApiException;
use App\Http\Controllers\Controller;
use App\Models\QuickBooksConnection;
use App\Models\Team;
use App\Services\QuickBooks\QuickBooksApi;
use App\Services\QuickBooks\QuickBooksOAuth;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class QuickBooksConnectionController extends Controller
{
    /**
     * Show the QuickBooks companies connected to the current team.
     */
    public function index(Request $request, QuickBooksOAuth $oauth): Response
    {
        $user = $request->user();
        $team = $this->currentTeam($request);

        $connections = $team->quickBooksConnections()
            ->with('connector')
            ->orderBy('company_name')
            ->get()
            ->map(fn (QuickBooksConnection $connection): array => [
                'slug' => $connection->slug,
                'companyName' => $connection->company_name,
                'realmId' => $connection->realm_id,
                'connectedBy' => $connection->connector?->name,
                'connectedAt' => $connection->created_at->toISOString(),
                'expiresAt' => $connection->refresh_token_expires_at->toISOString(),
                'needsReconnect' => $connection->refreshTokenHasExpired(),
            ]);

        return Inertia::render('settings/quickbooks', [
            'connections' => $connections,
            'teamName' => $team->name,
            'canManage' => $user->hasTeamPermission($team, TeamPermission::UpdateTeam),
            'configured' => $oauth->isConfigured(),
            'environment' => config('services.quickbooks.environment'),
            'redirectUri' => route('quickbooks.callback'),
            'mcpUrl' => url('/mcp/quickbooks'),
            'mcpTokenConfigured' => filled(config('services.mcp.token')),
            'projectPath' => base_path(),
        ]);
    }

    /**
     * Send the user to Intuit to authorize a company for the current team.
     */
    public function connect(Request $request, QuickBooksOAuth $oauth): RedirectResponse
    {
        $team = $this->currentTeam($request);

        Gate::authorize('update', $team);

        if (! $oauth->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Set QUICKBOOKS_CLIENT_ID and QUICKBOOKS_CLIENT_SECRET before connecting a company.')]);

            return to_route('quickbooks.index');
        }

        $state = Str::random(40);

        $request->session()->put('quickbooks.oauth', [
            'state' => $state,
            'team_id' => $team->id,
        ]);

        return redirect()->away($oauth->authorizationUrl(route('quickbooks.callback'), $state));
    }

    /**
     * Complete the Intuit authorization and store the company connection.
     */
    public function callback(Request $request, QuickBooksOAuth $oauth, QuickBooksApi $api): RedirectResponse
    {
        $pending = $request->session()->pull('quickbooks.oauth');
        $state = $request->string('state')->toString();
        $code = $request->string('code')->toString();
        $realmId = $request->string('realmId')->toString();

        if (! is_array($pending) || $state === '' || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return $this->failed(__('The QuickBooks authorization could not be verified. Please try connecting again.'));
        }

        if ($request->filled('error') || $code === '' || $realmId === '') {
            return $this->failed(__('QuickBooks authorization was cancelled or did not complete.'));
        }

        $team = Team::query()->findOrFail((int) ($pending['team_id'] ?? 0));

        Gate::authorize('update', $team);

        try {
            $tokens = $oauth->exchangeAuthorizationCode($code, route('quickbooks.callback'));

            $companyInfo = $api->companyInfo(new QuickBooksConnection([
                'realm_id' => $realmId,
                ...$tokens->toConnectionAttributes(),
            ]));
        } catch (QuickBooksApiException $exception) {
            report($exception);

            return $this->failed(__('Could not connect to QuickBooks: :reason', ['reason' => $exception->getMessage()]));
        }

        $companyName = $companyInfo['CompanyInfo']['CompanyName'] ?? "QuickBooks company {$realmId}";

        $connection = QuickBooksConnection::updateOrCreate(
            ['team_id' => $team->id, 'realm_id' => $realmId],
            [
                ...$tokens->toConnectionAttributes(),
                'company_name' => $companyName,
                'connected_by' => $request->user()->id,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected ":name".', ['name' => $connection->company_name])]);

        return to_route('quickbooks.index');
    }

    /**
     * Disconnect a company and revoke its tokens with Intuit.
     */
    public function destroy(Request $request, QuickBooksConnection $connection, QuickBooksOAuth $oauth): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($connection->team), 404);

        Gate::authorize('update', $connection->team);

        try {
            $oauth->revoke($connection->refresh_token);
        } catch (HttpClientException $exception) {
            report($exception);
        }

        $connection->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Disconnected ":name".', ['name' => $connection->company_name])]);

        return to_route('quickbooks.index');
    }

    /**
     * Get the team whose connections are being managed.
     */
    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        return $user->currentTeam ?? $user->personalTeam() ?? abort(404);
    }

    /**
     * Redirect back to the index with an error toast.
     */
    protected function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return to_route('quickbooks.index');
    }
}
