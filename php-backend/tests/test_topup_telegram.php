<?php
declare(strict_types=1);

namespace Decomu\Tests;

use Decomu\Core\AppError;
use Decomu\Core\Config;
use Decomu\Core\Database;
use Decomu\Services\AuthService;
use Decomu\Services\TelegramBotService;
use Decomu\Services\TopupService;
use Decomu\Services\WalletService;

function test_topup_telegram_suite(Database $db): void
{
    $config = Config::getInstance();
    $config->set('TELEGRAM_ADMIN_IDS', '12345678,98765432');
    $config->set('PROOF_UPLOAD_DIR', sys_get_temp_dir() . '/decomu_test_proofs_' . time());

    $authService = new AuthService($db, $config);
    $walletService = new WalletService($db);
    $topupService = new TopupService($db, $config, $walletService);
    $telegramBot = new TelegramBotService($config);

    $user = $authService->register('topup_tester_' . bin2hex(random_bytes(4)) . '@decomu.site', 'TopupPass12345#');
    $userId = $user['id'];

    // 1. Minimum amount validation
    $minCaught = false;
    try {
        $topupService->createTopup($userId, $user['email'], 5000);
    } catch (AppError $ae) {
        $minCaught = true;
        assert($ae->getStatusCode() === 400, 'Minimum topup validation did not return 400');
    }
    assert($minCaught, 'Allowed topup below Rp10.000');

    // 2. Create valid topup
    $topup = $topupService->createTopup($userId, $user['email'], 50000, 'manual_transfer');
    assert(!empty($topup['order_id']), 'Order ID is empty');
    assert(str_starts_with($topup['order_id'], 'INV-'), 'Order ID format invalid');
    assert($topup['credits_to_grant'] === 50000, 'Credits to grant mismatch');
    assert($topup['status'] === 'pending', 'Initial status is not pending');
    assert(!empty($topup['instructions']['bank_name']), 'Instructions missing bank name');

    $orderId = $topup['order_id'];

    // 3. Upload payment proof
    $fakeJpegBytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00" . str_repeat("\x00", 200);
    $uploadRes = $topupService->uploadProof(
        $orderId,
        $userId,
        'my_receipt.jpg',
        'image/jpeg',
        $fakeJpegBytes
    );
    assert($uploadRes['status'] === 'under_review', 'Status not updated to under_review after upload');

    // Verify file exists in private storage
    $topupRow = $topupService->getTopupByIdOrOrder($orderId, $userId);
    assert(!empty($topupRow['proof_file_key']), 'Proof file key is empty');
    $proofPath = rtrim((string)$config->get('PROOF_UPLOAD_DIR'), '/\\') . DIRECTORY_SEPARATOR . $topupRow['proof_file_key'];
    assert(file_exists($proofPath), 'Proof file does not exist in storage');

    // 4. Telegram Webhook Callback: Unauthorized user rejected
    $unauthorizedCb = [
        'id' => 'cb_unauth_1',
        'from' => ['id' => 99999999, 'username' => 'hacker'],
        'data' => "topup:approve:{$orderId}",
        'message' => ['chat' => ['id' => 99999999], 'message_id' => 100],
    ];
    $unauthRes = $telegramBot->handleCallbackQuery($unauthorizedCb, function (...$args) use ($topupService) {
        return $topupService->reviewTopup(...$args);
    });
    assert($unauthRes['status'] === 'forbidden', 'Unauthorized Telegram user was not rejected');
    assert($walletService->getWallet($userId)['balance_credits'] === 0, 'Unauthorized user credited wallet');

    // 5. Telegram Webhook Callback: Allowlisted Admin Approved
    $adminCb = [
        'id' => 'cb_admin_1',
        'from' => ['id' => 12345678, 'username' => 'superadmin'],
        'data' => "topup:approve:{$orderId}",
        'message' => ['chat' => ['id' => 12345678], 'message_id' => 101, 'text' => 'Permintaan topup'],
    ];
    $adminRes = $telegramBot->handleCallbackQuery($adminCb, function (...$args) use ($topupService) {
        return $topupService->reviewTopup(...$args);
    });
    assert($adminRes['status'] === 'success', 'Admin topup approval failed');
    assert($walletService->getWallet($userId)['balance_credits'] === 50000, 'Wallet was not credited with 50000');

    // 6. Idempotency: Duplicate callback must not double-credit
    $adminResDuplicate = $telegramBot->handleCallbackQuery($adminCb, function (...$args) use ($topupService) {
        return $topupService->reviewTopup(...$args);
    });
    assert($walletService->getWallet($userId)['balance_credits'] === 50000, 'Duplicate approval credited wallet again!');

    // 7. Rejection workflow test on second invoice
    $topup2 = $topupService->createTopup($userId, $user['email'], 20000, 'manual_qris');
    $orderId2 = $topup2['order_id'];

    $rejectCb = [
        'id' => 'cb_admin_2',
        'from' => ['id' => 98765432, 'username' => 'auditor'],
        'data' => "topup:reject:{$orderId2}",
        'message' => ['chat' => ['id' => 98765432], 'message_id' => 102, 'text' => 'Permintaan topup 2'],
    ];
    $rejectRes = $telegramBot->handleCallbackQuery($rejectCb, function (...$args) use ($topupService) {
        return $topupService->reviewTopup(...$args);
    });
    assert($rejectRes['status'] === 'success', 'Admin rejection failed');

    $topup2Row = $topupService->getTopupByIdOrOrder($orderId2);
    assert($topup2Row['status'] === 'rejected', 'Topup status should be rejected');
    assert($walletService->getWallet($userId)['balance_credits'] === 50000, 'Rejected topup modified wallet');

    // Cleanup test proof file
    if (file_exists($proofPath)) {
        @unlink($proofPath);
    }
}
