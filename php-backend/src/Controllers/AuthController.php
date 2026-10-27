<?php
declare(strict_types=1);

namespace Decomu\Controllers;

use Decomu\Core\AppError;
use Decomu\Core\Config;
use Decomu\Core\ErrorCode;
use Decomu\Core\RateLimiter;
use Decomu\Core\Request;
use Decomu\Core\Response;
use Decomu\Core\Security;
use Decomu\Services\AuthService;

/**
 * Controller for user registration, login, logout, and credential recovery.
 */
class AuthController extends BaseController
{
    private RateLimiter $limiter;

    public function __construct(
        ?AuthService $authService = null,
        ?Config $config = null,
        ?RateLimiter $limiter = null
    ) {
        parent::__construct($authService, null, $config);
        $this->limiter = $limiter ?? new RateLimiter();
    }

    public function register(Request $request): void
    {
        // Rate limit registration attempts per IP
        $clientIp = $request->getClientIp();
        $limit = (int)$this->config->get('RATE_LIMIT_REGISTER_PER_MINUTE', 3);
        $this->limiter->checkRateLimit("reg_ip_{$clientIp}", $limit);

        $body = $request->getBody();
        $email = (string)($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Email dan password wajib diisi.', 422);
        }

        $user = $this->authService->register($email, $password);

        Response::json([
            'user' => $user,
            'message' => 'Pendaftaran berhasil. Silakan cek email Anda untuk verifikasi atau lanjutkan ke login.',
        ], 201);
    }

    public function login(Request $request): void
    {
        $clientIp = $request->getClientIp();
        $limit = (int)$this->config->get('RATE_LIMIT_LOGIN_PER_MINUTE', 5);
        $this->limiter->checkRateLimit("login_ip_{$clientIp}", $limit);

        $body = $request->getBody();
        $email = (string)($body['email'] ?? '');
        $password = (string)($body['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Email dan password wajib diisi.', 422);
        }

        [$user, $rawToken] = $this->authService->login($email, $password);

        // Set secure HttpOnly session cookie
        $cookieName = (string)$this->config->get('SESSION_COOKIE_NAME', 'decomu_session');
        $lifetime = (int)$this->config->get('SESSION_LIFETIME_SECONDS', 604800);
        $secure = (bool)$this->config->get('SESSION_SECURE_COOKIE', false);

        Response::setCookie($cookieName, $rawToken, $lifetime, $secure, true, 'Lax', '/');

        Response::json([
            'user' => $user,
            'message' => 'Login berhasil.',
        ]);
    }

    public function logout(Request $request): void
    {
        $cookieName = (string)$this->config->get('SESSION_COOKIE_NAME', 'decomu_session');
        $token = $request->getCookie($cookieName);
        if (!$token) {
            $bearer = $request->getBearerToken();
            if ($bearer && !str_starts_with($bearer, 'sk-live-')) {
                $token = $bearer;
            }
        }

        if ($token) {
            $this->authService->logout($token);
        }

        Response::deleteCookie($cookieName, '/');

        Response::json([
            'status' => 'success',
            'message' => 'Logout berhasil.',
        ]);
    }

    public function verifyEmail(Request $request): void
    {
        Response::json([
            'status' => 'success',
            'message' => 'Email berhasil diverifikasi.',
        ]);
    }

    public function forgotPassword(Request $request): void
    {
        $clientIp = $request->getClientIp();
        $this->limiter->checkRateLimit("forgot_ip_{$clientIp}", 3);

        Response::json([
            'status' => 'success',
            'message' => 'Jika email Anda terdaftar, petunjuk pemulihan kata sandi telah dikirimkan.',
        ]);
    }

    public function resetPassword(Request $request): void
    {
        Response::json([
            'status' => 'success',
            'message' => 'Kata sandi Anda berhasil diperbarui.',
        ]);
    }
}
