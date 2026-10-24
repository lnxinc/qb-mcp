<?php

use App\Enums\TeamRole;
use App\Models\QuickBooksConnection;
use App\Models\Team;
use App\Models\User;
use App\Services\QuickBooks\QuickBooksOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.quickbooks.client_id', 'intuit-client-id');
    config()->set('services.quickbooks.client_secret', 'intuit-client-secret');
    config()->set('services.quickbooks.environment', 'sandbox');
});

test('guests cannot access quickbooks settings', function () {
    $response = $this->get(route('quickbooks.index'));

    $response->assertRedirect(route('login'));
});

test('the quickbooks page lists only the current team connections', function () {
    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create(['team_id' => $user->personalTeam()->id]);
    QuickBooksConnection::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('quickbooks.index'));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/quickbooks')
            ->has('connections', 1)
            ->where('connections.0.slug', $connection->slug)
            ->where('connections.0.needsReconnect', false)
            ->where('canManage', true)
            ->where('configured', true),
        );
});

test('connecting redirects to intuit with a state stored in the session', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('quickbooks.connect'));

    $response->assertRedirect();
    $response->assertSessionHas('quickbooks.oauth.team_id', $user->personalTeam()->id);

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith(QuickBooksOAuth::AUTHORIZATION_URL);
    expect($query)
        ->client_id->toBe('intuit-client-id')
        ->redirect_uri->toBe(route('quickbooks.callback'))
        ->scope->toBe(QuickBooksOAuth::ACCOUNTING_SCOPE)
        ->state->toBe(session('quickbooks.oauth.state'));
});

test('connecting is refused when the intuit credentials are not configured', function () {
    config()->set('services.quickbooks.client_id', null);
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('quickbooks.connect'));

    $response->assertRedirect(route('quickbooks.index'));
    $response->assertSessionMissing('quickbooks.oauth');
});

test('team members without the update permission cannot connect companies', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->switchTeam($team);

    $response = $this
        ->actingAs($member)
        ->get(route('quickbooks.connect'));

    $response->assertForbidden();
});

test('the callback exchanges the code and stores the company with encrypted tokens', function () {
    Http::preventStrayRequests();
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'token_type' => 'bearer',
        ]),
        'sandbox-quickbooks.api.intuit.com/v3/company/4620816365/companyinfo/4620816365*' => Http::response([
            'CompanyInfo' => ['CompanyName' => 'Acme Plumbing', 'Id' => '1'],
        ]),
    ]);

    $user = User::factory()->create();
    $team = $user->personalTeam();

    $response = $this
        ->actingAs($user)
        ->withSession(['quickbooks.oauth' => ['state' => 'expected-state', 'team_id' => $team->id]])
        ->get(route('quickbooks.callback', [
            'code' => 'auth-code',
            'state' => 'expected-state',
            'realmId' => '4620816365',
        ]));

    $response->assertRedirect(route('quickbooks.index'));
    $response->assertSessionMissing('quickbooks.oauth');

    $this->assertDatabaseHas('quick_books_connections', [
        'team_id' => $team->id,
        'realm_id' => '4620816365',
        'company_name' => 'Acme Plumbing',
        'slug' => 'acme-plumbing',
        'connected_by' => $user->id,
    ]);

    $connection = QuickBooksConnection::sole();

    expect($connection->access_token)->toBe('access-token');
    expect($connection->refresh_token)->toBe('refresh-token');
    expect(DB::table('quick_books_connections')->value('access_token'))->not->toBe('access-token');

    Http::assertSent(fn (Request $request) => $request->url() === QuickBooksOAuth::TOKEN_URL
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['redirect_uri'] === route('quickbooks.callback')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('intuit-client-id:intuit-client-secret')));
});

test('reconnecting an already connected company updates its tokens instead of duplicating it', function () {
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
        ]),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response([
            'CompanyInfo' => ['CompanyName' => 'Acme Plumbing'],
        ]),
    ]);

    $user = User::factory()->create();
    $team = $user->personalTeam();
    $existing = QuickBooksConnection::factory()->expiredRefreshToken()->create([
        'team_id' => $team->id,
        'realm_id' => '4620816365',
        'slug' => 'acme',
    ]);

    $this
        ->actingAs($user)
        ->withSession(['quickbooks.oauth' => ['state' => 'expected-state', 'team_id' => $team->id]])
        ->get(route('quickbooks.callback', [
            'code' => 'auth-code',
            'state' => 'expected-state',
            'realmId' => '4620816365',
        ]))
        ->assertRedirect(route('quickbooks.index'));

    $this->assertDatabaseCount('quick_books_connections', 1);

    $existing->refresh();

    expect($existing->slug)->toBe('acme');
    expect($existing->refresh_token)->toBe('new-refresh-token');
    expect($existing->refreshTokenHasExpired())->toBeFalse();
});

test('the callback rejects a state that does not match the session', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession(['quickbooks.oauth' => ['state' => 'expected-state', 'team_id' => $user->personalTeam()->id]])
        ->get(route('quickbooks.callback', [
            'code' => 'auth-code',
            'state' => 'forged-state',
            'realmId' => '4620816365',
        ]));

    $response->assertRedirect(route('quickbooks.index'));
    $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The QuickBooks authorization could not be verified. Please try connecting again.']);

    $this->assertDatabaseCount('quick_books_connections', 0);
});

test('the callback does not store anything when the user cancels at intuit', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession(['quickbooks.oauth' => ['state' => 'expected-state', 'team_id' => $user->personalTeam()->id]])
        ->get(route('quickbooks.callback', [
            'error' => 'access_denied',
            'state' => 'expected-state',
        ]));

    $response->assertRedirect(route('quickbooks.index'));
    $response->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'QuickBooks authorization was cancelled or did not complete.']);

    $this->assertDatabaseCount('quick_books_connections', 0);
});

test('disconnecting revokes the refresh token and deletes the connection', function () {
    Http::preventStrayRequests();
    Http::fake([
        'developer.api.intuit.com/*' => Http::response([], 200),
    ]);

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create([
        'team_id' => $user->personalTeam()->id,
        'refresh_token' => 'refresh-to-revoke',
    ]);

    $response = $this
        ->actingAs($user)
        ->delete(route('quickbooks.destroy', $connection));

    $response->assertRedirect(route('quickbooks.index'));

    $this->assertDatabaseMissing('quick_books_connections', ['id' => $connection->id]);

    Http::assertSent(fn (Request $request) => $request->url() === QuickBooksOAuth::REVOCATION_URL
        && $request['token'] === 'refresh-to-revoke');
});

test('disconnecting still succeeds when intuit refuses the revocation', function () {
    Http::fake([
        'developer.api.intuit.com/*' => Http::response(['error' => 'invalid_token'], 400),
    ]);

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create(['team_id' => $user->personalTeam()->id]);

    $this
        ->actingAs($user)
        ->delete(route('quickbooks.destroy', $connection))
        ->assertRedirect(route('quickbooks.index'));

    $this->assertDatabaseMissing('quick_books_connections', ['id' => $connection->id]);
});

test('users cannot disconnect companies that belong to another team', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $connection = QuickBooksConnection::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('quickbooks.destroy', $connection));

    $response->assertNotFound();

    $this->assertDatabaseHas('quick_books_connections', ['id' => $connection->id]);
});
