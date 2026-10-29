<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\Request;
use Ciptaone\Core\Security;
use Ciptaone\Services\PaymentAdapters\DuitkuPaymentAdapter;
use Ciptaone\Services\Payments\DuitkuService;
use Ciptaone\Services\TopupService;
use Ciptaone\Services\WalletService;
use Ciptaone\Controllers\WebhookController;

function test_duitku_payment_suite(Database $db): void {
    $config = Config::getInstance();
    $merchantCode = 'D12345';
    $apiKey = 'secret-api-key-duitku-abcdef123456';

    // 1. Configure settings in database
    $nowIso = gmdate('Y-m-d H:i:s');
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("DUITKU_MERCHANT_CODE", ?, ?)', [$merchantCode, $nowIso]);
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("DUITKU_API_KEY", ?, ?)', [$apiKey, $nowIso]);
    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES ("DUITKU_MODE", "sandbox", ?)', [$nowIso]);

    $duitkuService = new DuitkuService($config, $db);
    assert($duitkuService->isConfigured() === true, 'Duitku should be configured');
    assert($duitkuService->getMode() === 'sandbox', 'Duitku mode should be sandbox');
    assert(str_contains($duitkuService->getBaseUrl(), 'sandbox.duitku.com'), 'Base URL should contain sandbox.duitku.com');

    // 2. Test Callback Signature Verification & Tampering Protection
    $orderId = 'INV-TEST-DK-' . bin2hex(random_bytes(3));
    $amount = 50000;
    $validSignature = md5($merchantCode . $amount . $orderId . $apiKey);
    $invalidSignature = md5($merchantCode . $amount . $orderId . 'wrong-api-key');

    assert($duitkuService->verifyCallbackSignature($merchantCode, (string)$amount, $orderId, $validSignature) === true, 'Valid Duitku signature must pass');
    assert($duitkuService->verifyCallbackSignature($merchantCode, (string)$amount, $orderId, $invalidSignature) === false, 'Invalid Duitku signature must fail');
    assert($duitkuService->verifyCallbackSignature($merchantCode, (string)($amount + 1000), $orderId, $validSignature) === false, 'Tampered amount must fail signature check');

    // 3. Test DuitkuPaymentAdapter Webhook Parsing (Form-urlencoded and JSON)
    $adapter = new DuitkuPaymentAdapter($duitkuService, $config, $db);

    // Test form-urlencoded format
    $formPayload = http_build_query([
        'merchantCode' => $merchantCode,
        'amount' => (string)$amount,
        'merchantOrderId' => $orderId,
        'signature' => $validSignature,
        'resultCode' => '00',
        'reference' => 'REF-DK-998877',
        'paymentCode' => 'SP',
    ]);
    $verifiedForm = $adapter->verifyWebhook([], $formPayload);
    assert($verifiedForm['order_id'] === $orderId, 'Order ID must match parsed form');
    assert($verifiedForm['status'] === 'paid', 'Status must be paid for resultCode 00');
    assert($verifiedForm['amount'] === 50000, 'Amount must be 50000');

    // Test JSON format
    $jsonPayload = json_encode([
        'merchantCode' => $merchantCode,
        'amount' => (string)$amount,
        'merchantOrderId' => $orderId,
        'signature' => $validSignature,
        'resultCode' => '00',
        'reference' => 'REF-DK-998877',
        'paymentCode' => 'BC',
    ]);
    $verifiedJson = $adapter->verifyWebhook(['Content-Type' => 'application/json'], $jsonPayload);
    assert($verifiedJson['order_id'] === $orderId, 'Order ID must match parsed JSON');
    assert($verifiedJson['status'] === 'paid', 'Status must be paid for resultCode 00');

    // 4. Test Topup Lifecycle with Duitku Adapter & Instant Webhook Settlement
    $testUser = $db->fetchOne('SELECT * FROM users LIMIT 1');
    assert($testUser !== null, 'A test user must exist in database');
    $userId = (string)$testUser['id'];

    $initialWallet = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$userId]);
    $initialBalance = (int)($initialWallet['balance_credits'] ?? 0);

    // Create a pending topup invoice in DB for Duitku
    $duitkuOrderId = 'INV-DK-' . bin2hex(random_bytes(4));
    $topupId = Security::generateId('top');
    $topupAmount = 75000;
    $creditsToGrant = 75000;
    $now = time();
    $expiresIso = gmdate('Y-m-d H:i:s', $now + 3600);

    $db->execute(
        "INSERT INTO topups (
            id, user_id, order_id, payment_provider, payment_method,
            provider_reference, amount_minor, currency, credits_to_grant,
            status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
            idempotency_key, paid_at, expires_at, created_at, updated_at
        ) VALUES (?, ?, ?, 'duitku', 'duitku', 'DEV-DK-REF', ?, 'IDR', ?, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)",
        [
            $topupId, $userId, $duitkuOrderId,
            $topupAmount, $creditsToGrant,
            "topup_order_{$duitkuOrderId}", $expiresIso, $nowIso, $nowIso
        ]
    );

    // 5. Simulate Duitku Webhook POST to /api/v1/webhooks/duitku
    $callbackSig = md5($merchantCode . $topupAmount . $duitkuOrderId . $apiKey);
    $callbackBody = http_build_query([
        'merchantCode' => $merchantCode,
        'amount' => (string)$topupAmount,
        'merchantOrderId' => $duitkuOrderId,
        'signature' => $callbackSig,
        'resultCode' => '00',
        'reference' => 'DEV-DK-REF-12345',
        'paymentCode' => 'SP',
    ]);

    $walletService = new WalletService($db);
    $topupService = new TopupService($db, $config, $walletService);
    $webhookCtrl = new WebhookController($db, null, $topupService);

    \Ciptaone\Core\Response::$exitOnSend = false;

    $request = new Request(
        'POST',
        '/api/v1/webhooks/duitku',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        [],
        [],
        $callbackBody
    );

    // Catch output
    ob_start();
    $webhookCtrl->handlePaymentGatewayWebhook($request, ['gateway' => 'duitku']);
    $resJson = ob_get_clean();

    // Verify invoice is paid
    $updatedTopup = $db->fetchOne('SELECT status, paid_at, reviewed_by FROM topups WHERE order_id = ?', [$duitkuOrderId]);
    assert($updatedTopup['status'] === 'paid', 'Topup status must transition to paid');
    assert(!empty($updatedTopup['paid_at']), 'paid_at must be populated');
    assert($updatedTopup['reviewed_by'] === 'gateway:duitku:webhook', 'Reviewed by should be gateway:duitku:webhook');

    // Verify wallet credited
    $updatedWallet = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$userId]);
    $newBalance = (int)$updatedWallet['balance_credits'];
    assert($newBalance === $initialBalance + $creditsToGrant, "Wallet should be credited {$creditsToGrant} credits");

    // Verify Idempotency: Replaying webhook must be ignored safely without double crediting
    ob_start();
    $webhookCtrl->handlePaymentGatewayWebhook($request, ['gateway' => 'duitku']);
    $replayJson = ob_get_clean();

    $recheckedWallet = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$userId]);
    assert((int)$recheckedWallet['balance_credits'] === $newBalance, 'Replaying webhook must not double-credit wallet');
}
