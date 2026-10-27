<?php
declare(strict_types=1);

namespace Ciptaone\Services;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Security;

/**
 * Authentication and session service.
 */
class AuthService
{
    private Database $db;
    private Config $config;

    public function __construct(?Database $db = null, ?Config $config = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->config = $config ?? Config::getInstance();
    }

    public function register(string $email, string $password): array
    {
        $normEmail = Security::normalizeEmail($email);
        if (!filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Format email tidak valid.',
                422
            );
        }

        if (strlen($password) < 8 || strlen($password) > 128) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Kata sandi harus memiliki panjang antara 8 hingga 128 karakter.',
                422
            );
        }

        // Check if email already registered (do not leak to prevent user enumeration per PRD)
        $existing = $this->db->fetchOne(
            'SELECT id FROM users WHERE email_normalized = ?',
            [$normEmail]
        );
        if ($existing !== null) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Pendaftaran tidak dapat diproses. Pastikan format email benar atau gunakan akun yang ada.',
                400
            );
        }

        $userId = Security::generateId('usr');
        $pwdHash = Security::hashPassword($password);
        $nowIso = gmdate('Y-m-d H:i:s');

        // Create user and initial wallet atomically
        $this->db->executeBatch([
            [
                "INSERT INTO users (id, email_normalized, password_hash, email_verified_at, status, role, created_at, updated_at) VALUES (?, ?, ?, NULL, 'active', 'user', ?, ?)",
                [$userId, $normEmail, $pwdHash, $nowIso, $nowIso]
            ],
            [
                'INSERT INTO wallets (user_id, balance_credits, updated_at) VALUES (?, 0, ?)',
                [$userId, $nowIso]
            ]
        ]);

        return [
            'id' => $userId,
            'email' => $normEmail,
            'email_verified' => false,
            'status' => 'active',
            'role' => 'user',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($nowIso)),
        ];
    }

    public function login(string $email, string $password): array
    {
        $normEmail = Security::normalizeEmail($email);
        $userRow = $this->db->fetchOne(
            'SELECT id, email_normalized, password_hash, email_verified_at, status, role, created_at FROM users WHERE email_normalized = ?',
            [$normEmail]
        );

        if ($userRow === null || !Security::verifyPassword($password, (string)$userRow['password_hash'])) {
            throw new AppError(
                ErrorCode::UNAUTHORIZED,
                'Email atau kata sandi tidak valid.',
                401
            );
        }

        if ($userRow['status'] !== 'active') {
            throw new AppError(
                ErrorCode::FORBIDDEN,
                'Akun Anda sedang dinonaktifkan atau disuspend.',
                403
            );
        }

        $userId = $userRow['id'];
        $tokenData = Security::generateSessionToken();
        $rawToken = $tokenData['raw_token'];
        $tokenHash = $tokenData['token_hash'];

        $now = time();
        $lifetime = (int)$this->config->get('SESSION_LIFETIME_SECONDS', 604800);
        $nowIso = gmdate('Y-m-d H:i:s', $now);
        $expiresIso = gmdate('Y-m-d H:i:s', $now + $lifetime);
        $sessionId = Security::generateId('ses');

        $this->db->execute(
            'INSERT INTO sessions (id, user_id, token_hash, expires_at, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$sessionId, $userId, $tokenHash, $expiresIso, $nowIso, $nowIso]
        );

        $user = [
            'id' => $userId,
            'email' => $userRow['email_normalized'],
            'email_verified' => !empty($userRow['email_verified_at']),
            'status' => $userRow['status'],
            'role' => $userRow['role'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($userRow['created_at'])),
        ];

        return [$user, $rawToken];
    }

    public function getUserFromSession(string $rawToken): ?array
    {
        if (trim($rawToken) === '') {
            return null;
        }

        $tHash = Security::hashToken($rawToken);
        $nowIso = gmdate('Y-m-d H:i:s');

        $row = $this->db->fetchOne(
            "SELECT u.id, u.email_normalized, u.email_verified_at, u.status, u.role, u.created_at, s.id as session_id
             FROM sessions s
             JOIN users u ON s.user_id = u.id
             WHERE s.token_hash = ? AND s.expires_at > ? AND u.status = 'active'",
            [$tHash, $nowIso]
        );

        if ($row === null) {
            return null;
        }

        // Update last seen
        $this->db->execute(
            'UPDATE sessions SET last_seen_at = ? WHERE id = ?',
            [$nowIso, $row['session_id']]
        );

        return [
            'id' => $row['id'],
            'email' => $row['email_normalized'],
            'email_verified' => !empty($row['email_verified_at']),
            'status' => $row['status'],
            'role' => $row['role'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'])),
        ];
    }

    public function getUserById(string $userId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT id, email_normalized, email_verified_at, status, role, created_at
             FROM users
             WHERE id = ? AND status = 'active'",
            [$userId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => $row['id'],
            'email' => $row['email_normalized'],
            'email_verified' => !empty($row['email_verified_at']),
            'status' => $row['status'],
            'role' => $row['role'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'])),
        ];
    }

    public function logout(string $rawToken): void
    {
        if (trim($rawToken) === '') {
            return;
        }
        $tHash = Security::hashToken($rawToken);
        $this->db->execute('DELETE FROM sessions WHERE token_hash = ?', [$tHash]);
    }
}
