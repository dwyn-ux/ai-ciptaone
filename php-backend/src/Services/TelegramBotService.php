<?php
declare(strict_types=1);

namespace Decomu\Services;

use Decomu\Core\AppError;
use Decomu\Core\Config;

/**
 * Telegram Bot integration: sends invoice notifications to admin Telegram IDs
 * with interactive inline buttons, handles webhook callbacks, and validates admin allowlist.
 */
class TelegramBotService
{
    private ?string $botToken;
    private ?string $baseUrl;
    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->botToken = (string)$this->config->get('TELEGRAM_BOT_TOKEN', '');
        $this->baseUrl = $this->botToken !== '' ? "https://api.telegram.org/bot{$this->botToken}" : null;
    }

    public function notifyAdminNewTopup(
        string $orderId,
        string $userEmail,
        int $amountMinor,
        int $creditsToGrant,
        string $paymentMethod,
        bool $hasProof = false
    ): void {
        $adminIds = $this->config->getTelegramAdminIds();
        if ($this->baseUrl === null || empty($adminIds)) {
            return;
        }

        $methodLabel = $paymentMethod === 'manual_transfer' ? 'Transfer Bank' : 'QRIS Manual';
        $formattedAmount = number_format($amountMinor, 0, ',', '.');
        $formattedCredits = number_format($creditsToGrant, 0, ',', '.');
        $proofStatus = $hasProof ? 'Sudah diunggah (periksa mutasi resmi!)' : 'Belum diunggah';

        $text = "🔔 *PERMINTAAN TOP-UP BARU*\n\n" .
                "• *Order ID:* `{$orderId}`\n" .
                "• *Pengguna:* `{$userEmail}`\n" .
                "• *Nominal:* Rp{$formattedAmount}\n" .
                "• *Kredit:* {$formattedCredits} kredit\n" .
                "• *Metode:* {$methodLabel}\n" .
                "• *Bukti Transfer:* {$proofStatus}\n\n" .
                "⚠️ *Perhatian:* Jangan setujui hanya karena melihat screenshot. Pastikan dana benar-benar masuk pada mutasi rekening resmi.";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Setujui', 'callback_data' => "topup:approve:{$orderId}"],
                    ['text' => '❌ Tolak', 'callback_data' => "topup:reject:{$orderId}"],
                ]
            ]
        ];

        foreach ($adminIds as $adminId) {
            $this->sendTelegramRequest('/sendMessage', [
                'chat_id' => $adminId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => $replyMarkup,
            ]);
        }
    }

    public function handleCallbackQuery(
        array $callbackQuery,
        callable $topupServiceFn
    ): array {
        $callbackId = (string)($callbackQuery['id'] ?? '');
        $fromUser = (array)($callbackQuery['from'] ?? []);
        $telegramUserId = (int)($fromUser['id'] ?? 0);
        $username = (string)($fromUser['username'] ?? (string)$telegramUserId);
        $data = (string)($callbackQuery['data'] ?? '');
        $message = (array)($callbackQuery['message'] ?? []);
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        // 1. Check Admin Allowlist
        $adminIds = $this->config->getTelegramAdminIds();
        if (!in_array($telegramUserId, $adminIds, true)) {
            $this->answerCallback($callbackId, '⛔ Akses ditolak: Anda bukan admin terdaftar.', true);
            return ['status' => 'forbidden'];
        }

        // 2. Parse callback data
        $parts = explode(':', $data);
        if (count($parts) !== 3 || $parts[0] !== 'topup') {
            $this->answerCallback($callbackId, 'Format callback tidak valid.', false);
            return ['status' => 'invalid_format'];
        }

        $action = $parts[1];
        $orderId = $parts[2];

        if (!in_array($action, ['approve', 'reject'], true)) {
            $this->answerCallback($callbackId, 'Aksi tidak dikenal.', false);
            return ['status' => 'unknown_action'];
        }

        // 3. Process via TopupService
        $adminIdentifier = "tg_{$telegramUserId}_{$username}";
        $statusToSet = $action === 'approve' ? 'paid' : 'rejected';
        $rejectionReason = $action === 'reject' ? 'Ditolak oleh admin via Telegram' : null;

        try {
            $result = $topupServiceFn($orderId, $statusToSet, $adminIdentifier, $rejectionReason);

            $respText = $action === 'approve'
                ? "✅ Order {$orderId} berhasil DISETUJUI dan saldo dikreditkan."
                : "❌ Order {$orderId} berhasil DITOLAK.";

            $this->answerCallback($callbackId, $respText, true);

            // Update original message text with status badge
            if ($chatId && $messageId) {
                $origText = (string)($message['text'] ?? '');
                $decisionBadge = $action === 'approve'
                    ? "\n\n🏁 *STATUS:* DISETUJUI oleh @{$username}"
                    : "\n\n🏁 *STATUS:* DITOLAK oleh @{$username}";
                $this->editMessageText($chatId, (int)$messageId, $origText . $decisionBadge);
            }

            return ['status' => 'success', 'result' => $result];
        } catch (AppError $ae) {
            $this->answerCallback($callbackId, "Gagal: {$ae->getMessage()}", true);
            return ['status' => 'error', 'error' => $ae->getMessage()];
        } catch (\Throwable $ex) {
            $this->answerCallback($callbackId, "Kesalahan sistem: {$ex->getMessage()}", true);
            return ['status' => 'error', 'error' => $ex->getMessage()];
        }
    }

    private function answerCallback(string $callbackId, string $text, bool $alert = false): void
    {
        if ($this->baseUrl === null || $callbackId === '') {
            return;
        }

        $this->sendTelegramRequest('/answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    private function editMessageText(int|string $chatId, int $messageId, string $text): void
    {
        if ($this->baseUrl === null) {
            return;
        }

        $this->sendTelegramRequest('/editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendTelegramRequest(string $endpoint, array $payload): ?array
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $ch = curl_init("{$this->baseUrl}{$endpoint}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        return $res !== false ? json_decode((string)$res, true) : null;
    }
}
