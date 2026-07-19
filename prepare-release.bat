@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
cd /d "%ROOT%"

echo ========================================
echo ParamGold ERP - Release Preparation
echo ========================================
echo.

echo [1/5] Checking Git status...
git status --short
if errorlevel 1 (
  echo ERROR: Git is not available or this folder is not a Git repository.
  exit /b 1
)
echo.

echo [2/5] Running backend production checks...
cd /d "%ROOT%backend"
if not exist artisan (
  echo ERROR: backend\artisan not found.
  exit /b 1
)

php artisan about >nul 2>&1
if errorlevel 1 (
  echo ERROR: PHP/Laravel backend checks failed.
  exit /b 1
)

composer validate --no-check-publish --quiet
if errorlevel 1 (
  echo ERROR: composer.json validation failed.
  exit /b 1
)

php artisan route:cache >nul
if errorlevel 1 (
  echo ERROR: route:cache failed. Fix route issues before release.
  exit /b 1
)
php artisan route:clear >nul

php artisan config:cache >nul
if errorlevel 1 (
  echo ERROR: config:cache failed.
  exit /b 1
)
php artisan config:clear >nul

echo Backend checks passed.
echo.

echo [3/5] Running Flutter clean and pub get...
cd /d "%ROOT%mobile"
call flutter clean
if errorlevel 1 exit /b 1
call flutter pub get
if errorlevel 1 exit /b 1
echo.

echo [4/5] Running Flutter analyze...
call flutter analyze
if errorlevel 1 (
  echo ERROR: Flutter analyze reported issues. Fix blockers before building release APK.
  exit /b 1
)
echo.

echo [5/5] Building Flutter release APK...
call flutter build apk --release
if errorlevel 1 (
  echo ERROR: Release APK build failed.
  exit /b 1
)

echo.
echo ========================================
echo Release preparation completed.
echo APK output:
echo   %ROOT%mobile\build\app\outputs\flutter-apk\app-release.apk
echo ========================================
echo.
echo This script does NOT push to GitHub or deploy to Hostinger.
exit /b 0
