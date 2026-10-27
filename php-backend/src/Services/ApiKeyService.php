<?php
declare(strict_types=1);

namespace Decomu\Services;

use Decomu\Core\AppError;
use Decomu\Core\Database;
use Decomu\Core\ErrorCode;
use Decomu\Core\Security;

/**
 * Manages API keys lifecycle: creation, hash storage, listing, revocation, and validation.
 */
class ApiKeyService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function createApiKey(string $userId, string $name): array
    {
        $cleanName = trim($name);
        if ($cleanName === '' || strlen($cleanName) > 64) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Nama API key wajib diisi (maksimal 64 karakter).',
                422
            );
        }

        $keyId = Security::generateId('key');
        $keyData = Security::generateApiKey();
        $rawKey = $keyData['raw_key'];
        $keyPrefix = $keyData['key_prefix'];
        $keyHash = $keyData['key_hash'];
        $nowIso = gmdate('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO api_keys (id, user_id, name, key_prefix, key_hash, last_used_at, revoked_at, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)',
            [$keyId, $userId, $cleanName, $keyPrefix, $keyHash, $nowIso]
        );

        return [
            'id' => $keyId,
            'name' => $cleanName,
            'key_prefix' => $keyPrefix,
            'last_used_at' => null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($nowIso)),
            'key' => $rawKey, // Revealed ONCE only upon creation
        ];
    }

    public function listUserApiKeys(string $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, key_prefix, last_used_at, created_at
             FROM api_keys
             WHERE user_id = ? AND revoked_at IS NULL
             ORDER BY created_at DESC',
            [$userId]
        );

        return array_map(function ($r) {
            return [
                'id' => $r['id'],
                'name' => $r['name'],
                'key_prefix' => $r['key_prefix'],
                'last_used_at' => $r['last_used_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($r['last_used_at'])) : null,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'])),
            ];
        }, $rows);
    }

    public function revokeApiKey(string $userId, string $keyId): bool
    {
        $nowIso = gmdate('Y-m-d H:i:s');
        $rowcount = $this->db->execute(
            'UPDATE api_keys SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$nowIso, $keyId, $userId]
        );

        if ($rowcount === 0) {
            throw new AppError(
                ErrorCode::NOT_FOUND,
                'API key tidak ditemukan atau sudah dicabut.',
                404
            );
        }

        return true;
    }

    /**
     * Validates incoming raw API key string.
     * Returns: [$userId, $apiKeyId]
     */
    public function validateApiKey(string $rawKey): array
    {
        $trimmed = trim($rawKey);
        if ($trimmed === '' || !str_starts_with($trimmed, 'sk-live-')) {
            throw new AppError(
                ErrorCode::UNAUTHORIZED,
                'API key tidak valid atau format salah.',
                401
            );
        }

        $kHash = Security::hashToken($trimmed);
        $nowIso = gmdate('Y-m-d H:i:s');

        $row = $this->db->fetchOne(
            'SELECT k.id, k.user_id, k.key_hash, u.status as user_status
             FROM api_keys k
             JOIN users u ON k.user_id = u.id
             WHERE k.key_hash = ? AND k.revoked_at IS NULL',
            [$kHash]
        );

        if ($row === null) {
            throw new AppError(
                ErrorCode::UNAUTHORIZED,
                'API key tidak valid atau telah dicabut.',
                401
            );
        }

        if ($row['user_status'] !== 'active') {
            throw new AppError(
                ErrorCode::FORBIDDEN,
                'Akun pemilik API key tidak aktif.',
                403
            );
        }

        // Constant-time hash check
        if (!Security::secureCompare((string)$row['key_hash'], $kHash)) {
            throw new AppError(
                ErrorCode::UNAUTHORIZED,
                'Verifikasi API key gagal.',
                401
            );
        }

        // Update last_used_at
        $this->db->execute(
            'UPDATE api_keys SET last_used_at = ? WHERE id = ?',
            [$nowIso, $row['id']]
        );

        return [$row['user_id'], $row['id']];
    }
}
