@echo off
setlocal enabledelayedexpansion
title AI Ciptaone Router - Test Suite Runner

echo ========================================================
echo   AI Ciptaone Router - TEST RUNNER LOKAL
echo ========================================================
echo.

where php >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo [PERINGATAN] Perintah 'php' tidak ditemukan di PATH sistem Anda.
    echo.
    echo Mencari instalasi PHP di lokasi umum (XAMPP / Laragon)...
    
    set "PHP_CMD="
    if exist "C:\xampp\php\php.exe" set "PHP_CMD=C:\xampp\php\php.exe"
    if exist "C:\laragon\bin\php\php-8.2.0-Win32-vs16-x64\php.exe" set "PHP_CMD=C:\laragon\bin\php\php-8.2.0-Win32-vs16-x64\php.exe"
    if exist "C:\laragon\bin\php\php-8.1.0-Win32-vs16-x64\php.exe" set "PHP_CMD=C:\laragon\bin\php\php-8.1.0-Win32-vs16-x64\php.exe"
    if exist "C:\php\php.exe" set "PHP_CMD=C:\php\php.exe"

    if "!PHP_CMD!"=="" (
        echo [ERROR] PHP tidak ditemukan secara otomatis.
        echo Silakan jalankan perintah ini dengan path PHP Anda, contoh:
        echo   C:\xampp\php\php.exe php-backend\tests\run_tests.php
        echo.
        pause
        exit /b 1
    ) else (
        echo [OK] Menggunakan PHP dari: !PHP_CMD!
        echo.
        "!PHP_CMD!" php-backend\tests\run_tests.php
    )
) else (
    echo [OK] PHP terdeteksi di sistem PATH.
    echo.
    php php-backend\tests\run_tests.php
)

echo.
pause
