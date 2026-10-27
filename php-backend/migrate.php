<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Database Migration & Seed CLI Tool
 * Usage: php migrate.php
 * Supports both MySQL / MariaDB and SQLite automatically.
 * ========================================================
 */

require_once __DIR__ . '/src/bootstrap.php';

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;

echo "========================================================\n";
echo "🛠️  AI Ciptaone Router - DATABASE MIGRATION TOOL\n";
echo "========================================================\n";

$config = Config::getInstance();
$driver = strtolower((string)$config->get('DB_DRIVER', 'mysql'));

echo "• Driver terdeteksi: " . strtoupper($driver) . "\n";

try {
    $db = Database::getInstance($config);
    $pdo = $db->getPdo();
    echo "✅ Koneksi database berhasil.\n\n";

    $migrationsDir = __DIR__ . '/migrations';

    if ($driver === 'sqlite') {
        $schemaFiles = [
            $migrationsDir . '/sqlite_0001_initial_schema.sql',
            $migrationsDir . '/sqlite_0002_seed_data.sql',
        ];
    } else {
        $schemaFiles = [
            $migrationsDir . '/mysql_0001_initial_schema.sql',
            $migrationsDir . '/mysql_0002_seed_data.sql',
        ];
    }

    foreach ($schemaFiles as $file) {
        if (!file_exists($file)) {
            echo "⚠️ File tidak ditemukan: " . basename($file) . "\n";
            continue;
        }

        echo "• Menjalankan migration: " . basename($file) . "... ";
        $sql = (string)file_get_contents($file);
        $db->executeScript($sql);
        echo "✅ Selesai\n";
    }

    echo "\n--------------------------------------------------------\n";
    echo "📊 Verifikasi Tabel Terpasang:\n";

    if ($driver === 'sqlite') {
        $tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        foreach ($tables as $t) {
            $tableName = $t['name'];
            $countRow = $db->fetchOne("SELECT COUNT(*) as cnt FROM {$tableName}");
            echo "   - {$tableName}: {$countRow['cnt']} rows\n";
        }
    } else {
        $tables = $db->fetchAll("SHOW TABLES");
        foreach ($tables as $t) {
            $tableName = (string)array_values($t)[0];
            $countRow = $db->fetchOne("SELECT COUNT(*) as cnt FROM `{$tableName}`");
            echo "   - {$tableName}: {$countRow['cnt']} rows\n";
        }
    }

    echo "\n🎉 Database berhasil diinisialisasi dan siap digunakan!\n";
    echo "========================================================\n";

} catch (\Throwable $e) {
    echo "\n❌ Gagal menjalankan migration:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
