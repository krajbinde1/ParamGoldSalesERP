# ParamGold ERP — Deployment Checklist

Use this checklist before and after every production release.

## 1. Source control

- [ ] Run `git status` and confirm no secrets are staged (`.env`, keys, keystores).
- [ ] Confirm `backend/.env` is **not** tracked by Git.
- [ ] Confirm `mobile/android/local.properties` is **not** tracked by Git.
- [ ] Commit all intended deployment changes locally.
- [ ] Create a **private** GitHub repository.
- [ ] Push to GitHub:

```bash
git remote add origin <repository-url>
git branch -M main
git push -u origin main
```

## 2. Backend (Laravel) on Hostinger

- [ ] Upload/clone project to the server (recommended layout in `HOSTINGER_DEPLOYMENT.md`).
- [ ] Create production `.env` from `backend/.env.example` (never commit real values).
- [ ] Set at minimum:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL=https://erp.paramgold.in`
  - `APP_KEY=` (generate with `php artisan key:generate`)
  - MySQL credentials
- [ ] Create empty MySQL database in Hostinger hPanel.
- [ ] Import existing data **or** run migrations (never use `migrate:fresh`, `migrate:reset`, or `db:wipe`).
- [ ] Install dependencies:

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

- [ ] Build frontend assets (Filament/Breeze/Vite):

```bash
npm ci
npm run build
```

- [ ] Link public storage for uploaded files:

```bash
php artisan storage:link
```

- [ ] Set permissions on `storage/` and `bootstrap/cache/` (see `HOSTINGER_DEPLOYMENT.md`).
- [ ] Run safe cache commands:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
```

## 3. Domain and SSL

- [ ] Point `erp.paramgold.in` to the Hostinger hosting account.
- [ ] Enable SSL (Let's Encrypt) for `erp.paramgold.in`.
- [ ] Verify `https://erp.paramgold.in/up` returns healthy status.
- [ ] Verify admin panel loads at `https://erp.paramgold.in/admin` (Filament path if customized).
- [ ] Verify API health/login endpoint: `https://erp.paramgold.in/api/login` (POST).

## 4. File uploads and storage

- [ ] Confirm `public/storage` symlink exists.
- [ ] Test uploaded file URLs for:
  - Employee profile photos
  - Attendance punch photos
  - Dealer visit photos
  - Field activity photos
  - Collection photos
  - TA/DA bill attachments
- [ ] Confirm URLs resolve under `https://erp.paramgold.in/storage/...`

## 5. Flutter mobile app

- [ ] Confirm release builds use `https://erp.paramgold.in/api` (see `mobile/lib/core/api/api_config.dart`).
- [ ] Run locally:

```bash
cd mobile
flutter clean
flutter pub get
flutter analyze
flutter build apk --release
```

- [ ] Expected APK path:

```
mobile/build/app/outputs/flutter-apk/app-release.apk
```

- [ ] Install APK on a physical device and test:
  - Login
  - Attendance punch in/out
  - Dealers
  - Dealer visits
  - Field activities
  - Orders
  - Collections
  - TA/DA claims
  - Route tracking
  - Image uploads

## 6. Post-deployment verification

- [ ] Admin login works.
- [ ] Mobile login works with production credentials.
- [ ] No debug stack traces shown to end users.
- [ ] Laravel logs write to `backend/storage/logs/laravel.log`.
- [ ] Database backup taken before go-live (see `ROLLBACK_GUIDE.md`).

## 7. Rollback readiness

- [ ] Note the deployed Git commit hash.
- [ ] Keep a database backup from before deployment.
- [ ] Read `ROLLBACK_GUIDE.md` before making emergency changes.

---

**Never run destructive database commands in production.**
