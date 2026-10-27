<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Config;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Request;
use Ciptaone\Core\Security;
use Ciptaone\Services\ApiKeyService;
use Ciptaone\Services\AuthService;

/**
 * Base controller providing authentication helpers, admin checks,
 * and OpenAI completion credential resolution.
 */
abstract class BaseController
{
    protected AuthService $authService;
    protected ApiKeyService $apiKeyService;
    protected Config $config;

    public function __construct(
        ?AuthService $authService = null,
        ?ApiKeyService $apiKeyService = null,
        ?Config $config = null
    ) {
        $this->authService = $authService ?? new AuthService();
        $this->apiKeyService = $apiKeyService ?? new ApiKeyService();
        $this->config = $config ?? Config::getInstance();
    }

    /**
     * Resolves authenticated user from session cookie or Bearer session token.
     */
    protected function getAuthUser(Request $request): array
    {
        $token = $request->getCookie((string)$this->config->get('SESSION_COOKIE_NAME', 'ciptaone_session'));
        if (!$token) {
            $bearer = $request->getBearerToken();
            if ($bearer && !str_starts_with($bearer, 'sk-live-')) {
                $token = $bearer;
            }
        }

        if (!$token) {
            throw new AppError(
                ErrorCode::UNAUTHORIZED,
                'Silakan login terlebih dahulu.',
                401
            );
        }

        $user = $this->authService->getUserFromSession($token);
        if ($user === null) {
            throw new AppError(
                ErrorCode::UNAUTHORIZED,
                'Sesi telah kedaluwarsa. Silakan login kembali.',
                401
            );
        }

        return $user;
    }

    /**
     * Verifies that the authenticated user possesses admin privileges.
     */
    protected function getAdminUser(Request $request): array
    {
        $user = $this->getAuthUser($request);
        if (($user['role'] ?? '') !== 'admin') {
            throw new AppError(
                ErrorCode::FORBIDDEN,
                'Akses khusus admin.',
                403
            );
        }
        return $user;
    }

    /**
     * Resolves user identity for /v1/chat/completions:
     * 1. API key: Authorization: Bearer sk-live-... -> returns [$userId, $apiKeyId]
     * 2. Web session cookie (Playground) -> returns [$userId, null]
     */
    protected function getAuthForCompletion(Request $request): array
    {
        $bearer = $request->getBearerToken();
        if ($bearer && str_starts_with($bearer, 'sk-live-')) {
            return $this->apiKeyService->validateApiKey($bearer);
        }

        // Fallback to session cookie
        $cookieName = (string)$this->config->get('SESSION_COOKIE_NAME', 'ciptaone_session');
        $sessionToken = $request->getCookie($cookieName);
        if (!$sessionToken && $bearer && !str_starts_with($bearer, 'sk-live-')) {
            $sessionToken = $bearer;
        }

        if ($sessionToken) {
            $user = $this->authService->getUserFromSession($sessionToken);
            if ($user !== null) {
                return [$user['id'], null];
            }
        }

        throw new AppError(
            ErrorCode::UNAUTHORIZED,
            "Autentikasi diperlukan. Sertakan 'Authorization: Bearer <api_key>' atau login ke dashboard.",
            401
        );
    }
}
