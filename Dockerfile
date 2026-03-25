# Use PHP 8.3 with FPM on Alpine Linux
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

# Install PHP extensions that Sales-Spy needs
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# Install build dependencies required for PECL (Redis)
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    linux-headers

# Install the Redis PHP extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Remove build dependencies to keep image small
RUN apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project
COPY . .

# Install PHP packages
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf.template

# Copy and make executable startup script
COPY docker/start.sh /start.sh
RUN sed -i 's/\r//' /start.sh
RUN chmod +x /start.sh

EXPOSE 10000

CMD ["/start.sh"]