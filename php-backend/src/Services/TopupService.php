<?php
declare(strict_types=1);

namespace Decomu\Services;

use Decomu\Core\AppError;
use Decomu\Core\Config;
use Decomu\Core\Database;
use Decomu\Core\ErrorCode;
use Decomu\Core\Security;
use Decomu\Services\PaymentAdapters\BasePaymentAdapter;
use Decomu\Services\PaymentAdapters\DanaPaymentAdapter;
use Decomu\Services\PaymentAdapters\ManualPaymentAdapter;

/**
 * Top-up invoice management, safe private payment proof uploads,
 * admin review workflows, and Telegram notification coordination.
 */
class TopupService
{
    private Database $db;
    private Config $config;
    private WalletService $walletService;
    private TelegramBotService $telegramBot;
    private AuditService $auditService;
    private ManualPaymentAdapter $manualTransferAdapter;
    private ManualPaymentAdapter $manualQrisAdapter;
    private DanaPaymentAdapter $danaAdapter;

    public function __construct(
        ?Database $db = null,
        ?Config $config = null,
        ?WalletService $walletService = null,
        ?TelegramBotService $telegramBot = null,
        ?AuditService $auditService = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->config = $config ?? Config::getInstance();
        $this->walletService = $walletService ?? new WalletService($this->db);
        $this->telegramBot = $telegramBot ?? new TelegramBotService($this->config);
        $this->auditService = $auditService ?? new AuditService($this->db);

        $this->manualTransferAdapter = new ManualPaymentAdapter('manual_transfer', $this->config);
        $this->manualQrisAdapter = new ManualPaymentAdapter('manual_qris', $this->config);
        $this->danaAdapter = new DanaPaymentAdapter($this->config);
    }

