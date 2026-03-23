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
    supervisor

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

# Install Composer (PHP's package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory inside the container
WORKDIR /var/www/html

# Copy your entire project into the container
COPY . .

# Install PHP packages (production only, no dev tools)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy your nginx config into the right place
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy and make executable your startup script
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# Expose port (Railway will map this automatically)
EXPOSE 10000

# Run the startup script when the container launches
CMD ["/start.sh"]