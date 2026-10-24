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

#[Name('get_report')]
#[Description('Run a QuickBooks Online financial report for one company, such as ProfitAndLoss, BalanceSheet, CashFlow, TrialBalance, GeneralLedger, AgedReceivables, or AgedPayables. Dates are YYYY-MM-DD. Returns the raw report JSON (Header, Columns, Rows).')]
#[IsReadOnly]
#[IsIdempotent]
class GetReportTool extends QuickBooksTool
{
    /**
     * The reports exposed by the QuickBooks Online reports API.
     *
     * @var array<int, string>
     */
    public const array REPORTS = [
        'ProfitAndLoss',
        'ProfitAndLossDetail',
        'BalanceSheet',
        'CashFlow',
        'TrialBalance',
        'GeneralLedger',
        'AgedReceivables',
        'AgedReceivableDetail',
        'AgedPayables',
        'AgedPayableDetail',
        'CustomerBalance',
        'CustomerBalanceDetail',
        'CustomerIncome',
        'VendorBalance',
        'VendorBalanceDetail',
        'VendorExpenses',
        'ItemSales',
        'DepartmentSales',
        'ClassSales',
        'InventoryValuationSummary',
        'TransactionList',
        'TaxSummary',
        'AccountList',
    ];

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'company' => ['required', 'string'],
            'report' => ['required', 'string', 'in:'.implode(',', self::REPORTS)],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'date_macro' => ['nullable', 'string', 'max:50'],
            'accounting_method' => ['nullable', 'in:Cash,Accrual'],
            'summarize_column_by' => ['nullable', 'string', 'max:50'],
            'customer' => ['nullable', 'string', 'max:50'],
            'vendor' => ['nullable', 'string', 'max:50'],
            'options' => ['nullable', 'array'],
        ], [
            'report.in' => 'Unknown report. Choose one of: '.implode(', ', self::REPORTS).'.',
            'start_date.date_format' => 'start_date must be formatted as YYYY-MM-DD.',
            'end_date.date_format' => 'end_date must be formatted as YYYY-MM-DD.',
        ]);

        $connection = $this->resolveCompany($request);

        if (! $connection instanceof QuickBooksConnection) {
            return $connection;
        }

        $parameters = array_filter([
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'date_macro' => $validated['date_macro'] ?? null,
            'accounting_method' => $validated['accounting_method'] ?? null,
            'summarize_column_by' => $validated['summarize_column_by'] ?? null,
            'customer' => $validated['customer'] ?? null,
            'vendor' => $validated['vendor'] ?? null,
            ...array_filter($validated['options'] ?? [], 'is_scalar'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->respondWith(fn (): array => [
            'company' => $connection->slug,
            ...$this->api->report($connection, $validated['report'], $parameters),
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
            'report' => $schema->string()
                ->enum(self::REPORTS)
                ->description('The report to run.')
                ->required(),
            'start_date' => $schema->string()->description('Report start date (YYYY-MM-DD).'),
            'end_date' => $schema->string()->description('Report end date (YYYY-MM-DD).'),
            'date_macro' => $schema->string()->description('A QuickBooks date macro instead of explicit dates, e.g. "This Fiscal Year-to-date", "Last Month", "This Quarter".'),
            'accounting_method' => $schema->string()->enum(['Cash', 'Accrual'])->description('Accounting method for the report.'),
            'summarize_column_by' => $schema->string()->description('Column grouping, e.g. Total, Month, Quarter, Year, Customers, Vendors, Classes, Departments.'),
            'customer' => $schema->string()->description('Filter by a customer ID.'),
            'vendor' => $schema->string()->description('Filter by a vendor ID.'),
            'options' => $schema->object()->description('Any additional report query parameters supported by the QuickBooks reports API, e.g. {"aging_period": 30, "num_periods": 4}.'),
        ];
    }
}
