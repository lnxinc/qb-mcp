<p align="center">
  <h1 align="center">QuickBooks MCP</h1>
  <p align="center">
    <strong>Talk to every QuickBooks Online company you manage from one AI connector.</strong>
  </p>
  <p align="center">
    <a href="https://github.com/lnxinc/qb-mcp/actions/workflows/tests.yml"><img alt="Tests" src="https://github.com/lnxinc/qb-mcp/actions/workflows/tests.yml/badge.svg"></a>
    <a href="https://github.com/lnxinc/qb-mcp/actions/workflows/docker.yml"><img alt="Docker image" src="https://github.com/lnxinc/qb-mcp/actions/workflows/docker.yml/badge.svg"></a>
    <a href="https://github.com/lnxinc/qb-mcp/pkgs/container/qb-mcp"><img alt="ghcr.io" src="https://img.shields.io/badge/ghcr.io-lnxinc%2Fqb--mcp-blue?logo=docker"></a>
    <a href="LICENSE"><img alt="MIT License" src="https://img.shields.io/badge/license-MIT-green.svg"></a>
    <img alt="PHP 8.3+" src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white">
    <img alt="MCP" src="https://img.shields.io/badge/MCP-Streamable%20HTTP%20%2B%20stdio-8A2BE2">
  </p>
</p>

---

