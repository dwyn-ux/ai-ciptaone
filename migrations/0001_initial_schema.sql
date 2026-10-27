-- Migration: 0001_initial_schema.sql
-- Description: Core tables for AI Router MVP (Users, Wallets, Ledger, Models, Topups, etc.)

-- 1. Users
CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    email_normalized TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    email_verified_at TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'suspended', 'deleted')),
    role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('user', 'admin')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email_normalized);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);

-- 2. Sessions
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT UNIQUE NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_token_hash ON sessions(token_hash);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);

-- 3. API Keys
CREATE TABLE IF NOT EXISTS api_keys (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    key_prefix TEXT NOT NULL,
    key_hash TEXT UNIQUE NOT NULL,
    last_used_at TEXT NULL,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_api_keys_user_id ON api_keys(user_id);
CREATE INDEX IF NOT EXISTS idx_api_keys_key_hash ON api_keys(key_hash);

-- 4. Providers
CREATE TABLE IF NOT EXISTS providers (
    id TEXT PRIMARY KEY,
    slug TEXT UNIQUE NOT NULL,
    display_name TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    commercial_use_approved INTEGER NOT NULL DEFAULT 0,
    terms_checked_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_providers_slug ON providers(slug);

-- 5. Models
CREATE TABLE IF NOT EXISTS models (
    id TEXT PRIMARY KEY,
    public_id TEXT UNIQUE NOT NULL,
    provider_id TEXT NOT NULL REFERENCES providers(id),
    upstream_model TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    input_price_per_million INTEGER NOT NULL,
    output_price_per_million INTEGER NOT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    max_input_tokens INTEGER NOT NULL DEFAULT 4096,
    max_output_tokens INTEGER NOT NULL DEFAULT 2048,
    supports_streaming INTEGER NOT NULL DEFAULT 1,
    pricing_version INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_models_public_id ON models(public_id);
CREATE INDEX IF NOT EXISTS idx_models_provider ON models(provider_id);
CREATE INDEX IF NOT EXISTS idx_models_enabled ON models(enabled);

-- 6. Wallets
CREATE TABLE IF NOT EXISTS wallets (
    user_id TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    balance_credits INTEGER NOT NULL CHECK(balance_credits >= 0),
    updated_at TEXT NOT NULL
);

-- 7. Credit Ledger
CREATE TABLE IF NOT EXISTS credit_ledger (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    event_type TEXT NOT NULL,
    amount_credits INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    reference_type TEXT NOT NULL,
    reference_id TEXT NOT NULL,
    idempotency_key TEXT UNIQUE NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ledger_user ON credit_ledger(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ledger_idempotency ON credit_ledger(idempotency_key);
CREATE INDEX IF NOT EXISTS idx_ledger_ref ON credit_ledger(reference_type, reference_id);

-- 8. Credit Reservations
CREATE TABLE IF NOT EXISTS credit_reservations (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    request_id TEXT UNIQUE NOT NULL,
    reserved_credits INTEGER NOT NULL,
    settled_credits INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved', 'settled', 'released', 'needs_reconciliation')),
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_reservations_req ON credit_reservations(request_id);
CREATE INDEX IF NOT EXISTS idx_reservations_user ON credit_reservations(user_id, status);

-- 9. Usage Events
CREATE TABLE IF NOT EXISTS usage_events (
    id TEXT PRIMARY KEY,
    request_id TEXT UNIQUE NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    api_key_id TEXT NULL REFERENCES api_keys(id) ON DELETE SET NULL,
    model_id TEXT NOT NULL REFERENCES models(id),
    provider_id TEXT NOT NULL REFERENCES providers(id),
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    estimated_cost_minor INTEGER NOT NULL DEFAULT 0,
    billed_credits INTEGER NOT NULL DEFAULT 0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    error_code TEXT NULL,
    streaming INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_usage_user ON usage_events(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_request ON usage_events(request_id);

-- 10. Topups
CREATE TABLE IF NOT EXISTS topups (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    order_id TEXT UNIQUE NOT NULL,
    payment_provider TEXT NOT NULL,
    payment_method TEXT NOT NULL CHECK(payment_method IN ('manual_transfer', 'manual_qris', 'dana_qris_dynamic')),
    provider_reference TEXT NULL,
    amount_minor INTEGER NOT NULL,
    currency TEXT NOT NULL DEFAULT 'IDR',
    credits_to_grant INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'under_review', 'paid', 'rejected', 'failed', 'expired', 'refunded')),
    proof_file_key TEXT NULL,
    reviewed_by TEXT NULL,
    reviewed_at TEXT NULL,
    rejection_reason TEXT NULL,
    idempotency_key TEXT UNIQUE NOT NULL,
    paid_at TEXT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_topups_user ON topups(user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_topups_order ON topups(order_id);
CREATE INDEX IF NOT EXISTS idx_topups_status ON topups(status);

-- 11. Payment Webhook Events
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id TEXT PRIMARY KEY,
    gateway TEXT NOT NULL,
    event_id TEXT NOT NULL,
    payload_hash TEXT NOT NULL,
    processing_status TEXT NOT NULL DEFAULT 'received' CHECK(processing_status IN ('received', 'processed', 'ignored', 'failed')),
    received_at TEXT NOT NULL,
    processed_at TEXT NULL,
    error_code TEXT NULL,
    UNIQUE(gateway, event_id)
);
CREATE INDEX IF NOT EXISTS idx_webhook_status ON payment_webhook_events(processing_status);

-- 12. Admin Audit Logs
CREATE TABLE IF NOT EXISTS admin_audit_logs (
    id TEXT PRIMARY KEY,
    admin_user_id TEXT NOT NULL,
    action TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_id TEXT NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_admin ON admin_audit_logs(admin_user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_target ON admin_audit_logs(target_type, target_id);

-- 13. Rate Limit Buckets
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    rate_key TEXT PRIMARY KEY,
    window_start INTEGER NOT NULL,
    count INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rate_limit_expires ON rate_limit_buckets(expires_at);

-- 14. System Settings (Dynamic admin settings from Web UI)
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
