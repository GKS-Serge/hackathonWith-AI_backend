# ==============================================================================
# SCHOLARSHIP / ACADÉMIE - Dockerfile Backend (Laravel 12 + PHP 8.3-FPM)
# Conçu pour un environnement de développement sécurisé et reproductible.
# ==============================================================================

FROM php:8.3-fpm-alpine

# Arguments de build
ARG WWWUSER=1000
ARG WWWGROUP=1000

# 1. Installation des dépendances système requises pour Laravel et PostgreSQL
RUN apk update && apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    git \
    shadow \
    bash \
    icu-dev \
    oniguruma-dev

# 2. Installation et compilation des extensions PHP officielles
RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    bcmath \
    zip \
    intl \
    opcache

# 3. Installation de Composer (version officielle la plus récente)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4. Configuration des utilisateurs pour aligner les permissions hôte/conteneur
RUN usermod -u ${WWWUSER} www-data && groupmod -g ${WWWGROUP} www-data

# 5. Répertoire de travail de l'application
WORKDIR /var/www/html

# 6. Copie de la configuration PHP personnalisée
COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# 7. Copie du script d'entrée (sécurisation des permissions et démarrage propre)
COPY ./docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 8. Exposition du port PHP-FPM
EXPOSE 9000

# 9. Utilisateur d'exécution par défaut
USER www-data

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
