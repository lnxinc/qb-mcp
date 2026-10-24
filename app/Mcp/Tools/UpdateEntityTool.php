<?php

namespace App\Mcp\Tools;

use App\Models\QuickBooksConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('update_entity')]
#[Description('Apply a sparse update to an existing record in one QuickBooks Online company. The payload must include the record "Id" and its current "SyncToken" (fetch the record first with get_entity or query), plus only the fields to change. Confirm the change with the user first. Returns the updated record.')]
#[IsIdempotent]
#[IsOpenWorld]
class UpdateEntityTool extends QuickBooksTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'company' => ['required', 'string'],
            'entity' => ['required', 'string', 'alpha', 'max:50'],
            'payload' => ['required', 'array'],
            'payload.Id' => ['required'],
            'payload.SyncToken' => ['required'],
        ], [
            'payload.Id.required' => 'The payload must include the "Id" of the record to update.',
            'payload.SyncToken.required' => 'The payload must include the current "SyncToken" of the record. Fetch it with get_entity first.',
        ]);

        $connection = $this->resolveCompany($request);

        if (! $connection instanceof QuickBooksConnection) {
            return $connection;
        }

        return $this->respondWith(fn (): array => [
            'company' => $connection->slug,
            ...$this->api->update($connection, $validated['entity'], $validated['payload']),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $this->companySchema($schema),
            'entity' => $schema->string()->description('The entity type to update, e.g. Customer, Invoice, Bill.')->required(),
            'payload' => $schema->object()->description('The fields to change, including "Id" and the current "SyncToken".')->required(),
        ];
    }
}
