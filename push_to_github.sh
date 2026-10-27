#!/usr/bin/env bash
# Push Decomu AI Router to GitHub

echo "========================================================"
echo "  PUSH CODEBASE KE GITHUB: https://github.com/dwyn-ux/ai-ciptaone.git"
echo "========================================================"

if ! command -v git &> /dev/null; then
    echo "[ERROR] Git command not found. Please install Git."
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -d ".git" ]; then
    git init
    git branch -M main
fi

git remote remove origin 2>/dev/null || true
git remote add origin https://github.com/dwyn-ux/ai-ciptaone.git

git add .
git commit -m "feat: complete migration AI Router to PHP 8.x + MySQL for cPanel shared hosting"
git push -u origin main
