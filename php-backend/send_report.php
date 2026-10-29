<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Automated Analytics & Reporting CLI
 * Usage: php send_report.php [daily|weekly|monthly|all_time]
 * Example: php send_report.php daily
 *
 * Crontab Examples (cPanel / Linux):
 *   Daily at 07:00: 0 7 * * * php /path/to/php-backend/send_report.php daily
 *   Weekly Monday at 08:00: 0 8 * * 1 php /path/to/php-backend/send_report.php weekly
 *   Monthly 1st at 08:00: 0 8 1 * * php /path/to/php-backend/send_report.php monthly
 * ========================================================
 */

require_once __DIR__ . '/src/bootstrap.php';

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Services\TelegramBotService;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

$period = strtolower($argv[1] ?? 'daily');
$validPeriods = ['daily', 'weekly', 'monthly', 'all_time'];

if (!in_array($period, $validPeriods, true)) {
    echo "Usage: php send_report.php [daily|weekly|monthly|all_time]\n";
    echo "Defaulting to 'daily'...\n";
    $period = 'daily';
}

echo "========================================================\n";
echo "📊 AI Ciptaone Router - Generating & Sending {$period} Report\n";
echo "========================================================\n\n";

$config = Config::getInstance();
$db = Database::getInstance($config);
$telegramBot = new TelegramBotService($config);

$adminIds = $config->getTelegramAdminIds();
if (empty($adminIds)) {
    echo "⚠️ Peringatan: Tidak ada TELEGRAM_ADMIN_IDS yang terkonfigurasi di .env.\n";
    echo "   Laporan dihasilkan di console saja:\n\n";
    echo $telegramBot->generateReport($period, $db) . "\n";
    exit(0);
}

echo "Mengirimkan laporan ke " . count($adminIds) . " administrator Telegram...\n";
$telegramBot->sendReportToAdmins($period, $db);
echo "✅ Laporan berhasil dikirimkan!\n";
echo "========================================================\n";
