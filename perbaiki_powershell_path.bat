@echo off
setlocal enabledelayedexpansion
title Perbaikan PATH PowerShell Windows

echo ========================================================
echo   ALAT PERBAIKAN PATH POWERSHELL WINDOWS
echo ========================================================
echo.

set "PS_PATH=C:\Windows\System32\WindowsPowerShell\v1.0"
set "PS_EXE=%PS_PATH%\powershell.exe"

if not exist "%PS_EXE%" (
    echo [ERROR] powershell.exe tidak ditemukan di:
    echo   %PS_EXE%
    echo.
    echo Silakan periksa instalasi Windows Anda.
    pause
    exit /b 1
)

echo [OK] File fisik PowerShell ditemukan di:
echo   %PS_EXE%
echo.

echo [1/3] Menjalankan uji coba eksekusi langsung...
"%PS_EXE%" -NoProfile -Command "Write-Host '[OK] PowerShell dapat dijalankan langsung!' -ForegroundColor Green"
echo.

echo [2/3] Memeriksa dan memperbarui PATH di Registry User...
for /f "tokens=2*" %%a in ('C:\Windows\System32\reg.exe query "HKCU\Environment" /v Path 2^>nul') do set "USER_PATH=%%b"

echo Path User saat ini:
echo !USER_PATH!
echo.

echo !USER_PATH! | findstr /I /C:"%PS_PATH%" >nul
if %ERRORLEVEL% EQU 0 (
    echo [INFO] PowerShell sudah terdaftar di Path User.
) else (
    echo [INFO] Menambahkan PowerShell ke Path User...
    if "!USER_PATH!"=="" (
        set "NEW_PATH=%PS_PATH%;C:\Windows\System32"
    ) else (
        set "NEW_PATH=!USER_PATH!;%PS_PATH%;C:\Windows\System32"
    )
    C:\Windows\System32\reg.exe add "HKCU\Environment" /v Path /t REG_EXPAND_SZ /d "!NEW_PATH!" /f >nul
    if %ERRORLEVEL% EQU 0 (
        echo [OK] Berhasil menambahkan ke Registry HKCU\Environment\Path!
    ) else (
        echo [GAGAL] Tidak dapat menulis ke Registry.
    )
)
echo.

echo [3/3] Memperbarui PATH untuk sesi saat ini...
set "PATH=%PATH%;%PS_PATH%;C:\Windows\System32"

echo ========================================================
echo [SELESAI] PERBAIKAN TELAH DITERAPKAN!
echo ========================================================
echo.
echo PENTING:
echo Agar IDE Antigravity / AI Agent dapat mendeteksi perubahan ini:
echo 1. Tutup IDE Antigravity.
echo 2. Buka kembali IDE Antigravity.
echo.
echo Setelah dibuka kembali, AI Agent akan dapat menjalankan
echo perintah terminal powershell secara langsung!
echo ========================================================
echo.
pause
