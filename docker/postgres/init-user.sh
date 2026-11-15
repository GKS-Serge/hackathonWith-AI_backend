#!/bin/sh
set -e

# ==============================================================================
# SCHOLARSHIP / ACADÉMIE - Initialisation Sécurisée PostgreSQL
# Exécuté uniquement à la création d'un cluster PostgreSQL vierge
# Reçoit dynamiquement APP_DB_USER, APP_DB_PASSWORD et APP_DB_NAME depuis l'environnement
# ==============================================================================

# 1. Validation stricte de la présence des variables requises
if [ -z "$APP_DB_USER" ] || [ -z "$APP_DB_PASSWORD" ] || [ -z "$APP_DB_NAME" ]; then
    echo "[ERREUR] Variables APP_DB_USER, APP_DB_PASSWORD ou APP_DB_NAME manquantes dans l'environnement PostgreSQL."
    exit 1
fi

echo "[INFO] Initialisation du rôle applicatif non-superutilisateur et de la base dédiée..."

# 2. Utilisation sécurisée de \getenv pour lire le mot de passe directement depuis l'environnement :
# - Aucun mot de passe n'est passé en argument de ligne de commande (invisible dans 'ps' / 'top')
# - \getenv app_pass APP_DB_PASSWORD charge la variable shell dans la variable psql :app_pass
# - format('%I', :'app_user') échappe de façon stricte l'identifiant SQL du rôle
# - format('%L', :'app_pass') échappe de façon stricte le mot de passe littéral chaîne
# - \gexec exécute la requête DDL dynamiquement générée

psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$POSTGRES_DB" \
     -v app_user="$APP_DB_USER" \
     -v app_db="$APP_DB_NAME" <<-'EOSQL'

    -- Lecture sécurisée du mot de passe depuis l'environnement système du conteneur
    \getenv app_pass APP_DB_PASSWORD

    -- 2.a Création du rôle applicatif s'il n'existe pas déjà (rôle standard non-superutilisateur, sans droits d'admin)
    SELECT format('CREATE ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT', :'app_user', :'app_pass')
    WHERE NOT EXISTS (
        SELECT FROM pg_catalog.pg_roles WHERE rolname = :'app_user'
    )\gexec

    -- 2.b Si le rôle préexiste, mise à jour du mot de passe ET réapplication stricte de toutes les restrictions de privilèges
    SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT', :'app_user', :'app_pass')
    WHERE EXISTS (
        SELECT FROM pg_catalog.pg_roles WHERE rolname = :'app_user'
    )\gexec

    -- 2.c Création de la base de données applicative avec app_user comme propriétaire
    SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_user')
    WHERE NOT EXISTS (
        SELECT FROM pg_catalog.pg_database WHERE datname = :'app_db'
    )\gexec

    -- 2.d Attribution explicite des privilèges sur la base de données
    SELECT format('GRANT ALL PRIVILEGES ON DATABASE %I TO %I', :'app_db', :'app_user')\gexec
    SELECT format('ALTER DATABASE %I OWNER TO %I', :'app_db', :'app_user')\gexec

EOSQL

# 3. Connexion directe à la base applicative créée pour configurer la propriété et les droits sur le schéma public
psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$APP_DB_NAME" \
     -v app_user="$APP_DB_USER" <<-'EOSQL'

    -- 3.a Attribution de la propriété et des droits sur le schéma public à l'utilisateur applicatif
    -- Permet à Laravel d'exécuter les migrations (créer/modifier tables, index, séquences)
    -- et garantit que pg_restore avec --no-owner --no-privileges attribue tout à app_user sans conflit
    SELECT format('ALTER SCHEMA public OWNER TO %I', :'app_user')\gexec
    SELECT format('GRANT ALL ON SCHEMA public TO %I', :'app_user')\gexec

EOSQL

echo "[INFO] Initialisation PostgreSQL achevée avec succès !"