Bookkeepers, accountants, fractional CFOs and agency owners rarely look after just one set of books. Most AI integrations for QuickBooks assume you do. **QuickBooks MCP** is a small, self-hosted [Model Context Protocol](https://modelcontextprotocol.io) server that connects _all_ of your QuickBooks Online companies to Claude, Cursor, ChatGPT-style assistants and any other MCP client, through a single connection.

Authorize each company once with Intuit's standard "Connect to QuickBooks" flow. After that, every tool call simply names the company it should run against.

```
Your AI assistant ──► QuickBooks MCP ──► "acme-plumbing"   (realm 4620816365)
                                     ├─► "zed-roofing"     (realm 9311078421)
                                     └─► "northwind-llc"   (realm 1200456677)
```

Then just ask:

> _"List my QuickBooks companies."_
> _"What were Acme Plumbing's sales last quarter, broken down by month?"_
> _"Which Zed Roofing invoices are more than 30 days overdue?"_
> _"Compare the P&L of all three companies for September."_

## Why people like it

- **Multi-company by design.** One connector, unlimited companies. Tools take a `company` argument that accepts a slug, a realm ID or the company name.
- **Runs anywhere in one command.** `docker compose up` gives you the web app, the MCP endpoint, a database and automatic HTTPS when you want it.
- **Self-service connections.** A friendly settings page lets team owners connect, reconnect and disconnect companies without touching a terminal.
- **Tokens that look after themselves.** Access tokens refresh before they expire, behind a lock so concurrent calls never race. A rejected token is refreshed once and the call retried.
- **Works with your assistant of choice.** Streamable HTTP for claude.ai, Cursor, VS Code, Windsurf and friends. stdio for Claude Code, Claude Desktop and Codex CLI.
- **Safe to leave running.** Tokens are encrypted at rest, the HTTP endpoint is protected by a bearer token, and connections are scoped to teams.
- **Honest about writes.** The server tells the model to confirm the company and payload with you before it creates or updates anything.

## Quick start with Docker

You need [Docker](https://docs.docker.com/get-docker/) and a free [Intuit developer account](https://developer.intuit.com).

**1. Grab the project**

```bash
git clone https://github.com/lnxinc/qb-mcp.git
cd qb-mcp
cp .env.example .env
```

**2. Create an Intuit app** (two minutes, once)

1. Sign in at [developer.intuit.com](https://developer.intuit.com), create an app and give it the **Accounting** scope.
2. On **Keys & credentials**, copy the **Client ID** and **Client Secret**. Development keys talk to sandbox companies; Production keys talk to real ones.
3. Add this redirect URI to the app:

    ```
    http://localhost:8000/settings/quickbooks/callback
    ```

    The client ID and secret identify _your app_, not a company. One app can be authorized against any number of QuickBooks companies.

**3. Fill in `.env`**

```dotenv
QUICKBOOKS_CLIENT_ID=your-client-id
QUICKBOOKS_CLIENT_SECRET=your-client-secret
QUICKBOOKS_ENVIRONMENT=sandbox          # or production

MCP_ACCESS_TOKEN=paste-a-long-random-string-here
```

Make the token with `openssl rand -base64 32`. Everything else in `.env.example` can stay as it is.

**4. Start it**

```bash
docker compose up -d
```

Open <http://localhost:8000> and create your account. The first account can always sign up; after that the server is invitation only, so add colleagues from **Settings → Teams** (or set `APP_OPEN_REGISTRATION=true` to let anyone sign up). Then head to **Settings → QuickBooks → Connect company**. Intuit will ask you to pick a company and approve access. Repeat for each company you want your assistant to reach. Each one shows up with a slug such as `acme-plumbing`.

That's the whole setup. The database, the encryption key and any HTTPS certificates live in the `qb-mcp-data` volume, so `docker compose down` and `up` again keeps all your connections.

> **No clone needed?** A prebuilt image is published to GitHub Container Registry on every release:
>
> ```bash
> docker run -d --name qb-mcp -p 8000:8000 -v qb-mcp-data:/data \
>   -e QUICKBOOKS_CLIENT_ID=... -e QUICKBOOKS_CLIENT_SECRET=... \
>   -e MCP_ACCESS_TOKEN=$(openssl rand -base64 32) \
>   ghcr.io/lnxinc/qb-mcp:latest
> ```

## Connect your AI assistant

The server speaks MCP over two transports:

| Transport           | URL / command                                                                                 | Best for                                                             |
| ------------------- | --------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| **Streamable HTTP** | `http://localhost:8000/mcp/quickbooks` with header `Authorization: Bearer <MCP_ACCESS_TOKEN>` | claude.ai, Cursor, VS Code, Windsurf, Gemini CLI, remote Claude Code |
| **stdio**           | `docker exec -i qb-mcp php artisan mcp:start quickbooks`                                      | Claude Code, Claude Desktop, Codex CLI, anything on the same machine |

Pick your client below. Replace the host with your public URL if you deployed somewhere other than your laptop.

<details>
<summary><strong>Claude Code</strong></summary>

HTTP (works locally and remotely):

```bash
claude mcp add --transport http quickbooks http://localhost:8000/mcp/quickbooks \
  --header "Authorization: Bearer $MCP_ACCESS_TOKEN"
```

stdio through the running container:

```bash
claude mcp add quickbooks -- docker exec -i qb-mcp php artisan mcp:start quickbooks
```

Running from source instead of Docker? The repo ships a `.mcp.json`, so any Claude Code session started inside the folder already has the server.
</details>

<details>
<summary><strong>Claude Desktop</strong></summary>

Open **Settings → Developer → Edit Config** and add:

```json
{
    "mcpServers": {
        "quickbooks": {
            "command": "docker",
            "args": [
                "exec",
                "-i",
                "qb-mcp",
                "php",
                "artisan",
                "mcp:start",
                "quickbooks"
            ]
        }
    }
}
```

</details>

<details>
<summary><strong>claude.ai (web and mobile)</strong></summary>

claude.ai needs a public HTTPS URL. See [Deploying on the internet](#deploying-on-the-internet), then go to **Settings → Connectors → Add custom connector**, paste `https://your-host/mcp/quickbooks` and add the header `Authorization: Bearer <MCP_ACCESS_TOKEN>`.
</details>

<details>
<summary><strong>Cursor</strong></summary>

Add to `~/.cursor/mcp.json` (global) or `.cursor/mcp.json` (per project):

```json
{
    "mcpServers": {
        "quickbooks": {
            "url": "http://localhost:8000/mcp/quickbooks",
            "headers": { "Authorization": "Bearer <MCP_ACCESS_TOKEN>" }
        }
    }
}
```

</details>

<details>
<summary><strong>VS Code (GitHub Copilot)</strong></summary>

Add to `.vscode/mcp.json`:

```json
{
    "servers": {
        "quickbooks": {
            "type": "http",
            "url": "http://localhost:8000/mcp/quickbooks",
            "headers": { "Authorization": "Bearer <MCP_ACCESS_TOKEN>" }
        }
    }
}
```

</details>

<details>
<summary><strong>Windsurf</strong></summary>

Add to `~/.codeium/windsurf/mcp_config.json`:

```json
{
    "mcpServers": {
        "quickbooks": {
            "serverUrl": "http://localhost:8000/mcp/quickbooks",
            "headers": { "Authorization": "Bearer <MCP_ACCESS_TOKEN>" }
        }
    }
}
```

</details>

<details>
<summary><strong>OpenAI Codex CLI</strong></summary>

Add to `~/.codex/config.toml`:

```toml
[mcp_servers.quickbooks]
command = "docker"
args = ["exec", "-i", "qb-mcp", "php", "artisan", "mcp:start", "quickbooks"]
```

</details>

<details>
<summary><strong>Gemini CLI</strong></summary>

Add to `~/.gemini/settings.json`:

```json
{
    "mcpServers": {
        "quickbooks": {
            "httpUrl": "http://localhost:8000/mcp/quickbooks",
            "headers": { "Authorization": "Bearer <MCP_ACCESS_TOKEN>" }
        }
    }
}
```

</details>

<details>
<summary><strong>Any other MCP client</strong></summary>

Use the Streamable HTTP endpoint with a bearer header, or launch the stdio command. Both expose the same tools. The bundled MCP Inspector is handy for checking a client config:

```bash
docker exec -it qb-mcp php artisan mcp:inspector quickbooks
```

</details>

Once connected, start with _"List my QuickBooks companies"_ and go from there.

## What your assistant can do

| Tool             | What it does                                                                                                                         |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `list_companies` | Lists every connected company with its slug, realm ID, team and connection status. Assistants call this first.                       |
| `query`          | Runs a QuickBooks query such as `SELECT * FROM Invoice WHERE Balance > '0' MAXRESULTS 100`.                                          |
| `get_report`     | Runs a financial report: ProfitAndLoss, BalanceSheet, CashFlow, TrialBalance, GeneralLedger, AgedReceivables, AgedPayables and more. |
| `get_entity`     | Fetches one record by entity type and ID, including its `SyncToken`.                                                                 |
| `create_entity`  | Creates a record from a QuickBooks API JSON payload.                                                                                 |
| `update_entity`  | Applies a sparse update. The payload must include `Id` and the current `SyncToken`.                                                  |

Every tool except `list_companies` takes a `company` argument. Dates are `YYYY-MM-DD`; money comes back exactly as QuickBooks reports it, in the company's home currency.

A few things people use it for:

- Month-end close across a book of clients, with the assistant pulling each P&L and flagging unusual swings.
- Chasing receivables: "who owes us more than $5,000 and is past 60 days, across every company?"
- Drafting invoices, bills and journal entries that you review before they are posted.
- Answering ad-hoc questions from partners without opening six QuickBooks tabs.

## Deploying on the internet

To use the server from claude.ai, a phone, or a teammate's machine, it needs a public HTTPS URL. The Docker image serves HTTPS itself with certificates from Let's Encrypt, so no reverse proxy is required.

1. Point a DNS record (say `qb.example.com`) at your server.
2. In `.env`, set:

    ```dotenv
    APP_URL=https://qb.example.com
    SERVER_NAME=qb.example.com
    ```

3. In `compose.yaml`, uncomment the `80`, `443` and `443/udp` port lines.
4. Add `https://qb.example.com/settings/quickbooks/callback` as a redirect URI in your Intuit app. Intuit requires HTTPS for everything except `localhost`.
5. `docker compose up -d`.

Already have a proxy such as nginx, Traefik or Cloudflare Tunnel? Leave `SERVER_NAME=:8000` and forward traffic to port 8000 as you normally would.

### Configuration reference

| Variable                   | Default                 | Notes                                                                                                              |
| -------------------------- | ----------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `QUICKBOOKS_CLIENT_ID`     |                         | From your Intuit app. Required to connect companies.                                                               |
| `QUICKBOOKS_CLIENT_SECRET` |                         | From your Intuit app. Keep it secret.                                                                              |
| `QUICKBOOKS_ENVIRONMENT`   | `sandbox`               | `sandbox` or `production`. Must match the keys you used.                                                           |
| `QUICKBOOKS_MINOR_VERSION` | `75`                    | QuickBooks API minor version.                                                                                      |
| `MCP_ACCESS_TOKEN`         |                         | Bearer token for the HTTP transport. Empty means every HTTP request is rejected.                                   |
| `APP_URL`                  | `http://localhost:8000` | Public URL of the app. Used to build the OAuth redirect.                                                           |
| `APP_KEY`                  | generated               | Encrypts stored tokens. The container generates one and keeps it in `/data/app.key`. Back it up with the database. |
| `SERVER_NAME`              | `:8000`                 | Docker only. A hostname turns on automatic HTTPS.                                                                  |
| `APP_PORT`                 | `8000`                  | Docker only. Host port mapped to the container.                                                                    |
| `DB_CONNECTION`            | `sqlite`                | SQLite is plenty. MySQL, MariaDB and PostgreSQL drivers are included if you prefer them.                           |

## Running from source

Prefer a local toolchain? You need PHP 8.3 or newer, Composer and Node.js 20 or newer.

```bash
git clone https://github.com/lnxinc/qb-mcp.git
cd qb-mcp
composer run setup          # installs dependencies, creates .env, migrates, builds assets
# edit .env with your Intuit keys and MCP_ACCESS_TOKEN
composer run dev            # serves the app on http://localhost:8000
```

Then connect companies at **Settings → QuickBooks** exactly as above. For Claude Code, the included `.mcp.json` registers the stdio server automatically; from any other folder:

```bash
claude mcp add --scope user quickbooks -- php /path/to/qb-mcp/artisan mcp:start quickbooks
```

## How it works

| Piece                                    | Location                                                                                                         |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| MCP server and tools                     | `app/Mcp/Servers/QuickBooksServer.php`, `app/Mcp/Tools/`                                                         |
| Transport registration                   | `routes/ai.php`                                                                                                  |
| Intuit OAuth client                      | `app/Services/QuickBooks/QuickBooksOAuth.php`                                                                    |
| QuickBooks API client with token refresh | `app/Services/QuickBooks/QuickBooksApi.php`                                                                      |
| Company lookup by slug, realm ID or name | `app/Services/QuickBooks/CompanyResolver.php`                                                                    |
| Connection model and migration           | `app/Models/QuickBooksConnection.php`, `database/migrations/`                                                    |
| Settings page and OAuth callback         | `app/Http/Controllers/Settings/QuickBooksConnectionController.php`, `resources/js/pages/settings/quickbooks.tsx` |
| Bearer-token middleware for HTTP         | `app/Http/Middleware/AuthenticateMcpToken.php`                                                                   |
| Container build                          | `Dockerfile`, `compose.yaml`, `docker/`                                                                          |

A tool call resolves the `company` argument to a connection row, obtains a fresh access token for that row, and calls `https://{sandbox-}quickbooks.api.intuit.com/v3/company/{realmId}/...`. Faults from QuickBooks are passed back to the model as tool errors with Intuit's message and error code.

Refresh tokens from Intuit last about 100 days of inactivity. A company whose token has lapsed is flagged **Reconnect required** on the settings page and reported as `reconnect_required` by `list_companies`. One click reconnects it.

Built with [Laravel](https://laravel.com), [Laravel MCP](https://github.com/laravel/mcp), [Inertia](https://inertiajs.com) and React, and served by [FrankenPHP](https://frankenphp.dev) in the container.

## Contributing

Contributions of every size are welcome: bug reports, docs fixes, new tools, client configs for assistants we have not covered yet. Open an issue to talk through larger ideas before you sink time into them.

```bash
php artisan test --compact     # test suite (Pest)
vendor/bin/pint                # code style
vendor/bin/phpstan analyse     # static analysis
npm run check                  # frontend lint and format
npm run types:check            # TypeScript
docker compose build           # make sure the image still builds
```

Tests fake every outbound HTTP call, so you do not need Intuit credentials to run them.

Some things on the wish list, if you are looking for a place to start:

- Per-user access tokens for the HTTP transport (Sanctum or Passport) on shared deployments.
- MCP resources for company metadata and chart of accounts.
- Prompts for common workflows such as month-end review and AR follow-up.
- Prebuilt reports as first-class tools with friendlier parameters.

## Security

- Never commit `.env`. The client secret and `MCP_ACCESS_TOKEN` are secrets.
- `create_entity` and `update_entity` change live accounting data. The server instructs the model to confirm the company and payload with you first; keep that habit.
- The HTTP transport uses a single shared token. If several people need separate access on one deployment, swap `AuthenticateMcpToken` for Sanctum or Passport. The tools already scope results to the authenticated user's teams.
- Found a vulnerability? Please email the maintainers privately instead of opening a public issue.

## License

[MIT](LICENSE). Use it, fork it, ship it.
