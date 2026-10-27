<?php
declare(strict_types=1);

namespace Decomu\Tests;

use Decomu\Core\AppError;
use Decomu\Core\Config;
use Decomu\Core\Database;
use Decomu\Core\RateLimiter;
use Decomu\Core\Request;
use Decomu\Core\Security;

function test_security_csrf_suite(Database $db): void
{
    $config = Config::getInstance();
    $config->set('CORS_ORIGINS', 'https://decomu.site,http://localhost:5173');

    // 1. Mutating POST without Origin or with valid Origin passes
    $validReq = new Request(
        method: 'POST',
        uri: '/api/v1/api-keys',
        headers: ['Origin' => 'https://decomu.site', 'Content-Type' => 'application/json']
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
}
