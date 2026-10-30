<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Services\ApiKeyService;
use Ciptaone\Services\AuthService;
use Ciptaone\Services\PaymentAdapters\DuitkuPaymentAdapter;
use Ciptaone\Services\Payments\DuitkuService;
use Ciptaone\Services\TelegramBotService;
use Ciptaone\Services\TopupService;
use Ciptaone\Services\WalletService;

function test_topup_telegram_suite(Database $db): void
{
    $config = Config::getInstance();
    $config->set('TELEGRAM_ADMIN_IDS', '12345678,98765432');

    $authService = new AuthService($db, $config);
    $walletService = new WalletService($db);
    $apiKeyService = new ApiKeyService($db);
    $topupService = new TopupService($db, $config, $walletService);
    $telegramBot = new TelegramBotService($config);

    $user = $authService->register('automated_tester_' . bin2hex(random_bytes(4)) . '@ciptaone.com', 'TopupPass12345#');
    $userId = $user['id'];

    // 1. Minimum amount validation
    $minCaught = false;
    try {
        $topupService->createTopup($userId, $user['email'], 5000, 'duitku');
    } catch (AppError $ae) {
        $minCaught = true;
        assert($ae->getStatusCode() === 400, 'Minimum topup validation did not return 400');
    }
    assert($minCaught, 'Allowed topup below Rp10.000');

    // 2. Disallow manual payment methods
    $manualTransferCaught = false;
    try {
        $topupService->createTopup($userId, $user['email'], 50000, 'manual_transfer');
    } catch (AppError $ae) {
        $manualTransferCaught = true;
        assert(str_contains($ae->getMessage(), 'manual'), 'Manual transfer error message mismatch');
    }
    assert($manualTransferCaught, 'manual_transfer was not rejected');

    $manualQrisCaught = false;
    try {
        $topupService->createTopup($userId, $user['email'], 50000, 'manual_qris');
    } catch (AppError $ae) {
        $manualQrisCaught = true;
        assert(str_contains($ae->getMessage(), 'manual'), 'Manual qris error message mismatch');
    }
    assert($manualQrisCaught, 'manual_qris was not rejected');

    // 3. Disallow manual proof uploads
    $uploadCaught = false;
    try {
        $topupService->uploadProof('INV-ANY', $userId, 'proof.jpg', 'image/jpeg', 'fakebytes');
    } catch (AppError $ae) {
        $uploadCaught = true;
        assert(str_contains($ae->getMessage(), 'dihapus') || str_contains($ae->getMessage(), 'manual'), 'Upload proof should inform manual upload is disabled/removed');
    }
    assert($uploadCaught, 'uploadProof was not rejected');

    // 4. Create automated topup (Duitku QRIS / VA mock)
    $duitkuMock = new class($config, $db) extends DuitkuService {
        public function isConfigured(): bool { return true; }
        public function createInvoice(
            string $merchantOrderId,
            int $amount,
            string $customerName,
            string $customerEmail,
            string $customerPhone = '081234567890',
            string $paymentMethod = '',
            array $items = []
        ): array {
            return [
                'reference' => 'DK-REF-TEST',
                'order_id' => $merchantOrderId,
                'amount' => $amount,
                'payment_url' => 'https://sandbox.duitku.com/checkout/test',
                'qr_url' => 'https://duitku.com/qr/test.png',
                'qr_string' => '00020101021226...',
                'va_number' => '88880001234567',
                'expired_time' => time() + 3600,
            ];
        }
    };
    $topupServiceWithDuitku = new TopupService(
        $db,
        $config,
        $walletService,
        $telegramBot,
        null,
        new DuitkuPaymentAdapter($duitkuMock, $config, $db)
    );

    $topup = $topupServiceWithDuitku->createTopup($userId, $user['email'], 50000, 'duitku_qris');
    assert(!empty($topup['order_id']), 'Order ID is empty');
    assert(str_starts_with($topup['order_id'], 'INV-'), 'Order ID format invalid');
    assert($topup['credits_to_grant'] === 50000, 'Credits to grant mismatch');
    assert($topup['status'] === 'pending', 'Initial status is not pending');
    assert(!empty($topup['qr_url']), 'QR URL is missing in automated QRIS');

    $orderId = $topup['order_id'];

    // 5. Automated settlement via webhook callback reviewTopup
    $settleResult = $topupServiceWithDuitku->reviewTopup($orderId, 'paid', 'gateway:duitku:webhook');
    assert($settleResult['status'] === 'paid', 'Settlement failed');
    assert($walletService->getWallet($userId)['balance_credits'] === 50000, 'Wallet was not credited with 50000');

    // 6. Idempotency: duplicate settlement must not double-credit
    $settleDuplicate = $topupServiceWithDuitku->reviewTopup($orderId, 'paid', 'gateway:duitku:webhook');
    assert($walletService->getWallet($userId)['balance_credits'] === 50000, 'Duplicate settlement credited wallet again!');

    // 7. Verify Telegram Reporting Output
    $dailyReport = $telegramBot->generateReport('daily', $db);
    assert(str_contains($dailyReport, 'LAPORAN AI ROUTER (HARIAN'), 'Daily report header missing');
    assert(str_contains($dailyReport, 'PENGGUNA & REGISTRASI'), 'New users section missing');
    assert(str_contains($dailyReport, 'UANG MASUK (REVENUE)'), 'Revenue section missing');
    assert(str_contains($dailyReport, 'TOKEN KELUAR & PEMAKAIAN AI'), 'Token usage section missing');
    assert(str_contains($dailyReport, 'SISA TOKEN & SALDO PENGGUNA'), 'Remaining balance section missing');

    $weeklyReport = $telegramBot->generateReport('weekly', $db);
    assert(str_contains($weeklyReport, 'MINGGUAN (7 Hari Terakhir)'), 'Weekly report title missing');

    $monthlyReport = $telegramBot->generateReport('monthly', $db);
    assert(str_contains($monthlyReport, 'BULANAN (30 Hari Terakhir)'), 'Monthly report title missing');

    $allTimeReport = $telegramBot->generateReport('all_time', $db);
    assert(str_contains($allTimeReport, 'SELURUH WAKTU'), 'All time report title missing');

    // 8. Telegram Bot Text Commands Security Check
    // Unauthorized user trying to get report
    $unauthMsg = [
        'chat' => ['id' => 99999999],
        'from' => ['id' => 99999999, 'username' => 'hacker'],
        'text' => '/laporan_harian',
    ];
    $unauthRes = $telegramBot->handleTextMessage($unauthMsg, $db, $apiKeyService);
    assert($unauthRes['status'] === 'unauthorized', 'Unauthorized user accessed /laporan_harian');

    // Allowlisted Admin accessing reports
    $adminMsg = [
        'chat' => ['id' => 12345678],
        'from' => ['id' => 12345678, 'username' => 'superadmin'],
        'text' => '/laporan',
    ];
    $adminRes = $telegramBot->handleTextMessage($adminMsg, $db, $apiKeyService);
    assert($adminRes['status'] === 'handled', 'Admin failed to access /laporan');

    $adminDailyMsg = [
        'chat' => ['id' => 12345678],
        'from' => ['id' => 12345678, 'username' => 'superadmin'],
        'text' => '/daily',
    ];
    $adminDailyRes = $telegramBot->handleTextMessage($adminDailyMsg, $db, $apiKeyService);
    assert($adminDailyRes['status'] === 'handled', 'Admin failed to access /daily');

    // 9. Telegram Callback Queries for Reports
    $unauthCb = [
        'id' => 'cb_unauth_rep',
        'from' => ['id' => 99999999, 'username' => 'intruder'],
        'data' => 'report:daily',
        'message' => ['chat' => ['id' => 99999999], 'message_id' => 200],
    ];
    $unauthCbRes = $telegramBot->handleCallbackQuery($unauthCb, $db, $topupServiceWithDuitku, $apiKeyService);
    assert($unauthCbRes['status'] === 'unauthorized', 'Unauthorized user got report callback');

    $adminCb = [
        'id' => 'cb_admin_rep',
        'from' => ['id' => 12345678, 'username' => 'superadmin'],
        'data' => 'report:daily',
        'message' => ['chat' => ['id' => 12345678], 'message_id' => 201],
    ];
    $adminCbRes = $telegramBot->handleCallbackQuery($adminCb, $db, $topupServiceWithDuitku, $apiKeyService);
    assert($adminCbRes['status'] === 'handled', 'Admin report callback failed');

    // 10. Deprecated verification callback handling
    $deprecatedCb = [
        'id' => 'cb_old_verify',
        'from' => ['id' => 12345678, 'username' => 'superadmin'],
        'data' => "topup:approve:{$orderId}",
        'message' => ['chat' => ['id' => 12345678], 'message_id' => 202],
    ];
    $deprecatedRes = $telegramBot->handleCallbackQuery($deprecatedCb, $db, $topupServiceWithDuitku, $apiKeyService);
    assert($deprecatedRes['status'] === 'deprecated', 'Old verification button should be marked deprecated');

    // 11. Instant Payment Notification
    $telegramBot->notifyAdminPaymentReceived($orderId, $user['email'], 50000, 'duitku_qris');
}
