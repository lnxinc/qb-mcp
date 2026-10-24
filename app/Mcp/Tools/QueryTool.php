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

#[Name('query')]
#[Description('Run a QuickBooks Online query against one company using QBO query syntax, for example "SELECT * FROM Invoice WHERE TxnDate >= \'2026-01-01\' MAXRESULTS 100". Works for any entity: Customer, Vendor, Invoice, Bill, Payment, BillPayment, Account, Item, JournalEntry, Purchase, Estimate, SalesReceipt, CreditMemo, Deposit, Transfer, Employee, Class, Department, TaxCode, CompanyInfo, Preferences. Use "SELECT COUNT(*) FROM Entity" for counts and STARTPOSITION/MAXRESULTS (max 1000) for paging.')]
#[IsReadOnly]
#[IsIdempotent]
class QueryTool extends QuickBooksTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'company' => ['required', 'string'],
            'query' => ['required', 'string', 'max:5000'],
        ], [
            'company.required' => 'Specify which company to query. Call list_companies to see the available slugs.',
            'query.required' => 'Provide a QuickBooks query such as "SELECT * FROM Customer MAXRESULTS 50".',
        ]);

        $connection = $this->resolveCompany($request);

        if (! $connection instanceof QuickBooksConnection) {
            return $connection;
        }

        return $this->respondWith(function () use ($connection, $validated): array {
            $result = $this->api->query($connection, $validated['query']);

            return [
                'company' => $connection->slug,
                ...($result['QueryResponse'] ?? $result),
            ];
        });
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
            'query' => $schema->string()
                ->description('The QBO query to run, e.g. "SELECT * FROM Customer WHERE Active = true MAXRESULTS 100".')
                ->required(),
        ];
    }
}
