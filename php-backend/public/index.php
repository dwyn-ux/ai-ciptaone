<?php
declare(strict_types=1);

/**
 * AI Ciptaone Router - Public Front Controller
 * Entry point for all API requests routed via Apache/LiteSpeed .htaccess.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$request = new \Ciptaone\Core\Request();
$router = createApplicationRouter();
$router->dispatch($request);
