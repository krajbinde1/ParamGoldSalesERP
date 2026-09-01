@echo off
setlocal
cd /d "%~dp0"

where python >nul 2>nul
if errorlevel 1 (
    echo Python is not installed or not on PATH.
    echo Install Python 3.10+ from https://www.python.org/downloads/ and tick "Add python.exe to PATH".
    pause
    exit /b 1
)

if not exist ".env" (
    echo Missing .env
    echo Copy .env.example to .env and set ERP_BASE_URL and ERP_CONNECTOR_TOKEN.
    pause
    exit /b 1
)

echo Installing connector packages...
python -m pip install -r requirements.txt
if errorlevel 1 (
    echo pip install failed.
    pause
    exit /b 1
)

echo.
echo Starting ParamGold Tally connector. Keep Tally Prime open. Press Ctrl+C to stop.
echo.
python connector.py
echo.
pause
