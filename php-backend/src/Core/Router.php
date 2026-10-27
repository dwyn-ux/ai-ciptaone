<?php
declare(strict_types=1);

namespace Decomu\Core;

use Throwable;

/**
 * Lightweight, fast URL pattern router supporting parameter extraction
 * and global exception dispatching.
 */
class Router
{
    private array $routes = [];
    private array $middlewares = [];

    public function addRoute(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '/' . trim($pattern, '/'),
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->addRoute('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function dispatch(Request $request): void
    {
        // 1. Handle HTTP OPTIONS preflight immediately
        if ($request->getMethod() === 'OPTIONS') {
            Response::sendBaseHeaders(204);
            exit;
        }

        try {
            // 2. Run middlewares
            foreach ($this->middlewares as $middleware) {
                $middleware($request);
            }

            $method = $request->getMethod();
            $path = '/' . trim($request->getPath(), '/');

            // 3. Match route
            foreach ($this->routes as $route) {
                if ($route['method'] !== $method) {
                    continue;
                }

                $params = $this->matchPattern($route['pattern'], $path);
                if ($params !== null) {
                    $request->setRouteParams($params);
                    call_user_func($route['handler'], $request, $params);
                    return;
                }
            }

            // 4. No matching route
            throw new AppError(
                ErrorCode::NOT_FOUND,
                "Endpoint '{$method} {$path}' tidak ditemukan.",
                404
            );

        } catch (AppError $ae) {
            Response::json($ae->toEnvelope(), $ae->getStatusCode());
        } catch (Throwable $e) {
            $reqId = 'req_' . substr(bin2hex(random_bytes(8)), 0, 12);
            error_log("Unhandled Server Error [{$reqId}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            Response::error(
                ErrorCode::INTERNAL_ERROR,
                'Terjadi kesalahan internal pada server. Silakan coba beberapa saat lagi.',
                500,
                $reqId
            );
        }
    }

    private function matchPattern(string $pattern, string $path): ?array
    {
        // Convert {param} placeholders to regex named capture groups
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode($value);
                }
            }
            return $params;
        }

        return null;
    }
}
