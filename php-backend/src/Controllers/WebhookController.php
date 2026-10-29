<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;
use Ciptaone\Core\Security;
use Ciptaone\Services\ApiKeyService;
use Ciptaone\Services\PaymentAdapters\DanaPaymentAdapter;
use Ciptaone\Services\PaymentAdapters\DuitkuPaymentAdapter;
use Ciptaone\Services\PaymentAdapters\TripayPaymentAdapter;
use Ciptaone\Services\Payments\DuitkuService;
use Ciptaone\Services\Payments\TripayService;
use Ciptaone\Services\TelegramBotService;
use Ciptaone\Services\TopupService;

/**
 * Controller for handling external webhooks: Telegram bot callbacks & payment gateways.
 */
class WebhookController extends BaseController
{
    private Database $db;
    private TelegramBotService $telegramBot;
    private TopupService $topupService;

    public function __construct(
        ?Database $db = null,
        ?TelegramBotService $telegramBot = null,
        ?TopupService $topupService = null
    ) {
        parent::__construct();
        $this->db = $db ?? Database::getInstance();
        $this->telegramBot = $telegramBot ?? new TelegramBotService();
        $this->topupService = $topupService ?? new TopupService();
    }

    public function handleTelegramWebhook(Request $request): void
    {
        $this->processTelegramUpdate($request, 'customer');
    }

    public function handleTelegramReportWebhook(Request $request): void
    {
        $this->processTelegramUpdate($request, 'report');
    }

    private function processTelegramUpdate(Request $request, string $botRole): void
    {
        $secretKey = $botRole === 'report' ? 'TELEGRAM_REPORT_WEBHOOK_SECRET' : 'TELEGRAM_WEBHOOK_SECRET';
        $expectedSecret = (string)$this->config->get($secretKey, '');
        if ($expectedSecret === '' && $botRole === 'report') {
            $expectedSecret = (string)$this->config->get('TELEGRAM_WEBHOOK_SECRET', '');
        }

        if ($expectedSecret !== '') {
            $receivedSecret = $request->getHeader('X-Telegram-Bot-Api-Secret-Token');
            if ($receivedSecret !== $expectedSecret) {
                throw new AppError(ErrorCode::UNAUTHORIZED, 'Invalid Telegram secret token.', 401);
            }
        }

        $payload = $request->getBody();
        $apiKeyService = new ApiKeyService($this->db);
        $tripayService = new TripayService($this->config, $this->db);
        $duitkuService = new DuitkuService($this->config, $this->db);

        $this->telegramBot->handleTelegramUpdate(
            $payload,
            $this->db,
            $this->topupService,
            $apiKeyService,
            $tripayService,
            $duitkuService,
            $botRole
        );

        Response::json(['ok' => true]);
    }

