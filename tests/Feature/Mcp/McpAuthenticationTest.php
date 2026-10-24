<?php

function initializeMcpPayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
        ],
    ];
}

test('the remote mcp endpoint returns 401 without a bearer token', function () {
    config()->set('services.mcp.token', 'top-secret');

    $this->postJson('/mcp/quickbooks', initializeMcpPayload())
        ->assertUnauthorized();
});

test('the remote mcp endpoint returns 401 with the wrong bearer token', function () {
    config()->set('services.mcp.token', 'top-secret');

    $this->withToken('not-the-token')
        ->postJson('/mcp/quickbooks', initializeMcpPayload())
        ->assertUnauthorized();
});

test('the remote mcp endpoint returns 401 when no token is configured even if one is sent', function () {
    config()->set('services.mcp.token', null);

    $this->withToken('anything')
        ->postJson('/mcp/quickbooks', initializeMcpPayload())
        ->assertUnauthorized();
});

test('the remote mcp endpoint accepts the configured bearer token', function () {
    config()->set('services.mcp.token', 'top-secret');

    $this->withToken('top-secret')
        ->postJson('/mcp/quickbooks', initializeMcpPayload())
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'QuickBooks');
});
