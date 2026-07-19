#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="${SCRIPT_DIR}/backend"

if [[ ! -d "${BACKEND_DIR}" ]]; then
  echo "Error: backend directory not found at ${BACKEND_DIR}" >&2
  exit 1
fi

cd "${BACKEND_DIR}"

maintenance_enabled=false

cleanup() {
  if [[ "${maintenance_enabled}" == "true" ]]; then
    echo "Restoring application (maintenance mode off)..."
    php artisan up || true
  fi
}

trap cleanup EXIT

echo "==> Enabling maintenance mode..."
php artisan down || true
maintenance_enabled=true

echo "==> Pulling latest main branch..."
git pull origin main

echo "==> Installing production Composer dependencies..."
composer install --no-dev --optimize-autoloader

echo "==> Running database migrations (non-destructive)..."
php artisan migrate --force

echo "==> Clearing old caches..."
php artisan optimize:clear

echo "==> Rebuilding production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Ensuring public storage symlink..."
php artisan storage:link || true

echo "==> Disabling maintenance mode..."
php artisan up
maintenance_enabled=false

echo "Deployment completed successfully."
