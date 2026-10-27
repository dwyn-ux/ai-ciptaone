<?php
declare(strict_types=1);

/**
 * ========================================================
 * AI Ciptaone Router - Standalone Web Admin Control Center
 * Works out-of-the-box on shared hosting and local development
 * without requiring Node.js or Vite.
 * ========================================================
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;
use Ciptaone\Core\Security;
use Ciptaone\Services\AuditService;
use Ciptaone\Services\AuthService;
use Ciptaone\Services\TopupService;
use Ciptaone\Services\WalletService;

$config = Config::getInstance();
$db = Database::getInstance($config);
$authService = new AuthService($db, $config);
$walletService = new WalletService($db);
$auditService = new AuditService($db);
$topupService = new TopupService($db, $config, $walletService, null, $auditService);

$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');

// Auth check
$sessionCookie = $_COOKIE[$config->get('SESSION_COOKIE_NAME', 'ciptaone_session')] ?? '';
$currentUser = $sessionCookie ? $authService->getUserFromSession($sessionCookie) : null;
$isAdmin = ($currentUser && ($currentUser['role'] ?? '') === 'admin') || $isLocal;

$message = '';
$messageType = 'success';

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['admin_action'] ?? '';
    $adminId = $currentUser['id'] ?? 'admin_local';

    try {
        if ($action === 'save_settings') {
            $nowIso = gmdate('Y-m-d H:i:s');
            $allowed = [
                'OPENROUTER_API_KEY', 'OPENAI_API_KEY',
                'TELEGRAM_BOT_TOKEN', 'TELEGRAM_ADMIN_IDS',
                'TRIPAY_API_KEY', 'TRIPAY_PRIVATE_KEY', 'TRIPAY_MERCHANT_CODE', 'TRIPAY_MODE'
            ];
            foreach ($allowed as $k) {
                if (isset($_POST[$k]) && trim($_POST[$k]) !== '') {
                    $v = trim($_POST[$k]);
                    $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', [$k, $v, $nowIso]);
                }
            }
            $message = 'Pengaturan API Provider, Telegram, dan Tripay berhasil disimpan!';
        } elseif ($action === 'toggle_model') {
            $modelId = $_POST['model_id'] ?? '';
            $newStatus = (int)($_POST['new_status'] ?? 0);
            $db->execute('UPDATE models SET enabled = ?, updated_at = ? WHERE id = ? OR public_id = ?', [$newStatus, gmdate('Y-m-d H:i:s'), $modelId, $modelId]);
            $message = 'Status model berhasil diubah!';
        } elseif ($action === 'add_model') {
            $publicId = trim($_POST['public_id'] ?? '');
            $providerSlug = trim($_POST['provider_slug'] ?? 'openrouter');
            $upstream = trim($_POST['upstream_model'] ?? '') ?: $publicId;
            $inPrice = (int)($_POST['input_price_per_million'] ?? 15000);
            $outPrice = (int)($_POST['output_price_per_million'] ?? 30000);

            $prov = $db->fetchOne('SELECT id FROM providers WHERE slug = ?', [$providerSlug]);
            if (!$prov) throw new Exception("Provider '{$providerSlug}' tidak ditemukan.");

            $newId = Security::generateId('mod');
            $nowIso = gmdate('Y-m-d H:i:s');
            $db->execute(
                'INSERT INTO models (id, public_id, provider_id, upstream_model, enabled, input_price_per_million, output_price_per_million, currency, max_input_tokens, max_output_tokens, supports_streaming, pricing_version, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, "IDR", 8192, 4096, 1, 1, ?)',
                [$newId, $publicId, $prov['id'], $upstream, $inPrice, $outPrice, $nowIso]
            );
            $message = "Model '{$publicId}' berhasil ditambahkan dan langsung aktif!";
        } elseif ($action === 'update_pricing') {
            $modelId = $_POST['model_id'] ?? '';
            $inPrice = (int)$_POST['input_price_per_million'];
            $outPrice = (int)$_POST['output_price_per_million'];
            $db->execute('UPDATE models SET input_price_per_million = ?, output_price_per_million = ?, pricing_version = pricing_version + 1, updated_at = ? WHERE id = ? OR public_id = ?', [$inPrice, $outPrice, gmdate('Y-m-d H:i:s'), $modelId, $modelId]);
            $message = 'Tarif model berhasil diperbarui!';
        } elseif ($action === 'review_topup') {
            $orderId = $_POST['order_id'] ?? '';
            $decision = $_POST['decision'] ?? 'paid';
            $topupService->reviewTopup($orderId, $decision, $adminId, $decision === 'rejected' ? 'Ditolak via Web Admin' : null);
            $message = "Top-up {$orderId} berhasil di-" . ($decision === 'paid' ? 'setujui' : 'tolak') . "!";
        } elseif ($action === 'adjust_balance') {
            $targetUserId = $_POST['target_user_id'] ?? '';
            $amount = (int)$_POST['amount_credits'];
            $reason = trim($_POST['reason'] ?? 'Penyesuaian saldo manual via Admin Panel');
            $walletService->adjustBalanceAdmin($adminId, $targetUserId, $amount, $reason);
            $message = 'Saldo pengguna berhasil disesuaikan!';
        }
    } catch (\Throwable $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch Data for Tabs
$settingsRows = [];
try {
    $rows = $db->fetchAll('SELECT setting_key, setting_value FROM system_settings');
    foreach ($rows as $r) {
        $settingsRows[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable) {}

$openrouterKey = $settingsRows['OPENROUTER_API_KEY'] ?? (string)$config->get('OPENROUTER_API_KEY', '');
$openaiKey = $settingsRows['OPENAI_API_KEY'] ?? (string)$config->get('OPENAI_API_KEY', '');
$telegramToken = $settingsRows['TELEGRAM_BOT_TOKEN'] ?? (string)$config->get('TELEGRAM_BOT_TOKEN', '');
$telegramAdmins = $settingsRows['TELEGRAM_ADMIN_IDS'] ?? (string)$config->get('TELEGRAM_ADMIN_IDS', '');
$tripayApiKey = $settingsRows['TRIPAY_API_KEY'] ?? (string)$config->get('TRIPAY_API_KEY', '');
$tripayPrivKey = $settingsRows['TRIPAY_PRIVATE_KEY'] ?? (string)$config->get('TRIPAY_PRIVATE_KEY', '');
$tripayMerchant = $settingsRows['TRIPAY_MERCHANT_CODE'] ?? (string)$config->get('TRIPAY_MERCHANT_CODE', '');
$tripayMode = $settingsRows['TRIPAY_MODE'] ?? (string)$config->get('TRIPAY_MODE', 'sandbox');

$models = $db->fetchAll('SELECT m.*, p.slug as provider_slug FROM models m JOIN providers p ON m.provider_id = p.id ORDER BY m.public_id ASC');
$topups = $db->fetchAll('SELECT t.*, u.email_normalized as user_email FROM topups t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 50');
$usersList = $db->fetchAll('SELECT u.id, u.email_normalized, u.role, u.status, u.created_at, w.balance_credits FROM users u LEFT JOIN wallets w ON u.id = w.user_id ORDER BY u.created_at DESC LIMIT 50');
$auditLogs = $db->fetchAll('SELECT * FROM admin_audit_logs ORDER BY created_at DESC LIMIT 30');

$activeTab = $_GET['tab'] ?? 'settings';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Ciptaone Router — Web Admin Control Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-900 pb-12">
    <!-- Navbar -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold shadow-sm">
                    ⚙️
                </div>
                <div>
                    <h1 class="font-bold text-base text-slate-900 tracking-tight">AI Ciptaone Router <span class="text-xs px-2 py-0.5 bg-indigo-50 text-indigo-700 rounded-full border border-indigo-200 font-semibold">Web Admin</span></h1>
                </div>
            </div>
            <div class="flex items-center space-x-4 text-xs">
                <?php if ($isLocal): ?>
                    <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg font-bold border border-emerald-200">🟢 Mode Lokal (Auto-Admin)</span>
                <?php endif; ?>
                <a href="/test_runner.php" target="_blank" class="text-slate-600 hover:text-indigo-600 font-semibold">🧪 Test Runner</a>
                <a href="/v1/models" target="_blank" class="text-slate-600 hover:text-indigo-600 font-semibold">📋 /v1/models</a>
                <a href="/health" target="_blank" class="text-slate-600 hover:text-indigo-600 font-semibold">❤️ /health</a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        <!-- Notification Message -->
        <?php if ($message): ?>
            <div class="p-4 rounded-xl text-sm font-semibold border <?php echo $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Nav Tabs -->
        <div class="flex border-b border-slate-200 space-x-2 sm:space-x-6 text-sm font-semibold overflow-x-auto">
            <a href="?tab=settings" class="pb-3 px-2 flex items-center space-x-1.5 transition whitespace-nowrap <?php echo $activeTab === 'settings' ? 'border-b-2 border-indigo-600 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">
                <span>🔑 Pengaturan API Provider</span>
            </a>
            <a href="?tab=models" class="pb-3 px-2 flex items-center space-x-1.5 transition whitespace-nowrap <?php echo $activeTab === 'models' ? 'border-b-2 border-indigo-600 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">
                <span>🤖 Katalog Model & Tarif</span>
            </a>
            <a href="?tab=topups" class="pb-3 px-2 flex items-center space-x-1.5 transition whitespace-nowrap <?php echo $activeTab === 'topups' ? 'border-b-2 border-indigo-600 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">
                <span>💳 Review Top-up Manual (<?php echo count(array_filter($topups, fn($t) => $t['status'] === 'pending' || $t['status'] === 'under_review')); ?>)</span>
            </a>
            <a href="?tab=users" class="pb-3 px-2 flex items-center space-x-1.5 transition whitespace-nowrap <?php echo $activeTab === 'users' ? 'border-b-2 border-indigo-600 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">
                <span>👥 Pengguna & Saldo</span>
            </a>
            <a href="?tab=audit" class="pb-3 px-2 flex items-center space-x-1.5 transition whitespace-nowrap <?php echo $activeTab === 'audit' ? 'border-b-2 border-indigo-600 text-indigo-600 font-bold' : 'text-slate-500 hover:text-slate-800'; ?>">
                <span>📜 Audit Logs</span>
            </a>
        </div>

        <!-- TAB CONTENT: SETTINGS -->
        <?php if ($activeTab === 'settings'): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 max-w-3xl space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Input Kunci API Master Provider</h2>
                    <p class="text-xs text-slate-500 mt-1">
                        Kunci API yang Anda beli dari provider dimasukkan di sini. Kunci disimpan aman di database backend dan tidak pernah dikirim ke browser pengguna.
                    </p>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="admin_action" value="save_settings">

                    <!-- OpenRouter -->
                    <div class="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-sm text-slate-800">1. OpenRouter API Key</span>
                            <span class="px-2.5 py-0.5 rounded text-[11px] font-bold uppercase <?php echo $openrouterKey ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'; ?>">
                                <?php echo $openrouterKey ? '✅ Aktif (' . substr($openrouterKey, 0, 8) . '••••••••' . substr($openrouterKey, -4) . ')' : 'Belum Diisi'; ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">Mendukung ratusan model AI open-source (LLaMA 3, DeepSeek, Qwen, Mistral, dll) dari satu provider.</p>
                        <input type="password" name="OPENROUTER_API_KEY" placeholder="<?php echo $openrouterKey ? 'Ketik di sini untuk mengganti key...' : 'sk-or-v1-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                    </div>

                    <!-- OpenAI -->
                    <div class="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-sm text-slate-800">2. OpenAI Official API Key</span>
                            <span class="px-2.5 py-0.5 rounded text-[11px] font-bold uppercase <?php echo $openaiKey ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'; ?>">
                                <?php echo $openaiKey ? '✅ Aktif (' . substr($openaiKey, 0, 7) . '••••••••' . substr($openaiKey, -4) . ')' : 'Belum Diisi'; ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">Untuk model resmi OpenAI (GPT-4o, GPT-4o-mini).</p>
                        <input type="password" name="OPENAI_API_KEY" placeholder="<?php echo $openaiKey ? 'Ketik di sini untuk mengganti key...' : 'sk-proj-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                    </div>

                    <!-- Telegram Settings -->
                    <div class="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-sm text-slate-800">3. Telegram Bot Notifier & Customer Bot</span>
                            <span class="px-2.5 py-0.5 rounded text-[11px] font-bold uppercase <?php echo $telegramToken ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'; ?>">
                                <?php echo $telegramToken ? '✅ Terkonfigurasi' : 'Belum Diisi'; ?>
                            </span>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Bot Token</label>
                            <input type="password" name="TELEGRAM_BOT_TOKEN" placeholder="<?php echo $telegramToken ? '••••••••' : '123456789:ABCdefGhIJKlmNoPQRstuVWxYz'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Telegram Admin IDs (koma-terpisah)</label>
                            <input type="text" name="TELEGRAM_ADMIN_IDS" value="<?php echo htmlspecialchars($telegramAdmins); ?>" placeholder="Contoh: 12345678,98765432" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                        </div>
                    </div>

                    <!-- Tripay QRIS Dinamis Settings -->
                    <div class="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-sm text-slate-800">4. Tripay Payment Gateway (QRIS Dinamis Otomatis)</span>
                            <span class="px-2.5 py-0.5 rounded text-[11px] font-bold uppercase <?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'; ?>">
                                <?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? '✅ Siap Pakai (' . strtoupper($tripayMode) . ')' : 'Belum Lengkap'; ?>
                            </span>
                        </div>
                        <p class="text-xs text-slate-500">Memungkinkan pelanggan membeli saldo instan via QRIS di Telegram atau web dengan fee 0.7% dan verifikasi otomatis 3 detik.</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Merchant Code</label>
                                <input type="text" name="TRIPAY_MERCHANT_CODE" value="<?php echo htmlspecialchars($tripayMerchant); ?>" placeholder="Contoh: T12345" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">Environment Mode</label>
                                <select name="TRIPAY_MODE" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-semibold">
                                    <option value="sandbox" <?php echo $tripayMode === 'sandbox' ? 'selected' : ''; ?>>🧪 Sandbox (Testing / Uji Coba)</option>
                                    <option value="production" <?php echo $tripayMode === 'production' ? 'selected' : ''; ?>>🚀 Production (Live / Uang Asli)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">API Key</label>
                            <input type="password" name="TRIPAY_API_KEY" placeholder="<?php echo $tripayApiKey ? '••••••••' . substr($tripayApiKey, -4) : 'DEV-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Private Key (Secret Key Callback)</label>
                            <input type="password" name="TRIPAY_PRIVATE_KEY" placeholder="<?php echo $tripayPrivKey ? '••••••••' . substr($tripayPrivKey, -4) : 'xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white font-mono">
                        </div>

                        <div class="p-3 bg-indigo-50/70 border border-indigo-100 rounded-lg text-xs text-indigo-900 space-y-1">
                            <p class="font-bold">📍 URL Callback / Webhook untuk dimasukkan di Dashboard Tripay:</p>
                            <code class="block font-mono bg-white px-2.5 py-1 rounded border border-indigo-200 select-all font-semibold">https://ciptaone.com/api/v1/webhooks/tripay</code>
                        </div>
                    </div>

                    <button type="submit" class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm transition">
                        💾 Simpan Semua Pengaturan
                    </button>
                </form>
            </div>

        <!-- TAB CONTENT: MODELS -->
        <?php elseif ($activeTab === 'models'): ?>
            <div class="space-y-6">
                <!-- Add Model Card -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                    <h3 class="text-base font-bold text-slate-900 mb-3">➕ Tambah Model AI Baru</h3>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                        <input type="hidden" name="admin_action" value="add_model">
                        <div>
                            <label class="block font-bold text-slate-700 uppercase mb-1">Public Model ID</label>
                            <input type="text" name="public_id" required placeholder="Contoh: deepseek/deepseek-chat" class="w-full px-3 py-2 rounded-lg border border-slate-300">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 uppercase mb-1">Provider</label>
                            <select name="provider_slug" class="w-full px-3 py-2 rounded-lg border border-slate-300 bg-white">
                                <option value="openrouter">OpenRouter</option>
                                <option value="openai">OpenAI</option>
                                <option value="mock">Mock</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 uppercase mb-1">Upstream Model Name</label>
                            <input type="text" name="upstream_model" placeholder="Sama dengan Model ID jika kosong" class="w-full px-3 py-2 rounded-lg border border-slate-300">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 uppercase mb-1">Tarif Input / 1M Token (Rp)</label>
                            <input type="number" name="input_price_per_million" value="15000" class="w-full px-3 py-2 rounded-lg border border-slate-300">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 uppercase mb-1">Tarif Output / 1M Token (Rp)</label>
                            <input type="number" name="output_price_per_million" value="30000" class="w-full px-3 py-2 rounded-lg border border-slate-300">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg shadow-sm">
                                🚀 Tambahkan Model
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Models Table -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-3.5">Model ID</th>
                                <th class="px-6 py-3.5">Provider</th>
                                <th class="px-6 py-3.5">Tarif Input (1M)</th>
                                <th class="px-6 py-3.5">Tarif Output (1M)</th>
                                <th class="px-6 py-3.5">Status</th>
                                <th class="px-6 py-3.5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($models as $m): ?>
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-6 py-3.5 font-mono font-bold text-slate-900"><?php echo htmlspecialchars($m['public_id']); ?></td>
                                    <td class="px-6 py-3.5 text-slate-600 font-semibold"><?php echo htmlspecialchars($m['provider_slug']); ?></td>
                                    <td class="px-6 py-3.5 text-slate-700 font-semibold">Rp<?php echo number_format((int)$m['input_price_per_million'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-3.5 text-slate-700 font-semibold">Rp<?php echo number_format((int)$m['output_price_per_million'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-3.5">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $m['enabled'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'; ?>">
                                            <?php echo $m['enabled'] ? 'Online' : 'Disabled'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5 text-right space-x-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="admin_action" value="toggle_model">
                                            <input type="hidden" name="model_id" value="<?php echo htmlspecialchars($m['id']); ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $m['enabled'] ? '0' : '1'; ?>">
                                            <button type="submit" class="px-3 py-1 font-semibold rounded-lg <?php echo $m['enabled'] ? 'bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100'; ?>">
                                                <?php echo $m['enabled'] ? 'Matikan' : 'Aktifkan'; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- TAB CONTENT: TOPUPS -->
        <?php elseif ($activeTab === 'topups'): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Order ID</th>
                            <th class="px-6 py-3.5">Pengguna</th>
                            <th class="px-6 py-3.5">Nominal</th>
                            <th class="px-6 py-3.5">Kredit</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5">Bukti</th>
                            <th class="px-6 py-3.5 text-right">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($topups)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-8 text-center text-slate-400">Belum ada invoice top-up.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($topups as $t): ?>
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-6 py-3.5 font-mono font-semibold text-slate-900"><?php echo htmlspecialchars($t['order_id']); ?></td>
                                    <td class="px-6 py-3.5 text-slate-600"><?php echo htmlspecialchars($t['user_email']); ?></td>
                                    <td class="px-6 py-3.5 font-bold text-slate-800">Rp<?php echo number_format((int)$t['amount_minor'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-3.5 font-semibold text-indigo-600">+<?php echo number_format((int)$t['credits_to_grant'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-3.5">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php echo $t['status'] === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($t['status'] === 'rejected' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800'); ?>">
                                            <?php echo htmlspecialchars($t['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3.5">
                                        <?php if (!empty($t['proof_file_key'])): ?>
                                            <a href="/api/v1/admin/topups/<?php echo urlencode($t['order_id']); ?>/proof" target="_blank" class="text-indigo-600 hover:text-indigo-800 font-bold underline">👁️ Buka File Bukti</a>
                                        <?php else: ?>
                                            <span class="text-slate-400">Belum upload</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-3.5 text-right space-x-2">
                                        <?php if ($t['status'] !== 'paid' && $t['status'] !== 'rejected'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="admin_action" value="review_topup">
                                                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($t['order_id']); ?>">
                                                <input type="hidden" name="decision" value="paid">
                                                <button type="submit" class="px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 font-semibold rounded-lg">✅ Setujui</button>
                                            </form>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="admin_action" value="review_topup">
                                                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($t['order_id']); ?>">
                                                <input type="hidden" name="decision" value="rejected">
                                                <button type="submit" class="px-3 py-1 bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 font-semibold rounded-lg">❌ Tolak</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- TAB CONTENT: USERS -->
        <?php elseif ($activeTab === 'users'): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Email</th>
                            <th class="px-6 py-3.5">Role</th>
                            <th class="px-6 py-3.5">Saldo Kredit</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5">Tanggal Daftar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($usersList as $u): ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-3.5 font-medium text-slate-900"><?php echo htmlspecialchars($u['email_normalized']); ?></td>
                                <td class="px-6 py-3.5 font-bold uppercase text-slate-600"><?php echo htmlspecialchars($u['role']); ?></td>
                                <td class="px-6 py-3.5 font-bold text-indigo-600"><?php echo number_format((int)($u['balance_credits'] ?? 0), 0, ',', '.'); ?> Kredit</td>
                                <td class="px-6 py-3.5"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700"><?php echo htmlspecialchars($u['status']); ?></span></td>
                                <td class="px-6 py-3.5 text-slate-400"><?php echo date('Y-m-d H:i', strtotime($u['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- TAB CONTENT: AUDIT LOGS -->
        <?php elseif ($activeTab === 'audit'): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Waktu</th>
                            <th class="px-6 py-3.5">Admin ID</th>
                            <th class="px-6 py-3.5">Aksi</th>
                            <th class="px-6 py-3.5">Target</th>
                            <th class="px-6 py-3.5">Metadata</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($auditLogs as $l): ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-3.5 font-mono text-slate-400"><?php echo date('Y-m-d H:i:s', strtotime($l['created_at'])); ?></td>
                                <td class="px-6 py-3.5 font-semibold text-slate-800"><?php echo htmlspecialchars($l['admin_user_id']); ?></td>
                                <td class="px-6 py-3.5 font-mono text-indigo-600 font-bold"><?php echo htmlspecialchars($l['action']); ?></td>
                                <td class="px-6 py-3.5 font-mono text-slate-600"><?php echo htmlspecialchars($l['target_type'] . ':' . $l['target_id']); ?></td>
                                <td class="px-6 py-3.5 font-mono text-[11px] text-slate-500 max-w-xs truncate"><?php echo htmlspecialchars($l['metadata_json'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
