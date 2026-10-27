<?php
declare(strict_types=1);

namespace Ciptaone\Services;

use Ciptaone\Core\Database;
use Ciptaone\Core\Security;

/**
 * Service to record and query admin audit logs.
 */
class AuditService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function logAction(
        string $adminUserId,
        string $action,
        string $targetType,
        string $targetId,
        ?array $metadata = null
    ): string {
        $auditId = Security::generateId('aud');
        $nowIso = gmdate('Y-m-d H:i:s');
        $metaJson = json_encode($metadata ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->db->execute(
            'INSERT INTO admin_audit_logs (id, admin_user_id, action, target_type, target_id, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$auditId, $adminUserId, $action, $targetType, $targetId, $metaJson, $nowIso]
        );

        return $auditId;
    }

    public function listAuditLogs(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            'SELECT l.id, l.admin_user_id, u.email_normalized as admin_email,
                    l.action, l.target_type, l.target_id, l.metadata_json, l.created_at
             FROM admin_audit_logs l
             LEFT JOIN users u ON l.admin_user_id = u.id
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        return array_map(function ($r) {
            $meta = null;
            if (!empty($r['metadata_json'])) {
                $meta = json_decode((string)$r['metadata_json'], true);
            }
            return [
                'id' => $r['id'],
                'admin_user_id' => $r['admin_user_id'],
                'admin_email' => $r['admin_email'] ?? $r['admin_user_id'],
                'action' => $r['action'],
                'target_type' => $r['target_type'],
                'target_id' => $r['target_id'],
                'metadata' => $meta,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'])),
            ];
        }, $rows);
    }
}
