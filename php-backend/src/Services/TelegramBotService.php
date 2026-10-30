<?php
declare(strict_types=1);

namespace Ciptaone\Services;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\Security;
use Ciptaone\Services\Payments\DuitkuService;

/**
 * Telegram Bot integration:
 * 1. Admin operations: sends invoice review notifications to admin Telegram IDs with inline buttons.
 * 2. Customer operations: interactive commands (/start, /beli, /saldo, /key), Dynamic QRIS display, and instant payment delivery notifications.
 */
class TelegramBotService
{
    private ?string $botToken;
    private ?string $baseUrl;
    private ?string $reportBotToken;
    private ?string $reportBaseUrl;
    private Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->botToken = (string)$this->config->get('TELEGRAM_BOT_TOKEN', '');
        $this->baseUrl = $this->botToken !== '' ? "https://api.telegram.org/bot{$this->botToken}" : null;

        $reportToken = $this->config->getTelegramReportBotToken();
        $this->reportBotToken = $reportToken !== '' ? $reportToken : $this->botToken;
        $this->reportBaseUrl = $this->reportBotToken !== '' ? "https://api.telegram.org/bot{$this->reportBotToken}" : $this->baseUrl;
    }

    /**
     * Send instant notification to admin IDs when an automated top-up is completed.
     * Pure informational notification (no manual verification buttons).
     */
    public function notifyAdminPaymentReceived(
        string $orderId,
        string $userEmail,
        int $amountMinor,
        string $gateway
    ): void {
        $adminIds = $this->config->getTelegramAdminIds();
        $reportBase = $this->reportBaseUrl ?? $this->baseUrl;
        if ($reportBase === null || empty($adminIds)) {
            return;
        }

        $formattedAmount = number_format($amountMinor, 0, ',', '.');
        $dateStr = gmdate('d M Y, H:i') . ' UTC';
        $gatewayLabel = match (strtolower($gateway)) {
            'duitku', 'duitku_qris' => 'Duitku (QRIS / VA Bank)',
            default => strtoupper($gateway),
        };

        $text = "💰 *UANG MASUK OTOMATIS!*\n" .
                "━━━━━━━━━━━━━━━━━━━━\n" .
                "• *Order ID:* `{$orderId}`\n" .
                "• *Nominal:* *Rp{$formattedAmount}*\n" .
                "• *Kredit:* *+{$formattedAmount} kredit*\n" .
                "• *Gateway:* {$gatewayLabel}\n" .
                "• *Pengguna:* `{$userEmail}`\n" .
                "• *Waktu:* {$dateStr}\n" .
                "━━━━━━━━━━━━━━━━━━━━\n" .
                "✅ _Saldo otomatis masuk ke akun pengguna tanpa perlu verifikasi manual._";

        foreach ($adminIds as $adminId) {
            $this->sendMessage($adminId, $text, null, 'Markdown', true);
        }
    }

    /**
     * Backward-compatible stub: manual review is deprecated in favor of automated reports.
     */
    public function notifyAdminNewTopup(
        string $orderId,
        string $userEmail,
        int $amountMinor,
        int $creditsToGrant,
        string $paymentMethod,
        bool $hasProof = false
    ): void {
        // Direct to informational notification
        $this->notifyAdminPaymentReceived($orderId, $userEmail, $amountMinor, $paymentMethod);
    }

    /**
     * Inline keyboard markup for Telegram report menu.
     */
    public function getReportMenuMarkup(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📅 Laporan Harian (24 Jam)', 'callback_data' => 'report:daily'],
                    ['text' => '📆 Mingguan (7 Hari)', 'callback_data' => 'report:weekly'],
                ],
                [
                    ['text' => '📊 Bulanan (30 Hari)', 'callback_data' => 'report:monthly'],
                    ['text' => '💰 Ringkasan All-Time', 'callback_data' => 'report:all_time'],
                ]
            ]
        ];
    }

    /**
     * Generate structured analytics & financial report for a given timeframe.
     * Periods: 'daily' (24 jam), 'weekly' (7 hari), 'monthly' (30 hari), 'all_time' (seluruh waktu)
     */
    public function generateReport(string $period, Database $db): string
    {
        $now = time();
        $periodLabel = match ($period) {
            'weekly' => 'MINGGUAN (7 Hari Terakhir)',
            'monthly' => 'BULANAN (30 Hari Terakhir)',
            'all_time' => 'SELURUH WAKTU (All-Time)',
            default => 'HARIAN (24 Jam Terakhir)',
        };

        $secondsAgo = match ($period) {
            'weekly' => 7 * 86400,
            'monthly' => 30 * 86400,
            'all_time' => 365 * 10 * 86400,
            default => 86400,
        };

        $sinceIso = gmdate('Y-m-d H:i:s', $now - $secondsAgo);
        $currentDateStr = gmdate('d M Y, H:i') . ' UTC';

        // 1. Penambahan Pengguna Baru
        $newUsersRow = $db->fetchOne('SELECT COUNT(*) as total FROM users WHERE created_at >= ?', [$sinceIso]);
        $newUsersCount = (int)($newUsersRow['total'] ?? 0);

        $totalUsersRow = $db->fetchOne('SELECT COUNT(*) as total FROM users');
        $totalUsersCount = (int)($totalUsersRow['total'] ?? 0);

        // 2. Uang Masuk (Gross Revenue) dari Pembayaran Lunas
        $revRow = $db->fetchOne(
            "SELECT COALESCE(SUM(amount_minor), 0) as total_revenue, COUNT(*) as total_tx
             FROM topups
             WHERE status = 'paid' AND (paid_at >= ? OR updated_at >= ?)",
            [$sinceIso, $sinceIso]
        );
        $totalRevenue = (int)($revRow['total_revenue'] ?? 0);
        $totalTxCount = (int)($revRow['total_tx'] ?? 0);

        // Breakdown per gateway
        $duitkuRow = $db->fetchOne(
            "SELECT COALESCE(SUM(amount_minor), 0) as rev, COUNT(*) as count
             FROM topups
             WHERE status = 'paid' AND payment_method LIKE 'duitku%' AND (paid_at >= ? OR updated_at >= ?)",
            [$sinceIso, $sinceIso]
        );
        $duitkuRev = (int)($duitkuRow['rev'] ?? 0);
        $duitkuCount = (int)($duitkuRow['count'] ?? 0);

        // 3. Token Keluar & Pemakaian AI
        $usageRow = $db->fetchOne(
            "SELECT 
                COUNT(*) as total_req,
                COALESCE(SUM(input_tokens), 0) as in_tokens,
                COALESCE(SUM(output_tokens), 0) as out_tokens,
                COALESCE(SUM(billed_credits), 0) as billed_credits
             FROM usage_events
             WHERE status = 'success' AND created_at >= ?",
            [$sinceIso]
        );
        $totalReq = (int)($usageRow['total_req'] ?? 0);
        $inTokens = (int)($usageRow['in_tokens'] ?? 0);
        $outTokens = (int)($usageRow['out_tokens'] ?? 0);
        $totalTokens = $inTokens + $outTokens;
        $billedCredits = (int)($usageRow['billed_credits'] ?? 0);

        // Top 3 model AI terpakai
        $topModels = $db->fetchAll(
            "SELECT m.public_id, COUNT(u.id) as req_count
             FROM usage_events u
             JOIN models m ON u.model_id = m.id
             WHERE u.status = 'success' AND u.created_at >= ?
             GROUP BY m.public_id
             ORDER BY req_count DESC
             LIMIT 3",
            [$sinceIso]
        );

        $topModelsText = "";
        if (!empty($topModels)) {
            $rank = 1;
            foreach ($topModels as $tm) {
                $topModelsText .= "  {$rank}. `{$tm['public_id']}` (" . number_format((int)$tm['req_count'], 0, ',', '.') . " req)\n";
                $rank++;
            }
        } else {
            $topModelsText = "  _Belum ada request AI pada periode ini_\n";
        }

        // 4. Sisa Token & Saldo Mengendap
        $walletRow = $db->fetchOne("SELECT COALESCE(SUM(balance_credits), 0) as total_balance FROM wallets");
        $totalBalanceCredits = (int)($walletRow['total_balance'] ?? 0);

        $activeWalletsRow = $db->fetchOne("SELECT COUNT(*) as count FROM wallets WHERE balance_credits > 0");
        $activeWalletsCount = (int)($activeWalletsRow['count'] ?? 0);

        // Format Teks Laporan
        $fmtRevenue = number_format($totalRevenue, 0, ',', '.');
        $fmtDuitkuRev = number_format($duitkuRev, 0, ',', '.');
        $fmtInTokens = number_format($inTokens, 0, ',', '.');
        $fmtOutTokens = number_format($outTokens, 0, ',', '.');
        $fmtTotalTokens = number_format($totalTokens, 0, ',', '.');
        $fmtBilledCredits = number_format($billedCredits, 0, ',', '.');
        $fmtBalanceCredits = number_format($totalBalanceCredits, 0, ',', '.');

        return "📊 *LAPORAN AI ROUTER ({$periodLabel})*\n" .
               "🕒 *Dicatat pada:* {$currentDateStr}\n" .
               "━━━━━━━━━━━━━━━━━━━━\n\n" .
               "👥 *PENGGUNA & REGISTRASI*\n" .
               "• Penambahan User Baru: *+" . number_format($newUsersCount, 0, ',', '.') . " pengguna*\n" .
               "• Total Pengguna Terdaftar: *" . number_format($totalUsersCount, 0, ',', '.') . " akun*\n\n" .
               "💰 *UANG MASUK (REVENUE)*\n" .
               "• *Total Pemasukan:* *Rp{$fmtRevenue}*\n" .
               "• Transaksi Berhasil: *{$totalTxCount} transaksi*\n" .
               "• Duitku (VA & QRIS): Rp{$fmtDuitkuRev} ({$duitkuCount} tx)\n\n" .
               "⚡ *TOKEN KELUAR & PEMAKAIAN AI*\n" .
               "• Total Panggilan AI: *" . number_format($totalReq, 0, ',', '.') . " request*\n" .
               "• Input Tokens: *{$fmtInTokens} token*\n" .
               "• Output Tokens: *{$fmtOutTokens} token*\n" .
               "• Total Token Terproses: *{$fmtTotalTokens} token*\n" .
               "• Kredit Terpotong: *{$fmtBilledCredits} kredit (Rp{$fmtBilledCredits})*\n" .
               "• Model Terpopuler:\n" . $topModelsText . "\n" .
               "💼 *SISA TOKEN & SALDO PENGGUNA*\n" .
               "• Total Sisa Saldo Kredit User: *{$fmtBalanceCredits} kredit*\n" .
               "• Pengguna dengan Saldo Aktif: *{$activeWalletsCount} akun*\n" .
               "━━━━━━━━━━━━━━━━━━━━\n" .
               "🤖 _AI Ciptaone Router Analytics_";
    }

    /**
     * Send periodic report to all registered admin Telegram IDs.
     */
    public function sendReportToAdmins(string $period, Database $db): void
    {
        $adminIds = $this->config->getTelegramAdminIds();
        $reportBase = $this->reportBaseUrl ?? $this->baseUrl;
        if ($reportBase === null || empty($adminIds)) {
            return;
        }

        $reportText = $this->generateReport($period, $db);
        $replyMarkup = $this->getReportMenuMarkup();

        foreach ($adminIds as $adminId) {
            $this->sendMessage($adminId, $reportText, $replyMarkup, 'Markdown', true);
        }
    }

    /**
     * Send instant notification to customer upon successful payment settlement.
     */
    public function notifyUserPaymentSuccess(
        int|string $chatId,
        string $orderId,
        int $amount,
        int $newBalance,
        ?string $newApiKey = null
    ): void {
        if ($this->baseUrl === null) {
            return;
        }

        $formattedAmount = number_format($amount, 0, ',', '.');
        $formattedBalance = number_format($newBalance, 0, ',', '.');

        $text = "🎉 *PEMBAYARAN QRIS BERHASIL!*\n\n" .
                "Terima kasih! Pembayaran invoice `{$orderId}` sebesar *Rp{$formattedAmount}* telah terverifikasi otomatis.\n\n" .
                "💰 *Saldo API Anda sekarang:* {$formattedBalance} kredit\n";

        if ($newApiKey !== null && $newApiKey !== '') {
            $text .= "\n🔑 *API Key Baru Anda:*\n`{$newApiKey}`\n\n" .
                     "⚠️ *PENTING:* Simpan API key ini sekarang. Key hanya ditampilkan penuh satu kali ini demi keamanan.\n\n" .
                     "🌐 *Base URL (OpenAI & OpenRouter format):*\n`https://ciptaone.com/v1`\n\n" .
                     "📚 Dokumentasi API: https://ciptaone.com/docs";
        } else {
            $text .= "\nAPI key aktif Anda langsung dapat digunakan. Ketik /key untuk memeriksa status key Anda.";
        }

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 Cek Saldo', 'callback_data' => 'menu:saldo'],
                    ['text' => '🔑 API Key Saya', 'callback_data' => 'menu:key'],
                ],
                [
                    ['text' => '💳 Beli Saldo Lagi', 'callback_data' => 'menu:beli'],
                ]
            ]
        ];

        $this->sendMessage($chatId, $text, $replyMarkup);
    }

    /**
     * Main dispatcher for all incoming Telegram webhook updates (messages & callback queries).
     */
    public function handleTelegramUpdate(
        array $update,
        Database $db,
        TopupService $topupService,
        ApiKeyService $apiKeyService,
        ?DuitkuService $duitkuService = null,
        string $botRole = 'all'
    ): array {
        // 1. Handle Inline Button Callback Queries
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handleCallbackQuery(
                $update['callback_query'],
                $db,
                $topupService,
                $apiKeyService,
                $duitkuService,
                $botRole
            );
        }

        // 2. Handle Text Messages & Slash Commands
        if (isset($update['message']) && is_array($update['message'])) {
            return $this->handleTextMessage(
                $update['message'],
                $db,
                $apiKeyService,
                $botRole
            );
        }

        return ['status' => 'ignored'];
    }

    /**
     * Handle incoming text messages (/start, /beli, /saldo, /key, /help, /laporan).
     */
    public function handleTextMessage(
        array $message,
        Database $db,
        ApiKeyService $apiKeyService,
        string $botRole = 'all'
    ): array {
        $chatId = $message['chat']['id'] ?? null;
        $from = (array)($message['from'] ?? []);
        $telegramUserId = (int)($from['id'] ?? 0);
        $username = (string)($from['username'] ?? "user_{$telegramUserId}");
        $firstName = (string)($from['first_name'] ?? 'Sahabat Ciptaone');
        $text = trim((string)($message['text'] ?? ''));

        if ($chatId === null || $telegramUserId === 0) {
            return ['status' => 'invalid_message'];
        }

        // Support commands with bot username suffix (e.g., /start@BotUsername) and arguments (/start 123)
        $rawCommand = strtolower(explode(' ', $text)[0]);
        $command = explode('@', $rawCommand)[0];

        // Security check for admin reports across all bot roles
        if (in_array($command, ['/laporan', '/report', '/daily', '/weekly', '/monthly', '/laporan_harian', '/laporan_mingguan', '/laporan_bulanan', '/ringkasan', '/stats', '/all_time'], true)) {
            $adminIds = $this->config->getTelegramAdminIds();
            if (!in_array($telegramUserId, $adminIds, false)) {
                $this->sendMessage($chatId, '⛔ Akses ditolak: Menu laporan khusus administrator.');
                return ['status' => 'unauthorized'];
            }
        }

        // ==========================================
        // DEDICATED REPORT BOT HANDLERS (Admin Only)
        // Only active if explicitly targeting the dedicated report bot AND sender is an authorized admin
        // ==========================================
        $adminIds = $this->config->getTelegramAdminIds();
        $isAdmin = in_array($telegramUserId, $adminIds, false);

        if ($botRole === 'report' && $isAdmin) {
            switch ($command) {
                case '/start':
                    $welcome = "📊 *Halo, Administrator!*\n\n" .
                               "Selamat datang di *WAI-Bot Laporan & Analitik*.\n\n" .
                               "Bot ini memantau performa keuangan, omset masuk, penambahan user, dan konsumsi token AI secara otomatis.\n\n" .
                               "Silakan pilih periode laporan di bawah:";
                    $this->sendMessage($chatId, $welcome, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/start'];

                case '/laporan':
                case '/report':
                case '/ringkasan':
                    $menuText = "📊 *PUSAT ANALITIK & LAPORAN AI ROUTER*\n\n" .
                                "Pilih periode laporan keuangan & token:";
                    $this->sendMessage($chatId, $menuText, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/laporan'];

                case '/laporan_harian':
                case '/daily':
                case '/harian':
                    $rep = $this->generateReport('daily', $db);
                    $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/laporan_harian'];

                case '/laporan_mingguan':
                case '/weekly':
                case '/mingguan':
                    $rep = $this->generateReport('weekly', $db);
                    $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/laporan_mingguan'];

                case '/laporan_bulanan':
                case '/monthly':
                case '/bulanan':
                    $rep = $this->generateReport('monthly', $db);
                    $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/laporan_bulanan'];

                case '/stats':
                case '/all_time':
                    $rep = $this->generateReport('all_time', $db);
                    $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/stats'];

                default:
                    $help = "📊 *PERINTAH BOT LAPORAN ADMIN*\n\n" .
                            "• `/laporan` - Menu interaktif periode laporan\n" .
                            "• `/daily` / `/harian` - Rekap 24 jam terakhir\n" .
                            "• `/weekly` / `/mingguan` - Rekap 7 hari terakhir\n" .
                            "• `/monthly` / `/bulanan` - Rekap 30 hari terakhir\n" .
                            "• `/stats` - Total keseluruhan (all-time)\n\n" .
                            "💰 _Bot ini juga otomatis mengirimkan notifikasi instan setiap ada saldo yang dibeli oleh user._";
                    $this->sendMessage($chatId, $help, $this->getReportMenuMarkup(), 'Markdown', true);
                    return ['status' => 'handled', 'command' => '/help'];
            }
        }

        // Auto-provision user account for customer bot
        $user = $this->getOrCreateTelegramUser($telegramUserId, $username, $firstName, $db);

        switch ($command) {
            case '/start':
                $cleanName = trim(preg_replace('/[*_`\[\]()]/', '', $firstName)) ?: 'Sahabat';
                $welcome = "👋 *Halo, {$cleanName}! Selamat datang di AI Ciptaone.*\n\n" .
                           "Layanan AI Router berkecepatan tinggi dengan integrasi OpenAI & OpenRouter compatible.\n\n" .
                           "Gunakan tombol di bawah untuk transaksi instan:";
                $replyMarkup = [
                    'inline_keyboard' => [
                        [
                            ['text' => '💳 Beli Saldo API (QRIS)', 'callback_data' => 'menu:beli'],
                            ['text' => '💰 Cek Saldo', 'callback_data' => 'menu:saldo'],
                        ],
                        [
                            ['text' => '🔑 API Key Saya', 'callback_data' => 'menu:key'],
                            ['text' => 'ℹ️ Bantuan & Model', 'callback_data' => 'menu:help'],
                        ]
                    ]
                ];
                $this->sendMessage($chatId, $welcome, $replyMarkup);
                return ['status' => 'handled', 'command' => '/start'];

            case '/beli':
                $this->sendPackageSelectionMenu($chatId);
                return ['status' => 'handled', 'command' => '/beli'];

            case '/saldo':
                $this->sendBalanceMessage($chatId, (string)$user['id'], $db);
                return ['status' => 'handled', 'command' => '/saldo'];

            case '/key':
                $this->sendApiKeyMessage($chatId, (string)$user['id'], $username, $apiKeyService, $db);
                return ['status' => 'handled', 'command' => '/key'];

            case '/laporan':
            case '/report':
                $adminIds = $this->config->getTelegramAdminIds();
                if (!in_array($telegramUserId, $adminIds, false)) {
                    $this->sendMessage($chatId, '⛔ Akses ditolak: Menu laporan khusus administrator.');
                    return ['status' => 'unauthorized'];
                }
                $menuText = "📊 *PUSAT ANALITIK & LAPORAN AI ROUTER*\n\n" .
                            "Silakan pilih periode laporan yang ingin di-generate:";
                $this->sendMessage($chatId, $menuText, $this->getReportMenuMarkup());
                return ['status' => 'handled', 'command' => '/laporan'];

            case '/laporan_harian':
            case '/daily':
                $adminIds = $this->config->getTelegramAdminIds();
                if (!in_array($telegramUserId, $adminIds, false)) {
                    $this->sendMessage($chatId, '⛔ Akses ditolak: Perintah khusus administrator.');
                    return ['status' => 'unauthorized'];
                }
                $rep = $this->generateReport('daily', $db);
                $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup());
                return ['status' => 'handled', 'command' => '/laporan_harian'];

            case '/laporan_mingguan':
            case '/weekly':
                $adminIds = $this->config->getTelegramAdminIds();
                if (!in_array($telegramUserId, $adminIds, false)) {
                    $this->sendMessage($chatId, '⛔ Akses ditolak: Perintah khusus administrator.');
                    return ['status' => 'unauthorized'];
                }
                $rep = $this->generateReport('weekly', $db);
                $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup());
                return ['status' => 'handled', 'command' => '/laporan_mingguan'];

            case '/laporan_bulanan':
            case '/monthly':
                $adminIds = $this->config->getTelegramAdminIds();
                if (!in_array($telegramUserId, $adminIds, false)) {
                    $this->sendMessage($chatId, '⛔ Akses ditolak: Perintah khusus administrator.');
                    return ['status' => 'unauthorized'];
                }
                $rep = $this->generateReport('monthly', $db);
                $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup());
                return ['status' => 'handled', 'command' => '/laporan_bulanan'];

            case '/ringkasan':
            case '/stats':
                $adminIds = $this->config->getTelegramAdminIds();
                if (!in_array($telegramUserId, $adminIds, false)) {
                    $this->sendMessage($chatId, '⛔ Akses ditolak: Perintah khusus administrator.');
                    return ['status' => 'unauthorized'];
                }
                $rep = $this->generateReport('all_time', $db);
                $this->sendMessage($chatId, $rep, $this->getReportMenuMarkup());
                return ['status' => 'handled', 'command' => '/ringkasan'];

            case '/help':
            default:
                $helpText = "ℹ️ *PANDUAN PENGGUNAAN AI CIPTAONE*\n\n" .
                            "1. *Beli Saldo:* Ketik /beli lalu pilih nominal. Scan QRIS dinamis yang muncul.\n" .
                            "2. *Dapatkan API Key:* Ketik /key untuk mendapatkan key Anda.\n" .
                            "3. *Integrasi:* Gunakan Base URL:\n" .
                            "`https://ciptaone.com/v1`\n\n" .
                            "Contoh pemanggilan endpoint:\n" .
                            "• `GET https://ciptaone.com/v1/models`\n" .
                            "• `POST https://ciptaone.com/v1/chat/completions`\n\n" .
                            "Model populer: `openai/gpt-4o-mini`, `deepseek/deepseek-chat`.\n" .
                            "Butuh bantuan admin? Hubungi @admin atau support@ciptaone.com";
                $this->sendMessage($chatId, $helpText);
                return ['status' => 'handled', 'command' => '/help'];
        }
    }

    /**
     * Handle button click callbacks.
     */
    public function handleCallbackQuery(
        array $callbackQuery,
        Database|callable $dbOrCallable,
        ?TopupService $topupService = null,
        ?ApiKeyService $apiKeyService = null,
        ?DuitkuService $duitkuService = null,
        string $botRole = 'all'
    ): array {
        $callbackId = (string)($callbackQuery['id'] ?? '');
        $fromUser = (array)($callbackQuery['from'] ?? []);
        $telegramUserId = (int)($fromUser['id'] ?? 0);
        $username = (string)($fromUser['username'] ?? "user_{$telegramUserId}");
        $firstName = (string)($fromUser['first_name'] ?? 'User');
        $data = (string)($callbackQuery['data'] ?? '');
        $message = (array)($callbackQuery['message'] ?? []);
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        // Backward compatibility: If second parameter is callable, handle as admin review callback
        if (is_callable($dbOrCallable)) {
            return $this->handleAdminReviewCallback(
                $callbackId,
                $telegramUserId,
                $username,
                $data,
                $message,
                $chatId,
                $messageId,
                $dbOrCallable
            );
        }

        $db = $dbOrCallable;
        $topupService = $topupService ?? new TopupService($db, $this->config);
        $apiKeyService = $apiKeyService ?? new ApiKeyService($db);
        $duitku = $duitkuService ?? new DuitkuService($this->config, $db);

        // Auto-provision user account
        $user = $this->getOrCreateTelegramUser($telegramUserId, $username, $firstName, $db);

        // 1. Admin Analytics & Reporting Callbacks (report:daily, report:weekly, report:monthly, report:all_time)
        if (str_starts_with($data, 'report:')) {
            $adminIds = $this->config->getTelegramAdminIds();
            $useReport = ($botRole === 'report') || ($this->reportBotToken === $this->botToken);
            if (!in_array($telegramUserId, $adminIds, false)) {
                $this->answerCallback($callbackId, '⛔ Akses ditolak: Menu laporan khusus administrator.', true, $useReport);
                return ['status' => 'unauthorized'];
            }

            $period = substr($data, 7);
            if (!in_array($period, ['daily', 'weekly', 'monthly', 'all_time'], true)) {
                $period = 'daily';
            }

            $this->answerCallback($callbackId, '📊 Menyiapkan laporan...', false, $useReport);
            $reportText = $this->generateReport($period, $db);
            if ($chatId !== null) {
                $this->sendMessage($chatId, $reportText, $this->getReportMenuMarkup(), 'Markdown', $useReport);
            }
            return ['status' => 'handled', 'report' => $period];
        }

        // 2. Manual top-up approval action deprecated
        if (str_starts_with($data, 'topup:')) {
            $this->answerCallback(
                $callbackId,
                'ℹ️ Verifikasi manual telah dinonaktifkan. Seluruh pembayaran diproses otomatis oleh payment gateway.',
                true
            );
            return ['status' => 'deprecated'];
        }

        // 2. Customer Menu Actions
        if ($data === 'menu:beli') {
            $this->answerCallback($callbackId, 'Memuat paket saldo...');
            if ($chatId !== null) {
                $this->sendPackageSelectionMenu($chatId);
            }
            return ['status' => 'handled'];
        }

        if ($data === 'menu:saldo') {
            $this->answerCallback($callbackId, 'Memuat saldo...');
            if ($chatId !== null) {
                $this->sendBalanceMessage($chatId, (string)$user['id'], $db);
            }
            return ['status' => 'handled'];
        }

        if ($data === 'menu:key') {
            $this->answerCallback($callbackId, 'Memuat API key...');
            if ($chatId !== null) {
                $this->sendApiKeyMessage($chatId, (string)$user['id'], $username, $apiKeyService, $db);
            }
            return ['status' => 'handled'];
        }

        if ($data === 'menu:help') {
            $this->answerCallback($callbackId, 'Bantuan');
            if ($chatId !== null) {
                $this->handleTextMessage(['chat' => ['id' => $chatId], 'from' => $fromUser, 'text' => '/help'], $db, $apiKeyService);
            }
            return ['status' => 'handled'];
        }

        // 3. Customer Package Selection (pkg:10000, pkg:25000, pkg:50000, pkg:100000)
        if (str_starts_with($data, 'pkg:')) {
            $amount = (int)substr($data, 4);
            if ($amount < 1000) {
                $this->answerCallback($callbackId, 'Nominal tidak valid.', true);
                return ['status' => 'invalid_amount'];
            }

            if ($chatId === null) {
                $chatId = $telegramUserId;
            }

            // Immediately answer callback so Telegram client stops loading spinner
            $this->answerCallback($callbackId, 'Memproses invoice ' . number_format($amount, 0, ',', '.') . '...');

            // Priority 1: Duitku (if configured)
            if ($duitku->isConfigured()) {
                try {
                    $dateStr = gmdate('Ymd');
                    $orderId = 'ORD-DK-' . $dateStr . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                    $duitkuData = $duitku->createInvoice(
                        $orderId,
                        $amount,
                        $firstName,
                        (string)$user['email_normalized'],
                        '081234567890',
                        'SP'
                    );

                    $topupId = Security::generateId('top');
                    $nowIso = gmdate('Y-m-d H:i:s');
                    $expiredTime = (int)($duitkuData['expired_time'] ?? (time() + 3600));
                    $expiresIso = gmdate('Y-m-d H:i:s', $expiredTime);

                    $db->execute(
                        "INSERT INTO topups (
                            id, user_id, order_id, payment_provider, payment_method,
                            provider_reference, amount_minor, currency, credits_to_grant,
                            status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
                            idempotency_key, paid_at, expires_at, created_at, updated_at
                        ) VALUES (?, ?, ?, 'duitku', 'duitku_qris', ?, ?, 'IDR', ?, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)",
                        [
                            $topupId, (string)$user['id'], $orderId,
                            $duitkuData['reference'] ?? $orderId, $amount, $amount,
                            "topup_order_{$orderId}", $expiresIso, $nowIso, $nowIso
                        ]
                    );

                    $formattedAmount = number_format($amount, 0, ',', '.');
                    $caption = "🧾 *INVOICE PEMBAYARAN QRIS*\n\n" .
                               "• *Order ID:* `{$orderId}`\n" .
                               "• *Nominal:* *Rp{$formattedAmount}*\n" .
                               "• *Metode:* QRIS Dinamis (BCA, Mandiri, BRI, BNI, GoPay, OVO, Dana, ShopeePay)\n" .
                               "• *Masa Berlaku:* 60 Menit\n\n" .
                               "📱 *Petunjuk Pembayaran:*\n" .
                               "1. Buka aplikasi e-Wallet atau Mobile Banking Anda.\n" .
                               "2. Scan kode QRIS di atas dan selesaikan pembayaran.\n\n" .
                               "⚡ *Verifikasi Otomatis:* Saldo dan API Key Anda akan otomatis aktif dalam hitungan detik setelah pembayaran berhasil.";

                    $buttons = [];
                    if (!empty($duitkuData['payment_url'])) {
                        $buttons[] = [['text' => '💳 Bayar via Duitku / Browser', 'url' => $duitkuData['payment_url']]];
                    }
                    $buttons[] = [['text' => '🔄 Cek Status Pembayaran', 'callback_data' => "check:{$orderId}"]];
                    $markup = ['inline_keyboard' => $buttons];

                    if (!empty($duitkuData['qr_url'])) {
                        $this->sendPhoto($chatId, $duitkuData['qr_url'], $caption, $markup);
                    } else {
                        $this->sendMessage($chatId, $caption, $markup);
                    }
                    return ['status' => 'success', 'order_id' => $orderId, 'gateway' => 'duitku'];

                } catch (\Throwable $e) {
                    error_log("Duitku Telegram topup error: " . $e->getMessage());
                }
            }

            // Priority 2: Arahkan ke Portal Topup Web Otomatis
            $caption = "🧾 *TOP-UP SALDO AI CIPTAONE*\n\n" .
                       "• *Nominal:* *Rp" . number_format($amount, 0, ',', '.') . "*\n\n" .
                       "Silakan klik tombol *Bayar Sekarang via Web* di bawah untuk memilih metode pembayaran otomatis (QRIS atau Virtual Account Bank BCA, Mandiri, BRI, BNI).";

            $buttons = [
                [['text' => '💳 Bayar Sekarang via Web', 'url' => 'https://ai.ciptaone.site/topup']],
            ];
            $this->sendMessage($chatId, $caption, ['inline_keyboard' => $buttons]);
            return ['status' => 'directed_to_web'];
        }

        // 4. Customer Check Status Action (check:ORD-XXXX)
        if (str_starts_with($data, 'check:')) {
            $orderId = substr($data, 6);
            $topup = $db->fetchOne('SELECT status, amount_minor FROM topups WHERE order_id = ?', [$orderId]);
            if ($topup === null) {
                $this->answerCallback($callbackId, 'Invoice tidak ditemukan.', true);
                return ['status' => 'not_found'];
            }

            if ($topup['status'] === 'paid') {
                $this->answerCallback($callbackId, '✅ Pembayaran SUDAH LUNAS! Saldo telah masuk ke akun Anda.', true);
                return ['status' => 'handled'];
            }

            // If still pending, check live with Duitku in case webhook was delayed or blocked
            if ($duitku->isConfigured()) {
                try {
                    $statusRes = $duitku->checkTransactionStatus($orderId);
                    $statusCode = (string)($statusRes['statusCode'] ?? '');
                    // Duitku statusCode '00' indicates SUCCESS
                    if ($statusCode === '00') {
                        $topupService->reviewTopup($orderId, 'paid', 'gateway:duitku:status_poll');
                        $this->answerCallback($callbackId, '🎉 Pembayaran terkonfirmasi LUNAS! Saldo telah ditambahkan.', true);
                        if ($chatId !== null) {
                            $this->sendMessage($chatId, "🎉 *PEMBAYARAN BERHASIL!*\n\nTagihan `{$orderId}` berhasil dikonfirmasi lunas via Duitku. Saldo akun Anda telah bertambah.");
                        }
                        return ['status' => 'handled'];
                    }
                } catch (\Throwable) {
                    // Fall through to pending notification
                }
            }

            $this->answerCallback($callbackId, '⏳ Status: Menunggu Pembayaran. Silakan selesaikan pembayaran.', true);
            return ['status' => 'handled'];
        }

        $this->answerCallback($callbackId, 'Aksi tidak dikenal.');
        return ['status' => 'unknown'];
    }

    private function handleAdminReviewCallback(
        string $callbackId,
        int $telegramUserId,
        string $username,
        string $data,
        array $message,
        ?int $chatId,
        ?int $messageId,
        callable $topupServiceFn
    ): array {
        $adminIds = $this->config->getTelegramAdminIds();
        if (!in_array($telegramUserId, $adminIds, false)) {
            $this->answerCallback($callbackId, '⛔ Akses ditolak: Anda bukan admin terdaftar.', true);
            return ['status' => 'forbidden'];
        }

        $this->answerCallback(
            $callbackId,
            'ℹ️ Verifikasi manual telah dinonaktifkan. Seluruh pembayaran diproses otomatis oleh payment gateway.',
            true
        );
        return ['status' => 'deprecated', 'message' => 'Manual verification deprecated.'];
    }

    private function sendPackageSelectionMenu(int|string $chatId): void
    {
        $text = "💳 *PILIH PAKET SALDO AI CIPTAONE*\n\n" .
                "Silakan pilih nominal saldo token yang ingin Anda beli:\n" .
                "• *Rp 10.000* (~10.000 kredit)\n" .
                "• *Rp 25.000* (~25.000 kredit)\n" .
                "• *Rp 50.000* (~50.000 kredit)\n" .
                "• *Rp 100.000* (~100.000 kredit)\n\n" .
                "Pembayaran menggunakan *QRIS Dinamis* (didukung semua e-Wallet & Bank).";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '🪙 Rp 10.000', 'callback_data' => 'pkg:10000'],
                    ['text' => '🪙 Rp 25.000', 'callback_data' => 'pkg:25000'],
                ],
                [
                    ['text' => '🪙 Rp 50.000', 'callback_data' => 'pkg:50000'],
                    ['text' => '🪙 Rp 100.000', 'callback_data' => 'pkg:100000'],
                ],
                [
                    ['text' => '🔙 Kembali', 'callback_data' => 'menu:help'],
                ]
            ]
        ];

        $this->sendMessage($chatId, $text, $replyMarkup);
    }

    private function sendBalanceMessage(int|string $chatId, string $userId, Database $db): void
    {
        $wallet = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$userId]);
        $balance = (int)($wallet['balance_credits'] ?? 0);
        $formattedBal = number_format($balance, 0, ',', '.');

        $text = "💰 *INFORMASI SALDO ANDA*\n\n" .
                "• *Saldo Tersedia:* {$formattedBal} kredit\n\n" .
                "Ketik /beli untuk menambah saldo kapan saja via QRIS.";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '💳 Beli Saldo Sekarang', 'callback_data' => 'menu:beli'],
                    ['text' => '🔑 Cek API Key', 'callback_data' => 'menu:key'],
                ]
            ]
        ];

        $this->sendMessage($chatId, $text, $replyMarkup);
    }

    private function sendApiKeyMessage(
        int|string $chatId,
        string $userId,
        string $username,
        ApiKeyService $apiKeyService,
        Database $db
    ): void {
        $existing = $db->fetchOne(
            'SELECT key_prefix, created_at FROM api_keys WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC LIMIT 1',
            [$userId]
        );

        if ($existing !== null) {
            $prefix = (string)$existing['key_prefix'];
            $created = substr((string)$existing['created_at'], 0, 10);
            $text = "🔑 *API KEY AKTIF ANDA*\n\n" .
                    "• *Key:* `{$prefix}••••••••••••`\n" .
                    "• *Dibuat:* {$created}\n\n" .
                    "🌐 *Base URL:*\n`https://ciptaone.com/v1`\n\n" .
                    "💡 Gunakan key ini di header `Authorization: Bearer <KEY>` pada Cursor, Cline, Next.js, atau Python SDK.";
        } else {
            // Auto create first key
            $newKey = $apiKeyService->createApiKey($userId, "Telegram Key (@{$username})");
            $rawKey = $newKey['key'];
            $text = "🎉 *API KEY PERTAMA ANDA BERHASIL DIBUAT!*\n\n" .
                    "🔑 *API Key:* \n`{$rawKey}`\n\n" .
                    "⚠️ *PENTING:* Simpan API key ini sekarang di tempat aman. Key tidak akan ditampilkan penuh lagi demi alasan keamanan.\n\n" .
                    "🌐 *Base URL:*\n`https://ciptaone.com/v1`";
        }

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 Cek Saldo', 'callback_data' => 'menu:saldo'],
                    ['text' => '💳 Beli Saldo', 'callback_data' => 'menu:beli'],
                ]
            ]
        ];

        $this->sendMessage($chatId, $text, $replyMarkup);
    }

    public function getOrCreateTelegramUser(
        int $telegramUserId,
        string $username,
        string $firstName,
        Database $db
    ): array {
        $emailNormalized = "tg_{$telegramUserId}@ciptaone.com";
        $user = $db->fetchOne('SELECT * FROM users WHERE email_normalized = ?', [$emailNormalized]);
        if ($user !== null) {
            return $user;
        }

        $userId = Security::generateId('usr');
        $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $nowIso = gmdate('Y-m-d H:i:s');

        $db->execute(
            'INSERT INTO users (id, email_normalized, password_hash, email_verified_at, status, role, created_at, updated_at)
             VALUES (?, ?, ?, ?, "active", "user", ?, ?)',
            [$userId, $emailNormalized, $passwordHash, $nowIso, $nowIso, $nowIso]
        );

        $db->execute(
            'INSERT INTO wallets (user_id, balance_credits, updated_at)
             VALUES (?, 0, ?)',
            [$userId, $nowIso]
        );

        return $db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = 'Markdown',
        bool $useReportBot = false
    ): ?array {
        $base = $useReportBot ? ($this->reportBaseUrl ?? $this->baseUrl) : $this->baseUrl;
        if ($base === null) {
            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $res = $this->sendTelegramRequest('/sendMessage', $payload, $useReportBot);
        if (isset($res['ok']) && $res['ok'] === false && isset($res['description']) && str_contains($res['description'], "can't parse entities")) {
            unset($payload['parse_mode']);
            return $this->sendTelegramRequest('/sendMessage', $payload, $useReportBot);
        }
        return $res;
    }

    public function sendPhoto(
        int|string $chatId,
        string $photoUrl,
        string $caption = '',
        ?array $replyMarkup = null,
        bool $useReportBot = false
    ): ?array {
        $base = $useReportBot ? ($this->reportBaseUrl ?? $this->baseUrl) : $this->baseUrl;
        if ($base === null) {
            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $res = $this->sendTelegramRequest('/sendPhoto', $payload, $useReportBot);
        if (isset($res['ok']) && $res['ok'] === false && isset($res['description']) && str_contains($res['description'], "can't parse entities")) {
            unset($payload['parse_mode']);
            return $this->sendTelegramRequest('/sendPhoto', $payload, $useReportBot);
        }
        return $res;
    }

    private function answerCallback(string $callbackId, string $text, bool $alert = false, bool $useReportBot = false): void
    {
        $base = $useReportBot ? ($this->reportBaseUrl ?? $this->baseUrl) : $this->baseUrl;
        if ($base === null || $callbackId === '') {
            return;
        }

        $this->sendTelegramRequest('/answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert,
        ], $useReportBot);
    }

    private function editMessageText(int|string $chatId, int $messageId, string $text, bool $useReportBot = false): void
    {
        $base = $useReportBot ? ($this->reportBaseUrl ?? $this->baseUrl) : $this->baseUrl;
        if ($base === null) {
            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        $res = $this->sendTelegramRequest('/editMessageText', $payload, $useReportBot);
        if (isset($res['ok']) && $res['ok'] === false && isset($res['description']) && str_contains($res['description'], "can't parse entities")) {
            unset($payload['parse_mode']);
            $this->sendTelegramRequest('/editMessageText', $payload, $useReportBot);
        }
    }

    private function sendTelegramRequest(string $endpoint, array $payload, bool $useReportBot = false): ?array
    {
        $base = $useReportBot ? ($this->reportBaseUrl ?? $this->baseUrl) : $this->baseUrl;
        if ($base === null) {
            return null;
        }

        $ch = curl_init("{$base}{$endpoint}");
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
