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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_entity')]
#[Description('Fetch a single QuickBooks Online record by entity type and ID from one company, e.g. entity "Invoice" with id "1042". Returns the full record including its SyncToken, which is required for update_entity.')]
#[IsReadOnly]
#[IsIdempotent]
class GetEntityTool extends QuickBooksTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'company' => ['required', 'string'],
            'entity' => ['required', 'string', 'alpha', 'max:50'],
            'id' => ['required', 'string', 'max:50'],
        ], [
            'entity.alpha' => 'entity must be a QuickBooks entity name such as Customer, Invoice, or Bill.',
        ]);

        $connection = $this->resolveCompany($request);

        if (! $connection instanceof QuickBooksConnection) {
            return $connection;
        }

        return $this->respondWith(fn (): array => [
            'company' => $connection->slug,
            ...$this->api->find($connection, $validated['entity'], $validated['id']),
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
            'entity' => $schema->string()->description('The entity type, e.g. Customer, Vendor, Invoice, Bill, Item, Account.')->required(),
            'id' => $schema->string()->description('The QuickBooks ID of the record.')->required(),
        ];
    }
}
