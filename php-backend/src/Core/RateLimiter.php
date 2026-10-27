<?php
declare(strict_types=1);

namespace Ciptaone\Core;

/**
 * High-performance database-backed sliding window rate limiter.
 * Conforms to PRD Section 6 (rate_limit_buckets table) and works across
 * distributed PHP-FPM shared hosting workers without requiring Redis.
 */
class RateLimiter
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    private function getKeyColumn(): string
    {
        static $col = null;
        if ($col === null) {
            $col = 'rate_key';
            try {
                $this->db->fetchOne('SELECT rate_key FROM rate_limit_buckets LIMIT 1');
            } catch (\Throwable) {
                $col = '`key`';
            }
        }
        return $col;
    }

    /**
     * Checks rate limit for given key.
     * Throws AppError(RATE_LIMIT_EXCEEDED) with HTTP 429 if limit is violated.
     */
    public function checkRateLimit(string $key, int $maxRequests, int $windowSeconds = 60): bool
    {
        $now = time();
        $col = $this->getKeyColumn();

        // 1. Check existing bucket
        $row = $this->db->fetchOne(
            "SELECT count, window_start, expires_at FROM rate_limit_buckets WHERE {$col} = ?",
            [$key]
        );

        if ($row !== null) {
            $windowStart = (int)$row['window_start'];
            $expiresAt = (int)$row['expires_at'];
            $count = (int)$row['count'];

            if ($now < $expiresAt) {
                if ($count >= $maxRequests) {
                    $retryAfter = max(1, $expiresAt - $now);
                    throw new AppError(
                        ErrorCode::RATE_LIMIT_EXCEEDED,
                        "Batas pemakaian terlampaui. Coba lagi dalam {$retryAfter} detik.",
                        429,
                        null,
                        ['retry_after_seconds' => $retryAfter]
                    );
                }

                // Increment bucket count
                $this->db->execute(
                    "UPDATE rate_limit_buckets SET count = count + 1 WHERE {$col} = ?",
                    [$key]
                );
                return true;
            }
        }

        // 2. Insert new or reset expired window
        $newExpiresAt = $now + $windowSeconds;
        // REPLACE INTO is supported by both MySQL and SQLite
        $this->db->execute(
            "REPLACE INTO rate_limit_buckets ({$col}, window_start, count, expires_at) VALUES (?, ?, 1, ?)",
            [$key, $now, $newExpiresAt]
        );

        // 3. Probabilistic garbage collection (1 in 50 requests)
        if (mt_rand(1, 50) === 1) {
            $this->cleanup();
        }

        return true;
    }

    /**
     * Deletes expired rate limit rows to keep table compact.
     */
    public function cleanup(): void
    {
        try {
            $this->db->execute(
                'DELETE FROM rate_limit_buckets WHERE expires_at < ?',
                [time()]
            );
        } catch (\Throwable) {
            // Suppress cleanup errors to avoid failing the user request
        }
    }
}
