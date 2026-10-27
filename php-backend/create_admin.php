<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Admin User Creation & Promotion Tool
 * Usage: php create_admin.php [email] [password]
 * Example: php create_admin.php admin@ciptaone.com AdminPass12345#
 * ========================================================
 */

require_once __DIR__ . '/src/bootstrap.php';

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\Security;

echo "========================================================\n";
echo "👑  AI Ciptaone Router - CREATE / PROMOTE ADMIN USER\n";
echo "========================================================\n\n";

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;

if (!$email) {
    echo "Masukkan email admin: ";
    $email = trim((string)fgets(STDIN));
}

if (!$email) {
    echo "❌ Error: Email tidak boleh kosong.\n";
    exit(1);
}

$normEmail = Security::normalizeEmail($email);
$config = Config::getInstance();
$db = Database::getInstance($config);

// Check if user already exists
$existing = $db->fetchOne('SELECT id, email_normalized, role FROM users WHERE email_normalized = ?', [$normEmail]);

if ($existing) {
    // Promote existing user to admin
    $nowIso = gmdate('Y-m-d H:i:s');
    $db->execute("UPDATE users SET role = 'admin', updated_at = ? WHERE id = ?", [$nowIso, $existing['id']]);
    echo "✅ Berhasil! Pengguna '{$normEmail}' telah dipromosikan menjadi ADMIN.\n";
    echo "   Role saat ini: ADMIN\n";
    echo "   Silakan login di web frontend (http://localhost:5173/login) untuk mengakses menu Admin.\n";
    echo "========================================================\n";
    exit(0);
}

// User does not exist, create new admin
if (!$password) {
    echo "Masukkan password untuk admin baru: ";
    $password = trim((string)fgets(STDIN));
}

if (strlen($password) < 8) {
    echo "❌ Error: Password minimal 8 karakter.\n";
    exit(1);
}

$userId = Security::generateId('usr');
$pwdHash = Security::hashPassword($password);
$nowIso = gmdate('Y-m-d H:i:s');

$db->executeBatch([
    [
        "INSERT INTO users (id, email_normalized, password_hash, email_verified_at, status, role, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', 'admin', ?, ?)",
        [$userId, $normEmail, $pwdHash, $nowIso, $nowIso, $nowIso]
    ],
    [
        'INSERT INTO wallets (user_id, balance_credits, updated_at) VALUES (?, 100000, ?)',
        [$userId, $nowIso]
    ]
]);

echo "🎉 Akun Administrator berhasil dibuat!\n";
echo "   • Email    : {$normEmail}\n";
echo "   • Role     : ADMIN\n";
echo "   • Saldo    : 100.000 Kredit\n";
echo "   • URL Web  : http://localhost:5173/admin\n\n";
echo "Silakan login menggunakan akun ini di browser untuk membuka Admin Control Center.\n";
echo "========================================================\n";
