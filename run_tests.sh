#!/usr/bin/env bash
# Decomu AI Router - Test Suite Runner for Bash / Linux / Mac / Git Bash

echo "========================================================"
echo "  DECOMU AI ROUTER - TEST RUNNER LOKAL"
echo "========================================================"

if ! command -v php &> /dev/null; then
    echo "[ERROR] 'php' command not found. Please install PHP 8.1+."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "$SCRIPT_DIR/php-backend/tests/run_tests.php"
