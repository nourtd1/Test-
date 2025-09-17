# Production Dockerfile (php-fpm)
FROM php:8.2-fpm-alpine AS base

RUN docker-php-ext-install opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json /var/www/html/
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress || true

COPY . /var/www/html

# Configure PHP
RUN { \
  echo "opcache.enable=1"; \
  echo "opcache.enable_cli=0"; \
  echo "opcache.validate_timestamps=0"; \
  echo "opcache.jit_buffer_size=64M"; \
  echo "opcache.memory_consumption=128"; \
} > /usr/local/etc/php/conf.d/opcache.ini

CMD ["php-fpm"]

