@echo off
title Decomu AI Router - Launcher
cd /d "%~dp0"

echo ========================================================
echo   Menjalankan Decomu AI Router (Backend + Frontend)
echo ========================================================
echo.
echo [*] Membuka jendela Backend di port 8000...
start "Decomu Backend (FastAPI)" cmd /k "run_backend.bat"

timeout /t 3 /nobreak >nul

echo [*] Membuka jendela Frontend di port 5173...
start "Decomu Frontend (Vite)" cmd /k "run_frontend.bat"

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
