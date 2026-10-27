<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Web Test Runner
 * Allows executing the test suite directly from a web browser
 * on shared hosting where SSH terminal is restricted.
 * Protected by secret key parameter.
 * ========================================================
 */

require_once __DIR__ . '/run_tests.php';

use Ciptaone\Core\Config;

$config = Config::getInstance();
$configuredSecret = (string)$config->get('SECRET_KEY', '');
$providedSecret = $_GET['secret'] ?? '';

// Require either matching SECRET_KEY or allow in local development
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');
if (!$isLocal && ($providedSecret === '' || !hash_equals($configuredSecret, $providedSecret))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden: Invalid or missing test runner secret parameter (?secret=YOUR_SECRET_KEY)";
    exit;
}

ob_start();
$summary = run_all_tests();
$rawOutput = ob_get_clean();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>AI Ciptaone Router — Web Test Suite Runner</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, monospace; background: #0f172a; color: #f8fafc; padding: 2rem; margin: 0; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 1.5rem; }
        h1 { font-size: 1.25rem; color: #60a5fa; margin-top: 0; border-bottom: 1px solid #334155; padding-bottom: 0.75rem; }
        pre { background: #090d16; padding: 1rem; border-radius: 8px; border: 1px solid #1e293b; overflow-x: auto; font-size: 0.85rem; line-height: 1.5; color: #38bdf8; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-weight: bold; font-size: 0.75rem; }
        .badge-success { background: #065f46; color: #34d399; }
        .badge-error { background: #881337; color: #fda4af; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 AI Ciptaone Router Test Suite</h1>
        <div style="margin-bottom: 1rem;">
            <?php if ($summary['failed'] === 0): ?>
                <span class="badge badge-success">✅ ALL <?php echo $summary['passed']; ?> TESTS PASSED (<?php echo $summary['total_time']; ?>s)</span>
            <?php else: ?>
                <span class="badge badge-error">❌ <?php echo $summary['failed']; ?> FAILED / <?php echo $summary['passed']; ?> PASSED</span>
            <?php endif; ?>
        </div>
        <pre><?php echo htmlspecialchars($rawOutput); ?></pre>
    </div>
</body>
</html>
