@echo off
title Decomu AI Router - Backend (FastAPI)
cd /d "%~dp0backend"

echo ========================================================
echo   Starting Decomu AI Router Backend (FastAPI)
echo ========================================================
echo.

where python >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Python tidak ditemukan di PATH. Silakan instal Python 3.11 atau 3.12.
    pause
    exit /b 1
)

echo [*] Memeriksa dan menginstal dependencies Python...
pip install -r requirements.txt
if %errorlevel% neq 0 (
    echo [ERROR] Gagal menginstal dependensi Python.
    pause
    exit /b 1
)

echo.
echo [*] Menjalankan server FastAPI di http://127.0.0.1:8000 ...
echo [*] Swagger UI: http://127.0.0.1:8000/docs
echo [*] Health Check: http://127.0.0.1:8000/health/live
echo.
uvicorn app.main:app --host 127.0.0.1 --port 8000 --reload
pause
