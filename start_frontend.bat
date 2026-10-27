@echo off
title AI Ciptaone Router - Frontend Development Server
echo ========================================================
echo   AI Ciptaone Router - FRONTEND REACT / VITE
echo ========================================================
echo.

where npm >nul 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] 'npm' tidak ditemukan di PATH.
    echo Harap instal Node.js terlebih dahulu dari https://nodejs.org
    pause
    exit /b 1
)

echo [INFO] Menjalankan Vite dev server pada http://localhost:5173 ...
echo Frontend akan otomatis mem-proxy request API ke backend PHP (port 8000).
echo.
echo Tekan Ctrl+C untuk menghentikan frontend.
echo ========================================================
echo.

cd /d "%~dp0frontend"
npm run dev

pause
