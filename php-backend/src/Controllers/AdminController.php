<?php
declare(strict_types=1);

namespace Ciptaone\Controllers;

use Ciptaone\Core\AppError;
use Ciptaone\Core\Database;
use Ciptaone\Core\ErrorCode;
use Ciptaone\Core\Request;
use Ciptaone\Core\Response;
use Ciptaone\Core\Security;
use Ciptaone\Services\AuditService;
use Ciptaone\Services\RouterService;
use Ciptaone\Services\TopupService;
use Ciptaone\Services\WalletService;

/**
 * Controller for admin dashboard operations: model & provider kill switches,
 * top-up review & proof serving, user wallet balance adjustments, and audit trail.
 */
class AdminController extends BaseController
{
    private Database $db;
    private AuditService $auditService;
    private TopupService $topupService;
    private WalletService $walletService;

    public function __construct(
        ?Database $db = null,
        ?AuditService $auditService = null,
        ?TopupService $topupService = null,
        ?WalletService $walletService = null
    ) {
        parent::__construct();
        $this->db = $db ?? Database::getInstance();
        $this->auditService = $auditService ?? new AuditService($this->db);
        $this->topupService = $topupService ?? new TopupService($this->db);
        $this->walletService = $walletService ?? new WalletService($this->db);
    }

