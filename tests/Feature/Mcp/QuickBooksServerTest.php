<?php

use App\Mcp\Servers\QuickBooksServer;
use App\Mcp\Tools\CreateEntityTool;
use App\Mcp\Tools\GetEntityTool;
use App\Mcp\Tools\GetReportTool;
use App\Mcp\Tools\ListCompaniesTool;
use App\Mcp\Tools\QueryTool;
use App\Mcp\Tools\UpdateEntityTool;
use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksOAuth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.quickbooks.client_id', 'intuit-client-id');
    config()->set('services.quickbooks.client_secret', 'intuit-client-secret');
    config()->set('services.quickbooks.environment', 'sandbox');
    config()->set('services.quickbooks.minor_version', '75');
});

test('list_companies returns every connected company with its slug', function () {
    $acme = QuickBooksConnection::factory()->create(['company_name' => 'Acme Plumbing', 'slug' => 'acme-plumbing']);
    $expired = QuickBooksConnection::factory()->expiredRefreshToken()->create(['company_name' => 'Zed Roofing', 'slug' => 'zed-roofing']);

    $response = QuickBooksServer::tool(ListCompaniesTool::class);

    $response
        ->assertOk()
        ->assertSee('acme-plumbing')
        ->assertSee($acme->realm_id)
        ->assertSee('zed-roofing')
        ->assertSee('reconnect_required')
        ->assertSee($expired->team->name);
});

test('list_companies explains how to connect when nothing is connected', function () {
    QuickBooksServer::tool(ListCompaniesTool::class)
        ->assertOk()
        ->assertSee('No QuickBooks companies are connected yet');
});

test('query runs against the realm of the chosen company', function () {
    Http::preventStrayRequests();
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/query*' => Http::response([
            'QueryResponse' => ['Customer' => [['Id' => '1', 'DisplayName' => 'Alice Example']]],
        ]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme', 'access_token' => 'access-111']);
    QuickBooksConnection::factory()->create(['realm_id' => '222', 'slug' => 'other', 'access_token' => 'access-222']);

    $response = QuickBooksServer::tool(QueryTool::class, [
        'company' => 'acme',
        'query' => 'SELECT * FROM Customer',
    ]);

    $response
        ->assertOk()
        ->assertSee('Alice Example')
        ->assertSee('"company":"acme"');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://sandbox-quickbooks.api.intuit.com/v3/company/111/query?')
        && $request['query'] === 'SELECT * FROM Customer'
        && $request['minorversion'] === '75'
        && $request->hasHeader('Authorization', 'Bearer access-111'));
});

test('a company can be chosen by realm id or company name', function (string $identifier) {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/query*' => Http::response(['QueryResponse' => []]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme-plumbing', 'company_name' => 'Acme Plumbing']);

    QuickBooksServer::tool(QueryTool::class, ['company' => $identifier, 'query' => 'SELECT COUNT(*) FROM Invoice'])
        ->assertOk()
        ->assertSee('"company":"acme-plumbing"');
})->with(['111', 'acme plumbing', 'Acme Plumbing']);

test('query reports an unknown company and lists the available ones', function () {
    Http::preventStrayRequests();

    QuickBooksConnection::factory()->create(['slug' => 'acme', 'company_name' => 'Acme Plumbing']);

    $response = QuickBooksServer::tool(QueryTool::class, [
        'company' => 'nope',
        'query' => 'SELECT * FROM Customer',
    ]);

    $response
        ->assertHasErrors()
        ->assertSee('Unknown company [nope]')
        ->assertSee('acme (Acme Plumbing)');
});

test('an expiring access token is refreshed and persisted before the request', function () {
    Http::preventStrayRequests();
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
        ]),
        'sandbox-quickbooks.api.intuit.com/v3/company/111/query*' => Http::response(['QueryResponse' => []]),
    ]);

    $connection = QuickBooksConnection::factory()->expiredAccessToken()->create([
        'realm_id' => '111',
        'slug' => 'acme',
        'access_token' => 'stale-access',
        'refresh_token' => 'old-refresh',
    ]);

    QuickBooksServer::tool(QueryTool::class, ['company' => 'acme', 'query' => 'SELECT * FROM Account'])
        ->assertOk();

    $connection->refresh();

    expect($connection->access_token)->toBe('fresh-access');
    expect($connection->refresh_token)->toBe('fresh-refresh');
    expect($connection->accessTokenIsExpiring())->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->url() === QuickBooksOAuth::TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v3/company/111/query')
        && $request->hasHeader('Authorization', 'Bearer fresh-access'));
});

test('a rejected access token is refreshed once and the request retried', function () {
    Http::preventStrayRequests();
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response([
            'access_token' => 'fresh-access',
            'refresh_token' => 'fresh-refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
        ]),
        'sandbox-quickbooks.api.intuit.com/v3/company/111/query*' => Http::sequence()
            ->push(['Fault' => ['Error' => [['Message' => 'AuthenticationFailed', 'code' => '3200']]]], 401)
            ->push(['QueryResponse' => ['Account' => []]]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme', 'access_token' => 'revoked-access']);

    QuickBooksServer::tool(QueryTool::class, ['company' => 'acme', 'query' => 'SELECT * FROM Account'])
        ->assertOk();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/query')
        && $request->hasHeader('Authorization', 'Bearer fresh-access'));
});

