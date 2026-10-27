<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\Request;
use Ciptaone\Core\Security;
use Ciptaone\Services\ApiKeyService;
use Ciptaone\Services\PaymentAdapters\TripayPaymentAdapter;
use Ciptaone\Services\Payments\TripayService;
use Ciptaone\Services\TelegramBotService;
use Ciptaone\Services\TopupService;
use Ciptaone\Services\WalletService;
use Ciptaone\Controllers\WebhookController;

function test_tripay_payment_suite(Database $db): void {
    $config = Config::getInstance();
    $merchantCode = 'T12345';
    $apiKey = 'DEV-tripay-test-api-key-12345';
    $privateKey = 'secret-private-key-tripay-67890';

    // 1. Configure settings in database
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("TRIPAY_MERCHANT_CODE", ?, ?)', [$merchantCode, gmdate('Y-m-d H:i:s')]);
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("TRIPAY_API_KEY", ?, ?)', [$apiKey, gmdate('Y-m-d H:i:s')]);
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("TRIPAY_PRIVATE_KEY", ?, ?)', [$privateKey, gmdate('Y-m-d H:i:s')]);
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("TRIPAY_MODE", "sandbox", ?)', [gmdate('Y-m-d H:i:s')]);

    $tripayService = new TripayService($config, $db);
    assert($tripayService->isConfigured() === true, 'Tripay should be configured');
    assert($tripayService->getMode() === 'sandbox', 'Tripay mode should be sandbox');
    assert(str_contains($tripayService->getBaseUrl(), 'api-sandbox'), 'Base URL should contain api-sandbox');

    // 2. Test Callback Signature Verification & Tampering Protection
    $sampleBody = json_encode([
        'reference' => 'DEV-T123456789',
        'merchant_ref' => 'ORD-TEST-001',
        'payment_method' => 'QRIS',
        'total_amount' => 25000,
        'status' => 'PAID',
    ]);

    $validSignature = hash_hmac('sha256', $sampleBody, $privateKey);
    $invalidSignature = hash_hmac('sha256', $sampleBody, 'wrong-private-key');

    assert($tripayService->verifyCallbackSignature($sampleBody, $validSignature) === true, 'Valid signature must pass');
    assert($tripayService->verifyCallbackSignature($sampleBody, $invalidSignature) === false, 'Invalid signature must fail');
    assert($tripayService->verifyCallbackSignature($sampleBody . ' ', $validSignature) === false, 'Tampered body must fail');

    // 3. Test TripayPaymentAdapter
    $adapter = new TripayPaymentAdapter($tripayService, $config, $db);
    $verified = $adapter->verifyWebhook(['X-Callback-Signature' => $validSignature], $sampleBody);

    assert($verified['order_id'] === 'ORD-TEST-001', 'Order ID should match');
    assert($verified['status'] === 'paid', 'Status should be normalized to paid');
    assert($verified['amount'] === 25000, 'Amount should be 25000');

    // 4. Test Telegram Bot Auto-Provisioning & Interactive Commands
    $botService = new TelegramBotService($config);
    $apiKeyService = new ApiKeyService($db);
    $walletService = new WalletService($db);
    $topupService = new TopupService($db, $config, $walletService);

    $tgUserId = 998877;
    $tgUsername = 'testbuyer';
    $tgFirstName = 'Fahmi Tester';

    // Handle /start command
    $startResult = $botService->handleTextMessage([
        'chat' => ['id' => $tgUserId],
        'from' => ['id' => $tgUserId, 'username' => $tgUsername, 'first_name' => $tgFirstName],
        'text' => '/start',
    ], $db, $apiKeyService);

    assert($startResult['status'] === 'handled', '/start should be handled');

    // Verify user created
    $user = $db->fetchOne('SELECT * FROM users WHERE email_normalized = ?', ["tg_{$tgUserId}@ciptaone.com"]);
    assert($user !== null, 'Telegram user should be auto-provisioned in database');
    assert($user['role'] === 'user', 'User role should be user');

    $wallet = $db->fetchOne('SELECT * FROM wallets WHERE user_id = ?', [$user['id']]);
    assert($wallet !== null && (int)$wallet['balance_credits'] === 0, 'Initial wallet balance should be 0');

    // Handle /key command
    $keyResult = $botService->handleTextMessage([
        'chat' => ['id' => $tgUserId],
        'from' => ['id' => $tgUserId, 'username' => $tgUsername, 'first_name' => $tgFirstName],
        'text' => '/key',
    ], $db, $apiKeyService);

    assert($keyResult['status'] === 'handled', '/key should be handled');
    $keys = $apiKeyService->listUserApiKeys($user['id']);
    assert(count($keys) === 1, 'API key should be created for user');
    assert(str_starts_with($keys[0]['key_prefix'], 'sk-live-'), 'API key prefix should be sk-live-');

    // 5. Test Topup Lifecycle with Tripay Payment & Instant Webhook Settlement
    $orderId = 'ORD-TP-TEST-' . bin2hex(random_bytes(3));
    $topupId = Security::generateId('top');
    $nowIso = gmdate('Y-m-d H:i:s');
    $expiresIso = gmdate('Y-m-d H:i:s', time() + 1800);

    $db->execute(
        "INSERT INTO topups (
            id, user_id, order_id, payment_provider, payment_method,
            provider_reference, amount_minor, currency, credits_to_grant,
            status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
            idempotency_key, paid_at, expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, 'tripay', 'tripay_qris', 'DEV-REF-123', 50000, 'IDR', 50000, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)",
        [$topupId, $user['id'], $orderId, "topup_order_{$orderId}", $expiresIso, $nowIso, $nowIso]
    );

    // Simulate Tripay Webhook POST
    $webhookPayload = json_encode([
        'reference' => 'DEV-REF-123',
        'merchant_ref' => $orderId,
        'payment_method' => 'QRIS',
        'total_amount' => 50000,
        'status' => 'PAID',
    ]);
    $webhookSig = hash_hmac('sha256', $webhookPayload, $privateKey);

    $req = new Request(
        'POST',
        '/api/v1/webhooks/tripay',
        ['x-callback-signature' => $webhookSig, 'content-type' => 'application/json'],
        [],
        [],
        $webhookPayload
    );

    $ctrl = new WebhookController($db, $botService, $topupService);

    // Capture JSON output without exiting
    \Ciptaone\Core\Response::$exitOnSend = false;
    ob_start();
    $ctrl->handlePaymentGatewayWebhook($req, ['gateway' => 'tripay']);
    $out = ob_get_clean();

    assert(str_contains($out, '"status":"success"'), 'Webhook should return success');

    // Verify topup status updated to 'paid'
    $topupRecord = $db->fetchOne('SELECT status, paid_at FROM topups WHERE order_id = ?', [$orderId]);
    assert($topupRecord['status'] === 'paid', 'Topup status should be updated to paid');
    assert(!empty($topupRecord['paid_at']), 'paid_at should be populated');

    // Verify user balance credited atomically
    $updatedWallet = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$user['id']]);
    assert((int)$updatedWallet['balance_credits'] === 50000, 'Wallet balance should now be 50.000 credits');

    // Verify webhook idempotency (duplicate call should be ignored)
    ob_start();
    $ctrl->handlePaymentGatewayWebhook($req, ['gateway' => 'tripay']);
    $dupOut = ob_get_clean();
    assert(str_contains($dupOut, '"status":"ignored"'), 'Duplicate webhook should be ignored gracefully');
    \Ciptaone\Core\Response::$exitOnSend = true;
}
