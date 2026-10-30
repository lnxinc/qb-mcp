# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Stage 1: build PHP dependencies and the frontend bundle.
#
# The Vite build needs PHP on the PATH (Wayfinder generates TypeScript route
# helpers from the Laravel app), so a single builder image carries both.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.5-bookworm AS builder

RUN install-php-extensions bcmath intl pcntl pdo_sqlite pdo_mysql pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY package.json package-lock.json .npmrc ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund

COPY . .

RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi

RUN APP_ENV=production VITE_APP_NAME="QuickBooks MCP" npm run build

# ---------------------------------------------------------------------------
# Stage 2: the runtime image.
#
# FrankenPHP serves the Laravel app directly, with no separate web server to
# configure. Its bundled Caddyfile serves /app/public on SERVER_NAME, so ":8000"
# gives plain HTTP and a hostname gives automatic HTTPS. The /data volume holds
# the SQLite database, the generated APP_KEY, and any TLS certificates.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.5-bookworm

LABEL org.opencontainers.image.title="QuickBooks MCP" \
      org.opencontainers.image.description="One MCP server for every QuickBooks Online company you manage." \
      org.opencontainers.image.source="https://github.com/lnxinc/qb-mcp" \
      org.opencontainers.image.licenses="MIT"

RUN install-php-extensions bcmath intl opcache pcntl pdo_sqlite pdo_mysql pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-qb-mcp.ini"
COPY docker/entrypoint.sh /usr/local/bin/qb-mcp-entrypoint
RUN chmod +x /usr/local/bin/qb-mcp-entrypoint

WORKDIR /app

COPY --from=builder /app /app

RUN rm -rf node_modules tests .github .env \
    && mkdir -p /data storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache /data

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8000 \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    SERVER_NAME=:8000 \
    QUICKBOOKS_ENVIRONMENT=sandbox \
    QUICKBOOKS_MINOR_VERSION=75

VOLUME ["/data"]
EXPOSE 8000 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost:8000/up || curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["qb-mcp-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