test('a company whose refresh token expired returns a reconnect error without calling intuit', function () {
    Http::preventStrayRequests();

    QuickBooksConnection::factory()->expiredRefreshToken()->create(['slug' => 'acme', 'company_name' => 'Acme Plumbing']);

    QuickBooksServer::tool(QueryTool::class, ['company' => 'acme', 'query' => 'SELECT * FROM Account'])
        ->assertHasErrors()
        ->assertSee('The QuickBooks connection for "Acme Plumbing" has expired');
});

test('quickbooks faults are returned as tool errors with the intuit message', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/query*' => Http::response([
            'Fault' => [
                'Error' => [[
                    'Message' => 'Error parsing query',
                    'Detail' => 'QueryParserError: Encountered " <ID> "Customers "" at line 1',
                    'code' => '4000',
                ]],
                'type' => 'ValidationFault',
            ],
        ], 400),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme']);

    QuickBooksServer::tool(QueryTool::class, ['company' => 'acme', 'query' => 'SELECT * FROM Customers'])
        ->assertHasErrors()
        ->assertSee('Error parsing query: QueryParserError')
        ->assertSee('(code 4000)');
});

test('get_report passes the report parameters through to quickbooks', function () {
    Http::preventStrayRequests();
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/reports/ProfitAndLoss*' => Http::response([
            'Header' => ['ReportName' => 'ProfitAndLoss'],
            'Rows' => [],
        ]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme']);

    QuickBooksServer::tool(GetReportTool::class, [
        'company' => 'acme',
        'report' => 'ProfitAndLoss',
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-31',
        'accounting_method' => 'Accrual',
        'options' => ['summarize_column_by' => 'Month'],
    ])->assertOk()->assertSee('"ReportName":"ProfitAndLoss"');

    Http::assertSent(fn (Request $request) => $request['start_date'] === '2026-01-01'
        && $request['end_date'] === '2026-03-31'
        && $request['accounting_method'] === 'Accrual'
        && $request['summarize_column_by'] === 'Month'
        && ! isset($request['customer']));
});

test('get_report rejects an unknown report name', function () {
    Http::preventStrayRequests();

    QuickBooksConnection::factory()->create(['slug' => 'acme']);

    QuickBooksServer::tool(GetReportTool::class, ['company' => 'acme', 'report' => 'Nonsense'])
        ->assertHasErrors()
        ->assertSee('Unknown report');
});

test('get_entity fetches a single record by id', function () {
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/invoice/1042*' => Http::response([
            'Invoice' => ['Id' => '1042', 'SyncToken' => '3', 'TotalAmt' => 150.0],
        ]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme']);

    QuickBooksServer::tool(GetEntityTool::class, ['company' => 'acme', 'entity' => 'Invoice', 'id' => '1042'])
        ->assertOk()
        ->assertSee('"SyncToken":"3"');
});

test('create_entity posts the payload to the entity endpoint', function () {
    Http::preventStrayRequests();
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/customer*' => Http::response([
            'Customer' => ['Id' => '58', 'DisplayName' => 'Bob Builder'],
        ]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme']);

    QuickBooksServer::tool(CreateEntityTool::class, [
        'company' => 'acme',
        'entity' => 'Customer',
        'payload' => ['DisplayName' => 'Bob Builder'],
    ])->assertOk()->assertSee('"Id":"58"');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://sandbox-quickbooks.api.intuit.com/v3/company/111/customer?minorversion=75')
        && $request['DisplayName'] === 'Bob Builder');
});

test('update_entity sends a sparse update and requires the sync token', function () {
    Http::preventStrayRequests();
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/111/customer*' => Http::response([
            'Customer' => ['Id' => '58', 'SyncToken' => '1', 'DisplayName' => 'Robert Builder'],
        ]),
    ]);

    QuickBooksConnection::factory()->create(['realm_id' => '111', 'slug' => 'acme']);

    QuickBooksServer::tool(UpdateEntityTool::class, [
        'company' => 'acme',
        'entity' => 'Customer',
        'payload' => ['Id' => '58', 'DisplayName' => 'Robert Builder'],
    ])->assertHasErrors()->assertSee('SyncToken');

    Http::assertNothingSent();

    QuickBooksServer::tool(UpdateEntityTool::class, [
        'company' => 'acme',
        'entity' => 'Customer',
        'payload' => ['Id' => '58', 'SyncToken' => '0', 'DisplayName' => 'Robert Builder'],
    ])->assertOk()->assertSee('Robert Builder');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['sparse'] === true
        && $request['Id'] === '58'
        && $request['SyncToken'] === '0');
});
