<?php
declare(strict_types=1);

namespace Ciptaone\Services;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\Security;
use Ciptaone\Services\Payments\DuitkuService;
use Ciptaone\Services\Payments\TripayService;

/**
 * Telegram Bot integration:
 * 1. Admin operations: sends invoice review notifications to admin Telegram IDs with inline buttons.
 * 2. Customer operations: interactive commands (/start, /beli, /saldo, /key), Dynamic QRIS display, and instant payment delivery notifications.
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

    /**
     * Send notification to admin IDs when a new manual top-up requires review.
     */
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

        $methodLabel = match ($paymentMethod) {
            'manual_transfer' => 'Transfer Bank Manual',
            'manual_qris' => 'QRIS Manual',
            'tripay_qris' => 'QRIS Dinamis (Tripay)',
            'duitku' => 'Duitku Full Service (QRIS & VA)',
            'duitku_qris' => 'QRIS Dinamis (Duitku)',
            default => $paymentMethod,
        };

        $formattedAmount = number_format($amountMinor, 0, ',', '.');
        $formattedCredits = number_format($creditsToGrant, 0, ',', '.');
        $proofStatus = $hasProof ? 'Sudah diunggah' : 'Belum diunggah';

        $text = "🔔 *PERMINTAAN TOP-UP BARU*\n\n" .
                "• *Order ID:* `{$orderId}`\n" .
                "• *Pengguna:* `{$userEmail}`\n" .
                "• *Nominal:* Rp{$formattedAmount}\n" .
                "• *Kredit:* {$formattedCredits} kredit\n" .
                "• *Metode:* {$methodLabel}\n" .
                "• *Bukti Transfer:* {$proofStatus}\n\n" .
                "⚠️ *Perhatian:* Pastikan dana benar-benar masuk pada mutasi rekening resmi.";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Setujui', 'callback_data' => "topup:approve:{$orderId}"],
                    ['text' => '❌ Tolak', 'callback_data' => "topup:reject:{$orderId}"],
                ]
            ]
        ];

        foreach ($adminIds as $adminId) {
            $this->sendMessage($adminId, $text, $replyMarkup);
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
        ?TripayService $tripayService = null,
        ?DuitkuService $duitkuService = null
    ): array {
        // 1. Handle Inline Button Callback Queries
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handleCallbackQuery(
                $update['callback_query'],
                $db,
                $topupService,
                $apiKeyService,
                $tripayService,
                $duitkuService
            );
        }

        // 2. Handle Text Messages & Slash Commands
        if (isset($update['message']) && is_array($update['message'])) {
            return $this->handleTextMessage(
                $update['message'],
                $db,
                $apiKeyService
            );
        }

        return ['status' => 'ignored'];
    }

    /**
     * Handle incoming text messages (/start, /beli, /saldo, /key, /help).
     */
    public function handleTextMessage(
        array $message,
        Database $db,
        ApiKeyService $apiKeyService
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

        // Auto-provision user account
        $user = $this->getOrCreateTelegramUser($telegramUserId, $username, $firstName, $db);

        $command = strtolower(explode(' ', $text)[0]);

        switch ($command) {
            case '/start':
                $welcome = "👋 *Halo, {$firstName}! Selamat datang di AI Ciptaone.*\n\n" .
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
        ?TripayService $tripayService = null,
        ?DuitkuService $duitkuService = null
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
        $tripay = $tripayService ?? new TripayService($this->config, $db);
        $duitku = $duitkuService ?? new DuitkuService($this->config, $db);

        // Auto-provision user account
        $user = $this->getOrCreateTelegramUser($telegramUserId, $username, $firstName, $db);

        // 1. Admin top-up approval action (topup:approve:ORD / topup:reject:ORD)
        if (str_starts_with($data, 'topup:')) {
            return $this->handleAdminReviewCallback(
                $callbackId,
                $telegramUserId,
                $username,
                $data,
                $message,
                $chatId,
                $messageId,
                function (string $orderId, string $action, string $adminUserId, ?string $rejectionReason) use ($topupService) {
                    return $topupService->reviewTopup($orderId, $action, $adminUserId, $rejectionReason);
                }
            );
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
                return ['status' => 'no_chat_id'];
            }

            // Priority: Duitku (if configured) or Tripay
            if ($duitku->isConfigured()) {
                $this->answerCallback($callbackId, 'Membuat tagihan Duitku (QRIS & VA)...');
                try {
                    $dateStr = gmdate('Ymd');
                    $orderId = 'ORD-DK-' . $dateStr . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                    $duitkuData = $duitku->createInvoice(
                        $orderId,
                        $amount,
                        $firstName,
                        (string)$user['email_normalized']
                    );

                    $topupId = Security::generateId('top');
                    $nowIso = gmdate('Y-m-d H:i:s');
                    $expiresIso = gmdate('Y-m-d H:i:s', (int)$duitkuData['expired_time']);

                    $db->execute(
                        "INSERT INTO topups (
                            id, user_id, order_id, payment_provider, payment_method,
                            provider_reference, amount_minor, currency, credits_to_grant,
                            status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
                            idempotency_key, paid_at, expires_at, created_at, updated_at
                        ) VALUES (?, ?, ?, 'duitku', 'duitku', ?, ?, 'IDR', ?, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)",
                        [
                            $topupId, (string)$user['id'], $orderId,
                            $duitkuData['reference'], $amount, $amount,
                            "topup_order_{$orderId}", $expiresIso, $nowIso, $nowIso
                        ]
                    );

                    $formattedAmount = number_format($amount, 0, ',', '.');
                    $caption = "🧾 *INVOICE PEMBAYARAN DUITKU*\n\n" .
                               "• *Order ID:* `{$orderId}`\n" .
                               "• *Nominal:* *Rp{$formattedAmount}*\n" .
                               "• *Metode:* QRIS & Virtual Account (BCA, Mandiri, BRI, BNI, dll)\n" .
                               "• *Masa Berlaku:* 60 Menit\n\n" .
                               "📱 *Petunjuk Pembayaran:*\n" .
                               "Klik tombol *Bayar Sekarang* di bawah untuk membuka halaman pembayaran resmi Duitku atau scan QRIS bila tersedia.\n\n" .
                               "⚡ *Verifikasi Otomatis:* Saldo dan API Key Anda akan otomatis aktif dalam hitungan detik setelah pembayaran berhasil.";

                    $buttons = [];
                    if (!empty($duitkuData['payment_url'])) {
                        $buttons[] = [['text' => '💳 Bayar Sekarang via Duitku', 'url' => $duitkuData['payment_url']]];
                    }
                    $buttons[] = [['text' => '🔄 Cek Status Pembayaran', 'callback_data' => "check:{$orderId}"]];
                    $markup = ['inline_keyboard' => $buttons];

                    if (!empty($duitkuData['qr_url'])) {
                        $this->sendPhoto($chatId, $duitkuData['qr_url'], $caption, $markup);
                    } else {
                        $this->sendMessage($chatId, $caption, $markup);
                    }
                    return ['status' => 'success', 'order_id' => $orderId];

                } catch (\Throwable $e) {
                    $this->sendMessage($chatId, "❌ Gagal membuat transaksi Duitku: {$e->getMessage()}");
                    return ['status' => 'error', 'error' => $e->getMessage()];
                }
            } elseif ($tripay->isConfigured()) {
                $this->answerCallback($callbackId, 'Membuat QRIS Dinamis Tripay...');
                try {
                    // Generate unique order ID
                    $dateStr = gmdate('Ymd');
                    $orderId = 'ORD-TP-' . $dateStr . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                    // Request Tripay Dynamic QRIS
                    $tripayData = $tripay->createQrisTransaction(
                        $orderId,
                        $amount,
                        $firstName,
                        (string)$user['email_normalized']
                    );

                    // Insert into topups table
                    $topupId = Security::generateId('top');
                    $nowIso = gmdate('Y-m-d H:i:s');
                    $expiresIso = gmdate('Y-m-d H:i:s', (int)$tripayData['expired_time']);

                    $db->execute(
                        "INSERT INTO topups (
                            id, user_id, order_id, payment_provider, payment_method,
                            provider_reference, amount_minor, currency, credits_to_grant,
                            status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
                            idempotency_key, paid_at, expires_at, created_at, updated_at
                        ) VALUES (?, ?, ?, 'tripay', 'tripay_qris', ?, ?, 'IDR', ?, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)",
                        [
                            $topupId, (string)$user['id'], $orderId,
                            $tripayData['reference'], $amount, $amount,
                            "topup_order_{$orderId}", $expiresIso, $nowIso, $nowIso
                        ]
                    );

                    $formattedAmount = number_format($amount, 0, ',', '.');
                    $caption = "🧾 *INVOICE QRIS DINAMIS*\n\n" .
                               "• *Order ID:* `{$orderId}`\n" .
                               "• *Nominal:* *Rp{$formattedAmount}*\n" .
                               "• *Masa Berlaku:* 15 Menit\n\n" .
                               "📱 *Petunjuk Pembayaran:*\n" .
                               "1. Buka aplikasi e-Wallet atau Mobile Banking Anda (BCA, Mandiri, BRI, GoPay, OVO, Dana, ShopeePay).\n" .
                               "2. Scan QRIS di atas dan periksa nominalnya.\n" .
                               "3. Selesaikan pembayaran.\n\n" .
                               "⚡ *Verifikasi Otomatis:* Saldo dan API Key Anda akan otomatis aktif dalam 3 detik setelah pembayaran berhasil.";

                    $checkMarkup = [
                        'inline_keyboard' => [
                            [
                                ['text' => '🔄 Cek Status Pembayaran', 'callback_data' => "check:{$orderId}"],
                            ]
                        ]
                    ];

                    $this->sendPhoto($chatId, $tripayData['qr_url'], $caption, $checkMarkup);
                    return ['status' => 'success', 'order_id' => $orderId];

                } catch (\Throwable $e) {
                    $this->sendMessage($chatId, "❌ Gagal membuat transaksi QRIS: {$e->getMessage()}");
                    return ['status' => 'error', 'error' => $e->getMessage()];
                }
            } else {
                $this->sendMessage(
                    $chatId,
                    "⚠️ *Layanan Pembayaran Otomatis Sedang Disiapkan*\n\n" .
                    "Payment gateway (Duitku / Tripay) sedang dalam konfigurasi oleh admin. Silakan hubungi admin atau gunakan opsi pembayaran manual di website."
                );
                return ['status' => 'payment_gateway_not_configured'];
            }
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
            } else {
                $this->answerCallback($callbackId, '⏳ Status: Menunggu Pembayaran. Silakan scan QRIS di atas.', true);
            }
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
        if (!in_array($telegramUserId, $adminIds, true)) {
            $this->answerCallback($callbackId, '⛔ Akses ditolak: Anda bukan admin terdaftar.', true);
            return ['status' => 'forbidden'];
        }

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

        $adminIdentifier = "tg_{$telegramUserId}_{$username}";
        $statusToSet = $action === 'approve' ? 'paid' : 'rejected';
        $rejectionReason = $action === 'reject' ? 'Ditolak oleh admin via Telegram' : null;

        try {
            $result = $topupServiceFn($orderId, $statusToSet, $adminIdentifier, $rejectionReason);

            $respText = $action === 'approve'
                ? "✅ Order {$orderId} berhasil DISETUJUI dan saldo dikreditkan."
                : "❌ Order {$orderId} berhasil DITOLAK.";

            $this->answerCallback($callbackId, $respText, true);

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

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): ?array
    {
        if ($this->baseUrl === null) {
            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->sendTelegramRequest('/sendMessage', $payload);
    }

    public function sendPhoto(int|string $chatId, string $photoUrl, string $caption = '', ?array $replyMarkup = null): ?array
    {
        if ($this->baseUrl === null) {
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

        return $this->sendTelegramRequest('/sendPhoto', $payload);
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
