<?php
declare(strict_types=1);

namespace Decomu\Core;

/**
 * Handles HTTP responses: JSON envelopes, SSE streaming for AI chat,
 * secure cookies, CORS negotiation, and security headers.
 */
class Response
{
    private static bool $headersSent = false;

    /**
     * Sends standard JSON response.
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): void
    {
        self::sendBaseHeaders($statusCode, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Sends standardized PRD error response.
     */
    public static function error(
        string $code,
        string $message,
        int $statusCode = 400,
        ?string $requestId = null,
        array $details = []
    ): void {
        $reqId = $requestId ?? 'req_' . substr(bin2hex(random_bytes(8)), 0, 12);
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $reqId,
            ],
        ];

        if (!empty($details)) {
            $payload['error']['details'] = $details;
        }

        self::json($payload, $statusCode);
    }

    /**
     * Sets secure HttpOnly session cookie.
     */
    public static function setCookie(
        string $name,
        string $value,
        int $maxAge = 604800,
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        string $path = '/'
    ): void {
        $options = [
            'expires' => time() + $maxAge,
            'path' => $path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ];
        setcookie($name, $value, $options);
    }

    /**
     * Deletes cookie by expiring it in the past.
     */
    public static function deleteCookie(string $name, string $path = '/'): void
    {
        setcookie($name, '', [
            'expires' => time() - 86400,
            'path' => $path,
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Sets up headers and disables output buffering for Server-Sent Events (SSE).
     */
    public static function startSse(): void
    {
        if (self::$headersSent) {
            return;
        }

        // Disable script timeout for streaming
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        self::sendBaseHeaders(200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);

        // Clear all active output buffers to ensure chunks flush immediately
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        self::$headersSent = true;
    }

    /**
     * Flushes a single SSE data chunk to client.
     */
    public static function sendSseChunk(mixed $data): void
    {
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "data: {$payload}\n\n";
        @flush();
    }

    /**
     * Flushes the final SSE [DONE] terminator.
     */
    public static function sendSseDone(): void
    {
        echo "data: [DONE]\n\n";
        @flush();
    }

    /**
     * Streams a local file download/read safely (e.g. admin proof viewer).
     */
    public static function file(string $filePath, ?string $mimeType = null): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            self::error(ErrorCode::NOT_FOUND, 'File tidak ditemukan.', 404);
            return;
        }

        $mime = $mimeType ?? mime_content_type($filePath) ?: 'application/octet-stream';
        $size = filesize($filePath);

        self::sendBaseHeaders(200, [
            'Content-Type' => $mime,
            'Content-Length' => (string)$size,
            'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
        ]);

        readfile($filePath);
        exit;
    }

    /**
     * Sends base security and CORS headers.
     */
    public static function sendBaseHeaders(int $statusCode = 200, array $extraHeaders = []): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($statusCode);

        // Security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // CORS headers
        $config = Config::getInstance();
        $allowedOrigins = $config->getCorsOrigins();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if ($origin) {
            $parsed = parse_url($origin);
            $cleanOrigin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            
            $isAllowed = false;
            foreach ($allowedOrigins as $ao) {
                if ($ao === '*' || rtrim($ao, '/') === rtrim($cleanOrigin, '/')) {
                    $isAllowed = true;
                    break;
                }
            }

            if ($isAllowed) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Credentials: true');
            }
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With, X-Telegram-Bot-Api-Secret-Token, X-CSRF-Token');
        header('Access-Control-Expose-Headers: Content-Type, Authorization, X-Accel-Buffering');

        foreach ($extraHeaders as $k => $v) {
            header("{$k}: {$v}");
        }
    }
}
