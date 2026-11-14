#!/usr/bin/env bash
set -e

cd /var/www/html

# Assurer les permissions d'écriture sur les volumes montés
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache || true

# Génération automatique de APP_KEY uniquement si absente dans l'environnement
if [ -z "${APP_KEY:-}" ] && [ -f .env ] && ! grep -qE '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

# Attente active de la disponibilité de PostgreSQL
if [ "${DB_CONNECTION:-pgsql}" = "pgsql" ]; then
    DB_HOST_VAL="${DB_HOST:-postgres}"
    DB_PORT_VAL="${DB_PORT:-5432}"
    DB_USER_VAL="${DB_USERNAME:-scholarship_user}"
    DB_NAME_VAL="${DB_DATABASE:-hackathon_db}"

    echo "Attente de PostgreSQL sur ${DB_HOST_VAL}:${DB_PORT_VAL}..."
    until pg_isready -h "${DB_HOST_VAL}" -p "${DB_PORT_VAL}" -U "${DB_USER_VAL}" -d "${DB_NAME_VAL}" >/dev/null 2>&1; do
        sleep 2
    done
    echo "PostgreSQL est prêt."
fi

# Exécution sécurisée et non destructive des migrations en attente (opt-in par variable)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "Application des migrations Laravel non destructives..."
    php artisan migrate --force --no-interaction
fi

# Seed idempotent optionnel (désactivé par défaut afin de préserver tout état existant)
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    echo "Exécution des seeders idempotents..."
    php artisan db:seed --force --no-interaction
fi

# Nettoyage des caches de config/route pour prendre en compte les variables d'environnement Docker
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

exec "$@"
