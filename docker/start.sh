#!/bin/sh
set -e

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Sales-Spy API — Starting up"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Use Railway's PORT variable, default to 10000 if not set
export PORT="${PORT:-10000}"
echo "→ Using port: $PORT"

# Replace ${PORT} in the nginx template with the actual port value
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Run database migrations
echo "→ Running database migrations..."
php artisan migrate:fresh --force
php artisan db:seed --force



# Cache config and routes for performance
echo "→ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set correct permissions on storage
echo "→ Setting storage permissions..."
chmod -R 775 /var/www/html/storage
chmod -R 775 /var/www/html/bootstrap/cache

# Start PHP-FPM in the background
echo "→ Starting PHP-FPM..."
php-fpm -D

# Start Nginx in the foreground
echo "→ Starting Nginx..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  API is live on port $PORT"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
nginx -g 'daemon off;'