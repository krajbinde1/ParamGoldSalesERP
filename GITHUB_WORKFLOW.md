# GitHub Workflow — ParamGold ERP

Repository layout:

```text
ParamGoldSalesERP/
├── backend/     # Laravel API + Filament admin
├── mobile/      # Flutter Android app
├── deploy.sh    # Server deployment script
└── prepare-release.bat
```

---

## Prerequisites

- Git installed locally
- Private GitHub repository created manually
- **Do not commit secrets:** `backend/.env`, keystores, API keys, signing keys

---

## Initial push to GitHub

After creating a private repository on GitHub:

```bash
cd D:\Projects\ParamGoldSalesERP
git add .
git commit -m "Initial ParamGold ERP deployment"
git branch -M main
git remote add origin <repository-url>
git push -u origin main
```

Replace `<repository-url>` with your private repo URL, for example:

```text
https://github.com/your-org/paramgold-sales-erp.git
```

---

## Future local updates

```bash
git add .
git commit -m "Describe changes"
git push origin main
```

Use clear commit messages describing what changed (bug fix, deployment config, mobile release prep, etc.).

---

## Server updates (Hostinger)

SSH into the server, then:

```bash
cd ~/paramgold-erp
./deploy.sh
```

Or manually:

```bash
cd ~/paramgold-erp/backend
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

If frontend assets changed:

```bash
npm ci
npm run build
```

---

## Branch strategy

- `main` — production-ready code deployed to Hostinger
- Create feature branches for larger changes, merge via pull request when ready

---

## Secrets policy

| File | Commit? |
|------|---------|
| `backend/.env.example` | Yes (placeholders only) |
| `backend/.env` | **Never** |
| `mobile/android/local.properties` | **Never** |
| `*.jks`, `*.keystore`, `key.properties` | **Never** |
| `google-services.json` (if added later) | **Never** |

If a secret was accidentally committed, rotate the credential immediately and remove it from Git history.

---

## Release workflow (recommended)

1. Develop and test locally.
2. Run `prepare-release.bat` on Windows (backend checks + Flutter analyze + release APK).
3. Commit and push to `main`.
4. Deploy on Hostinger with `deploy.sh`.
5. Follow `DEPLOYMENT_CHECKLIST.md` for verification.

---

## GitHub CLI

GitHub CLI (`gh`) is **not required**. If not installed, create the repository manually in the GitHub web UI.

Do **not** push until you explicitly confirm the repository URL and credentials.
