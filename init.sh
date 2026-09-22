#!/usr/bin/env bash
set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/API/NUTRIX-API"
FRONT_DIR="$ROOT_DIR/Interface Client/NUTRIX-InterfaceClient"

echo "==> Demarrage de la base de donnees (Docker)"
cd "$ROOT_DIR"
docker compose up -d

echo "==> Attente que MySQL soit pret..."
until [ "$(docker inspect --format='{{.State.Health.Status}}' nutrix_mysql 2>/dev/null)" = "healthy" ]; do
    sleep 2
    echo "    ... toujours en attente"
done
echo "==> MySQL est pret"

echo "==> Installation des dependances Symfony"
cd "$API_DIR"
composer install

if [ ! -f .env.local ]; then
    echo "==> Creation de .env.local depuis le gabarit"
    cp .env.local.example .env.local
else
    echo "==> .env.local existe deja, on ne le touche pas"
fi

echo "==> Verification de la connexion a la base"
php bin/console doctrine:query:sql "SELECT 1" > /dev/null

if ls migrations/Version*.php >/dev/null 2>&1; then
    echo "==> Application des migrations"
    php bin/console doctrine:migrations:migrate --no-interaction
else
    echo "==> Aucune migration pour le moment (pas encore d'entite creee)"
fi

echo "==> Installation des dependances du front React"
cd "$FRONT_DIR"
npm install

echo ""
echo "Projet initialise. Lance ./start.sh pour demarrer le serveur."
