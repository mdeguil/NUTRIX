#!/usr/bin/env bash
set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/API/NUTRIX-API"
FRONT_DIR="$ROOT_DIR/Interface Client/NUTRIX-InterfaceClient"

echo "==> Demarrage du serveur Symfony (arriere-plan)"
cd "$API_DIR"
symfony server:start -d --no-tls

cleanup() {
    echo ""
    echo "==> Arret du serveur Symfony"
    cd "$API_DIR"
    symfony server:stop
}
trap cleanup EXIT

echo "==> Demarrage du front React (Ctrl+C pour arreter)"
cd "$FRONT_DIR"
npm run dev
