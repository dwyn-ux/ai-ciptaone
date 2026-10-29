<?php
declare(strict_types=1);

namespace Ciptaone\Services;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Security;
use Ciptaone\Services\PaymentAdapters\BasePaymentAdapter;
use Ciptaone\Services\PaymentAdapters\DanaPaymentAdapter;
use Ciptaone\Services\PaymentAdapters\DuitkuPaymentAdapter;
use Ciptaone\Services\PaymentAdapters\ManualPaymentAdapter;
use Ciptaone\Services\PaymentAdapters\TripayPaymentAdapter;

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
    private TripayPaymentAdapter $tripayAdapter;
    private DuitkuPaymentAdapter $duitkuAdapter;

    public function __construct(
        ?Database $db = null,
        ?Config $config = null,
        ?WalletService $walletService = null,
        ?TelegramBotService $telegramBot = null,
        ?AuditService $auditService = null,
        ?TripayPaymentAdapter $tripayAdapter = null,
        ?DuitkuPaymentAdapter $duitkuAdapter = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->config = $config ?? Config::getInstance();
        $this->walletService = $walletService ?? new WalletService($this->db);
        $this->telegramBot = $telegramBot ?? new TelegramBotService($this->config);
        $this->auditService = $auditService ?? new AuditService($this->db);

        $this->manualTransferAdapter = new ManualPaymentAdapter('manual_transfer', $this->config);
        $this->manualQrisAdapter = new ManualPaymentAdapter('manual_qris', $this->config);
        $this->danaAdapter = new DanaPaymentAdapter($this->config);
        $this->tripayAdapter = $tripayAdapter ?? new TripayPaymentAdapter(null, $this->config, $this->db);
        $this->duitkuAdapter = $duitkuAdapter ?? new DuitkuPaymentAdapter(null, $this->config, $this->db);
    }

    public function getAdapter(string $paymentMethod): BasePaymentAdapter
    {
        return match ($paymentMethod) {
            'tripay_qris' => throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'Metode pembayaran Tripay dinonaktifkan. Silakan gunakan Duitku Gateway.',
                400
            ),
            'duitku', 'duitku_qris' => $this->duitkuAdapter,
            'dana_qris_dynamic' => $this->danaAdapter,
            'manual_transfer', 'manual_qris' => throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Metode pembayaran manual ('{$paymentMethod}') telah dinonaktifkan. Silakan gunakan pembayaran otomatis QRIS atau Virtual Account.",
                400
            ),
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
        string $paymentMethod = 'duitku'
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
        $qrUrl = $invoiceData['qr_url'] ?? null;
        $qrString = $invoiceData['qr_string'] ?? null;
        $providerRef = $invoiceData['provider_reference'] ?? $orderId;
        $instructions = $invoiceData['instructions'] ?? [];

        // Store payment metadata (checkout_url, qr_url, instructions) in rejection_reason column
        // while status is 'pending', so it persists across page reloads and status checks without schema changes.
        $metadataJson = json_encode([
            'checkout_url' => $checkoutUrl,
            'qr_url' => $qrUrl,
            'qr_string' => $qrString,
            'instructions' => $instructions,
        ], JSON_UNESCAPED_SLASHES);

        $this->db->execute(
            "INSERT INTO topups (
                id, user_id, order_id, payment_provider, payment_method,
                provider_reference, amount_minor, currency, credits_to_grant,
                status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
                idempotency_key, paid_at, expires_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'IDR', ?, 'pending', NULL, NULL, NULL, ?, ?, NULL, ?, ?, ?)",
            [
                $topupId, $userId, $orderId, $paymentMethod, $paymentMethod,
                $providerRef, $amountMinor, $creditsToGrant,
                $metadataJson,
                $idempotencyKey, $expiresIso, $nowIso, $nowIso
            ]
        );

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
            'qr_url' => $qrUrl,
            'qr_string' => $qrString,
            'instructions' => $instructions,
        ];
    }

    public function getTopupByIdOrOrder(string $identifier, ?string $userId = null, bool $pollGateway = true): array
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

        // Live Gateway Status Verification for pending invoices:
        // Check live with Duitku in case webhook was delayed, missed, or blocked by sandbox networking.
        if ($pollGateway && $row['status'] === 'pending' && in_array($row['payment_method'], ['duitku', 'duitku_qris'], true)) {
            $duitku = $this->duitkuAdapter->getDuitkuService();
            if ($duitku->isConfigured()) {
                try {
                    $statusRes = $duitku->checkTransactionStatus($row['order_id']);
                    $statusCode = (string)($statusRes['statusCode'] ?? '');
                    // Duitku statusCode '00' indicates SUCCESS / PAID
                    if ($statusCode === '00') {
                        $this->reviewTopup($row['order_id'], 'paid', 'gateway:duitku:status_poll');
                        $row = $this->db->fetchOne($sql, $params);
                    } elseif ($statusCode === '02') {
                        // 02 = Expired / Failed
                        $this->reviewTopup($row['order_id'], 'rejected', 'gateway:duitku:status_poll', 'Pembayaran kedaluwarsa atau dibatalkan.');
                        $row = $this->db->fetchOne($sql, $params);
                    }
                } catch (\Throwable $e) {
                    error_log("Duitku live status check error for [{$row['order_id']}]: " . $e->getMessage());
                }
            }
        }

        $row['amount_minor'] = (int)$row['amount_minor'];
        $row['credits_to_grant'] = (int)$row['credits_to_grant'];
        $row['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['expires_at']));
        $row['created_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at']));
        if (!empty($row['paid_at'])) {
            $row['paid_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['paid_at']));
        }

        // Unpack metadata if pending
        if ($row['status'] === 'pending' && !empty($row['rejection_reason'])) {
            $meta = json_decode((string)$row['rejection_reason'], true);
            if (is_array($meta)) {
                $row['checkout_url'] = $meta['checkout_url'] ?? null;
                $row['qr_url'] = $meta['qr_url'] ?? null;
                $row['qr_string'] = $meta['qr_string'] ?? null;
                $row['instructions'] = $meta['instructions'] ?? [];
            }
        }

        // Fallback for existing pending topups that don't have metadata stored
        if ($row['status'] === 'pending' && empty($row['checkout_url']) && empty($row['qr_url'])) {
            try {
                $adapter = $this->getAdapter($row['payment_method']);
                $invData = $adapter->createInvoice(
                    $row['order_id'],
                    $row['amount_minor'],
                    $row['currency'] ?? 'IDR',
                    $row['user_id'],
                    'customer@ciptaone.com'
                );
                $row['checkout_url'] = $invData['checkout_url'] ?? null;
                $row['qr_url'] = $invData['qr_url'] ?? null;
                $row['qr_string'] = $invData['qr_string'] ?? null;
                $row['instructions'] = $invData['instructions'] ?? [];

                $metaJson = json_encode([
                    'checkout_url' => $row['checkout_url'],
                    'qr_url' => $row['qr_url'],
                    'qr_string' => $row['qr_string'],
                    'instructions' => $row['instructions'],
                ], JSON_UNESCAPED_SLASHES);
                $this->db->execute('UPDATE topups SET rejection_reason = ? WHERE id = ?', [$metaJson, $row['id']]);
            } catch (\Throwable $e) {
                $row['instructions'] = [
                    'keterangan' => 'Silakan klik tombol Buat Tagihan Baru untuk membuat pesanan pembayaran otomatis.',
                ];
            }
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
        throw new AppError(
            ErrorCode::INVALID_REQUEST,
            'Metode pembayaran manual dan unggah bukti telah dinonaktifkan. Seluruh transaksi diverifikasi secara otomatis oleh sistem payment gateway.',
            400
        );
    }

    public function reviewTopup(
        string $orderId,
        string $action, // 'paid' or 'rejected'
        string $adminUserId,
        ?string $rejectionReason = null
    ): array {
        $topup = $this->getTopupByIdOrOrder($orderId, null, false);
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

    public function cancelTopup(string $identifier, string $userId): array
    {
        $topup = $this->db->fetchOne(
            'SELECT * FROM topups WHERE (id = ? OR order_id = ?) AND user_id = ?',
            [$identifier, $identifier, $userId]
        );

        if ($topup === null) {
            throw new AppError(
                ErrorCode::NOT_FOUND,
                'Invoice top-up tidak ditemukan.',
                404
            );
        }

        if ($topup['status'] !== 'pending') {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Hanya invoice dengan status 'menunggu pembayaran' yang dapat dibatalkan.",
                400
            );
        }

        $nowIso = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            "UPDATE topups
             SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?
             WHERE id = ?",
            ['Dibatalkan oleh pengguna.', $userId, $nowIso, $nowIso, $topup['id']]
        );

        $this->auditService->logAction(
            $userId,
            'cancel_topup',
            'topup',
            $topup['order_id'],
            [
                'order_id' => $topup['order_id'],
                'amount_minor' => (int)$topup['amount_minor'],
                'action' => 'user_cancel',
            ]
        );

        return [
            'status' => 'rejected',
            'message' => 'Pembayaran top-up berhasil dibatalkan.',
            'order_id' => $topup['order_id'],
        ];
    }
}

