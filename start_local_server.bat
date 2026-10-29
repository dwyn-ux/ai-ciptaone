@echo off
setlocal enabledelayedexpansion
title AI Ciptaone Router - Local PHP Server

echo ========================================================
echo   AI Ciptaone Router - SERVER LOKAL PHP
echo ========================================================
echo.

where php >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_CMD=C:\xampp\php\php.exe"
    ) else if exist "C:\php\php.exe" (
        set "PHP_CMD=C:\php\php.exe"
    ) else (
        echo [ERROR] PHP tidak ditemukan di PATH.
        echo Harap instal PHP 8.1+ atau jalankan via XAMPP.
        pause
        exit /b 1
    )
) else (
    set "PHP_CMD=php"
)

echo [INFO] Menjalankan PHP Development Server pada http://127.0.0.1:8000 ...
echo.
echo Endpoint yang siap diakses:
echo   - Dokumentasi API: http://127.0.0.1:8000/docs
echo   - OpenAPI Spec   : http://127.0.0.1:8000/openapi.json
echo   - Web Admin UI   : http://127.0.0.1:8000/admin
echo   - Web Test Suite : http://127.0.0.1:8000/test_runner.php
echo   - Health Check   : http://127.0.0.1:8000/health
echo   - Models API     : http://127.0.0.1:8000/v1/models
echo.
echo Tekan Ctrl+C untuk menghentikan server.
echo ========================================================
echo.

cd /d "%~dp0php-backend"
!PHP_CMD! -S 127.0.0.1:8000 -t public public/index.php

pause
