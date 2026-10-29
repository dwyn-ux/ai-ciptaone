@echo off
title AI Ciptaone Router - Launcher
cd /d "%~dp0"

echo ========================================================
echo   Menjalankan AI Ciptaone Router (Backend + Frontend)
echo ========================================================
echo.
echo [*] Membuka jendela Backend PHP di port 8000...
start "AI Ciptaone Backend (PHP)" cmd /k "start_local_server.bat"

timeout /t 3 /nobreak >nul

echo [*] Membuka jendela Frontend di port 5173...
start "AI Ciptaone Frontend (Vite)" cmd /k "run_frontend.bat"

echo.
echo ========================================================
echo [OK] Backend dan Frontend berjalan di jendela terpisah!
echo.
echo  • Web Playground & Dashboard : http://localhost:5173
echo  • Backend API & Health Check : http://127.0.0.1:8000
echo  • Dokumentasi Interaktif     : http://127.0.0.1:8000/docs
echo ========================================================
echo.
pause
