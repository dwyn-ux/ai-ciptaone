@echo off
title Decomu AI Router - Deploy Cloudflare D1 & Worker
cd /d "%~dp0worker"

echo ========================================================
echo   DEPLOY CLOUDFLARE D1 & WORKER GATEWAY
echo ========================================================
echo.

echo [*] Langkah 1: Memeriksa instalasi Node.js & Wrangler...
where npx >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Node.js tidak ditemukan di PATH. Silakan instal Node.js terlebih dahulu.
    pause
    exit /b 1
)

echo.
echo [*] Langkah 2: Login ke akun Cloudflare Anda...
call npx wrangler login
if %errorlevel% neq 0 (
    echo [ERROR] Login Cloudflare dibatalkan atau gagal.
    pause
    exit /b 1
)

echo.
echo [*] Langkah 3: Membuat database D1 (nama: decomu-d1)...
call npx wrangler d1 create decomu-d1

echo.
echo ==============================================================================
echo [PENTING] Salin 'database_id' yang muncul di atas, lalu buka file:
echo           worker/wrangler.toml
echo Masukkan database_id tersebut pada baris: database_id = "..."
echo ==============================================================================
echo.
pause

echo.
echo [*] Langkah 4: Menjalankan migrasi SQL ke database D1...
call npx wrangler d1 execute decomu-d1 --file=../migrations/0001_initial_schema.sql
call npx wrangler d1 execute decomu-d1 --file=../migrations/0002_seed_data.sql

echo.
echo [*] Langkah 5: Mengatur secret token (D1_SERVICE_TOKEN)...
echo Masukkan token rahasia (string acak panjang, misal: 32 karakter hex).
echo Token ini yang nantinya juga dimasukkan ke file .env di server Oracle.
call npx wrangler secret put D1_SERVICE_TOKEN

echo.
echo [*] Langkah 6: Mendeploy Worker ke Cloudflare...
call npx wrangler deploy

echo.
echo ==============================================================================
echo [SELESAI] Cloudflare D1 dan Worker Gateway Anda berhasil di-deploy!
echo Catat URL Worker yang muncul di atas (misal: https://decomu-d1-worker.xxx.workers.dev)
echo Masukkan URL tersebut dan D1_SERVICE_TOKEN ke file .env pada server Oracle:
echo   DB_MODE=worker
echo   D1_WORKER_URL=https://decomu-d1-worker.xxx.workers.dev
echo   D1_SERVICE_TOKEN=token_rahasia_anda
echo ==============================================================================
echo.
pause
