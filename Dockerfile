# Use PHP 8.3 with FPM on Alpine Linux
# Alpine is a minimal Linux - makes the image small and fast to deploy
FROM php:8.3-fpm-alpine

# Install system dependencies
# These are Linux packages needed to compile PHP extensions
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

# Install the Redis PHP extension
# This lets PHP talk to Redis directly (faster than predis)
RUN pecl install redis \
    && docker-php-ext-enable redis

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

# Tell Docker this container listens on port 10000
EXPOSE 10000

# Run the startup script when the container launches
CMD ["/start.sh"]
