<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Standalone PHP Test Suite Runner
 * Executes all unit and integration test modules.
 * Compatible with PHP 8.1+ and zero external dependencies.
 * ========================================================
 */

require_once __DIR__ . '/../src/bootstrap.php';

require_once __DIR__ . '/test_auth.php';
require_once __DIR__ . '/test_api_keys.php';
require_once __DIR__ . '/test_wallet_ledger.php';
require_once __DIR__ . '/test_router_chat.php';
require_once __DIR__ . '/test_topup_telegram.php';
require_once __DIR__ . '/test_payment_adapters.php';
require_once __DIR__ . '/test_duitku_payment.php';
require_once __DIR__ . '/test_admin_audit.php';
require_once __DIR__ . '/test_security_csrf.php';

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;

function run_all_tests(): array {
    $tempDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ciptaone_test_' . time() . '_' . bin2hex(random_bytes(4)) . '.sqlite3';

    $config = Config::getInstance();
    $config->set('DB_DRIVER', 'sqlite');
    $config->set('SQLITE_PATH', $tempDb);
    $config->set('PROOF_UPLOAD_DIR', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ciptaone_proofs_test');

    Database::reset();
    $db = Database::getInstance($config);

    // Initialize test schema & seed data
    $schemaCandidates = [
        dirname(__DIR__) . '/migrations/sqlite_0001_initial_schema.sql',
        dirname(__DIR__, 2) . '/migrations/0001_initial_schema.sql',
        dirname(__DIR__) . '/migrations/0001_initial_schema.sql',
    ];
    $seedCandidates = [
        dirname(__DIR__) . '/migrations/sqlite_0002_seed_data.sql',
        dirname(__DIR__, 2) . '/migrations/0002_seed_data.sql',
        dirname(__DIR__) . '/migrations/0002_seed_data.sql',
    ];

    foreach ($schemaCandidates as $candidate) {
        if (file_exists($candidate)) {
            $db->executeScript((string)file_get_contents($candidate));
            break;
        }
    }
    foreach ($seedCandidates as $candidate) {
        if (file_exists($candidate)) {
            $db->executeScript((string)file_get_contents($candidate));
            break;
        }
    }

    $suites = [
        'Auth: Password Hashing, Normalization & Lifecycle' => function () use ($db) {
            \Ciptaone\Tests\test_auth_suite($db);
        },
        'API Keys: Generation, Hashing, Reveal & Revocation' => function () use ($db) {
            \Ciptaone\Tests\test_api_keys_suite($db);
        },
        'Wallet & Ledger: Atomic Reservation, Settle & Idempotency' => function () use ($db) {
            \Ciptaone\Tests\test_wallet_ledger_suite($db);
        },
        'Router: Token Math, Models Catalog & Chat Completions' => function () use ($db) {
            \Ciptaone\Tests\test_router_chat_suite($db);
        },
        'Top-up: Invoices, Private Proofs & Telegram Allowlist Review' => function () use ($db) {
            \Ciptaone\Tests\test_topup_telegram_suite($db);
        },
        'Payment Adapters: Manual Transfer & DANA Deactivation Protection' => function () {
            \Ciptaone\Tests\test_payment_adapters_suite();
        },
        'Duitku: Full Service (QRIS & VA), Signature Tampering & Webhook Settlement' => function () use ($db) {
            \Ciptaone\Tests\test_duitku_payment_suite($db);
        },
        'Admin: Model Controls, Balance Adjustments & Audit Logs' => function () use ($db) {
            \Ciptaone\Tests\test_admin_audit_suite($db);
        },
        'Security: CSRF Origin Protection & Database Rate Limiting' => function () use ($db) {
            \Ciptaone\Tests\test_security_csrf_suite($db);
        },
    ];

    $results = [];
    $totalStartTime = microtime(true);

    echo "============================================================\n";
    echo "🚀 RUNNING AI Ciptaone Router PHP TEST SUITE (PHP " . PHP_VERSION . ")\n";
    echo "============================================================\n";

    foreach ($suites as $name => $fn) {
        echo "• Running: {$name}... ";
        $t0 = microtime(true);
        try {
            $fn();
            $elapsed = round(microtime(true) - $t0, 4);
            echo "✅ PASSED ({$elapsed}s)\n";
            $results[] = ['name' => $name, 'passed' => true, 'time' => $elapsed];
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 4);
            echo "❌ FAILED ({$elapsed}s)\n";
            echo "  Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
            $results[] = ['name' => $name, 'passed' => false, 'time' => $elapsed, 'error' => $e->getMessage()];
        }
    }

    $totalTime = round(microtime(true) - $totalStartTime, 3);
    $passedCount = count(array_filter($results, fn($r) => $r['passed']));
    $failedCount = count($results) - $passedCount;

    echo "\n============================================================\n";
    echo "📊 TEST RESULTS: {$passedCount} PASSED, {$failedCount} FAILED (Total: {$totalTime}s)\n";
    echo "============================================================\n";

    // Clean up temporary sqlite test database
    $db->close();
    Database::reset();
    if (file_exists($tempDb)) {
        @unlink($tempDb);
    }

    return [
        'passed' => $passedCount,
        'failed' => $failedCount,
        'total_time' => $totalTime,
        'results' => $results,
    ];
}

// If invoked from CLI
if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
    $summary = run_all_tests();
    if ($summary['failed'] > 0) {
        exit(1);
    }
    exit(0);
}
