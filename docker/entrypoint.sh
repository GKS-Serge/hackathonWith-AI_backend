#!/bin/sh
set -e

# ==============================================================================
# SCHOLARSHIP / ACADÉMIE - Docker Entrypoint (Laravel 12 + PHP 8.3-FPM)
# Exécuté à l'initialisation du conteneur backend
# ==============================================================================

# 1. Vérification et création des répertoires vitaux de stockage si absents
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# 2. Sécurisation des permissions d'écriture pour l'utilisateur d'exécution www-data
# Dossiers en 775 et fichiers en 664 (séparation stricte sans bit d'exécution sur les fichiers plats)
find /var/www/html/storage /var/www/html/bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
find /var/www/html/storage /var/www/html/bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true

# 3. Installation conditionnelle des dépendances Composer :
# Exécuté UNIQUEMENT si le volume vendor est vide (absence de vendor/autoload.php).
# Ne se relance JAMAIS aux démarrages ultérieurs.
if [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "[INFO] vendor/autoload.php absent. Premier démarrage détecté : exécution de composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress
    echo "[INFO] Dépendances Composer installées avec succès dans le volume persistant."
fi

# 4. Attente active de la disponibilité réseau de PostgreSQL avant de lancer PHP-FPM
if [ -n "$DB_HOST" ]; then
    echo "[INFO] Attente de la disponibilité de la base de données ($DB_HOST:${DB_PORT:-5432})..."
    until nc -z -w3 "$DB_HOST" "${DB_PORT:-5432}" >/dev/null 2>&1; do
        sleep 1
    done
    echo "[INFO] PostgreSQL est prêt et joignable sur le réseau privé Docker !"
fi

# 5. Lancement de la commande principale (php-fpm par défaut)
exec "$@"
