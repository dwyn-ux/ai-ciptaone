-- ========================================================
-- AI Ciptaone Router - MySQL / MariaDB Initial Seed Data
-- Migration: 0002_seed_data.sql
-- Providers and Initial Models Setup
-- ========================================================

-- 1. Mock Provider for Development & Testing
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES (
    'prov_mock',
    'mock',
    'Mock Provider (Testing Only)',
    1,
    1,
    NOW(),
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`), `enabled` = VALUES(`enabled`);

INSERT INTO `models` (
    `id`, `public_id`, `provider_id`, `upstream_model`, `enabled`,
    `input_price_per_million`, `output_price_per_million`, `currency`,
    `max_input_tokens`, `max_output_tokens`, `supports_streaming`,
    `pricing_version`, `updated_at`
) VALUES (
    'mod_mock_echo',
    'mock/echo-v1',
    'prov_mock',
    'mock-echo-v1',
    1,
    1500, -- 1,500 IDR per 1M input tokens
    3000, -- 3,000 IDR per 1M output tokens
    'IDR',
    4096,
    2048,
    1,
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`);

-- 2. OpenRouter Provider (Disabled by default until resale/commercial approval verified)
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES (
    'prov_openrouter',
    'openrouter',
    'OpenRouter',
    0,
    0,
    NULL,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

INSERT INTO `models` (
    `id`, `public_id`, `provider_id`, `upstream_model`, `enabled`,
    `input_price_per_million`, `output_price_per_million`, `currency`,
    `max_input_tokens`, `max_output_tokens`, `supports_streaming`,
    `pricing_version`, `updated_at`
) VALUES (
    'mod_meta_llama3_8b',
    'meta-llama/llama-3-8b-instruct',
    'prov_openrouter',
    'meta-llama/llama-3-8b-instruct',
    0,
    10000,
    15000,
    'IDR',
    8192,
    4096,
    1,
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`);

-- 3. OpenAI Provider (Disabled by default until resale/commercial approval verified)
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES (
    'prov_openai',
    'openai',
    'OpenAI',
    0,
    0,
    NULL,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

INSERT INTO `models` (
    `id`, `public_id`, `provider_id`, `upstream_model`, `enabled`,
    `input_price_per_million`, `output_price_per_million`, `currency`,
    `max_input_tokens`, `max_output_tokens`, `supports_streaming`,
    `pricing_version`, `updated_at`
) VALUES (
    'mod_gpt_4o_mini',
    'openai/gpt-4o-mini',
    'prov_openai',
    'gpt-4o-mini',
    0,
    25000,
    100000,
    'IDR',
    128000,
    16384,
    1,
    1,
    NOW()
)
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`);

-- 4. Groq Cloud (LPU Ultra-Fast)
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES ('prov_groq', 'groq', 'Groq Cloud (LPU)', 0, 1, NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

INSERT INTO `models` (`id`, `public_id`, `provider_id`, `upstream_model`, `enabled`, `input_price_per_million`, `output_price_per_million`, `currency`, `max_input_tokens`, `max_output_tokens`, `supports_streaming`, `pricing_version`, `updated_at`)
VALUES ('mod_groq_llama33_70b', 'groq/llama-3.3-70b', 'prov_groq', 'llama-3.3-70b-versatile', 0, 10000, 15000, 'IDR', 128000, 8192, 1, 1, NOW())
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`);

-- 5. DeepSeek Direct API
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES ('prov_deepseek', 'deepseek', 'DeepSeek Direct', 0, 1, NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

INSERT INTO `models` (`id`, `public_id`, `provider_id`, `upstream_model`, `enabled`, `input_price_per_million`, `output_price_per_million`, `currency`, `max_input_tokens`, `max_output_tokens`, `supports_streaming`, `pricing_version`, `updated_at`)
VALUES ('mod_deepseek_chat', 'deepseek/deepseek-chat', 'prov_deepseek', 'deepseek-chat', 0, 3000, 6000, 'IDR', 64000, 8192, 1, 1, NOW())
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`);

-- 6. Google Gemini (Official OpenAI Endpoint)
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES ('prov_gemini', 'gemini', 'Google Gemini (Official)', 0, 1, NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

INSERT INTO `models` (`id`, `public_id`, `provider_id`, `upstream_model`, `enabled`, `input_price_per_million`, `output_price_per_million`, `currency`, `max_input_tokens`, `max_output_tokens`, `supports_streaming`, `pricing_version`, `updated_at`)
VALUES ('mod_gemini_2_flash', 'google/gemini-2.0-flash', 'prov_gemini', 'gemini-2.0-flash', 0, 2000, 8000, 'IDR', 1000000, 8192, 1, 1, NOW())
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = VALUES(`updated_at`);

-- 7. Mistral AI Direct
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES ('prov_mistral', 'mistral', 'Mistral AI Direct', 0, 1, NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);

-- 8. Custom / Local AI (Ollama / vLLM)
INSERT INTO `providers` (`id`, `slug`, `display_name`, `enabled`, `commercial_use_approved`, `terms_checked_at`, `created_at`, `updated_at`)
VALUES ('prov_custom', 'custom', 'Custom OpenAI / Local (Ollama/vLLM)', 0, 1, NOW(), NOW(), NOW())
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);
