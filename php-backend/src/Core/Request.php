<?php
declare(strict_types=1);

namespace Decomu\Core;

/**
 * Encapsulates incoming HTTP request: URI, method, headers, JSON body,
 * cookies, query parameters, uploaded files, and client IP.
 */
class Request
{
    private string $method;
    private string $path;
    private array $queryParams;
    private array $headers;
    private array $cookies;
    private array $body;
    private string $rawBody;
    private array $files;
    private array $routeParams = [];

    public function __construct(
        ?string $method = null,
        ?string $uri = null,
        ?array $headers = null,
        ?array $queryParams = null,
        ?array $cookies = null,
        ?string $rawBody = null,
        ?array $files = null
    ) {
        $this->method = strtoupper($method ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
        
        $rawUri = $uri ?? $_SERVER['REQUEST_URI'] ?? '/';
        $parsedPath = parse_url($rawUri, PHP_URL_PATH) ?? '/';
        $this->path = '/' . trim($parsedPath, '/');

        $this->headers = $headers ?? $this->captureHeaders();
        $this->queryParams = $queryParams ?? $_GET;
        $this->cookies = $cookies ?? $_COOKIE;
        $this->files = $files ?? $_FILES;

        if ($rawBody !== null) {
            $this->rawBody = $rawBody;
        } else {
            $input = file_get_contents('php://input');
            $this->rawBody = $input !== false ? $input : '';
        }

        $this->body = $this->parseBody();
    }

    private function captureHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string)$value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                $headers[$name] = (string)$value;
            }
        }
        return $headers;
    }

    private function parseBody(): array
    {
        $contentType = $this->getHeader('Content-Type') ?? '';
        if (str_contains($contentType, 'application/json')) {
            if ($this->rawBody === '') {
                return [];
            }
            $decoded = json_decode($this->rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AppError(
                    ErrorCode::INVALID_REQUEST,
                    'Format JSON body tidak valid: ' . json_last_error_msg(),
                    422
                );
            }
            return is_array($decoded) ? $decoded : [];
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        return [];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $target = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $target) {
                return $value;
            }
        }
        return $default;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('Authorization');
        if ($auth && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    public function getCookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->body[$key])) {
            return $this->body[$key];
        }
        if (isset($this->routeParams[$key])) {
            return $this->routeParams[$key];
        }
        if (isset($this->queryParams[$key])) {
            return $this->queryParams[$key];
        }
        return $default;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function getRouteParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function getClientIp(): string
    {
        $headersToCheck = [
            'CF-Connecting-IP',
            'X-Forwarded-For',
            'X-Real-IP',
        ];

        foreach ($headersToCheck as $h) {
            $val = $this->getHeader($h);
            if ($val) {
                $ips = explode(',', $val);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
