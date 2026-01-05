# Use PHP 8.4 CLI to match your dependencies
FROM php:8.4-cli

# Set working directory
WORKDIR /app

# Install system dependencies & required PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpq-dev \
    && docker-php-ext-install pcntl zip sockets pdo_pgsql pgsql

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    && mv composer.phar /usr/local/bin/composer

# Configure PHP memory limit for worker (256MB should be sufficient for translation workers)
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Copy project into container
COPY . .

# Install PHP dependencies (ignore platform requirements to avoid missing extensions)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Default command: run translation worker
CMD ["php", "artisan", "worker:translation"]
