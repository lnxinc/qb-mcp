# QuickBooks MCP

One MCP server, many QuickBooks Online companies.

`qb-mcp` lets Claude (Claude Code, claude.ai, or any MCP client) work with every QuickBooks Online company you manage through a single connector. Each company is authorized once through Intuit's OAuth flow; after that, every tool call simply names the company it should run against.

```
Claude ──► one MCP server ──► company "acme-plumbing"   (realm 4620816365)
                          ├─► company "zed-roofing"     (realm 9311078421)
                          └─► company "northwind-llc"   (realm 1200456677)
```

## Features

- **Multi-company by design.** Tools take a `company` argument (slug, realm ID, or company name). No per-company connectors.
- **Self-service connections.** Team owners connect, reconnect, and disconnect companies from a settings page in the web app.
- **Automatic token refresh.** Access tokens are refreshed before they expire, behind a lock so concurrent calls never race. A rejected token is refreshed once and the call retried.
- **Two transports.** Local stdio for Claude Code, and an HTTP endpoint protected by a bearer token for claude.ai and remote sessions.
- **Encrypted at rest.** Access and refresh tokens are stored with Laravel's encrypted cast and hidden from serialization.
- **Team scoped.** Connections belong to a team. When a transport authenticates a user, tools only see that user's teams.

## Tools

| Tool | Description |
| --- | --- |
| `list_companies` | Lists every connected company with its slug, realm ID, team, and connection status. Call this first. |
| `query` | Runs a QBO query such as `SELECT * FROM Invoice WHERE Balance > '0' MAXRESULTS 100`. |
| `get_report` | Runs a financial report: ProfitAndLoss, BalanceSheet, CashFlow, TrialBalance, GeneralLedger, AgedReceivables, AgedPayables, and more. |
| `get_entity` | Fetches one record by entity type and ID, including its `SyncToken`. |
| `create_entity` | Creates a record from a QuickBooks API JSON payload. |
| `update_entity` | Applies a sparse update. The payload must include `Id` and the current `SyncToken`. |

Example conversation once connected:

> List my QuickBooks companies.
> What were Acme Plumbing's total sales last quarter, by month?
> Show overdue invoices for Zed Roofing.

## Requirements

- PHP 8.3 or newer, Composer
- Node.js 20 or newer, npm
- A database (SQLite is configured by default)
- An Intuit developer app ([developer.intuit.com](https://developer.intuit.com))

## Setup

### 1. Install

```bash
git clone git@github.com:lnxinc/qb-mcp.git
cd qb-mcp
composer run setup
```

`composer run setup` installs PHP and JS dependencies, creates `.env`, generates the app key, runs migrations, and builds the frontend.

### 2. Create an Intuit app

1. Sign in at [developer.intuit.com](https://developer.intuit.com) and create an app with the **Accounting** scope.
2. On the app's **Keys & credentials** page, copy the **Client ID** and **Client Secret**. Use the Development keys for sandbox companies and the Production keys for real companies.
3. Add this redirect URI to the app (replace the host with your `APP_URL`):

   ```
   http://localhost:8000/settings/quickbooks/callback
   ```

   Intuit requires HTTPS for production redirect URIs. `localhost` over HTTP is allowed for development.

The client ID and secret identify **your app**, not a company. One app can be authorized against any number of QuickBooks companies.

### 3. Configure `.env`

```dotenv
APP_URL=http://localhost:8000

QUICKBOOKS_CLIENT_ID=your-client-id
QUICKBOOKS_CLIENT_SECRET=your-client-secret
QUICKBOOKS_ENVIRONMENT=sandbox        # or production
QUICKBOOKS_MINOR_VERSION=75

MCP_ACCESS_TOKEN=a-long-random-string  # only needed for the HTTP transport
```

Generate a token with `openssl rand -base64 32`.

### 4. Run the app and connect companies

```bash
composer run dev
```

Open the app, register an account, then go to **Settings → QuickBooks** and click **Connect company**. You will be sent to Intuit to pick a company and approve access. Repeat for every company you want Claude to reach. Each company appears in the list with a slug such as `acme-plumbing`.

Refresh tokens from Intuit last about 100 days of inactivity. A company whose token has expired is flagged **Reconnect required** on the settings page and reported as `reconnect_required` by `list_companies`.

## Connecting Claude

### Claude Code (local)

The repository ships a `.mcp.json` that registers the server for Claude Code sessions started inside this folder:

```json
{
    "mcpServers": {
        "quickbooks": {
            "command": "php",
            "args": ["artisan", "mcp:start", "quickbooks"]
        }
    }
}
```

To use it from any folder, add it to your user scope with an absolute path:

```bash
claude mcp add --scope user quickbooks -- php /path/to/qb-mcp/artisan mcp:start quickbooks
```

No token is needed for the stdio transport. It reads the same database as the web app.

### claude.ai and remote Claude Code (HTTP)

Deploy the app on a public HTTPS URL, set `MCP_ACCESS_TOKEN`, then point the client at the endpoint with a bearer header:

```bash
claude mcp add --transport http quickbooks https://your-host/mcp/quickbooks \
  --header "Authorization: Bearer $MCP_ACCESS_TOKEN"
```

In claude.ai, add a custom connector with the same URL and header. Requests without the correct token receive `401`.

### Debugging

Laravel MCP bundles the MCP Inspector:

```bash
php artisan mcp:inspector quickbooks          # local server
php artisan mcp:inspector mcp/quickbooks      # HTTP server
```

## How it works

| Piece | Location |
| --- | --- |
| MCP server and tools | `app/Mcp/Servers/QuickBooksServer.php`, `app/Mcp/Tools/` |
| Transport registration | `routes/ai.php` |
| Intuit OAuth client | `app/Services/QuickBooks/QuickBooksOAuth.php` |
| QuickBooks API client with token refresh | `app/Services/QuickBooks/QuickBooksApi.php` |
| Company lookup by slug, realm ID, or name | `app/Services/QuickBooks/CompanyResolver.php` |
| Connection model and migration | `app/Models/QuickBooksConnection.php`, `database/migrations/` |
| Settings page and OAuth callback | `app/Http/Controllers/Settings/QuickBooksConnectionController.php`, `resources/js/pages/settings/quickbooks.tsx` |
| Bearer-token middleware for the HTTP transport | `app/Http/Middleware/AuthenticateMcpToken.php` |

A tool call resolves the `company` argument to a connection row, obtains a fresh access token for that row, and calls `https://{sandbox-}quickbooks.api.intuit.com/v3/company/{realmId}/...`. Faults returned by QuickBooks are passed back to the model as tool errors with Intuit's message and error code.

## Development

```bash
php artisan test --compact     # test suite (Pest)
vendor/bin/pint                # code style
vendor/bin/phpstan analyse     # static analysis
npm run check                  # frontend lint and format
npm run types:check            # TypeScript
```

Tests fake every outbound HTTP call, so no Intuit credentials are required to run them.

## Security notes

- Never commit `.env`. The client secret and `MCP_ACCESS_TOKEN` are secrets.
- `create_entity` and `update_entity` change live accounting data. The server instructions tell Claude to confirm the company and payload with you first; keep that habit.
- The HTTP transport uses a single shared token. If several people need separate access on a shared deployment, replace `AuthenticateMcpToken` with Laravel Sanctum or Passport. The tools already scope results to the authenticated user's teams.

## License

MIT
