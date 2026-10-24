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

#[Name('create_entity')]
#[Description('Create a record in one QuickBooks Online company. Provide the entity type (e.g. Customer, Invoice, Bill, JournalEntry) and the JSON payload in the shape the QuickBooks API expects, for example {"DisplayName": "Acme Co"} for a Customer or {"CustomerRef": {"value": "12"}, "Line": [...]} for an Invoice. Confirm the target company and payload with the user before creating anything. Returns the created record.')]
#[IsIdempotent(false)]
#[IsOpenWorld]
class CreateEntityTool extends QuickBooksTool
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
        ], [
            'payload.required' => 'Provide the record to create as a JSON object in the "payload" argument.',
        ]);

        $connection = $this->resolveCompany($request);

        if (! $connection instanceof QuickBooksConnection) {
            return $connection;
        }

        return $this->respondWith(fn (): array => [
            'company' => $connection->slug,
            ...$this->api->create($connection, $validated['entity'], $validated['payload']),
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
            'entity' => $schema->string()->description('The entity type to create, e.g. Customer, Vendor, Invoice, Bill, Item, JournalEntry.')->required(),
            'payload' => $schema->object()->description('The record body in QuickBooks API JSON format.')->required(),
        ];
    }
}