    public function handlePaymentGatewayWebhook(Request $request, array $params): void
    {
        $gateway = (string)($params['gateway'] ?? '');
        $rawBody = $request->getRawBody();
        $headers = $request->getHeaders();
        $payloadHash = hash('sha256', $rawBody);
        $nowIso = gmdate('Y-m-d H:i:s');
        $webhookId = Security::generateId('wh');

        // Optional IP Whitelist enforcement if configured in .env
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $whitelistKey = strtoupper($gateway) . '_WEBHOOK_IP_WHITELIST';
        $whitelistStr = (string)$this->config->get($whitelistKey, '');
        if ($whitelistStr !== '') {
            $allowedIps = array_filter(array_map('trim', explode(',', $whitelistStr)));
            if (!in_array($clientIp, $allowedIps, true)) {
                throw new AppError(ErrorCode::FORBIDDEN, "IP sumber webhook '{$clientIp}' tidak terdaftar pada whitelist resmi {$gateway}.", 403);
            }
        }

        // Deduplication check
        $existing = $this->db->fetchOne(
            'SELECT id, processing_status FROM payment_webhook_events WHERE gateway = ? AND payload_hash = ?',
            [$gateway, $payloadHash]
        );
        if ($existing !== null) {
            Response::json(['status' => 'ignored', 'message' => 'Duplicate event payload already recorded.']);
            return;
        }

        // Record incoming event
        $this->db->execute(
            'INSERT INTO payment_webhook_events (id, gateway, event_id, payload_hash, processing_status, received_at, processed_at, error_code)
             VALUES (?, ?, ?, ?, "received", ?, NULL, NULL)',
            [$webhookId, $gateway, substr($payloadHash, 0, 16), $payloadHash, $nowIso]
        );

        if ($gateway === 'tripay') {
            $adapter = new TripayPaymentAdapter(null, $this->config, $this->db);
            try {
                $verifiedData = $adapter->verifyWebhook($headers, $rawBody);
                $orderId = (string)($verifiedData['order_id'] ?? '');

                if (($verifiedData['status'] ?? '') === 'paid') {
                    // Defensive amount check: ensure paid amount is not less than required invoice amount
                    $topupRecord = $this->db->fetchOne('SELECT amount_minor FROM topups WHERE order_id = ?', [$orderId]);
                    if ($topupRecord !== null && (int)($verifiedData['amount'] ?? 0) < (int)$topupRecord['amount_minor']) {
                        throw new AppError(ErrorCode::INVALID_REQUEST, 'Nominal pembayaran webhook lebih kecil dari jumlah tagihan invoice.', 400);
                    }

                    $this->topupService->reviewTopup(
                        $orderId,
                        'paid',
                        'gateway:tripay:webhook'
                    );

                    $this->db->execute(
                        'UPDATE payment_webhook_events SET processing_status = "processed", processed_at = ? WHERE id = ?',
                        [$nowIso, $webhookId]
                    );

                    // Push instant fulfillment to Telegram customer
                    $this->notifyCustomerPaymentSuccess($orderId, (int)($verifiedData['amount'] ?? 0));

                    // Push instant alert to Telegram admin
                    try {
                        $topupUser = $this->db->fetchOne(
                            'SELECT u.email_normalized FROM topups t JOIN users u ON t.user_id = u.id WHERE t.order_id = ?',
                            [$orderId]
                        );
                        $userEmail = (string)($topupUser['email_normalized'] ?? 'user');
                        $this->telegramBot->notifyAdminPaymentReceived($orderId, $userEmail, (int)($verifiedData['amount'] ?? 0), 'tripay_qris');
                    } catch (\Throwable) {
                    }
                }
            } catch (\Throwable $ex) {
                $this->db->execute(
                    'UPDATE payment_webhook_events SET processing_status = "failed", error_code = ? WHERE id = ?',
                    [substr($ex->getMessage(), 0, 255), $webhookId]
                );
                throw $ex;
            }
        } elseif ($gateway === 'duitku') {
            $adapter = new DuitkuPaymentAdapter(null, $this->config, $this->db);
            try {
                $verifiedData = $adapter->verifyWebhook($headers, $rawBody);
                $orderId = (string)($verifiedData['order_id'] ?? '');

                if (($verifiedData['status'] ?? '') === 'paid') {
                    // Defensive amount check: ensure paid amount is not less than required invoice amount
                    $topupRecord = $this->db->fetchOne('SELECT amount_minor FROM topups WHERE order_id = ?', [$orderId]);
                    if ($topupRecord !== null && (int)($verifiedData['amount'] ?? 0) < (int)$topupRecord['amount_minor']) {
                        throw new AppError(ErrorCode::INVALID_REQUEST, 'Nominal pembayaran webhook lebih kecil dari jumlah tagihan invoice.', 400);
                    }

                    $this->topupService->reviewTopup(
                        $orderId,
                        'paid',
                        'gateway:duitku:webhook'
                    );

                    $this->db->execute(
                        'UPDATE payment_webhook_events SET processing_status = "processed", processed_at = ? WHERE id = ?',
                        [$nowIso, $webhookId]
                    );

                    // Push instant fulfillment to Telegram customer
                    $this->notifyCustomerPaymentSuccess($orderId, (int)($verifiedData['amount'] ?? 0));

                    // Push instant alert to Telegram admin
                    try {
                        $topupUser = $this->db->fetchOne(
                            'SELECT u.email_normalized FROM topups t JOIN users u ON t.user_id = u.id WHERE t.order_id = ?',
                            [$orderId]
                        );
                        $userEmail = (string)($topupUser['email_normalized'] ?? 'user');
                        $this->telegramBot->notifyAdminPaymentReceived($orderId, $userEmail, (int)($verifiedData['amount'] ?? 0), 'duitku');
                    } catch (\Throwable) {
                    }
                }
            } catch (\Throwable $ex) {
                $this->db->execute(
                    'UPDATE payment_webhook_events SET processing_status = "failed", error_code = ? WHERE id = ?',
                    [substr($ex->getMessage(), 0, 255), $webhookId]
                );
                throw $ex;
            }
        } elseif ($gateway === 'dana') {
            $adapter = new DanaPaymentAdapter($this->config);
            try {
                $verifiedData = $adapter->verifyWebhook($headers, $rawBody);
                $orderId = (string)($verifiedData['order_id'] ?? '');

                if (($verifiedData['status'] ?? '') === 'paid') {
                    $this->topupService->reviewTopup(
                        $orderId,
                        'paid',
                        'gateway:dana:webhook'
                    );
                    $this->db->execute(
                        'UPDATE payment_webhook_events SET processing_status = "processed", processed_at = ? WHERE id = ?',
                        [$nowIso, $webhookId]
                    );
                }
            } catch (\Throwable $ex) {
                $this->db->execute(
                    'UPDATE payment_webhook_events SET processing_status = "failed", error_code = ? WHERE id = ?',
                    [substr($ex->getMessage(), 0, 255), $webhookId]
                );
                throw $ex;
            }
        } else {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                "Gateway '{$gateway}' tidak didukung.",
                400
            );
        }

        Response::json(['status' => 'success', 'message' => 'Webhook processed successfully.']);
    }

    private function notifyCustomerPaymentSuccess(string $orderId, int $amountPaid): void
    {
        try {
            $topup = $this->db->fetchOne(
                'SELECT t.*, u.email_normalized, w.balance_credits
                 FROM topups t
                 JOIN users u ON t.user_id = u.id
                 JOIN wallets w ON t.user_id = w.user_id
                 WHERE t.order_id = ?',
                [$orderId]
            );

            if ($topup === null) {
                return;
            }

            $email = (string)$topup['email_normalized'];
            if (str_starts_with($email, 'tg_')) {
                $parts = explode('@', $email, 2);
                $chatId = substr($parts[0], 3); // strip 'tg_'
                $userId = (string)$topup['user_id'];

                // Check if user has an active key
                $existingKey = $this->db->fetchOne(
                    'SELECT id FROM api_keys WHERE user_id = ? AND revoked_at IS NULL LIMIT 1',
                    [$userId]
                );

                $newApiKey = null;
                if ($existingKey === null) {
                    $apiKeyService = new ApiKeyService($this->db);
                    $keyResult = $apiKeyService->createApiKey($userId, 'Default Telegram Key');
                    $newApiKey = (string)($keyResult['key'] ?? null);
                }

                $this->telegramBot->notifyUserPaymentSuccess(
                    $chatId,
                    $orderId,
                    $amountPaid,
                    (int)$topup['balance_credits'],
                    $newApiKey
                );
            }
        } catch (\Throwable $e) {
            error_log("Failed to notify customer payment success [{$orderId}]: " . $e->getMessage());
        }
    }
}
