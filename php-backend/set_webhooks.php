<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Register Telegram Webhooks CLI
 * Usage: php set_webhooks.php
 *
 * Mendaftarkan URL webhook untuk Bot Pelanggan & Bot Laporan
 * langsung ke Telegram Bot API server.
 * ========================================================
 */

require_once __DIR__ . '/src/bootstrap.php';

use Ciptaone\Core\Config;

$config = Config::getInstance();
$botToken = trim((string)$config->get('TELEGRAM_BOT_TOKEN', ''));
$reportBotToken = trim((string)$config->get('TELEGRAM_REPORT_BOT_TOKEN', ''));
$webhookSecret = trim((string)$config->get('TELEGRAM_WEBHOOK_SECRET', ''));
$reportWebhookSecret = trim((string)$config->get('TELEGRAM_REPORT_WEBHOOK_SECRET', $webhookSecret));

echo "========================================================\n";
echo "🤖 AI Ciptaone Router - Pendaftaran Webhook Telegram\n";
echo "========================================================\n\n";

function callTelegramApi(string $token, string $method, array $params = []): array
{
    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || !empty($err)) {
        return ['ok' => false, 'error' => $err ?: 'Network error'];
    }

    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : ['ok' => false, 'http_code' => $code, 'raw' => $raw];
}

// 1. Bot Toko / Pelanggan (TELEGRAM_BOT_TOKEN)
echo "[1] Bot Pelanggan / Store (TELEGRAM_BOT_TOKEN):\n";
if (empty($botToken)) {
    echo "    ⚠️ TELEGRAM_BOT_TOKEN kosong di .env. Dilewati.\n\n";
} else {
    $webhookUrl = "https://ai.ciptaone.site/api/v1/webhooks/telegram";
    $params = [
        'url' => $webhookUrl,
        'allowed_updates' => ['message', 'callback_query'],
    ];
    if (!empty($webhookSecret)) {
        $params['secret_token'] = $webhookSecret;
    }

    $res = callTelegramApi($botToken, 'setWebhook', $params);
    if (!empty($res['ok'])) {
        echo "    ✅ Webhook BERHASIL didaftarkan!\n";
        echo "       Target URL: {$webhookUrl}\n";
    } else {
        echo "    ❌ Gagal mendaftarkan webhook: " . ($res['description'] ?? json_encode($res)) . "\n";
    }

    $info = callTelegramApi($botToken, 'getWebhookInfo');
    if (!empty($info['ok'])) {
        echo "       Status URL aktif: " . ($info['result']['url'] ?? '-') . "\n";
        echo "       Pending updates : " . ($info['result']['pending_update_count'] ?? 0) . "\n";
    }
    echo "\n";
}

// 2. Bot Laporan / Admin (TELEGRAM_REPORT_BOT_TOKEN)
echo "[2] Bot Laporan / Admin (TELEGRAM_REPORT_BOT_TOKEN):\n";
$targetReportToken = !empty($reportBotToken) ? $reportBotToken : $botToken;

if (empty($targetReportToken)) {
    echo "    ⚠️ TELEGRAM_REPORT_BOT_TOKEN (dan TELEGRAM_BOT_TOKEN) kosong di .env. Dilewati.\n\n";
} else {
    $webhookUrl = "https://ai.ciptaone.site/api/v1/webhooks/telegram-report";
    $params = [
        'url' => $webhookUrl,
        'allowed_updates' => ['message', 'callback_query'],
    ];
    if (!empty($reportWebhookSecret)) {
        $params['secret_token'] = $reportWebhookSecret;
    }

    $res = callTelegramApi($targetReportToken, 'setWebhook', $params);
    if (!empty($res['ok'])) {
        echo "    ✅ Webhook Laporan BERHASIL didaftarkan!\n";
        echo "       Target URL: {$webhookUrl}\n";
    } else {
        echo "    ❌ Gagal mendaftarkan webhook: " . ($res['description'] ?? json_encode($res)) . "\n";
    }

    $info = callTelegramApi($targetReportToken, 'getWebhookInfo');
    if (!empty($info['ok'])) {
        echo "       Status URL aktif: " . ($info['result']['url'] ?? '-') . "\n";
        echo "       Pending updates : " . ($info['result']['pending_update_count'] ?? 0) . "\n";
    }
    echo "\n";
}

echo "========================================================\n";
echo "Selesai. Silakan coba kirim /start di Telegram sekarang!\n";
echo "========================================================\n";
