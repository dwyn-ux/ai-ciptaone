<?php
declare(strict_types=1);

/**
 * AI Ciptaone Router - Bootstrap and PSR-4 Autoloader
 * Zero external dependencies: works out-of-the-box on any PHP 8.x cPanel hosting.
 */

// 1. Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// 2. Register PSR-4 Autoloader for AI Ciptaone namespace
spl_autoload_register(function (string $class) {
    $prefix = 'Ciptaone\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// 3. Initialize Config
$config = \Ciptaone\Core\Config::getInstance();
if ((bool)$config->get('DEBUG', false)) {
    ini_set('display_errors', '1');
}

// 4. Return configured router with all endpoints registered
function createApplicationRouter(): \Ciptaone\Core\Router {
    $router = new \Ciptaone\Core\Router();

    // Instantiate Controllers
    $authCtrl    = new \Ciptaone\Controllers\AuthController();
    $meCtrl      = new \Ciptaone\Controllers\MeController();
    $apiKeyCtrl  = new \Ciptaone\Controllers\ApiKeyController();
    $openAiCtrl  = new \Ciptaone\Controllers\OpenAIController();
    $topupCtrl   = new \Ciptaone\Controllers\TopupController();
    $webhookCtrl = new \Ciptaone\Controllers\WebhookController();
    $adminCtrl   = new \Ciptaone\Controllers\AdminController();
    $healthCtrl  = new \Ciptaone\Controllers\HealthController();

    // Health Routes
    $router->get('/health', [$healthCtrl, 'live']);
    $router->get('/health/live', [$healthCtrl, 'live']);
    $router->get('/health/ready', [$healthCtrl, 'ready']);

    // Documentation & OpenAPI Spec Routes
    $router->get('/docs', function (\Ciptaone\Core\Request $request) {
        require __DIR__ . '/../public/docs.php';
        exit;
    });
    $router->get('/documentation', function (\Ciptaone\Core\Request $request) {
        header('Location: /docs');
        exit;
    });
    $router->get('/openapi.json', function (\Ciptaone\Core\Request $request) {
        header('Content-Type: application/json; charset=utf-8');
        echo file_get_contents(__DIR__ . '/../public/openapi.json');
        exit;
    });

    // Root Landing Page & SPA Routes (Dashboard, Playground, Top-up, Login, Register, History, API Keys)
    $spaHandler = function (\Ciptaone\Core\Request $request) {
        $htmlFile = __DIR__ . '/../public/index.html';
        if (file_exists($htmlFile)) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            readfile($htmlFile);
            if (\Ciptaone\Core\Response::$exitOnSend) {
                exit;
            }
            return;
        }
        $rootHtml = __DIR__ . '/../../index.html';
        if (file_exists($rootHtml)) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            readfile($rootHtml);
            if (\Ciptaone\Core\Response::$exitOnSend) {
                exit;
            }
            return;
        }
        if (!headers_sent()) {
            header('Location: /docs');
        }
        if (\Ciptaone\Core\Response::$exitOnSend) {
            exit;
        }
    };

    $spaRoutes = ['/', '/landing', '/login', '/register', '/dashboard', '/playground', '/api-keys', '/topup', '/history'];
    foreach ($spaRoutes as $spaRoute) {
        $router->get($spaRoute, $spaHandler);
        $router->post($spaRoute, $spaHandler);
    }

    $router->get('/admin', function (\Ciptaone\Core\Request $request) {
        header('Location: /admin.php');
        exit;
    });

    // Auth Routes
    $router->post('/api/v1/auth/register', [$authCtrl, 'register']);
    $router->post('/api/v1/auth/login', [$authCtrl, 'login']);
    $router->post('/api/v1/auth/logout', [$authCtrl, 'logout']);
    $router->post('/api/v1/auth/verify-email', [$authCtrl, 'verifyEmail']);
    $router->post('/api/v1/auth/forgot-password', [$authCtrl, 'forgotPassword']);
    $router->post('/api/v1/auth/reset-password', [$authCtrl, 'resetPassword']);

    // User Profile, Wallet, Ledger, Usage
    $router->get('/api/v1/me', [$meCtrl, 'getMe']);
    $router->get('/api/v1/wallet', [$meCtrl, 'getWallet']);
    $router->get('/api/v1/transactions', [$meCtrl, 'getTransactions']);
    $router->get('/api/v1/usage', [$meCtrl, 'getUsage']);

    // API Keys
    $router->post('/api/v1/api-keys', [$apiKeyCtrl, 'createApiKey']);
    $router->get('/api/v1/api-keys', [$apiKeyCtrl, 'listApiKeys']);
    $router->delete('/api/v1/api-keys/{key_id}', [$apiKeyCtrl, 'revokeApiKey']);

    // OpenAI Compatible Gateway & Public Models
    $router->get('/v1/models', [$openAiCtrl, 'listModels']);
    $router->get('/api/v1/models', [$openAiCtrl, 'listActiveModels']);
    $router->post('/v1/chat/completions', [$openAiCtrl, 'chatCompletions']);

    // Top-ups
    $router->post('/api/v1/topups', [$topupCtrl, 'createTopup']);
    $router->get('/api/v1/topups', [$topupCtrl, 'listTopups']);
    $router->get('/api/v1/topups/{identifier}', [$topupCtrl, 'getTopupStatus']);
    $router->post('/api/v1/topups/{identifier}/proof', [$topupCtrl, 'uploadProof']);

    // Webhooks (Dual-Bot Telegram & Payment Gateways)
    $router->post('/api/v1/webhooks/telegram', [$webhookCtrl, 'handleTelegramWebhook']);
    $router->post('/api/v1/webhooks/telegram-report', [$webhookCtrl, 'handleTelegramReportWebhook']);
    $router->post('/api/v1/webhooks/{gateway}', [$webhookCtrl, 'handlePaymentGatewayWebhook']);
    $router->post('/v1/webhooks/telegram', [$webhookCtrl, 'handleTelegramWebhook']);
    $router->post('/v1/webhooks/telegram-report', [$webhookCtrl, 'handleTelegramReportWebhook']);
    $router->post('/v1/webhooks/{gateway}', [$webhookCtrl, 'handlePaymentGatewayWebhook']);

    // Admin Operations
    $router->get('/api/v1/admin/settings', [$adminCtrl, 'getSettings']);
    $router->post('/api/v1/admin/settings', [$adminCtrl, 'updateSettings']);
    $router->get('/api/v1/admin/models', [$adminCtrl, 'listModels']);
    $router->post('/api/v1/admin/models', [$adminCtrl, 'createModel']);
    $router->patch('/api/v1/admin/models/{model_id}', [$adminCtrl, 'updateModel']);
    $router->get('/api/v1/admin/providers', [$adminCtrl, 'listProviders']);
    $router->patch('/api/v1/admin/providers/{provider_id}', [$adminCtrl, 'updateProvider']);
    $router->get('/api/v1/admin/topups', [$adminCtrl, 'listTopups']);
    $router->post('/api/v1/admin/topups/{order_id}/review', [$adminCtrl, 'reviewTopup']);
    $router->get('/api/v1/admin/topups/{order_id}/proof', [$adminCtrl, 'getProof']);
    $router->get('/api/v1/admin/users', [$adminCtrl, 'listUsers']);
    $router->post('/api/v1/admin/users/balance-adjust', [$adminCtrl, 'adjustBalance']);
    $router->get('/api/v1/admin/audit-logs', [$adminCtrl, 'listAuditLogs']);

    return $router;
}
