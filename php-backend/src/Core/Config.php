<?php
declare(strict_types=1);

namespace Ciptaone\Core;

/**
 * Environment configuration manager with zero-dependency .env loader.
 */
class Config
{
    private static ?Config $instance = null;
    private array $data = [];

    private function __construct(?string $envFilePath = null)
    {
        $this->loadDefaults();
        $this->loadEnvFile($envFilePath);
        $this->loadServerEnv();
    }

    public static function getInstance(?string $envFilePath = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($envFilePath);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    private function loadDefaults(): void
    {
        $this->data = [
            'APP_ENV' => 'development',
            'DEBUG' => false,
            'SECRET_KEY' => 'insecure-default-change-in-production-0123456789abcdef',
            'SESSION_COOKIE_NAME' => 'ciptaone_session',
            'SESSION_LIFETIME_SECONDS' => 604800, // 7 days
            'SESSION_SECURE_COOKIE' => false,
            'CORS_ORIGINS' => 'http://localhost:5173,http://localhost:3000,https://ciptaone.com',
            
            // Database
            'DB_DRIVER' => 'mysql', // 'mysql' or 'sqlite'
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 3306,
            'DB_NAME' => 'ciptaone_router',
            'DB_USER' => 'root',
            'DB_PASS' => '',
            'DB_CHARSET' => 'utf8mb4',
            'SQLITE_PATH' => __DIR__ . '/../../storage/local.sqlite3',

            // Storage
            'PROOF_UPLOAD_DIR' => __DIR__ . '/../../storage/proofs',
            'MAX_PROOF_SIZE_BYTES' => 5 * 1024 * 1024, // 5MB

            // Topup
            'TOPUP_EXPIRY_MINUTES' => 120,
            'CREDIT_PER_RUPIAH' => 1,
            'MANUAL_BANK_NAME' => 'BCA / Mandiri / BRI',
            'MANUAL_ACCOUNT_NO' => '1234567890',
            'MANUAL_ACCOUNT_HOLDER' => 'AI Ciptaone Router',
            'MANUAL_QRIS_IMAGE_URL' => '',

            // Telegram
            'TELEGRAM_BOT_TOKEN' => '',
            'TELEGRAM_ADMIN_IDS' => '',
            'TELEGRAM_WEBHOOK_SECRET' => '',

            // Tripay Payment Gateway (Dynamic QRIS)
            'TRIPAY_API_KEY' => '',
            'TRIPAY_PRIVATE_KEY' => '',
            'TRIPAY_MERCHANT_CODE' => '',
            'TRIPAY_MODE' => 'sandbox', // 'sandbox' or 'production'

            // DANA (Deactivated by default until merchant verified)
            'DANA_ENABLED' => false,
            'DANA_ENV' => 'sandbox',
            'DANA_CLIENT_ID' => '',
            'DANA_CLIENT_SECRET' => '',
            'DANA_MERCHANT_ID' => '',
            'DANA_PUBLIC_KEY' => '',
            'DANA_PRIVATE_KEY' => '',

            // Upstream AI
            'OPENROUTER_API_KEY' => '',
            'OPENAI_API_KEY' => '',

            // Rate Limits
            'RATE_LIMIT_LOGIN_PER_MINUTE' => 5,
            'RATE_LIMIT_REGISTER_PER_MINUTE' => 3,
            'RATE_LIMIT_CHAT_PER_MINUTE' => 60,
            'RATE_LIMIT_TOPUP_PER_MINUTE' => 5,
        ];
    }

    private function loadEnvFile(?string $path = null): void
    {
        $searchPaths = $path ? [$path] : [
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 3) . '/.env',
            dirname(__DIR__) . '/.env',
        ];

        foreach ($searchPaths as $file) {
            if ($file && file_exists($file) && is_readable($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines === false) {
                    continue;
                }
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (str_contains($line, '=')) {
                        [$key, $value] = explode('=', $line, 2);
                        $key = trim($key);
                        $value = trim($value);

                        // Strip outer quotes
                        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                            $value = substr($value, 1, -1);
                        }

                        $this->setTypedValue($key, $value);
                    }
                }
                break;
            }
        }
    }

    private function loadServerEnv(): void
    {
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $this->setTypedValue($key, $value);
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && isset($this->data[$key])) {
                $this->setTypedValue($key, $value);
            }
        }
    }

    private function setTypedValue(string $key, string $value): void
    {
        $lower = strtolower($value);
        if ($lower === 'true') {
            $this->data[$key] = true;
        } elseif ($lower === 'false') {
            $this->data[$key] = false;
        } elseif ($lower === 'null') {
            $this->data[$key] = null;
        } elseif (is_numeric($value)) {
            $this->data[$key] = str_contains($value, '.') ? (float)$value : (int)$value;
        } else {
            if (($key === 'SQLITE_PATH' || $key === 'PROOF_UPLOAD_DIR') && !empty($value)) {
                if (!str_starts_with($value, '/') && !preg_match('/^[a-zA-Z]:[\\\\\/]/', $value)) {
                    $value = dirname(__DIR__, 2) . '/' . ltrim($value, '/\\');
                }
            }
            $this->data[$key] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function getCorsOrigins(): array
    {
        $raw = (string)$this->get('CORS_ORIGINS', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function getTelegramAdminIds(): array
    {
        $raw = (string)$this->get('TELEGRAM_ADMIN_IDS', '');
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $p = trim($part);
            if ($p !== '' && ctype_digit($p)) {
                $ids[] = (int)$p;
            }
        }
        return $ids;
    }
}
