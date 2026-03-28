FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libzip-dev \
    postgresql-dev \
    oniguruma-dev \
    supervisor \
    gettext

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    linux-headers \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy nginx config template
COPY docker/nginx.conf /etc/nginx/nginx.conf.template

# Write start.sh directly inside the container
# This completely avoids CRLF line ending issues from Windows/Mac
RUN printf '#!/bin/sh\n\
    \n\
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"\n\
    echo "  Sales-Spy API — Starting up"\n\
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"\n\
    \n\
    export PORT="${PORT:-8080}"\n\
    echo "→ Using port: $PORT"\n\
    \n\
    envsubst '"'"'${PORT}'"'"' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf\n\
    \n\
    php artisan optimize:clear\n\
    \n\
    echo "→ Running database migrations..."\n\
    php artisan migrate --force\n\
    \n\
    echo "→ Seeding roles..."\n\
    php artisan db:seed --force\n\
    \n\
    echo "→ Caching configuration..."\n\
    php artisan config:cache\n\
    php artisan route:cache\n\
    php artisan view:cache\n\
    \n\
    echo "→ Setting storage permissions..."\n\
    chmod -R 775 /var/www/html/storage\n\
    chmod -R 775 /var/www/html/bootstrap/cache\n\
    \n\
    echo "→ Starting PHP-FPM..."\n\
    php-fpm -D\n\
    \n\
    echo "→ Starting Nginx..."\n\
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"\n\
    echo "  API is live on port $PORT"\n\
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"\n\
    nginx -g '"'"'daemon off;'"'"'\n\
    ' > /start.sh && chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]