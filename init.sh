#!/usr/bin/env bash
set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/API/NUTRIX-API"
FRONT_DIR="$ROOT_DIR/Interface Client/NUTRIX-InterfaceClient"

echo "==> Installation des dependances Symfony"
cd "$API_DIR"
composer install

if [ ! -f .env.local ]; then
    echo "==> Creation de .env.local depuis le gabarit (+ secrets locaux generes)"
    cp .env.local.example .env.local
    JWT_PASSPHRASE_VALUE="$(php -r 'echo bin2hex(random_bytes(16));')"
    { echo "APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));')"; echo "JWT_PASSPHRASE=$JWT_PASSPHRASE_VALUE"; } >> .env.local
    echo "JWT_PASSPHRASE=$JWT_PASSPHRASE_VALUE" > .env.test.local
    echo ""
    echo "    !!! Renseigne le DATABASE_URL (base hebergee) dans API/NUTRIX-API/.env.local avant de continuer !!!"
    echo ""
else
    echo "==> .env.local existe deja, on ne le touche pas"
fi

if [ ! -f .env.test.local ]; then
    echo "==> Creation de .env.test.local (JWT_PASSPHRASE synchronisee avec .env.local)"
    grep "^JWT_PASSPHRASE=" .env.local > .env.test.local
fi

echo "==> Generation des cles JWT (si absentes)"
php bin/console lexik:jwt:generate-keypair --skip-if-exists

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
echo "(la base est partagee via l'hebergeur : les fixtures ne sont PAS rechargees automatiquement,"
echo " car 'doctrine:fixtures:load' vide la base avant de la repeupler. Ne le lance qu'en connaissance de cause.)"
