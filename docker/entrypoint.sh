#!/usr/bin/env bash
# Prepares the container on every start, then hands off to the web server (or
# to whatever command was given, e.g. `php artisan mcp:start quickbooks`).
set -euo pipefail

DATA_DIR="${DATA_DIR:-/data}"
KEY_FILE="$DATA_DIR/app.key"

mkdir -p "$DATA_DIR"

# APP_KEY encrypts the stored QuickBooks tokens, so it must survive restarts.
# Generate it once and keep it on the data volume unless one was supplied.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        php artisan key:generate --show > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
        echo "qb-mcp: generated a new APP_KEY and stored it in $KEY_FILE"
    fi
    export APP_KEY
    APP_KEY="$(cat "$KEY_FILE")"
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ ! -f "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
fi

if [ -z "${QUICKBOOKS_CLIENT_ID:-}" ] || [ -z "${QUICKBOOKS_CLIENT_SECRET:-}" ]; then
    echo "qb-mcp: QUICKBOOKS_CLIENT_ID / QUICKBOOKS_CLIENT_SECRET are not set." >&2
    echo "qb-mcp: the app will start, but you cannot connect a company until they are." >&2
fi

if [ -z "${MCP_ACCESS_TOKEN:-}" ]; then
    echo "qb-mcp: MCP_ACCESS_TOKEN is not set, so the HTTP endpoint /mcp/quickbooks will reject every request." >&2
    echo "qb-mcp: generate one with: openssl rand -base64 32" >&2
fi

# Only the web server process should migrate and warm caches. Side commands
# such as `php artisan mcp:start` run against the same database but skip this.
if [ "${1:-}" = "frankenphp" ]; then
    php artisan migrate --force --no-interaction
    php artisan optimize --no-interaction >/dev/null
fi

exec "$@"
