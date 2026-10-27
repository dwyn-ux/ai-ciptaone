<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;
use Ciptaone\Core\Security;
use Ciptaone\Services\PaymentAdapters\DanaPaymentAdapter;
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
        $expectedSecret = (string)$this->config->get('TELEGRAM_WEBHOOK_SECRET', '');
        if ($expectedSecret !== '') {
            $receivedSecret = $request->getHeader('X-Telegram-Bot-Api-Secret-Token');
            if ($receivedSecret !== $expectedSecret) {
                throw new AppError(ErrorCode::UNAUTHORIZED, 'Invalid Telegram secret token.', 401);
            }
        }

        $payload = $request->getBody();
        if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
            $this->telegramBot->handleCallbackQuery(
                $payload['callback_query'],
                function (string $orderId, string $action, string $adminUserId, ?string $rejectionReason) {
                    return $this->topupService->reviewTopup($orderId, $action, $adminUserId, $rejectionReason);
                }
            );
        }

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

        if ($gateway === 'dana') {
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
}
