<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Core\Security;
use Ciptaone\Services\ApiKeyService;
use Ciptaone\Services\AuthService;

function test_api_keys_suite(Database $db): void
{
    $authService = new AuthService($db);
    $apiKeyService = new ApiKeyService($db);

    $user = $authService->register('apikey_tester_' . bin2hex(random_bytes(4)) . '@ciptaone.com', 'KeyPass12345#');
    $userId = $user['id'];

    // 1. Create API key
    $keyData = $apiKeyService->createApiKey($userId, 'Test Production Key');
    assert(!empty($keyData['id']), 'API key ID is empty');
    assert(str_starts_with($keyData['key'], 'sk-live-'), 'API key format invalid');
    assert(str_starts_with($keyData['key_prefix'], 'sk-live-'), 'API key prefix format invalid');
    assert(str_ends_with($keyData['key_prefix'], '...'), 'API key prefix does not end with ...');

    // Verify hash stored in DB, plaintext key NOT stored
    $row = $db->fetchOne('SELECT key_hash FROM api_keys WHERE id = ?', [$keyData['id']]);
    assert($row !== null, 'API key record not found in database');
    assert($row['key_hash'] === Security::hashToken($keyData['key']), 'Stored key hash does not match plaintext key hash');

    // 2. List API keys
    $keys = $apiKeyService->listUserApiKeys($userId);
    assert(count($keys) === 1, 'Expected exactly 1 API key');
    assert($keys[0]['name'] === 'Test Production Key', 'Key name mismatch');
    assert(!isset($keys[0]['key']), 'Plaintext key leaked in list response');

    // 3. Validate valid API key
    [$validUserId, $validKeyId] = $apiKeyService->validateApiKey($keyData['key']);
    assert($validUserId === $userId, 'Validated user ID mismatch');
    assert($validKeyId === $keyData['id'], 'Validated key ID mismatch');

    // 4. Revoke API key
    $revoked = $apiKeyService->revokeApiKey($userId, $keyData['id']);
    assert($revoked === true, 'Failed to revoke API key');

    // 5. Validating revoked API key must fail
    $revokedCaught = false;
    try {
        $apiKeyService->validateApiKey($keyData['key']);
    } catch (AppError $ae) {
        $revokedCaught = true;
        assert($ae->getStatusCode() === 401, 'Revoked API key did not return 401');
    }
    assert($revokedCaught, 'Revoked API key was accepted');

    // 6. Malformed API key
    $malformedCaught = false;
    try {
        $apiKeyService->validateApiKey('invalid-prefix-123');
    } catch (AppError $ae) {
        $malformedCaught = true;
        assert($ae->getStatusCode() === 401, 'Malformed API key did not return 401');
    }
    assert($malformedCaught, 'Malformed API key was accepted');
}
