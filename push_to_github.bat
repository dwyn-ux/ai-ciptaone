@echo off
setlocal
title Push Decomu AI Router to GitHub

echo ========================================================
echo   PUSH CODEBASE KE GITHUB: https://github.com/dwyn-ux/ai-ciptaone.git
echo ========================================================
echo.

where git >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Git tidak ditemukan di PATH sistem Anda.
    echo Pastikan Git for Windows sudah terinstal dari https://git-scm.com
    pause
    exit /b 1
)

cd /d "%~dp0"

echo [1/5] Memeriksa repositori Git lokal...
if not exist ".git" (
    echo Menginisialisasi repositori Git baru...
    git init
    git branch -M main
)

echo [2/5] Mengatur remote origin ke: https://github.com/dwyn-ux/ai-ciptaone.git
git remote remove origin >nul 2>nul
git remote add origin https://github.com/dwyn-ux/ai-ciptaone.git

echo [3/5] Menambahkan perubahan file sesuai .gitignore (rahasia aman)...
git add .

echo [4/5] Membuat commit migrasi...
git commit -m "feat: complete migration AI Router to PHP 8.x + MySQL for cPanel shared hosting"

echo [5/5] Melakukan push ke GitHub (main)...
git push -u origin main

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================================
    echo [OK] BERHASIL! Kode berhasil di-push ke GitHub:
    echo      https://github.com/dwyn-ux/ai-ciptaone
    echo ========================================================
) else (
    echo.
    echo [INFO] Jika repositori GitHub sudah memiliki file (seperti README / license),
    echo silakan lakukan sync dengan perintah:
    echo   git pull origin main --rebase
    echo   git push -u origin main
)

echo.
pause
