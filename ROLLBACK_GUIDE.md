# Rollback Guide — ParamGold ERP

Use this guide when a deployment causes errors and you need to return to a known-good state **without losing user data**.

---

## Before every deployment

1. **Back up the database** (mandatory):

   - Hostinger hPanel → **phpMyAdmin** → Export → Quick export → SQL
   - Or use Hostinger automatic backups if enabled
   - Store the `.sql` file with date/time in the filename

2. **Note the current Git commit**:

```bash
git rev-parse HEAD
git log -1 --oneline
```

3. **Note the current mobile APK version** (if releasing mobile at the same time).

---

## Identify the previous good commit

List recent commits:

```bash
git log --oneline -10
```

Find the last commit that was working in production, for example:

```text
e44576b Add soft delete support and enrich dealer management
```

---

## Roll back application code (safe)

On the server:

```bash
cd ~/paramgold-erp
git fetch origin
git checkout <previous-commit-hash>
cd backend
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm ci
npm run build
php artisan up
```

Or use `deploy.sh` after checking out the previous commit.

On your local machine (to keep GitHub in sync after verification):

```bash
git revert <bad-commit-hash>   # preferred: preserves history
# OR
git reset --hard <previous-commit-hash>   # only if not yet shared / team agrees
git push origin main
```

**Prefer `git revert` on shared branches** instead of force-push.

---

## Database rollback — handle carefully

Code rollback and database rollback are **separate**.

### Why database rollback is risky

- Users may have created orders, attendance, collections, and uploads **after** the bad deploy.
- Restoring an old `.sql` backup **will delete new production data**.
- Migrations may have added columns/tables that older code expects.

### Safe approach

1. **Fix forward** when possible (deploy a patch commit instead of rolling back DB).
2. If a migration caused data issues:
   - Restore DB backup **only** if no important new data exists after deployment.
   - Consult the specific migration before reversing anything manually.
3. **Never run** in production:
   - `migrate:fresh`
   - `migrate:reset`
   - `db:wipe`
   - `DELETE` / `TRUNCATE` without a verified backup

### Restore database from backup (last resort)

1. Put app in maintenance mode:

```bash
cd backend
php artisan down
```

2. phpMyAdmin → select database → **Import** → choose pre-deployment `.sql` backup.
3. Deploy the matching application code commit from the same point in time.
4. Run:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

5. Verify admin and mobile functionality before opening to users.

---

## Roll back mobile app only

Android APK rollback:

1. Rebuild from the previous Git tag/commit:

```bash
cd mobile
git checkout <previous-commit-hash>
flutter clean
flutter pub get
flutter build apk --release
```

2. Distribute `mobile/build/app/outputs/flutter-apk/app-release.apk` to users.

Release APK always targets `https://erp.paramgold.in/api` unless overridden with `--dart-define`.

---

## Uploaded files rollback

User uploads live in `backend/storage/app/public/`.

- Code rollback does **not** remove uploaded files.
- Database restore **may** reference file paths that no longer match if files were added after backup.
- Before DB restore, back up `storage/app/public/` separately:

```bash
tar -czf storage-public-backup-$(date +%F).tar.gz storage/app/public
```

---

## Post-rollback verification

- [ ] Admin panel login
- [ ] Mobile login
- [ ] Recent records still visible (if DB was not restored)
- [ ] Image URLs load correctly
- [ ] No errors in `storage/logs/laravel.log`
- [ ] Document what failed and which commit was restored

---

## Emergency contacts checklist

Keep handy:

- Hostinger hPanel login
- GitHub repository URL
- Database name / user (not password in shared docs)
- Last known good commit hash
- Location of latest DB backup file