    private function getAdapter(string $paymentMethod): BasePaymentAdapter
    {
        return match ($paymentMethod) {
            'manual_transfer' => $this->manualTransferAdapter,
            'manual_qris' => $this->manualQrisAdapter,
            'dana_qris_dynamic' => $this->danaAdapter,
            default => throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Metode pembayaran '{$paymentMethod}' tidak valid.",
                400
            ),
        };
    }

    public function createTopup(
        string $userId,
        string $userEmail,
        int $amountMinor,
        string $paymentMethod = 'manual_transfer'
    ): array {
        if ($amountMinor < 10000) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Nominal top-up minimum adalah Rp10.000.',
                400
            );
        }

        $creditPerRupiah = (int)$this->config->get('CREDIT_PER_RUPIAH', 1);
        $creditsToGrant = $amountMinor * $creditPerRupiah;

        $topupId = Security::generateId('top');
        $now = time();
        $dateStr = gmdate('Ymd', $now);
        $orderId = 'INV-' . $dateStr . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        $expiryMinutes = (int)$this->config->get('TOPUP_EXPIRY_MINUTES', 120);
        $expiresAt = $now + ($expiryMinutes * 60);

        $nowIso = gmdate('Y-m-d H:i:s', $now);
        $expiresIso = gmdate('Y-m-d H:i:s', $expiresAt);
        $idempotencyKey = "topup_order_{$orderId}";

        $adapter = $this->getAdapter($paymentMethod);
        $invoiceData = $adapter->createInvoice($orderId, $amountMinor, 'IDR', $userId, $userEmail);

        $checkoutUrl = $invoiceData['checkout_url'] ?? null;
        $providerRef = $invoiceData['provider_reference'] ?? $orderId;
        $instructions = $invoiceData['instructions'] ?? [];

        $this->db->execute(
            "INSERT INTO topups (
                id, user_id, order_id, payment_provider, payment_method,
                provider_reference, amount_minor, currency, credits_to_grant,
                status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
                idempotency_key, paid_at, expires_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'IDR', ?, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)",
            [
                $topupId, $userId, $orderId, $paymentMethod, $paymentMethod,
                $providerRef, $amountMinor, $creditsToGrant,
                $idempotencyKey, $expiresIso, $nowIso, $nowIso
            ]
        );

        // Notify admin via Telegram Bot (ignore failures to avoid blocking response)
        try {
            $this->telegramBot->notifyAdminNewTopup(
                $orderId,
                $userEmail,
                $amountMinor,
                $creditsToGrant,
                $paymentMethod,
                false
            );
        } catch (\Throwable) {
        }

        return [
            'topup_id' => $topupId,
            'order_id' => $orderId,
            'amount_minor' => $amountMinor,
            'currency' => 'IDR',
            'credits_to_grant' => $creditsToGrant,
            'payment_method' => $paymentMethod,
            'status' => 'pending',
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'checkout_url' => $checkoutUrl,
            'instructions' => $instructions,
        ];
    }

    public function getTopupByIdOrOrder(string $identifier, ?string $userId = null): array
    {
        $sql = 'SELECT * FROM topups WHERE (id = ? OR order_id = ?)';
        $params = [$identifier, $identifier];

        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $row = $this->db->fetchOne($sql, $params);
        if ($row === null) {
            throw new AppError(
                ErrorCode::NOT_FOUND,
                'Invoice top-up tidak ditemukan.',
                404
            );
        }

        $row['amount_minor'] = (int)$row['amount_minor'];
        $row['credits_to_grant'] = (int)$row['credits_to_grant'];
        $row['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['expires_at']));
        $row['created_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at']));
        if (!empty($row['paid_at'])) {
            $row['paid_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['paid_at']));
        }
        return $row;
    }

    public function uploadProof(
        string $orderId,
        string $userId,
        string $filename,
        string $contentType,
        string $fileBytes
    ): array {
        $topup = $this->getTopupByIdOrOrder($orderId, $userId);

        if (!in_array($topup['status'], ['pending', 'under_review'], true)) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Tidak dapat mengunggah bukti: status invoice saat ini adalah '{$topup['status']}'.",
                400
            );
        }

        $maxBytes = (int)$this->config->get('MAX_PROOF_SIZE_BYTES', 5242880);
        if (strlen($fileBytes) > $maxBytes) {
            $maxMb = intdiv($maxBytes, 1024 * 1024);
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Ukuran file bukti melebihi batas {$maxMb}MB.",
                400
            );
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $cleanMime = strtolower(trim($contentType));
        if (!in_array($cleanMime, $allowedMimes, true)) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Tipe file bukti harus berformat JPEG, PNG, atau WEBP.',
                400
            );
        }

        $ext = match ($cleanMime) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            default => '.jpg',
        };

        $proofKey = 'proof_' . bin2hex(random_bytes(16)) . $ext;
        $uploadDir = (string)$this->config->get('PROOF_UPLOAD_DIR');

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destPath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $proofKey;
        file_put_contents($destPath, $fileBytes);

        $nowIso = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            "UPDATE topups SET proof_file_key = ?, status = 'under_review', updated_at = ? WHERE order_id = ?",
            [$proofKey, $nowIso, $orderId]
        );

        // Notify admins via Telegram
        $userRow = $this->db->fetchOne('SELECT email_normalized FROM users WHERE id = ?', [$userId]);
        $userEmail = $userRow['email_normalized'] ?? 'user';
        try {
            $this->telegramBot->notifyAdminNewTopup(
                $orderId,
                $userEmail,
                $topup['amount_minor'],
                $topup['credits_to_grant'],
                $topup['payment_method'],
                true
            );
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'status' => 'under_review',
            'message' => 'Bukti pembayaran berhasil diunggah. Admin akan memverifikasi mutasi rekening.',
        ];
    }

    public function reviewTopup(
        string $orderId,
        string $action, // 'paid' or 'rejected'
        string $adminUserId,
        ?string $rejectionReason = null
    ): array {
        $topup = $this->getTopupByIdOrOrder($orderId);
        $currentStatus = $topup['status'];

        if ($currentStatus === 'paid') {
            // Idempotent return
            return [
                'status' => 'paid',
                'message' => 'Top-up sudah disetujui sebelumnya.',
            ];
        }

        if (!in_array($currentStatus, ['pending', 'under_review'], true)) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Invoice dengan status '{$currentStatus}' tidak dapat ditinjau ulang.",
                400
            );
        }

        $nowIso = gmdate('Y-m-d H:i:s');

        if ($action === 'paid') {
            // Update topup record
            $this->db->execute(
                "UPDATE topups
                 SET status = 'paid', paid_at = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?
                 WHERE order_id = ? AND status != 'paid'",
                [$nowIso, $adminUserId, $nowIso, $nowIso, $orderId]
            );

            // Grant credits atomically to user wallet
            $newBalance = $this->walletService->creditWalletForTopup(
                (string)$topup['user_id'],
                $orderId,
                (int)$topup['credits_to_grant'],
                [
                    'order_id' => $orderId,
                    'amount_minor' => $topup['amount_minor'],
                    'payment_method' => $topup['payment_method'],
                    'reviewed_by' => $adminUserId,
                ]
            );

            // Write admin audit log
            $this->auditService->logAction(
                $adminUserId,
                'approve_topup',
                'topup',
                $orderId,
                [
                    'order_id' => $orderId,
                    'amount_minor' => $topup['amount_minor'],
                    'credits_to_grant' => $topup['credits_to_grant'],
                    'action' => 'approve',
                ]
            );

            return [
                'status' => 'paid',
                'message' => "Top-up berhasil disetujui. Saldo pengguna kini {$newBalance} kredit.",
                'new_balance' => $newBalance,
            ];

        } elseif ($action === 'rejected') {
            $reason = $rejectionReason ?? 'Pembayaran tidak ditemukan dalam mutasi rekening resmi.';

            $this->db->execute(
                "UPDATE topups
                 SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?
                 WHERE order_id = ?",
                [$reason, $adminUserId, $nowIso, $nowIso, $orderId]
            );

            $this->auditService->logAction(
                $adminUserId,
                'reject_topup',
                'topup',
                $orderId,
                [
                    'order_id' => $orderId,
                    'reason' => $reason,
                    'action' => 'reject',
                ]
            );

            return [
                'status' => 'rejected',
                'message' => "Top-up berhasil ditolak. Alasan: {$reason}",
            ];
        } else {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Aksi review tidak valid.',
                400
            );
        }
    }

    public function listUserTopups(string $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->fetchAll(
            'SELECT id, order_id, amount_minor, currency, credits_to_grant, payment_method,
                    status, proof_file_key, rejection_reason, expires_at, created_at, paid_at
             FROM topups
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?',
            [$userId, $limit]
        );

        return array_map(function ($r) {
            $r['amount_minor'] = (int)$r['amount_minor'];
            $r['credits_to_grant'] = (int)$r['credits_to_grant'];
            $r['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['expires_at']));
            $r['created_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at']));
            if (!empty($r['paid_at'])) {
                $r['paid_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['paid_at']));
            }
            return $r;
        }, $rows);
    }
}
