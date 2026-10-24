<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateEntityTool;
use App\Mcp\Tools\GetEntityTool;
use App\Mcp\Tools\GetReportTool;
use App\Mcp\Tools\ListCompaniesTool;
use App\Mcp\Tools\QueryTool;
use App\Mcp\Tools\UpdateEntityTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('QuickBooks')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
This server gives you access to several QuickBooks Online companies through one connection.

- Every tool except list_companies takes a "company" argument. Call list_companies first and pass the slug it returns. If the user names a company you do not recognize, list the companies and ask which one they mean rather than guessing.
- Use "query" for lists and lookups (QBO query syntax, e.g. SELECT * FROM Invoice WHERE Balance > '0' MAXRESULTS 100). Use "get_report" for financial statements such as ProfitAndLoss, BalanceSheet, CashFlow, AgedReceivables, and AgedPayables.
- Dates are YYYY-MM-DD. Money values are returned as QuickBooks provides them, in the company's home currency.
- create_entity and update_entity change live accounting data. Confirm the company and the exact payload with the user before calling them. update_entity needs the record's current SyncToken; fetch it with get_entity first.
- A company whose status is "reconnect_required" must be re-authorized by the user in the web app (Settings → QuickBooks) before it can be used.
MARKDOWN)]
class QuickBooksServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListCompaniesTool::class,
        QueryTool::class,
        GetReportTool::class,
        GetEntityTool::class,
        CreateEntityTool::class,
        UpdateEntityTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
