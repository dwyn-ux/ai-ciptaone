<?php
declare(strict_types=1);

namespace Ciptaone\Tests;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Core\Security;
use Ciptaone\Services\AuthService;

function test_auth_suite(Database $db): void
{
    $authService = new AuthService($db);

    // 1. Password Hashing & Verification
    $plain = 'SuperSecret123!';
    $hash = Security::hashPassword($plain);
    assert(Security::verifyPassword($plain, $hash), 'Password verification failed');
    assert(!Security::verifyPassword('WrongPassword', $hash), 'Incorrect password was accepted');

    // 2. Email Normalization
    $rawEmail = "  User.Name@Example.COM \t";
    $norm = Security::normalizeEmail($rawEmail);
    assert($norm === 'user.name@example.com', "Email normalization failed: expected 'user.name@example.com', got '{$norm}'");

    // 3. User Registration
    $regEmail = 'tester_' . bin2hex(random_bytes(4)) . '@ciptaone.com';
    $user = $authService->register($regEmail, 'StrongPass12345#');
    assert(!empty($user['id']), 'User ID is empty');
    assert($user['email'] === $regEmail, 'Normalized email mismatch');
    assert($user['role'] === 'user', 'Default role is not user');
    assert($user['status'] === 'active', 'Default status is not active');

    // Verify wallet was auto-created with 0 credits
    $walletRow = $db->fetchOne('SELECT balance_credits FROM wallets WHERE user_id = ?', [$user['id']]);
    assert($walletRow !== null, 'Wallet was not created for registered user');
    assert((int)$walletRow['balance_credits'] === 0, 'Initial balance is not 0');

    // 4. Duplicate Registration (Should reject without email enumeration)
    $duplicateCaught = false;
    try {
        $authService->register($regEmail, 'AnotherPass123#');
    } catch (AppError $ae) {
        $duplicateCaught = true;
        assert($ae->getCodeString() === 'invalid_request', 'Unexpected error code on duplicate registration');
    }
    assert($duplicateCaught, 'Duplicate registration was not rejected');

    // 5. Login
    [$loginUser, $rawToken] = $authService->login($regEmail, 'StrongPass12345#');
    assert($loginUser['id'] === $user['id'], 'Login user ID mismatch');
    assert(!empty($rawToken), 'Raw session token is empty');

    // 6. Resolve user from session
    $resolvedUser = $authService->getUserFromSession($rawToken);
    assert($resolvedUser !== null, 'Failed to resolve user from valid session token');
    assert($resolvedUser['id'] === $user['id'], 'Resolved user ID mismatch');

    // 7. Invalid password rejection
    $invalidPassCaught = false;
    try {
        $authService->login($regEmail, 'WrongPassword#');
    } catch (AppError $ae) {
        $invalidPassCaught = true;
        assert($ae->getStatusCode() === 401, 'Invalid password did not return HTTP 401');
    }
    assert($invalidPassCaught, 'Invalid password was accepted');

    // 8. Logout & Session revocation
    $authService->logout($rawToken);
    $revokedSessionUser = $authService->getUserFromSession($rawToken);
    assert($revokedSessionUser === null, 'Revoked session is still valid');
}
