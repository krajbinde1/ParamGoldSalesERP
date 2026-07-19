#!/usr/bin/env bash
set -euo pipefail

BACKEND="/home/u630302848/paramgold-erp/backend"
WEB_ROOT="/home/u630302848/domains/paramgold.in/public_html/erp"
DEPLOY_FILES="${BACKEND}/deploy/hostinger/public_erp"

echo "==> 1. SSH identity"
pwd
whoami
hostname

echo "==> 2. Laravel version and about"
cd "${BACKEND}"
php artisan --version
php artisan about

echo "==> 3. Admin / Filament routes"
php artisan route:list | grep -Ei "admin|filament|login" || true

echo "==> 4. Filament panel path (from provider)"
grep -n "->path(" "${BACKEND}/app/Providers/Filament/AdminPanelProvider.php" || true

echo "==> 5. Check .htaccess files"
ls -la "${BACKEND}/public/.htaccess"
ls -la "${WEB_ROOT}/.htaccess" 2>/dev/null || echo "MISSING: ${WEB_ROOT}/.htaccess"

echo "==> 6. Install web-root .htaccess and index.php"
cp "${DEPLOY_FILES}/.htaccess" "${WEB_ROOT}/.htaccess"
cp "${DEPLOY_FILES}/index.php" "${WEB_ROOT}/index.php"
chmod 644 "${WEB_ROOT}/.htaccess" "${WEB_ROOT}/index.php"

echo "==> 7. Clear and rebuild safe caches"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> 8. HTTP checks"
curl -sI "https://erp.paramgold.in" | head -5
curl -sI "https://erp.paramgold.in/admin" | head -10
curl -sI "https://erp.paramgold.in/admin/login" | head -10

echo "==> 9. Recent Laravel logs"
tail -n 150 "${BACKEND}/storage/logs/laravel.log" 2>/dev/null || echo "No laravel.log yet"

echo "==> Done. Open https://erp.paramgold.in/admin/login"
