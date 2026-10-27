<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\RateLimiter;
use Ciptaone\Core\Request;
use Ciptaone\Core\Security;

function test_security_csrf_suite(Database $db): void
{
    $config = Config::getInstance();
    $config->set('CORS_ORIGINS', 'https://ciptaone.com,http://localhost:5173');

    // 1. Mutating POST without Origin or with valid Origin passes
    $validReq = new Request(
        method: 'POST',
        uri: '/api/v1/api-keys',
        headers: ['Origin' => 'https://ciptaone.com', 'Content-Type' => 'application/json']
    );
    // Should not throw
    Security::validateCsrf($validReq, $config);

    // 2. Untrusted Origin must be rejected
    $evilReq = new Request(
        method: 'POST',
        uri: '/api/v1/api-keys',
        headers: ['Origin' => 'https://evil-site.com', 'Content-Type' => 'application/json']
    );
    $evilCaught = false;
    try {
        Security::validateCsrf($evilReq, $config);
    } catch (AppError $ae) {
        $evilCaught = true;
        assert($ae->getStatusCode() === 403, 'Untrusted origin did not return 403');
    }
    assert($evilCaught, 'Untrusted origin was allowed');

    // 3. Bearer authentication bypasses CSRF check
    $bearerReq = new Request(
        method: 'POST',
        uri: '/api/v1/api-keys',
        headers: ['Authorization' => 'Bearer sk-live-12345678', 'Origin' => 'https://evil-site.com']
    );
    Security::validateCsrf($bearerReq, $config); // Should pass without exception

    // 4. RateLimiter sliding window
    $limiter = new RateLimiter($db);
    $testKey = 'test_rate_' . bin2hex(random_bytes(4));

    // Allow 3 requests in 10 seconds
    assert($limiter->checkRateLimit($testKey, 3, 10), 'Request 1 should pass');
    assert($limiter->checkRateLimit($testKey, 3, 10), 'Request 2 should pass');
    assert($limiter->checkRateLimit($testKey, 3, 10), 'Request 3 should pass');

    // 4th request must throw 429
    $rateLimitCaught = false;
    try {
        $limiter->checkRateLimit($testKey, 3, 10);
    } catch (AppError $ae) {
        $rateLimitCaught = true;
        assert($ae->getStatusCode() === 429, 'Rate limit violation did not return 429');
    }
    assert($rateLimitCaught, 'Exceeding rate limit was allowed');

    // 5. Test File Upload Magic Bytes Protection (Prevent PHP execution in disguised images)
    if (class_exists('\finfo')) {
        $topupService = new \Ciptaone\Services\TopupService($db, $config);
        $fakeImageBytes = "<?php echo 'malicious php payload'; ?>";
        $magicBytesCaught = false;
        try {
        $userRow = $db->fetchOne('SELECT id FROM users LIMIT 1');
        $testUserId = $userRow !== null ? (string)$userRow['id'] : 'usr_default';
        $testOrderId = 'ORD-SEC-' . bin2hex(random_bytes(3));
        $nowStr = gmdate('Y-m-d H:i:s');
        $db->execute(
            "INSERT INTO topups (id, user_id, order_id, payment_provider, payment_method, amount_minor, currency, credits_to_grant, status, idempotency_key, expires_at, created_at, updated_at)
             VALUES ('top_sec_1', ?, ?, 'manual', 'manual_transfer', 10000, 'IDR', 10000, 'pending', ?, ?, ?, ?)",
            [$testUserId, $testOrderId, "idem_{$testOrderId}", gmdate('Y-m-d H:i:s', time() + 3600), $nowStr, $nowStr]
        );
        $topupService->uploadProof($testOrderId, $testUserId, 'fake.jpg', 'image/jpeg', $fakeImageBytes);
        } catch (AppError $ae) {
            $magicBytesCaught = true;
            assert($ae->getStatusCode() === 400, 'Disguised PHP file should be rejected with 400');
        }
        assert($magicBytesCaught, 'Disguised PHP script with fake image/jpeg header was not blocked by magic bytes inspection');
    }
}
