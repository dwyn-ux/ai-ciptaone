<?php
declare(strict_types=1);

namespace Decomu\Core;

/**
 * Cryptographic security utilities: password hashing, token generation,
 * API key lifecycle, constant-time comparisons, and CSRF protection.
 */
class Security
{
    /**
     * Hashes plaintext password using Argon2id if available, falling back to Bcrypt.
     */
    public static function hashPassword(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            try {
                return password_hash($password, PASSWORD_ARGON2ID, [
                    'memory_cost' => 65536,
                    'time_cost'   => 4,
                    'threads'     => 1,
                ]);
            } catch (\Throwable) {
                // Fallback to bcrypt if Argon2id fails or unsupported on hosting
            }
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verifies plaintext password against stored hash.
     */
    public static function verifyPassword(string $plainPassword, string $hashedPassword): bool
    {
        return password_verify($plainPassword, $hashedPassword);
    }

    /**
     * Normalizes email address to lowercase and trimmed string.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Generates a 256-bit cryptographically secure session token and SHA-256 hash.
     * Returns: ['raw_token' => '...', 'token_hash' => '...']
     */
    public static function generateSessionToken(): array
    {
        $raw = bin2hex(random_bytes(32));
        $hash = self::hashToken($raw);
        return [
            'raw_token' => $raw,
            'token_hash' => $hash,
        ];
    }

    /**
     * Computes SHA-256 hex digest of a token.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Generates an OpenAI-compatible API key.
     * Format: sk-live-<32 random chars>
     * Returns: ['raw_key' => '...', 'key_prefix' => 'sk-live-XXXXXX...', 'key_hash' => '...']
     */
    public static function generateApiKey(): array
    {
        $random = bin2hex(random_bytes(16)); // 32 hex chars
        $rawKey = "sk-live-{$random}";
        $prefix = "sk-live-" . substr($random, 0, 6) . "...";
        $hash = self::hashToken($rawKey);
        return [
            'raw_key' => $rawKey,
            'key_prefix' => $prefix,
            'key_hash' => $hash,
        ];
    }

    /**
     * Constant-time string comparison to prevent timing attacks.
     */
    public static function secureCompare(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Generates standard prefixed unique identifier.
     */
    public static function generateId(string $prefix = 'id'): string
    {
        return "{$prefix}_" . bin2hex(random_bytes(8));
    }

    /**
     * Validates CSRF for session-cookie authenticated mutating requests.
     * Throws AppError(CSRF_INVALID) on violation.
     */
    public static function validateCsrf(Request $request, Config $config): void
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // If request is authenticated via Bearer token (API Key or Session header), CSRF is not applicable
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return;
        }

        // Webhook endpoints have their own signature/secret validation
        $path = $request->getPath();
        if (str_starts_with($path, '/api/v1/webhooks/')) {
            return;
        }

        // Check Origin / Referer against CORS allowlist
        $origin = $request->getHeader('Origin') ?? $request->getHeader('Referer');
        if ($origin) {
            $parsedOrigin = parse_url($origin);
            $originHost = $parsedOrigin['host'] ?? '';
            $originScheme = $parsedOrigin['scheme'] ?? 'https';
            $originPort = isset($parsedOrigin['port']) ? ":{$parsedOrigin['port']}" : '';
            $fullOrigin = "{$originScheme}://{$originHost}{$originPort}";

            $allowedOrigins = $config->getCorsOrigins();
            $allowed = false;
            foreach ($allowedOrigins as $allowedOrigin) {
                if ($allowedOrigin === '*' || rtrim($allowedOrigin, '/') === rtrim($fullOrigin, '/')) {
                    $allowed = true;
                    break;
                }
            }

            // Also allow the server's own host
            $serverHost = $_SERVER['HTTP_HOST'] ?? '';
            if ($serverHost && $originHost === explode(':', $serverHost)[0]) {
                $allowed = true;
            }

            if (!$allowed) {
                throw new AppError(
                    ErrorCode::FORBIDDEN,
                    'CSRF check failed: Origin / Referer tidak diizinkan.',
                    403
                );
            }
        }
    }
}
