<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Services\AuditService;
use Ciptaone\Services\AuthService;
use Ciptaone\Services\WalletService;

function test_admin_audit_suite(Database $db): void
{
    $authService = new AuthService($db);
    $walletService = new WalletService($db);
    $auditService = new AuditService($db);

    // Create regular user and admin user
    $user = $authService->register('regular_' . bin2hex(random_bytes(4)) . '@ciptaone.com', 'RegularPass123#');
    $admin = $authService->register('admin_' . bin2hex(random_bytes(4)) . '@ciptaone.com', 'AdminPass123#');

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
    $userLogs = array_values(array_filter($auditLogs, fn($l) => $l['target_id'] === $user['id']));
    assert(count($userLogs) >= 1, 'Audit log was not written');
    assert($userLogs[0]['action'] === 'balance_adjustment', 'Audit action mismatch');
    assert($userLogs[0]['target_id'] === $user['id'], 'Audit target mismatch');
    assert($userLogs[0]['metadata']['reason'] === 'Kompensasi gangguan teknis provider', 'Audit metadata reason mismatch');

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

    // 5. Test Multi-Provider Bootstrap & Dynamic Keys
    \Ciptaone\Services\RouterService::ensureDefaultProviders($db);
    $allProviders = $db->fetchAll('SELECT slug FROM providers');
    $slugs = array_column($allProviders, 'slug');
    assert(in_array('groq', $slugs, true), 'Groq provider missing');
    assert(in_array('deepseek', $slugs, true), 'DeepSeek provider missing');
    assert(in_array('gemini', $slugs, true), 'Gemini provider missing');
    assert(in_array('mistral', $slugs, true), 'Mistral provider missing');
    assert(in_array('custom', $slugs, true), 'Custom provider missing');

    // 6. Test AdminController Settings with Multi-Provider Keys
    $adminController = new \Ciptaone\Controllers\AdminController($db, $auditService);
    \Ciptaone\Core\Response::$exitOnSend = false;

    // Login session for admin
    $sessionToken = bin2hex(random_bytes(32));
    $db->execute(
        'INSERT INTO sessions (id, user_id, token_hash, expires_at, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['sess_admin_test', $admin['id'], hash('sha256', $sessionToken), date('Y-m-d H:i:s', time() + 3600), $nowIso, $nowIso]
    );

    $reqHeaders = [
        'cookie' => 'ciptaone_session=' . $sessionToken,
    ];
    $csrfToken = hash_hmac('sha256', $admin['id'] . $sessionToken, (string)\Ciptaone\Core\Config::getInstance()->get('SECRET_KEY', 'ciptaone_sec_key_default'));

    $updateReq = new \Ciptaone\Core\Request(
        'POST',
        '/admin/settings',
        array_merge($reqHeaders, ['x-csrf-token' => $csrfToken, 'content-type' => 'application/json']),
        [],
        ['ciptaone_session' => $sessionToken],
        json_encode([
            'GROQ_API_KEY' => 'gsk_test1234567890',
            'DEEPSEEK_API_KEY' => 'sk_ds_test1234567890',
            'GEMINI_API_KEY' => 'AIzaSy_test1234567890',
            'CUSTOM_PROVIDER_BASE_URL' => 'http://localhost:11434/v1',
        ])
    );

    ob_start();
    $adminController->updateSettings($updateReq);
    $outJson = ob_get_clean();
    $updateRes = json_decode($outJson, true);
    assert(($updateRes['status'] ?? '') === 'success', 'Update multi-provider settings failed');

    // Verify stored in system_settings
    $groqRow = $db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['GROQ_API_KEY']);
    assert($groqRow['setting_value'] === 'gsk_test1234567890', 'GROQ_API_KEY was not stored');
    $geminiRow = $db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['GEMINI_API_KEY']);
    assert($geminiRow['setting_value'] === 'AIzaSy_test1234567890', 'GEMINI_API_KEY was not stored');
    $customRow = $db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', ['CUSTOM_PROVIDER_BASE_URL']);
    assert($customRow['setting_value'] === 'http://localhost:11434/v1', 'CUSTOM_PROVIDER_BASE_URL was not stored');

    // Test getSettings exposes multi-provider configuration safely
    $getReq = new \Ciptaone\Core\Request('GET', '/admin/settings', $reqHeaders, [], ['ciptaone_session' => $sessionToken]);
    ob_start();
    $adminController->getSettings($getReq);
    $getJson = ob_get_clean();
    $getRes = json_decode($getJson, true);
    assert(($getRes['groq']['configured'] ?? false) === true, 'Groq should report configured = true');
    assert(str_contains($getRes['groq']['masked'] ?? '', '••••••••'), 'Groq key not masked');
    assert(($getRes['gemini']['configured'] ?? false) === true, 'Gemini should report configured = true');
    assert(($getRes['custom']['configured'] ?? false) === true, 'Custom endpoint should report configured = true');

    \Ciptaone\Core\Response::$exitOnSend = true;
}
