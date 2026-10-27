@echo off
title Decomu AI Router - Frontend (React + Vite)
cd /d "%~dp0frontend"

echo ========================================================
echo   Starting Decomu AI Router Frontend (Vite)
echo ========================================================
echo.

where npm >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Node.js / npm tidak ditemukan di PATH. Silakan instal Node.js.
    pause
    exit /b 1
)

if not exist node_modules (
    echo [*] Menginstal dependencies frontend (npm install)...
    call npm install
)

echo.
echo [*] Menjalankan server frontend di http://localhost:5173 ...
echo.
call npm run dev
pause
