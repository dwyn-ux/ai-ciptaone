<?php
declare(strict_types=1);

namespace Decomu\Tests;

use Decomu\Core\AppError;
use Decomu\Core\Database;
use Decomu\Services\AuthService;
use Decomu\Services\WalletService;

function test_wallet_ledger_suite(Database $db): void
{
    $authService = new AuthService($db);
    $walletService = new WalletService($db);

    $user = $authService->register('wallet_tester_' . bin2hex(random_bytes(4)) . '@decomu.site', 'WalletPass12345#');
    $userId = $user['id'];

    // 1. Initial wallet
    $w = $walletService->getWallet($userId);
    assert($w['balance_credits'] === 0, 'Initial balance is not 0');

    // 2. Credit topup
    $orderId = 'INV-TEST-001';
    $bal1 = $walletService->creditWalletForTopup($userId, $orderId, 50000);
    assert($bal1 === 50000, "Expected balance 50000, got {$bal1}");

    // Verify ledger entry
    $ledgerRow = $db->fetchOne('SELECT * FROM credit_ledger WHERE reference_id = ?', [$orderId]);
    assert($ledgerRow !== null, 'Ledger entry for topup not found');
    assert((int)$ledgerRow['amount_credits'] === 50000, 'Ledger amount mismatch');
    assert((int)$ledgerRow['balance_after'] === 50000, 'Ledger balance_after mismatch');

    // 3. Double credit prevention (idempotency check)
    $balDuplicate = $walletService->creditWalletForTopup($userId, $orderId, 50000);
    assert($balDuplicate === 50000, 'Duplicate topup crediting doubled the balance!');
    $ledgerCount = $db->fetchOne('SELECT COUNT(*) as count FROM credit_ledger WHERE idempotency_key = ?', ["topup_{$orderId}"]);
    assert((int)$ledgerCount['count'] === 1, 'Duplicate ledger entry was created');

    // 4. Reserve credits
    $reqId = 'req_test_abc123';
    $walletService->reserveCredits($userId, $reqId, 10000);
    $wAfterReserve = $walletService->getWallet($userId);
    assert($wAfterReserve['balance_credits'] === 40000, "Balance after reservation should be 40000, got {$wAfterReserve['balance_credits']}");

    // 5. Insufficient credits test
    $insufficientCaught = false;
    try {
        $walletService->reserveCredits($userId, 'req_overspend', 100000);
    } catch (AppError $ae) {
        $insufficientCaught = true;
        assert($ae->getStatusCode() === 402, 'Insufficient credits did not return HTTP 402');
    }
    assert($insufficientCaught, 'Overspending was allowed without error');

    // 6. Settle credits (reserved 10000, actual spent 4000 -> refund 6000 back to wallet)
    $billed = $walletService->settleCredits($userId, $reqId, 4000, ['test' => 'settle']);
    assert($billed === 4000, 'Final billed mismatch');

    $wAfterSettle = $walletService->getWallet($userId);
    // 40000 + 6000 refund = 46000
    assert($wAfterSettle['balance_credits'] === 46000, "Balance after settle should be 46000, got {$wAfterSettle['balance_credits']}");

    // Check ledger for settlement
    $settleLedger = $db->fetchOne('SELECT * FROM credit_ledger WHERE idempotency_key = ?', ["settle_{$reqId}"]);
    assert($settleLedger !== null, 'Settlement ledger not found');
    assert((int)$settleLedger['amount_credits'] === -4000, 'Settlement ledger amount should be -4000');
    assert((int)$settleLedger['balance_after'] === 46000, 'Settlement ledger balance_after should be 46000');

    // 7. Idempotent settlement (duplicate settle call)
    $billedAgain = $walletService->settleCredits($userId, $reqId, 4000);
    assert($billedAgain === 4000, 'Idempotent settle did not return billed credits');
    $wAfterDuplicateSettle = $walletService->getWallet($userId);
    assert($wAfterDuplicateSettle['balance_credits'] === 46000, 'Duplicate settle changed wallet balance');

    // 8. Release reservation
    $reqFail = 'req_test_fail';
    $walletService->reserveCredits($userId, $reqFail, 6000);
    assert($walletService->getWallet($userId)['balance_credits'] === 40000, 'Balance after fail reservation mismatch');

    $walletService->releaseReservation($userId, $reqFail);
    assert($walletService->getWallet($userId)['balance_credits'] === 46000, 'Full reservation was not refunded upon release');

    // 9. Negative balance constraint & race simulation
    $insufficientAttempt = false;
    try {
        $walletService->reserveCredits($userId, 'req_too_much', 50000);
    } catch (AppError $ae) {
        $insufficientAttempt = true;
    }
    assert($insufficientAttempt, 'Allowed reserving more than balance');
    assert($walletService->getWallet($userId)['balance_credits'] >= 0, 'Wallet balance went negative');
}