    public function listModels(Request $request): void
    {
        $this->getAdminUser($request);

        $rows = $this->db->fetchAll(
            'SELECT m.id, m.public_id, m.provider_id, p.slug as provider_slug, p.display_name,
                    m.upstream_model, m.enabled, m.input_price_per_million, m.output_price_per_million,
                    m.currency, m.max_input_tokens, m.max_output_tokens, m.supports_streaming, m.pricing_version
             FROM models m
             JOIN providers p ON m.provider_id = p.id
             ORDER BY m.public_id ASC'
        );

        $data = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'public_id' => $r['public_id'],
                'provider_id' => $r['provider_id'],
                'provider_slug' => $r['provider_slug'],
                'display_name' => $r['display_name'],
                'upstream_model' => $r['upstream_model'],
                'enabled' => (bool)$r['enabled'],
                'input_price_per_million' => (int)$r['input_price_per_million'],
                'output_price_per_million' => (int)$r['output_price_per_million'],
                'currency' => $r['currency'],
                'max_input_tokens' => (int)$r['max_input_tokens'],
                'max_output_tokens' => (int)$r['max_output_tokens'],
                'supports_streaming' => (bool)$r['supports_streaming'],
                'pricing_version' => (int)$r['pricing_version'],
            ];
        }, $rows);

        Response::json($data);
    }

    public function updateModel(Request $request, array $params): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);
        $modelId = (string)($params['model_id'] ?? '');

        $body = $request->getBody();
        $updates = [];
        $sqlParams = [];

        if (array_key_exists('enabled', $body)) {
            $updates[] = 'enabled = ?';
            $sqlParams[] = $body['enabled'] ? 1 : 0;
        }
        if (isset($body['input_price_per_million'])) {
            $updates[] = 'input_price_per_million = ?';
            $sqlParams[] = (int)$body['input_price_per_million'];
        }
        if (isset($body['output_price_per_million'])) {
            $updates[] = 'output_price_per_million = ?';
            $sqlParams[] = (int)$body['output_price_per_million'];
        }
        if (isset($body['max_input_tokens'])) {
            $updates[] = 'max_input_tokens = ?';
            $sqlParams[] = (int)$body['max_input_tokens'];
        }
        if (isset($body['max_output_tokens'])) {
            $updates[] = 'max_output_tokens = ?';
            $sqlParams[] = (int)$body['max_output_tokens'];
        }
        if (!empty($body['public_id'])) {
            $updates[] = 'public_id = ?';
            $sqlParams[] = trim((string)$body['public_id']);
        }
        if (array_key_exists('upstream_model', $body)) {
            $updates[] = 'upstream_model = ?';
            $sqlParams[] = trim((string)$body['upstream_model']);
        }
        if (!empty($body['provider_slug'])) {
            $prov = $this->db->fetchOne('SELECT id FROM providers WHERE slug = ?', [$body['provider_slug']]);
            if ($prov) {
                $updates[] = 'provider_id = ?';
                $sqlParams[] = $prov['id'];
            }
        }

        if (empty($updates)) {
            Response::json(['status' => 'unchanged']);
            return;
        }

        $nowIso = gmdate('Y-m-d H:i:s');
        $updates[] = 'pricing_version = pricing_version + 1';
        $updates[] = 'updated_at = ?';
        $sqlParams[] = $nowIso;

        $sql = 'UPDATE models SET ' . implode(', ', $updates) . ' WHERE id = ? OR public_id = ?';
        $sqlParams[] = $modelId;
        $sqlParams[] = $modelId;

        $this->db->execute($sql, $sqlParams);

        $this->auditService->logAction(
            $admin['id'],
            'update_model',
            'model',
            $modelId,
            $body
        );

        Response::json(['status' => 'success', 'message' => 'Model berhasil diperbarui.']);
    }

    public function listProviders(Request $request): void
    {
        $this->getAdminUser($request);
        RouterService::ensureDefaultProviders($this->db);
        $rows = $this->db->fetchAll('SELECT * FROM providers ORDER BY slug ASC');
        $data = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'slug' => $r['slug'],
                'display_name' => $r['display_name'],
                'enabled' => (bool)$r['enabled'],
                'commercial_use_approved' => (bool)$r['commercial_use_approved'],
                'terms_checked_at' => $r['terms_checked_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($r['terms_checked_at'])) : null,
            ];
        }, $rows);
        Response::json($data);
    }

    public function createProvider(Request $request): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);
        $body = $request->getBody();

        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($body['slug'] ?? ''))));
        $displayName = trim((string)($body['display_name'] ?? ''));
        $baseUrl = trim((string)($body['base_url'] ?? ''));
        $apiKey = trim((string)($body['api_key'] ?? ''));

        if ($slug === '' || $displayName === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Slug dan Nama Provider wajib diisi.', 422);
        }

        $existing = $this->db->fetchOne('SELECT id FROM providers WHERE slug = ?', [$slug]);
        if ($existing !== null) {
            throw new AppError(ErrorCode::INVALID_REQUEST, "Provider dengan slug '{$slug}' sudah ada.", 409);
        }

        $provId = Security::generateId('prov');
        $nowIso = gmdate('Y-m-d H:i:s');
        $this->db->execute(
            'INSERT INTO providers (id, slug, display_name, enabled, commercial_use_approved, terms_checked_at, created_at, updated_at)
             VALUES (?, ?, ?, 1, 1, ?, ?, ?)',
            [$provId, $slug, $displayName, $nowIso, $nowIso, $nowIso]
        );

        $slugUpper = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $slug));
        if ($baseUrl !== '') {
            $this->db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', ["PROVIDER_{$slugUpper}_BASE_URL", $baseUrl, $nowIso]);
        }
        if ($apiKey !== '') {
            $this->db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', ["PROVIDER_{$slugUpper}_API_KEY", $apiKey, $nowIso]);
        }

        $this->auditService->logAction($admin['id'], 'create_provider', 'provider', $slug, ['display_name' => $displayName, 'base_url' => $baseUrl]);
        Response::json(['status' => 'success', 'message' => "Provider '{$displayName}' berhasil ditambahkan.", 'id' => $provId]);
    }

    public function updateProvider(Request $request, array $params): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);
        $providerId = (string)($params['provider_id'] ?? '');

        $body = $request->getBody();
        $enabled = !empty($body['enabled']) ? 1 : 0;
        $nowIso = gmdate('Y-m-d H:i:s');

        $this->db->execute(
            'UPDATE providers SET enabled = ?, updated_at = ? WHERE id = ? OR slug = ?',
            [$enabled, $nowIso, $providerId, $providerId]
        );

        $this->auditService->logAction(
            $admin['id'],
            'toggle_provider',
            'provider',
            $providerId,
            ['enabled' => (bool)$enabled]
        );

        Response::json(['status' => 'success', 'message' => 'Status provider berhasil diperbarui.']);
    }

    public function listTopups(Request $request): void
    {
        $this->getAdminUser($request);
        $statusFilter = $request->getQuery('status_filter');
        $limit = (int)$request->getQuery('limit', 50);

        $sql = 'SELECT t.id, t.user_id, u.email_normalized as user_email, t.order_id,
                       t.amount_minor, t.currency, t.credits_to_grant, t.payment_method,
                       t.status, t.proof_file_key, t.reviewed_by, t.reviewed_at, t.rejection_reason,
                       t.expires_at, t.created_at, t.paid_at
                FROM topups t
                JOIN users u ON t.user_id = u.id';
        $params = [];

        if ($statusFilter) {
            $sql .= ' WHERE t.status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY t.created_at DESC LIMIT ?';
        $params[] = $limit;

        $rows = $this->db->fetchAll($sql, $params);
        $data = array_map(function ($r) {
            $r['amount_minor'] = (int)$r['amount_minor'];
            $r['credits_to_grant'] = (int)$r['credits_to_grant'];
            $r['expires_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['expires_at']));
            $r['created_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at']));
            if (!empty($r['paid_at'])) {
                $r['paid_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['paid_at']));
            }
            return $r;
        }, $rows);

        Response::json($data);
    }

    public function reviewTopup(Request $request, array $params): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);
        $orderId = (string)($params['order_id'] ?? '');

        $body = $request->getBody();
        $actionInput = (string)($body['action'] ?? '');
        $rejectionReason = isset($body['rejection_reason']) ? (string)$body['rejection_reason'] : null;

        $action = $actionInput === 'approve' ? 'paid' : 'rejected';
        $res = $this->topupService->reviewTopup($orderId, $action, $admin['id'], $rejectionReason);

        Response::json($res);
    }

    public function getProof(Request $request, array $params): void
    {
        $this->getAdminUser($request);
        throw new AppError(ErrorCode::NOT_FOUND, 'Fitur bukti transfer manual telah dihapus.', 404);
    }

    public function listUsers(Request $request): void
    {
        $this->getAdminUser($request);
        $limit = (int)$request->getQuery('limit', 50);
        $offset = (int)$request->getQuery('offset', 0);

        $rows = $this->db->fetchAll(
            'SELECT u.id, u.email_normalized as email, u.status, u.role, u.created_at,
                    COALESCE(w.balance_credits, 0) as balance_credits
             FROM users u
             LEFT JOIN wallets w ON u.id = w.user_id
             ORDER BY u.created_at DESC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );

        $data = array_map(function ($r) {
            return [
                'id' => $r['id'],
                'email' => $r['email'],
                'status' => $r['status'],
                'role' => $r['role'],
                'balance_credits' => (int)$r['balance_credits'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['created_at'])),
            ];
        }, $rows);

        Response::json($data);
    }

    public function adjustBalance(Request $request): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);
        $body = $request->getBody();

        $targetUserId = (string)($body['user_id'] ?? '');
        $amountCredits = (int)($body['amount_credits'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));

        if ($targetUserId === '' || strlen($reason) < 5) {
            throw new AppError(
                ErrorCode::INVALID_REQUEST,
                'User ID dan alasan penyesuaian (minimal 5 karakter) wajib diisi.',
                422
            );
        }

        $newBalance = $this->walletService->adjustBalanceAdmin(
            $admin['id'],
            $targetUserId,
            $amountCredits,
            $reason
        );

        Response::json([
            'status' => 'success',
            'message' => "Saldo pengguna berhasil disesuaikan. Saldo baru: {$newBalance} kredit.",
            'new_balance' => $newBalance,
        ]);
    }

    public function listAuditLogs(Request $request): void
    {
        $this->getAdminUser($request);
        $limit = (int)$request->getQuery('limit', 50);
        $offset = (int)$request->getQuery('offset', 0);

        $logs = $this->auditService->listAuditLogs($limit, $offset);
        Response::json($logs);
    }

    private function getSettingValue(string $key): string
    {
        try {
            $row = $this->db->fetchOne('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
            if ($row !== null && !empty($row['setting_value'])) {
                return (string)$row['setting_value'];
            }
        } catch (\Throwable) {
        }
        return (string)$this->config->get($key, '');
    }

    public function getSettings(Request $request): void
    {
        $this->getAdminUser($request);
        $openrouter = $this->getSettingValue('OPENROUTER_API_KEY');
        $openai = $this->getSettingValue('OPENAI_API_KEY');
        $groq = $this->getSettingValue('GROQ_API_KEY');
        $deepseek = $this->getSettingValue('DEEPSEEK_API_KEY');
        $gemini = $this->getSettingValue('GEMINI_API_KEY');
        $mistral = $this->getSettingValue('MISTRAL_API_KEY');
        $customBaseUrl = $this->getSettingValue('CUSTOM_PROVIDER_BASE_URL');
        $customKey = $this->getSettingValue('CUSTOM_PROVIDER_API_KEY');

        $telegramToken = $this->getSettingValue('TELEGRAM_BOT_TOKEN');
        $telegramAdmins = $this->getSettingValue('TELEGRAM_ADMIN_IDS');

        $duitkuApiKey = $this->getSettingValue('DUITKU_API_KEY');
        $duitkuMerchant = $this->getSettingValue('DUITKU_MERCHANT_CODE');
        $duitkuMode = $this->getSettingValue('DUITKU_MODE') ?: 'sandbox';
        $duitkuCallback = $this->getSettingValue('DUITKU_CALLBACK_URL') ?: 'https://ciptaone.com/api/v1/webhooks/duitku';

        Response::json([
            'openrouter' => [
                'configured' => !empty($openrouter),
                'masked' => !empty($openrouter) ? substr($openrouter, 0, 8) . '••••••••' . substr($openrouter, -4) : null,
            ],
            'openai' => [
                'configured' => !empty($openai),
                'masked' => !empty($openai) ? substr($openai, 0, 7) . '••••••••' . substr($openai, -4) : null,
            ],
            'groq' => [
                'configured' => !empty($groq),
                'masked' => !empty($groq) ? substr($groq, 0, 8) . '••••••••' . substr($groq, -4) : null,
            ],
            'deepseek' => [
                'configured' => !empty($deepseek),
                'masked' => !empty($deepseek) ? substr($deepseek, 0, 8) . '••••••••' . substr($deepseek, -4) : null,
            ],
            'gemini' => [
                'configured' => !empty($gemini),
                'masked' => !empty($gemini) ? substr($gemini, 0, 8) . '••••••••' . substr($gemini, -4) : null,
            ],
            'mistral' => [
                'configured' => !empty($mistral),
                'masked' => !empty($mistral) ? substr($mistral, 0, 8) . '••••••••' . substr($mistral, -4) : null,
            ],
            'custom' => [
                'base_url' => $customBaseUrl,
                'configured' => !empty($customBaseUrl),
                'masked_key' => !empty($customKey) ? substr($customKey, 0, 6) . '••••••••' . substr($customKey, -4) : null,
            ],
            'telegram' => [
                'configured' => !empty($telegramToken),
                'admin_ids' => $telegramAdmins,
            ],
            'duitku' => [
                'configured' => !empty($duitkuApiKey) && !empty($duitkuMerchant),
                'merchant_code' => $duitkuMerchant,
                'mode' => $duitkuMode,
                'callback_url' => $duitkuCallback,
                'masked_api_key' => !empty($duitkuApiKey) ? substr($duitkuApiKey, 0, 6) . '••••••••' . substr($duitkuApiKey, -4) : null,
            ],
        ]);
    }

    public function updateSettings(Request $request): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);

        // Security Lockdown: In production, sensitive API & gateway keys cannot be altered via HTTP API
        if ($this->config->get('APP_ENV') === 'production') {
            throw new AppError(
                ErrorCode::FORBIDDEN,
                'Pengubahan kredensial API & secret gateway via API dinonaktifkan pada lingkungan produksi demi keamanan. Harap kelola konfigurasi langsung melalui file .env server.',
                403
            );
        }

        $body = $request->getBody();
        $nowIso = gmdate('Y-m-d H:i:s');

        $allowedKeys = [
            'OPENROUTER_API_KEY', 'OPENAI_API_KEY',
            'GROQ_API_KEY', 'DEEPSEEK_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY',
            'CUSTOM_PROVIDER_BASE_URL', 'CUSTOM_PROVIDER_API_KEY',
            'TELEGRAM_BOT_TOKEN', 'TELEGRAM_ADMIN_IDS',
            'DUITKU_API_KEY', 'DUITKU_MERCHANT_CODE', 'DUITKU_MODE', 'DUITKU_CALLBACK_URL'
        ];
        $updated = [];

        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $body)) {
                $val = trim((string)$body[$key]);
                if ($val !== '') {
                    $this->db->execute(
                        'REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                        [$key, $val, $nowIso]
                    );
                    $this->config->set($key, $val);
                    $updated[] = $key;
                }
            }
        }

        $this->auditService->logAction(
            $admin['id'],
            'update_settings',
            'system',
            'settings',
            ['updated_keys' => $updated]
        );

        Response::json(['status' => 'success', 'message' => 'Pengaturan API Provider berhasil disimpan.']);
    }

    public function createModel(Request $request): void
    {
        Security::validateCsrf($request, $this->config);
        $admin = $this->getAdminUser($request);
        $body = $request->getBody();

        $publicId = trim((string)($body['public_id'] ?? ''));
        $providerSlug = trim((string)($body['provider_slug'] ?? ''));
        $upstreamModel = trim((string)($body['upstream_model'] ?? '')) ?: $publicId;
        $inputPrice = (int)($body['input_price_per_million'] ?? 15000);
        $outputPrice = (int)($body['output_price_per_million'] ?? 30000);
        $maxInput = (int)($body['max_input_tokens'] ?? 8192);
        $maxOutput = (int)($body['max_output_tokens'] ?? 4096);

        if ($publicId === '' || $providerSlug === '') {
            throw new AppError(ErrorCode::INVALID_REQUEST, 'Model ID dan Provider wajib diisi.', 422);
        }

        $provider = $this->db->fetchOne('SELECT id FROM providers WHERE slug = ?', [$providerSlug]);
        if ($provider === null) {
            throw new AppError(ErrorCode::NOT_FOUND, "Provider '{$providerSlug}' tidak ditemukan.", 404);
        }

        $modelId = Security::generateId('mod');
        $nowIso = gmdate('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO models (id, public_id, provider_id, upstream_model, enabled,
                                 input_price_per_million, output_price_per_million, currency,
                                 max_input_tokens, max_output_tokens, supports_streaming, pricing_version, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, "IDR", ?, ?, 1, 1, ?)',
            [$modelId, $publicId, $provider['id'], $upstreamModel, $inputPrice, $outputPrice, $maxInput, $maxOutput, $nowIso]
        );

        $this->auditService->logAction($admin['id'], 'create_model', 'model', $publicId, $body);
        Response::json(['status' => 'success', 'message' => "Model '{$publicId}' berhasil ditambahkan.", 'id' => $modelId]);
    }
}
