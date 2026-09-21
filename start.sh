#!/usr/bin/env bash
set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$ROOT_DIR/API/NUTRIX-API"

echo "==> Demarrage de la base de donnees (Docker)"
cd "$ROOT_DIR"
docker compose up -d

echo "==> Demarrage du serveur Symfony (Ctrl+C pour arreter)"
cd "$API_DIR"
symfony server:start --no-tls
