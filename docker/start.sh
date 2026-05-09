#!/bin/sh
set -e

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Sales-Spy API — Starting up"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

export PORT="${PORT:-8080}"
echo "→ Using port: $PORT"

# Substitute PORT into nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# ── Step 1: Clear any stale caches from previous deploys ──────────────
echo "→ Clearing stale caches..."
php artisan optimize:clear

# ── Step 2: Run migrations (safe — never drops tables) ────────────────
echo "→ Running database migrations..."
php artisan migrate --force

# ── Step 3: Seed only if SEED_ON_BOOT=true ────────────────────────────
# Set this env var to "true" only on first deploy or staging.
# Leave unset in production to avoid re-seeding on every restart.
if [ "${SEED_ON_BOOT}" = "true" ]; then
    echo "→ Seeding database..."
    php artisan db:seed --force
else
    echo "→ Skipping seed (set SEED_ON_BOOT=true to enable)"
fi

# ── Step 4: Generate Scribe API documentation ─────────────────────────
echo "→ Generating API documentation..."
php artisan scribe:generate --no-extraction 2>/dev/null || \
php artisan scribe:generate || \
echo "⚠ Scribe generation failed — continuing without docs"

# ── Step 5: Cache everything for performance ──────────────────────────
echo "→ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── Step 6: Storage permissions ───────────────────────────────────────
echo "→ Setting storage permissions..."
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# ── Step 7: Start services ────────────────────────────────────────────
echo "→ Starting PHP-FPM..."
php-fpm -D

echo "→ Starting Nginx..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  API is live on port $PORT"
echo "  Docs: /docs"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
nginx -g 'daemon off;'