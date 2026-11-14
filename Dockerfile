# ==============================================================================
# Dockerfile - Backend SCHOLARSHIP / ACADÉMIE (Laravel 12 + PHP 8.3 FPM + Nginx)
# ==============================================================================
FROM php:8.3-fpm-alpine

LABEL maintainer="SCHOLARSHIP Backend Team"
LABEL description="API REST Laravel 12 + PostgreSQL + Google Gemini AI"

# Dépendances système, Nginx, Supervisor et bibliothèques de compilation PHP/PostgreSQL
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    zip \
    icu-dev \
    libpq-dev \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    nginx \
    supervisor \
    postgresql-client \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        zip \
        bcmath \
        opcache \
        xml \
        pcntl \
    && rm -rf /var/cache/apk/*

# Installation officielle de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configuration PHP & OPcache adaptée à Laravel
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-laravel.ini

# Configuration Nginx & Supervisor
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# Copie préalable des manifests Composer pour optimiser le cache Docker
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copie du code source Laravel (le .env est exclu via .dockerignore)
COPY . .

# Finalisation de l'autoloader optimisé Composer
RUN composer dump-autoload --optimize --no-dev

# Création des répertoires d'exécution & permissions Laravel
RUN mkdir -p \
        storage/app/private/referentiels \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /run/nginx \
        /var/log/supervisor \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Script d'entrée sécurisé (attente PostgreSQL, migrations non destructives, permissions)
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
