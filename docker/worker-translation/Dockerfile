# Use PHP 8.4 CLI to match your dependencies
FROM php:8.4-cli

# Set working directory
WORKDIR /app

# Install system dependencies & required PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev \
    && docker-php-ext-install pcntl zip sockets

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    && mv composer.phar /usr/local/bin/composer

# Copy project into container
COPY . .

# Install PHP dependencies (ignore platform requirements to avoid missing extensions)
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# Default command: run translation worker
CMD ["php", "artisan", "worker:translation"]
