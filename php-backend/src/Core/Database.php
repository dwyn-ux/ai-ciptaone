<?php
declare(strict_types=1);

namespace Decomu\Core;

use PDO;
use PDOException;
use Exception;

/**
 * Robust PDO database wrapper supporting MySQL/MariaDB (production shared hosting)
 * and SQLite (local dev & automated testing suite).
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;
    private Config $config;

    private function __construct(?Config $config = null)
    {
        $this->config = $config ?? Config::getInstance();
        $this->connect();
    }

    public static function getInstance(?Config $config = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->close();
            self::$instance = null;
        }
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }

    private function connect(): void
    {
        $driver = strtolower((string)$this->config->get('DB_DRIVER', 'mysql'));

        try {
            if ($driver === 'sqlite') {
                $sqlitePath = (string)$this->config->get('SQLITE_PATH');
                $dir = dirname($sqlitePath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $dsn = "sqlite:{$sqlitePath}";
                $this->pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 30,
                ]);
                $this->pdo->exec('PRAGMA foreign_keys = ON;');
                $this->pdo->exec('PRAGMA journal_mode = WAL;');

                // Auto-bootstrap SQLite schema if tables do not exist
                $tbl = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='models'")->fetch();
                if (!$tbl) {
                    $schemaCandidates = [
                        dirname(__DIR__, 2) . '/migrations/sqlite_0001_initial_schema.sql',
                        dirname(__DIR__, 3) . '/migrations/0001_initial_schema.sql',
                    ];
                    $seedCandidates = [
                        dirname(__DIR__, 2) . '/migrations/sqlite_0002_seed_data.sql',
                        dirname(__DIR__, 3) . '/migrations/0002_seed_data.sql',
                    ];
                    foreach ($schemaCandidates as $f) {
                        if (file_exists($f)) {
                            $this->pdo->exec((string)file_get_contents($f));
                            break;
                        }
                    }
                    foreach ($seedCandidates as $f) {
                        if (file_exists($f)) {
                            $this->pdo->exec((string)file_get_contents($f));
                            break;
                        }
                    }
                }
            } else {
                $host = (string)$this->config->get('DB_HOST', '127.0.0.1');
                $port = (int)$this->config->get('DB_PORT', 3306);
                $dbname = (string)$this->config->get('DB_NAME', 'decomu_router');
                $charset = (string)$this->config->get('DB_CHARSET', 'utf8mb4');
                $user = (string)$this->config->get('DB_USER', 'root');
                $pass = (string)$this->config->get('DB_PASS', '');

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
                $this->pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
                ]);
            }
        } catch (PDOException $e) {
            throw new AppError(
                ErrorCode::INTERNAL_ERROR,
                'Koneksi database gagal: ' . $e->getMessage(),
                500
            );
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    public function rollBack(): bool
    {
        if ($this->getPdo()->inTransaction()) {
            return $this->getPdo()->rollBack();
        }
        return false;
    }

    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    /**
     * Executes callable inside an atomic transaction.
     * Automatically commits on success or rolls back on exception.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /**
     * Executes multiple statements inside a single atomic transaction.
     * statements: array of [string $sql, array $params]
     */
    public function executeBatch(array $statements): bool
    {
        if (empty($statements)) {
            return true;
        }

        return $this->transaction(function () use ($statements) {
            foreach ($statements as $item) {
                $sql = $item[0];
                $params = $item[1] ?? [];
                $this->execute($sql, $params);
            }
            return true;
        });
    }

    /**
     * Executes raw multi-statement SQL script (e.g. migration files).
     */
    public function executeScript(string $sqlScript): void
    {
        $this->getPdo()->exec($sqlScript);
    }
}
