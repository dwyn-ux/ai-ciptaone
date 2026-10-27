<?php
declare(strict_types=1);

namespace Decomu\Tests;

use Decomu\Core\AppError;
use Decomu\Core\Database;
use Decomu\Services\AuditService;
use Decomu\Services\AuthService;
use Decomu\Services\WalletService;

function test_admin_audit_suite(Database $db): void
{
    $authService = new AuthService($db);
    $walletService = new WalletService($db);
    $auditService = new AuditService($db);

    // Create regular user and admin user
    $user = $authService->register('regular_' . bin2hex(random_bytes(4)) . '@decomu.site', 'RegularPass123#');
    $admin = $authService->register('admin_' . bin2hex(random_bytes(4)) . '@decomu.site', 'AdminPass123#');

    // Elevate admin
    $db->execute("UPDATE users SET role = 'admin' WHERE id = ?", [$admin['id']]);

    // 1. Admin adds credits
    $newBal1 = $walletService->adjustBalanceAdmin(
        $admin['id'],
        $user['id'],
        75000,
        'Kompensasi gangguan teknis provider'
    );
    assert($newBal1 === 75000, "Expected balance 75000, got {$newBal1}");

    // Verify audit log
    $auditLogs = $auditService->listAuditLogs(10);
    assert(count($auditLogs) >= 1, 'Audit log was not written');
    assert($auditLogs[0]['action'] === 'balance_adjustment', 'Audit action mismatch');
    assert($auditLogs[0]['target_id'] === $user['id'], 'Audit target mismatch');
    assert($auditLogs[0]['metadata']['reason'] === 'Kompensasi gangguan teknis provider', 'Audit metadata reason mismatch');

    // 2. Admin deducts partial credits
    $newBal2 = $walletService->adjustBalanceAdmin(
        $admin['id'],
        $user['id'],
        -25000,
        'Koreksi kelebihan saldo'
    );
    assert($newBal2 === 50000, "Expected balance 50000, got {$newBal2}");

    // 3. Admin cannot adjust below zero
    $negativeCaught = false;
    try {
        $walletService->adjustBalanceAdmin(
            $admin['id'],
            $user['id'],
            -100000,
            'Pengurangan berlebih'
        );
    } catch (AppError $ae) {
        $negativeCaught = true;
        assert($ae->getStatusCode() === 400, 'Adjustment resulting in negative balance did not return 400');
    }
    assert($negativeCaught, 'Adjustment below zero was allowed');
    assert($walletService->getWallet($user['id'])['balance_credits'] === 50000, 'Balance changed after failed adjustment');

    // 4. Test system_settings dynamic override
    $nowIso = gmdate('Y-m-d H:i:s');
    $db->execute(
        'REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
        ['OPENROUTER_API_KEY', 'sk-or-v1-testkey12345678', $nowIso]
    );
    $rowSetting = $db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['OPENROUTER_API_KEY']);
    assert($rowSetting['setting_value'] === 'sk-or-v1-testkey12345678', 'System settings persistence failed');
}
