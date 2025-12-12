# Use PHP 8.4 CLI
FROM php:8.4-cli

# Working directory
WORKDIR /app

# Install system packages & PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev \
    && docker-php-ext-install pcntl zip sockets

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    && mv composer.phar /usr/local/bin/composer

# Configure PHP memory limit for worker (512MB)
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Copy application files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Default command for the worker
CMD ["php", "artisan", "worker:product-sync"]

