-- ========================================================
-- AI Ciptaone Router - MySQL / MariaDB Database Schema
-- Migration: 0001_initial_schema.sql
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- Engine: InnoDB, Charset: utf8mb4
-- ========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` VARCHAR(64) NOT NULL,
    `email_normalized` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `email_verified_at` DATETIME NULL,
    `status` ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
    `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email_normalized`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Sessions Table
CREATE TABLE IF NOT EXISTS `sessions` (
    `id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    `last_seen_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sessions_token_hash` (`token_hash`),
    KEY `idx_sessions_user_id` (`user_id`),
    KEY `idx_sessions_expires_at` (`expires_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. API Keys Table
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NOT NULL,
    `name` VARCHAR(64) NOT NULL,
    `key_prefix` VARCHAR(32) NOT NULL,
    `key_hash` VARCHAR(64) NOT NULL,
    `last_used_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_keys_hash` (`key_hash`),
    KEY `idx_api_keys_user_id` (`user_id`),
    CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Providers Table
CREATE TABLE IF NOT EXISTS `providers` (
    `id` VARCHAR(64) NOT NULL,
    `slug` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(128) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `commercial_use_approved` TINYINT(1) NOT NULL DEFAULT 0,
    `terms_checked_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_providers_slug` (`slug`),
    KEY `idx_providers_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Models Table
CREATE TABLE IF NOT EXISTS `models` (
    `id` VARCHAR(64) NOT NULL,
    `public_id` VARCHAR(128) NOT NULL,
    `provider_id` VARCHAR(64) NOT NULL,
    `upstream_model` VARCHAR(128) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `input_price_per_million` INT NOT NULL,
    `output_price_per_million` INT NOT NULL,
    `currency` VARCHAR(16) NOT NULL DEFAULT 'IDR',
    `max_input_tokens` INT NOT NULL DEFAULT 4096,
    `max_output_tokens` INT NOT NULL DEFAULT 2048,
    `supports_streaming` TINYINT(1) NOT NULL DEFAULT 1,
    `pricing_version` INT NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_models_public_id` (`public_id`),
    KEY `idx_models_provider_id` (`provider_id`),
    KEY `idx_models_enabled` (`enabled`),
    CONSTRAINT `fk_models_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Wallets Table (Strict non-negative check)
CREATE TABLE IF NOT EXISTS `wallets` (
    `user_id` VARCHAR(64) NOT NULL,
    `balance_credits` BIGINT NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `chk_wallets_balance` CHECK (`balance_credits` >= 0),
    CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Credit Ledger Table
CREATE TABLE IF NOT EXISTS `credit_ledger` (
    `id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `amount_credits` BIGINT NOT NULL,
    `balance_after` BIGINT NOT NULL,
    `reference_type` VARCHAR(64) NOT NULL,
    `reference_id` VARCHAR(128) NOT NULL,
    `idempotency_key` VARCHAR(191) NOT NULL,
    `metadata_json` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ledger_idempotency` (`idempotency_key`),
    KEY `idx_ledger_user_created` (`user_id`, `created_at` DESC),
    KEY `idx_ledger_ref` (`reference_type`, `reference_id`),
    CONSTRAINT `fk_ledger_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Credit Reservations Table
CREATE TABLE IF NOT EXISTS `credit_reservations` (
    `id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NOT NULL,
    `request_id` VARCHAR(128) NOT NULL,
    `reserved_credits` BIGINT NOT NULL,
    `settled_credits` BIGINT NOT NULL DEFAULT 0,
    `status` ENUM('reserved', 'settled', 'released', 'needs_reconciliation') NOT NULL DEFAULT 'reserved',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_reservations_request` (`request_id`),
    KEY `idx_reservations_user_status` (`user_id`, `status`),
    CONSTRAINT `fk_reservations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Usage Events Table (No sensitive prompt/response logs)
CREATE TABLE IF NOT EXISTS `usage_events` (
    `id` VARCHAR(64) NOT NULL,
    `request_id` VARCHAR(128) NOT NULL,
    `user_id` VARCHAR(64) NOT NULL,
    `api_key_id` VARCHAR(64) NULL,
    `model_id` VARCHAR(64) NOT NULL,
    `provider_id` VARCHAR(64) NOT NULL,
    `input_tokens` INT NOT NULL DEFAULT 0,
    `output_tokens` INT NOT NULL DEFAULT 0,
    `estimated_cost_minor` BIGINT NOT NULL DEFAULT 0,
    `billed_credits` BIGINT NOT NULL DEFAULT 0,
    `latency_ms` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(32) NOT NULL,
    `error_code` VARCHAR(64) NULL,
    `streaming` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usage_request` (`request_id`),
    KEY `idx_usage_user_created` (`user_id`, `created_at` DESC),
    CONSTRAINT `fk_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_usage_api_key` FOREIGN KEY (`api_key_id`) REFERENCES `api_keys` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_usage_model` FOREIGN KEY (`model_id`) REFERENCES `models` (`id`),
    CONSTRAINT `fk_usage_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Topups Table
CREATE TABLE IF NOT EXISTS `topups` (
    `id` VARCHAR(64) NOT NULL,
    `user_id` VARCHAR(64) NOT NULL,
    `order_id` VARCHAR(64) NOT NULL,
    `payment_provider` VARCHAR(64) NOT NULL,
    `payment_method` ENUM('manual_transfer', 'manual_qris', 'dana_qris_dynamic', 'tripay_qris') NOT NULL,
    `provider_reference` VARCHAR(128) NULL,
    `amount_minor` BIGINT NOT NULL,
    `currency` VARCHAR(16) NOT NULL DEFAULT 'IDR',
    `credits_to_grant` BIGINT NOT NULL,
    `status` ENUM('pending', 'under_review', 'paid', 'rejected', 'failed', 'expired', 'refunded') NOT NULL DEFAULT 'pending',
    `proof_file_key` VARCHAR(255) NULL,
    `reviewed_by` VARCHAR(64) NULL,
    `reviewed_at` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    `idempotency_key` VARCHAR(191) NOT NULL,
    `paid_at` DATETIME NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_topups_order` (`order_id`),
    UNIQUE KEY `uq_topups_idempotency` (`idempotency_key`),
    KEY `idx_topups_user_created` (`user_id`, `created_at` DESC),
    KEY `idx_topups_status` (`status`),
    CONSTRAINT `fk_topups_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Payment Webhook Events Table
CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
    `id` VARCHAR(64) NOT NULL,
    `gateway` VARCHAR(64) NOT NULL,
    `event_id` VARCHAR(128) NOT NULL,
    `payload_hash` VARCHAR(64) NOT NULL,
    `processing_status` ENUM('received', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    `received_at` DATETIME NOT NULL,
    `processed_at` DATETIME NULL,
    `error_code` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_webhook_gateway_event` (`gateway`, `event_id`),
    KEY `idx_webhook_status` (`processing_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Admin Audit Logs Table
CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
    `id` VARCHAR(64) NOT NULL,
    `admin_user_id` VARCHAR(64) NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `target_type` VARCHAR(64) NOT NULL,
    `target_id` VARCHAR(128) NOT NULL,
    `metadata_json` TEXT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_audit_admin_created` (`admin_user_id`, `created_at` DESC),
    KEY `idx_audit_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Rate Limit Buckets Table (High-efficiency database rate limiting)
CREATE TABLE IF NOT EXISTS `rate_limit_buckets` (
    `rate_key` VARCHAR(191) NOT NULL,
    `window_start` INT NOT NULL,
    `count` INT NOT NULL,
    `expires_at` INT NOT NULL,
    PRIMARY KEY (`rate_key`),
    KEY `idx_rate_limit_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. System Settings Table (Dynamic admin settings from Web UI)
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
