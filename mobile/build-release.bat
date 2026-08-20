@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem Always run from the Flutter mobile folder, even if launched elsewhere.
cd /d "%~dp0."

echo.
echo --------------------------------------------
echo  ParamGold release APK
echo --------------------------------------------
echo.

set "PUBSPEC=%~dp0pubspec.yaml"
set "APK_SRC=build\app\outputs\flutter-apk\app-release.apk"
set "APK_DEST=release\paramgold-latest.apk"
set "VERFILE=%TEMP%\paramgold-release-version.txt"

if not exist "%PUBSPEC%" (
    echo PARAMGOLD RELEASE BUILD FAILED
    echo Could not find pubspec.yaml
    exit /b 1
)

rem Read version: x.y.z+n from pubspec.yaml, increment patch and build,
rem then write the new version back. Example: 1.0.6+7 -> 1.0.7+8
del "%VERFILE%" >nul 2>nul

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $p='%~dp0pubspec.yaml'; $raw=[System.IO.File]::ReadAllText($p); $m=[regex]::Match($raw,'(?m)^version:\s*(\d+)\.(\d+)\.(\d+)\+(\d+)\s*$'); if(-not $m.Success){ Write-Error 'Could not parse version: line in pubspec.yaml'; exit 1 }; $name=('{0}.{1}.{2}' -f [int]$m.Groups[1].Value,[int]$m.Groups[2].Value,([int]$m.Groups[3].Value+1)); $build=[string]([int]$m.Groups[4].Value+1); $newRaw=$raw.Remove($m.Index,$m.Length).Insert($m.Index,('version: '+$name+'+'+$build)); $utf8=New-Object System.Text.UTF8Encoding $false; [System.IO.File]::WriteAllText($p,$newRaw,$utf8); Write-Output ($name+'|'+$build)" > "%VERFILE%"
if errorlevel 1 (
    echo PARAMGOLD RELEASE BUILD FAILED
    echo Could not read or update version in pubspec.yaml
    exit /b 1
)

set "NEW_VERSION="
set "NEW_BUILD="
for /f "usebackq tokens=1,2 delims=|" %%A in ("%VERFILE%") do (
    set "NEW_VERSION=%%A"
    set "NEW_BUILD=%%B"
)
del "%VERFILE%" >nul 2>nul

if not defined NEW_VERSION (
    echo PARAMGOLD RELEASE BUILD FAILED
    echo Could not read or update version in pubspec.yaml
    exit /b 1
)
if not defined NEW_BUILD (
    echo PARAMGOLD RELEASE BUILD FAILED
    echo Could not read or update version in pubspec.yaml
    exit /b 1
)

echo Updated pubspec.yaml to !NEW_VERSION!+!NEW_BUILD!
echo.
echo Building release APK...
echo.

call flutter build apk --release
if errorlevel 1 (
    echo.
    echo PARAMGOLD RELEASE BUILD FAILED
    exit /b 1
)

if not exist "%APK_SRC%" (
    echo.
    echo PARAMGOLD RELEASE BUILD FAILED
    echo APK not found: %APK_SRC%
    exit /b 1
)

if not exist "release" mkdir "release"
copy /Y /B "%APK_SRC%" "%APK_DEST%" >nul
if errorlevel 1 (
    echo.
    echo PARAMGOLD RELEASE BUILD FAILED
    echo Could not copy APK to %APK_DEST%
    exit /b 1
)

if not exist "%APK_DEST%" (
    echo.
    echo PARAMGOLD RELEASE BUILD FAILED
    echo Could not copy APK to %APK_DEST%
    exit /b 1
)

echo.
echo PARAMGOLD RELEASE BUILD SUCCESSFUL
echo.
echo Version: !NEW_VERSION!
echo Build: !NEW_BUILD!
echo.
echo APK:
echo %APK_DEST%
echo.

endlocal
exit /b 0
