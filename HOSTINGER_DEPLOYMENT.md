# Hostinger Deployment Guide — ParamGold ERP

This guide deploys the Laravel backend and Filament admin panel to Hostinger for:

**Production URL:** `https://erp.paramgold.in`

---

## Recommended folder structure

Hostinger shared hosting typically uses a web root such as `public_html/`.

Recommended layout:

```text
/home/<user>/
├── paramgold-erp/              # Git clone (not directly web-accessible)
│   ├── backend/                # Laravel application root
│   │   ├── app/
│   │   ├── bootstrap/
│   │   ├── config/
│   │   ├── public/             # Document root target
│   │   ├── routes/
│   │   ├── storage/
│   │   └── vendor/
│   ├── mobile/                 # Flutter source (build APK locally, not on server)
│   └── deploy.sh
└── domains/
    └── erp.paramgold.in/
        └── public_html/  ->  ../../paramgold-erp/backend/public
```

If symlinks are not allowed, copy or point the subdomain document root directly to `backend/public` in hPanel.

---

## Subdomain setup

1. Open **hPanel → Domains → Subdomains**.
2. Create subdomain: `erp`
3. Domain result: `erp.paramgold.in`
4. Set document root to Laravel `public` folder (`backend/public`).

---

## Clone from GitHub

```bash
cd ~
git clone <repository-url> paramgold-erp
cd paramgold-erp/backend
```

---

## PHP and Composer requirements

- PHP **8.2+**
- Extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`
- Composer 2.x

Install dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

---

## Production `.env`

Copy the example file:

```bash
cp .env.example .env
```

Edit `.env` with production values (never commit this file):

```dotenv
APP_NAME="ParamGold ERP"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.paramgold.in
APP_KEY=

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<your_database>
DB_USERNAME=<your_user>
DB_PASSWORD=<your_password>

FILESYSTEM_DISK=public
SESSION_DOMAIN=erp.paramgold.in
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=erp.paramgold.in
LOG_LEVEL=error
```

Generate application key:

```bash
php artisan key:generate
```

---

## MySQL database setup

1. hPanel → **Databases → MySQL Databases**
2. Create database, user, and password.
3. Assign user to database with all privileges.
4. Use credentials in `.env`.

### Option A — Fresh database (migrations only)

```bash
php artisan migrate --force
```

### Option B — Import existing data

1. Export local database from phpMyAdmin (`.sql` file).
2. hPanel → **phpMyAdmin** → select database → **Import**.
3. Import the `.sql` file.
4. Run pending migrations only:

```bash
php artisan migrate --force
```

**Never run:** `migrate:fresh`, `migrate:reset`, `db:wipe`.

---

## Frontend assets (Vite)

The admin panel and Breeze views require compiled assets:

```bash
npm ci
npm run build
```

This creates `public/build/`. Without this step, CSS/JS may be missing in production.

---

## Storage link (required for uploads)

Uploaded files (photos, attachments) are stored on the `public` disk and served via `/storage`.

```bash
php artisan storage:link
```

Verify:

```bash
ls -la public/storage
```

Should point to `storage/app/public`.

---

## File permissions

```bash
chmod -R ug+rwx storage bootstrap/cache
```

On some Hostinger plans:

```bash
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

---

## Cache and optimization commands

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## SSL

1. hPanel → **Security → SSL**
2. Enable free SSL for `erp.paramgold.in`
3. Force HTTPS in hPanel if available
4. Confirm `APP_URL` uses `https://`

---

## Automated deployment

From the project root on the server:

```bash
chmod +x deploy.sh
./deploy.sh
```

This script:

- Enables maintenance mode
- Pulls `main`
- Runs `composer install --no-dev`
- Runs `migrate --force`
- Rebuilds caches
- Runs `storage:link`
- Disables maintenance mode

It **never** wipes the database.

---

## Common Hostinger issues and fixes

| Issue | Likely cause | Fix |
|------|--------------|-----|
| 500 error after deploy | Missing `APP_KEY`, bad permissions | Run `php artisan key:generate`, fix `storage/` permissions |
| Admin panel unstyled | Assets not built | Run `npm ci && npm run build` |
| Uploaded images 404 | Missing storage link | Run `php artisan storage:link` |
| API login works, images fail | Wrong `APP_URL` | Set `APP_URL=https://erp.paramgold.in` and rebuild config cache |
| `route:cache` fails | Closure routes (rare) | Run `php artisan route:clear`, fix routes, retry |
| Database connection error | Wrong credentials/host | Verify hPanel DB name/user; use `127.0.0.1` as Hostinger DB host |
| Permission denied on logs | `storage/logs` not writable | Fix permissions on `storage/` |
| White screen, no details | `APP_DEBUG=false` (expected) | Check `storage/logs/laravel.log` |

---

## Production verification

- [ ] `https://erp.paramgold.in/up` — health check
- [ ] Admin panel login
- [ ] Mobile API login (`POST /api/login`)
- [ ] Upload and view a test image via `/storage/...`
- [ ] Queue/cron: currently `QUEUE_CONNECTION=sync` (no worker required)

---

## Mobile app note

Build the Android APK on your development machine, not on Hostinger:

```bash
cd mobile
flutter build apk --release
```

Release APK uses `https://erp.paramgold.in/api` automatically.
