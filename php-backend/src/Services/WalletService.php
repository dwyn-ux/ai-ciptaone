<?php
declare(strict_types=1);

namespace Decomu\Services;

use Decomu\Core\AppError;
use Decomu\Core\Database;
use Decomu\Core\ErrorCode;
use Decomu\Core\Security;

/**
 * Handles wallet balances, atomic reservations, settlements, topup crediting,
 * and immutable double-entry style audit ledger.
 */
class WalletService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getWallet(string $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT balance_credits, updated_at FROM wallets WHERE user_id = ?',
            [$userId]
        );

        if ($row === null) {
            $nowIso = gmdate('Y-m-d H:i:s');
            // Auto provision wallet if missing
            try {
                $this->db->execute(
                    'INSERT INTO wallets (user_id, balance_credits, updated_at) VALUES (?, 0, ?)',
                    [$userId, $nowIso]
                );
            } catch (\Throwable) {
            }
            return [
                'balance_credits' => 0,
                'currency' => 'IDR',
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($nowIso)),
            ];
        }

        return [
            'balance_credits' => (int)$row['balance_credits'],
            'currency' => 'IDR',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['updated_at'])),
        ];
    }

    /**
     * Atomically reserves credits for an AI completion request before upstream call.
     * Uses strict conditional UPDATE to guarantee balance_credits >= max_credits.
     */
    public function reserveCredits(string $userId, string $requestId, int $maxCredits): string
    {
        if ($maxCredits <= 0) {
            return $requestId;
        }

        $reservationId = Security::generateId('res');
        $now = time();
        $nowIso = gmdate('Y-m-d H:i:s', $now);
        $expiresIso = gmdate('Y-m-d H:i:s', $now + 900); // 15 minutes expiry

        return $this->db->transaction(function (Database $db) use ($userId, $requestId, $reservationId, $maxCredits, $nowIso, $expiresIso) {
            // Conditional atomic deduction
            $affected = $db->execute(
                'UPDATE wallets
                 SET balance_credits = balance_credits - ?, updated_at = ?
                 WHERE user_id = ? AND balance_credits >= ?',
                [$maxCredits, $nowIso, $userId, $maxCredits]
            );

            if ($affected === 0) {
                $wallet = $this->getWallet($userId);
                throw new AppError(
                    ErrorCode::INSUFFICIENT_CREDITS,
                    "Saldo kredit tidak mencukupi (Butuh {$maxCredits} kredit, saldo saat ini {$wallet['balance_credits']} kredit). Silakan lakukan top-up.",
                    402,
                    null,
                    [
                        'required_credits' => $maxCredits,
                        'current_balance' => $wallet['balance_credits'],
                    ]
                );
            }

            $db->execute(
                "INSERT INTO credit_reservations (id, user_id, request_id, reserved_credits, settled_credits, status, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, 'reserved', ?, ?, ?)",
                [$reservationId, $userId, $requestId, $maxCredits, $expiresIso, $nowIso, $nowIso]
            );

            return $reservationId;
        });
    }

    /**
     * Settles a reserved request:
     * 1. Refunds unused delta (reserved - actual) back to wallet.
     * 2. Marks reservation as 'settled'.
     * 3. Writes an atomic ledger entry for the net debited credits.
     */
    public function settleCredits(
        string $userId,
        string $requestId,
        int $actualBilledCredits,
        ?array $metadata = null
    ): int {
        $reservation = $this->db->fetchOne(
            'SELECT id, reserved_credits, status FROM credit_reservations WHERE request_id = ? AND user_id = ?',
            [$requestId, $userId]
        );

        if ($reservation === null) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Reservasi kredit tidak ditemukan.',
                404
            );
        }

        if ($reservation['status'] === 'settled') {
            // Already settled (idempotency check)
            return $actualBilledCredits;
        }

        $reservedCredits = (int)$reservation['reserved_credits'];
        $finalBilled = min($actualBilledCredits, $reservedCredits);
        $refundAmount = max(0, $reservedCredits - $finalBilled);

        $nowIso = gmdate('Y-m-d H:i:s');
        $ledgerId = Security::generateId('led');
        $idempotencyKey = "settle_{$requestId}";
        $metaJson = json_encode($metadata ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->db->transaction(function (Database $db) use (
            $userId, $requestId, $refundAmount, $finalBilled, $ledgerId, $idempotencyKey, $metaJson, $nowIso
        ) {
            // 1. Refund unused portion
            if ($refundAmount > 0) {
                $db->execute(
                    'UPDATE wallets SET balance_credits = balance_credits + ?, updated_at = ? WHERE user_id = ?',
                    [$refundAmount, $nowIso, $userId]
                );
            }

            // 2. Mark reservation settled
            $db->execute(
                "UPDATE credit_reservations SET settled_credits = ?, status = 'settled', updated_at = ? WHERE request_id = ?",
                [$finalBilled, $nowIso, $requestId]
            );

            // 3. Compute current balance for ledger snapshot
            $walletRow = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$userId]);
            $balanceAfter = $walletRow !== null ? (int)$walletRow['balance_credits'] : 0;

            // 4. Record ledger entry
            $db->execute(
                "INSERT INTO credit_ledger (id, user_id, event_type, amount_credits, balance_after, reference_type, reference_id, idempotency_key, metadata_json, created_at)
                 VALUES (?, ?, 'chat_usage', ?, ?, 'request', ?, ?, ?, ?)",
                [$ledgerId, $userId, -$finalBilled, $balanceAfter, $requestId, $idempotencyKey, $metaJson, $nowIso]
            );

            return $finalBilled;
        });
    }

    /**
     * Releases entire reservation back to user wallet if upstream request failed.
     */
    public function releaseReservation(string $userId, string $requestId): void
    {
        $reservation = $this->db->fetchOne(
            'SELECT id, reserved_credits, status FROM credit_reservations WHERE request_id = ? AND user_id = ?',
            [$requestId, $userId]
        );

        if ($reservation === null || $reservation['status'] !== 'reserved') {
            return;
        }

        $reservedCredits = (int)$reservation['reserved_credits'];
        $nowIso = gmdate('Y-m-d H:i:s');

        $this->db->executeBatch([
            [
                'UPDATE wallets SET balance_credits = balance_credits + ?, updated_at = ? WHERE user_id = ?',
                [$reservedCredits, $nowIso, $userId]
            ],
            [
                "UPDATE credit_reservations SET status = 'released', updated_at = ? WHERE request_id = ?",
                [$nowIso, $requestId]
            ]
        ]);
    }

    /**
     * Credits user wallet when topup is approved. Protected by unique idempotency key.
     */
    public function creditWalletForTopup(
        string $userId,
        string $orderId,
        int $creditsToGrant,
        ?array $metadata = null
    ): int {
        $idempotencyKey = "topup_{$orderId}";

        // Idempotency check: verify if already credited
        $existing = $this->db->fetchOne(
            'SELECT id FROM credit_ledger WHERE idempotency_key = ?',
            [$idempotencyKey]
        );
        if ($existing !== null) {
            $w = $this->getWallet($userId);
            return $w['balance_credits'];
        }

        $nowIso = gmdate('Y-m-d H:i:s');
        $ledgerId = Security::generateId('led');
        $metaJson = json_encode($metadata ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->db->transaction(function (Database $db) use (
            $userId, $orderId, $creditsToGrant, $idempotencyKey, $metaJson, $nowIso, $ledgerId
        ) {
            $db->execute(
                'UPDATE wallets SET balance_credits = balance_credits + ?, updated_at = ? WHERE user_id = ?',
                [$creditsToGrant, $nowIso, $userId]
            );

            $walletRow = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$userId]);
            $balanceAfter = $walletRow !== null ? (int)$walletRow['balance_credits'] : $creditsToGrant;

            $db->execute(
                "INSERT INTO credit_ledger (id, user_id, event_type, amount_credits, balance_after, reference_type, reference_id, idempotency_key, metadata_json, created_at)
                 VALUES (?, ?, 'topup', ?, ?, 'topup', ?, ?, ?, ?)",
                [$ledgerId, $userId, $creditsToGrant, $balanceAfter, $orderId, $idempotencyKey, $metaJson, $nowIso]
            );

            return $balanceAfter;
        });
    }

    /**
     * Admin manual balance adjustment with mandatory audit log.
     */
    public function adjustBalanceAdmin(
        string $adminUserId,
        string $targetUserId,
        int $amountCredits,
        string $reason
    ): int {
        $wallet = $this->getWallet($targetUserId);
        $newBalance = $wallet['balance_credits'] + $amountCredits;
        if ($newBalance < 0) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Penyesuaian saldo gagal: saldo akhir tidak boleh negatif (Saldo saat ini: {$wallet['balance_credits']}, Pengurangan: " . abs($amountCredits) . ")",
                400
            );
        }

        $nowIso = gmdate('Y-m-d H:i:s');
        $ledgerId = Security::generateId('led');
        $auditId = Security::generateId('aud');
        $idempotencyKey = "admin_adj_" . bin2hex(random_bytes(16));

        $meta = [
            'admin_user_id' => $adminUserId,
            'reason' => $reason,
        ];
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->db->transaction(function (Database $db) use (
            $targetUserId, $adminUserId, $amountCredits, $newBalance, $ledgerId, $auditId, $idempotencyKey, $metaJson, $nowIso
        ) {
            $db->execute(
                'UPDATE wallets SET balance_credits = ?, updated_at = ? WHERE user_id = ?',
                [$newBalance, $nowIso, $targetUserId]
            );

            $db->execute(
                "INSERT INTO credit_ledger (id, user_id, event_type, amount_credits, balance_after, reference_type, reference_id, idempotency_key, metadata_json, created_at)
                 VALUES (?, ?, 'admin_adjustment', ?, ?, 'admin', ?, ?, ?, ?)",
                [$ledgerId, $targetUserId, $amountCredits, $newBalance, $adminUserId, $idempotencyKey, $metaJson, $nowIso]
            );

            $db->execute(
                "INSERT INTO admin_audit_logs (id, admin_user_id, action, target_type, target_id, metadata_json, created_at)
                 VALUES (?, ?, 'balance_adjustment', 'user', ?, ?, ?)",
                [$auditId, $adminUserId, $targetUserId, $metaJson, $nowIso]
            );

            return $newBalance;
        });
    }

    public function listTransactions(string $userId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            'SELECT id, event_type, amount_credits, balance_after, reference_type, reference_id, metadata_json, created_at
             FROM credit_ledger
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            [$userId, $limit, $offset]
        );

        $totalRow = $this->db->fetchOne(
            'SELECT COUNT(*) as total FROM credit_ledger WHERE user_id = ?',
            [$userId]
        );
        $total = $totalRow !== null ? (int)$totalRow['total'] : 0;

        $items = [];
        foreach ($rows as $r) {
            $meta = null;
            if (!empty($r['metadata_json'])) {
                $meta = json_decode((string)$r['metadata_json'], true);
            }
            $items[] = [
                'id' => $r['id'],
                'event_type' => $r['event_type'],
                'amount_credits' => (int)$r['amount_credits'],
                'balance_after' => (int)$r['balance_after'],
                'reference_type' => $r['reference_type'],
                'reference_id' => $r['reference_id'],
                'metadata' => $meta,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'])),
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
