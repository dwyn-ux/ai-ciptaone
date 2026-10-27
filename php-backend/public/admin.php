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

// 1. Strict Environment & Auth Check (Never rely solely on spoofable HTTP_HOST)
$isDev = $config->get('APP_ENV') === 'development';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = $isDev && in_array($clientIp, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);

$cookieName = (string)$config->get('SESSION_COOKIE_NAME', 'ciptaone_session');
$sessionCookie = $_COOKIE[$cookieName] ?? '';
$currentUser = $sessionCookie ? $authService->getUserFromSession($sessionCookie) : null;
$isAdmin = ($currentUser && ($currentUser['role'] ?? '') === 'admin') || $isLocal;

// 2. Handle Logout Action
$action = $_POST['admin_action'] ?? ($_GET['action'] ?? '');
if ($action === 'logout') {
    if ($sessionCookie) {
        $authService->logout($sessionCookie);
    }
    setcookie($cookieName, '', time() - 3600, '/');
    header('Location: /admin.php');
    exit;
}

// 3. Handle Admin Login Form Action & First Admin Bootstrap
$loginError = '';
$adminCount = 0;
try {
    $row = $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
    $adminCount = (int)($row['count'] ?? 0);
} catch (\Throwable) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'init_admin') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        try {
            if ($adminCount > 0) {
                throw new Exception('Akun administrator sudah terdaftar. Silakan login.');
            }
            if ($password !== $confirmPassword) {
                throw new Exception('Konfirmasi kata sandi tidak cocok.');
            }
            if (strlen($password) < 8) {
                throw new Exception('Kata sandi minimal 8 karakter.');
            }

            $user = $authService->register($email, $password);
            $db->execute("UPDATE users SET role = 'admin' WHERE id = ?", [$user['id']]);

            [$loggedInUser, $rawToken] = $authService->login($email, $password);
            $secureCookie = !$isLocal && (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443');
            setcookie($cookieName, $rawToken, time() + 604800, '/', '', $secureCookie, true);

            header('Location: /admin.php');
            exit;
        } catch (\Throwable $e) {
            $loginError = $e->getMessage();
        }
    } elseif ($action === 'admin_login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        try {
            [$user, $rawToken] = $authService->login($email, $password);
            if (($user['role'] ?? '') !== 'admin') {
                throw new Exception('Akses ditolak: Akun Anda tidak memiliki hak akses administrator.');
            }

            $secureCookie = !$isLocal && (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443');
            setcookie(
                $cookieName,
                $rawToken,
                time() + 604800,
                '/',
                '',
                $secureCookie,
                true
            );

            header('Location: /admin.php');
            exit;
        } catch (\Throwable $e) {
            $loginError = $e->getMessage();
        }
    }
}

// 4. Gatekeeper: If not admin, render secure login or initial setup page and EXIT immediately!
if (!$isAdmin) {
    ?>
    <!DOCTYPE html>
    <html lang="id" class="h-full bg-slate-950">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $adminCount === 0 ? 'Setup Administrator Pertama' : 'Login Administrator'; ?> — AI Ciptaone Router</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    </head>
    <body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl space-y-6">
            <?php if ($adminCount === 0): ?>
                <!-- Initial Setup View -->
                <div class="text-center space-y-2">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center mx-auto text-white shadow-lg shadow-emerald-500/25">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h1 class="text-xl font-extrabold text-white tracking-tight">Setup Admin Pertama</h1>
                    <p class="text-xs text-slate-400">Belum ada akun admin di database. Buat akun administrator utama Anda untuk mulai mengelola router.</p>
                </div>

                <?php if ($loginError): ?>
                    <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-xs font-semibold text-rose-300 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span><?php echo htmlspecialchars($loginError); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="admin_action" value="init_admin">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Administrator</label>
                        <input type="email" name="email" required placeholder="admin@ciptaone.com" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 placeholder:text-slate-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kata Sandi (Minimal 8 Karakter)</label>
                        <input type="password" name="password" required placeholder="••••••••••••" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 placeholder:text-slate-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Konfirmasi Kata Sandi</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••••••" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 placeholder:text-slate-600">
                    </div>
                    <button type="submit" class="w-full py-2.5 px-4 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-lg shadow-emerald-600/30 transition flex items-center justify-center space-x-2">
                        <span>Buat Akun & Masuk</span>
                    </button>
                </form>
            <?php else: ?>
                <!-- Standard Login View -->
                <div class="text-center space-y-2">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center mx-auto text-white shadow-lg shadow-indigo-500/25">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h1 class="text-xl font-extrabold text-white tracking-tight">AI Ciptaone Control Center</h1>
                    <p class="text-xs text-slate-400">Silakan masuk menggunakan akun administrator terverifikasi.</p>
                </div>

                <?php if ($loginError): ?>
                    <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-xs font-semibold text-rose-300 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span><?php echo htmlspecialchars($loginError); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="admin_action" value="admin_login">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Administrator</label>
                        <input type="email" name="email" required placeholder="admin@ciptaone.com" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 placeholder:text-slate-600">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kata Sandi</label>
                        <input type="password" name="password" required placeholder="••••••••••••" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 placeholder:text-slate-600">
                    </div>
                    <button type="submit" class="w-full py-2.5 px-4 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-500 shadow-lg shadow-indigo-600/30 transition flex items-center justify-center space-x-2">
                        <span>Masuk ke Dashboard</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 5. CSRF Token Generation for Authenticated Admin
$csrfSecret = (string)$config->get('SECRET_KEY', 'ciptaone_sec_key_default');
$adminCsrfToken = hash_hmac('sha256', ($currentUser['id'] ?? 'local_admin') . $sessionCookie, $csrfSecret);

$message = '';
$messageType = 'success';

// 6. Handle Mutating Actions (POST with CSRF Verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'admin_login') {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    $adminId = $currentUser['id'] ?? 'admin_local';

    try {
        if (!Security::secureCompare($adminCsrfToken, $submittedCsrf)) {
            throw new Exception('Validasi keamanan (CSRF Token) gagal atau sesi Anda telah kedaluwarsa. Silakan refresh halaman.');
        }

        if ($action === 'save_settings') {
            $nowIso = gmdate('Y-m-d H:i:s');
            $allowed = [
                'OPENROUTER_API_KEY', 'OPENAI_API_KEY',
                'GROQ_API_KEY', 'DEEPSEEK_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY',
                'CUSTOM_PROVIDER_BASE_URL', 'CUSTOM_PROVIDER_API_KEY',
                'TELEGRAM_BOT_TOKEN', 'TELEGRAM_ADMIN_IDS',
                'TRIPAY_API_KEY', 'TRIPAY_PRIVATE_KEY', 'TRIPAY_MERCHANT_CODE', 'TRIPAY_MODE'
            ];
            foreach ($allowed as $k) {
                if (isset($_POST[$k])) {
                    $v = trim((string)$_POST[$k]);
                    if ($v !== '') {
                        $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', [$k, $v, $nowIso]);
                    }
                }
            }
            $message = 'Pengaturan API Provider, Telegram, dan Tripay berhasil disimpan!';
        } elseif ($action === 'toggle_provider') {
            $provId = $_POST['provider_id'] ?? '';
            $newStatus = (int)($_POST['new_status'] ?? 0);
            $db->execute('UPDATE providers SET enabled = ?, updated_at = ? WHERE id = ? OR slug = ?', [$newStatus, gmdate('Y-m-d H:i:s'), $provId, $provId]);
            $message = 'Status provider AI berhasil diperbarui!';
        } elseif ($action === 'add_provider') {
            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['slug'] ?? ''))));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $baseUrl = trim((string)($_POST['base_url'] ?? ''));
            $apiKey = trim((string)($_POST['api_key'] ?? ''));

            if ($slug === '' || $displayName === '') {
                throw new Exception('Slug dan Nama Provider wajib diisi.');
            }

            $existing = $db->fetchOne('SELECT id FROM providers WHERE slug = ?', [$slug]);
            if ($existing) {
                throw new Exception("Provider dengan slug '{$slug}' sudah terdaftar.");
            }

            $provId = Security::generateId('prov');
            $nowIso = gmdate('Y-m-d H:i:s');
            $db->execute(
                'INSERT INTO providers (id, slug, display_name, enabled, commercial_use_approved, terms_checked_at, created_at, updated_at) VALUES (?, ?, ?, 1, 1, ?, ?, ?)',
                [$provId, $slug, $displayName, $nowIso, $nowIso, $nowIso]
            );

            $slugUpper = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $slug));
            if ($baseUrl !== '') {
                $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', ["PROVIDER_{$slugUpper}_BASE_URL", $baseUrl, $nowIso]);
            }
            if ($apiKey !== '') {
                $db->execute('REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)', ["PROVIDER_{$slugUpper}_API_KEY", $apiKey, $nowIso]);
            }

            $message = "Provider '{$displayName}' ({$slug}) berhasil ditambahkan dan siap digunakan!";
        } elseif ($action === 'toggle_model') {
            $modelId = $_POST['model_id'] ?? '';
            $newStatus = (int)($_POST['new_status'] ?? 0);
            $db->execute('UPDATE models SET enabled = ?, updated_at = ? WHERE id = ? OR public_id = ?', [$newStatus, gmdate('Y-m-d H:i:s'), $modelId, $modelId]);
            $message = 'Status model berhasil diperbarui!';
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
try {
    \Ciptaone\Services\RouterService::ensureDefaultProviders($db);
} catch (\Throwable) {}

$settingsRows = [];
try {
    $rows = $db->fetchAll('SELECT setting_key, setting_value FROM system_settings');
    foreach ($rows as $r) {
        $settingsRows[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable) {}

$openrouterKey = $settingsRows['OPENROUTER_API_KEY'] ?? (string)$config->get('OPENROUTER_API_KEY', '');
$openaiKey = $settingsRows['OPENAI_API_KEY'] ?? (string)$config->get('OPENAI_API_KEY', '');
$groqKey = $settingsRows['GROQ_API_KEY'] ?? (string)$config->get('GROQ_API_KEY', '');
$deepseekKey = $settingsRows['DEEPSEEK_API_KEY'] ?? (string)$config->get('DEEPSEEK_API_KEY', '');
$geminiKey = $settingsRows['GEMINI_API_KEY'] ?? (string)$config->get('GEMINI_API_KEY', '');
$mistralKey = $settingsRows['MISTRAL_API_KEY'] ?? (string)$config->get('MISTRAL_API_KEY', '');
$customBaseUrl = $settingsRows['CUSTOM_PROVIDER_BASE_URL'] ?? (string)$config->get('CUSTOM_PROVIDER_BASE_URL', '');
$customKey = $settingsRows['CUSTOM_PROVIDER_API_KEY'] ?? (string)$config->get('CUSTOM_PROVIDER_API_KEY', '');

$telegramToken = $settingsRows['TELEGRAM_BOT_TOKEN'] ?? (string)$config->get('TELEGRAM_BOT_TOKEN', '');
$telegramAdmins = $settingsRows['TELEGRAM_ADMIN_IDS'] ?? (string)$config->get('TELEGRAM_ADMIN_IDS', '');
$tripayApiKey = $settingsRows['TRIPAY_API_KEY'] ?? (string)$config->get('TRIPAY_API_KEY', '');
$tripayPrivKey = $settingsRows['TRIPAY_PRIVATE_KEY'] ?? (string)$config->get('TRIPAY_PRIVATE_KEY', '');
$tripayMerchant = $settingsRows['TRIPAY_MERCHANT_CODE'] ?? (string)$config->get('TRIPAY_MERCHANT_CODE', '');
$tripayMode = $settingsRows['TRIPAY_MODE'] ?? (string)$config->get('TRIPAY_MODE', 'sandbox');

$providers = $db->fetchAll('SELECT * FROM providers ORDER BY enabled DESC, display_name ASC');
$models = $db->fetchAll('SELECT m.*, p.slug as provider_slug FROM models m JOIN providers p ON m.provider_id = p.id ORDER BY m.public_id ASC');
$topups = $db->fetchAll('SELECT t.*, u.email_normalized as user_email FROM topups t JOIN users u ON t.user_id = u.id ORDER BY t.created_at DESC LIMIT 50');
$usersList = $db->fetchAll('SELECT u.id, u.email_normalized, u.role, u.status, u.created_at, w.balance_credits FROM users u LEFT JOIN wallets w ON u.id = w.user_id ORDER BY u.created_at DESC LIMIT 50');
$auditLogs = $db->fetchAll('SELECT * FROM admin_audit_logs ORDER BY created_at DESC LIMIT 30');

$activeTab = $_GET['tab'] ?? 'settings';
?>
<!DOCTYPE html>
<html lang="id" class="h-full bg-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Ciptaone Router — Control Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        code, pre { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col antialiased">
    <!-- Navbar Header -->
    <header class="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3.5">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/20 ring-1 ring-white/20">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <div>
                    <div class="flex items-center space-x-2">
                        <span class="font-extrabold text-base tracking-tight text-white">AI Ciptaone</span>
                        <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-indigo-500/10 text-indigo-400 rounded-md border border-indigo-500/20">Control Center</span>
                    </div>
                    <p class="text-[11px] text-slate-400">High-Performance Central AI Gateway & Billing</p>
                </div>
            </div>

            <div class="flex items-center space-x-3 text-xs">
                <?php if ($isLocal): ?>
                    <div class="hidden sm:flex items-center space-x-1.5 px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-semibold">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span>Mode Lokal</span>
                    </div>
                <?php endif; ?>
                
                <a href="/test_runner.php" target="_blank" class="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium border border-slate-700 transition">
                    <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>Test Suite</span>
                </a>

                <a href="/v1/models" target="_blank" class="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium border border-slate-700 transition">
                    <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                    </svg>
                    <span>/v1/models</span>
                </a>

                <?php if ($currentUser): ?>
                    <a href="?action=logout" class="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 font-semibold border border-rose-500/30 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        <span>Keluar</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 flex-1 w-full">
        <!-- Notification Message Banner -->
        <?php if ($message): ?>
            <div class="p-4 rounded-xl text-sm font-semibold flex items-center space-x-3 border <?php echo $messageType === 'success' ? 'bg-emerald-950/60 text-emerald-300 border-emerald-800/80' : 'bg-rose-950/60 text-rose-300 border-rose-800/80'; ?>">
                <?php if ($messageType === 'success'): ?>
                    <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                <?php else: ?>
                    <svg class="w-5 h-5 text-rose-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Nav Tabs Navigation -->
        <div class="flex border-b border-slate-800 space-x-1 sm:space-x-4 text-sm font-semibold overflow-x-auto pb-px">
            <a href="?tab=settings" class="pb-3 px-3 flex items-center space-x-2 transition whitespace-nowrap border-b-2 <?php echo $activeTab === 'settings' ? 'border-indigo-500 text-indigo-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200'; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
                <span>Pengaturan API Provider</span>
            </a>

            <a href="?tab=models" class="pb-3 px-3 flex items-center space-x-2 transition whitespace-nowrap border-b-2 <?php echo $activeTab === 'models' ? 'border-indigo-500 text-indigo-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200'; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
                <span>Katalog Model & Tarif</span>
            </a>

            <a href="?tab=topups" class="pb-3 px-3 flex items-center space-x-2 transition whitespace-nowrap border-b-2 <?php echo $activeTab === 'topups' ? 'border-indigo-500 text-indigo-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200'; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
                <span>Review Top-up</span>
                <?php 
                    $pendingCount = count(array_filter($topups, fn($t) => $t['status'] === 'pending' || $t['status'] === 'under_review')); 
                    if ($pendingCount > 0):
                ?>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-indigo-500 text-white font-bold"><?php echo $pendingCount; ?></span>
                <?php endif; ?>
            </a>

            <a href="?tab=users" class="pb-3 px-3 flex items-center space-x-2 transition whitespace-nowrap border-b-2 <?php echo $activeTab === 'users' ? 'border-indigo-500 text-indigo-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200'; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                <span>Pengguna & Saldo</span>
            </a>

            <a href="?tab=audit" class="pb-3 px-3 flex items-center space-x-2 transition whitespace-nowrap border-b-2 <?php echo $activeTab === 'audit' ? 'border-indigo-500 text-indigo-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200'; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Audit Logs</span>
            </a>
        </div>

        <!-- ========================================== -->
        <!-- TAB 1: SETTINGS                            -->
        <!-- ========================================== -->
        <?php if ($activeTab === 'settings'): ?>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 max-w-4xl space-y-8 shadow-xl">
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center space-x-2">
                        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>Konfigurasi Provider & Payment Gateway</span>
                    </h2>
                    <p class="text-xs text-slate-400 mt-1.5">
                        Kunci API disimpan secara terenkripsi dalam database. Sistem tidak pernah mengekspos kunci ini ke sisi browser klien pengguna.
                    </p>
                </div>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="admin_action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">

                    <!-- Provider 1: OpenRouter -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">1. OpenRouter API Key</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $openrouterKey ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $openrouterKey ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $openrouterKey ? 'Aktif (' . substr($openrouterKey, 0, 8) . '••••••••' . substr($openrouterKey, -4) . ')' : 'Belum Dikonfigurasi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Menyediakan akses ratusan model open-source (LLaMA 3, DeepSeek Chat/Coder, Qwen, Mistral) via satu upstream.</p>
                        <input type="password" name="OPENROUTER_API_KEY" placeholder="<?php echo $openrouterKey ? 'Masukkan kunci baru untuk mengganti...' : 'sk-or-v1-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                    </div>

                    <!-- Provider 2: OpenAI -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">2. OpenAI Official API Key</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $openaiKey ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $openaiKey ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $openaiKey ? 'Aktif (' . substr($openaiKey, 0, 7) . '••••••••' . substr($openaiKey, -4) . ')' : 'Belum Dikonfigurasi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Dibutuhkan untuk upstream model resmi OpenAI (GPT-4o, GPT-4o-mini).</p>
                        <input type="password" name="OPENAI_API_KEY" placeholder="<?php echo $openaiKey ? 'Masukkan kunci baru untuk mengganti...' : 'sk-proj-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                    </div>

                    <!-- Provider 3: Groq Cloud (LPU Ultra-Fast) -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-orange-500/10 border border-orange-500/20 flex items-center justify-center text-orange-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">3. Groq Cloud (LPU Ultra-Fast) API Key</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $groqKey ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $groqKey ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $groqKey ? 'Aktif (' . substr($groqKey, 0, 7) . '••••••••' . substr($groqKey, -4) . ')' : 'Belum Dikonfigurasi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Inference kecepatan kilat 500+ token/detik untuk LLaMA 3.3 70B & DeepSeek R1 Distill via Groq LPU.</p>
                        <input type="password" name="GROQ_API_KEY" placeholder="<?php echo $groqKey ? 'Masukkan kunci baru untuk mengganti...' : 'gsk_xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                    </div>

                    <!-- Provider 4: DeepSeek Direct -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">4. DeepSeek Direct API Key</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $deepseekKey ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $deepseekKey ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $deepseekKey ? 'Aktif (' . substr($deepseekKey, 0, 7) . '••••••••' . substr($deepseekKey, -4) . ')' : 'Belum Dikonfigurasi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Koneksi langsung ke DeepSeek V3 (Chat) & R1 (Reasoner) dengan harga termurah di dunia via api.deepseek.com.</p>
                        <input type="password" name="DEEPSEEK_API_KEY" placeholder="<?php echo $deepseekKey ? 'Masukkan kunci baru untuk mengganti...' : 'sk-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                    </div>

                    <!-- Provider 5: Google Gemini Direct -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">5. Google Gemini API Key</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $geminiKey ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $geminiKey ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $geminiKey ? 'Aktif (' . substr($geminiKey, 0, 7) . '••••••••' . substr($geminiKey, -4) . ')' : 'Belum Dikonfigurasi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Akses resmi Google AI Studio (Gemini 2.0 Flash, Gemini 1.5 Pro) menggunakan endpoint OpenAI-compatible resmi Google.</p>
                        <input type="password" name="GEMINI_API_KEY" placeholder="<?php echo $geminiKey ? 'Masukkan kunci baru untuk mengganti...' : 'AIzaSyxxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                    </div>

                    <!-- Provider 6: Mistral AI Direct -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-pink-500/10 border border-pink-500/20 flex items-center justify-center text-pink-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">6. Mistral AI Direct API Key</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $mistralKey ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $mistralKey ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $mistralKey ? 'Aktif (' . substr($mistralKey, 0, 7) . '••••••••' . substr($mistralKey, -4) . ')' : 'Belum Dikonfigurasi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Akses model resmi Mistral Large, Codestral, dan Ministral via api.mistral.ai.</p>
                        <input type="password" name="MISTRAL_API_KEY" placeholder="<?php echo $mistralKey ? 'Masukkan kunci baru untuk mengganti...' : 'xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                    </div>

                    <!-- Provider 7: Custom / Self-Hosted OpenAI Endpoint (Ollama / vLLM) -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-teal-500/10 border border-teal-500/20 flex items-center justify-center text-teal-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">7. Custom / Local AI Endpoint (Ollama / vLLM / LM Studio)</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $customBaseUrl ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $customBaseUrl ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $customBaseUrl ? 'Terkonfigurasi' : 'Belum Diisi'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Hubungkan router ke server AI lokal milik sendiri atau provider custom manapun yang kompatibel dengan format OpenAI.</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Base URL Endpoint</label>
                                <input type="text" name="CUSTOM_PROVIDER_BASE_URL" value="<?php echo htmlspecialchars($customBaseUrl); ?>" placeholder="http://localhost:11434/v1" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">API Key (Opsional / Dummy jika lokal)</label>
                                <input type="password" name="CUSTOM_PROVIDER_API_KEY" placeholder="<?php echo $customKey ? '••••••••' : 'Kosongkan jika Ollama lokal'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                            </div>
                        </div>
                    </div>

                    <!-- Provider 3: Telegram Bot -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">3. Telegram Bot Notifier & Customer Bot</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $telegramToken ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo $telegramToken ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo $telegramToken ? 'Terkonfigurasi' : 'Belum Diisi'; ?></span>
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Bot Token</label>
                                <input type="password" name="TELEGRAM_BOT_TOKEN" placeholder="<?php echo $telegramToken ? '••••••••' : '123456789:ABCdefGhIJKlmNoPQRstuVWxYz'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Telegram Admin IDs (koma-terpisah)</label>
                                <input type="text" name="TELEGRAM_ADMIN_IDS" value="<?php echo htmlspecialchars($telegramAdmins); ?>" placeholder="Contoh: 12345678,98765432" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                            </div>
                        </div>
                    </div>

                    <!-- Provider 4: Tripay Payment Gateway -->
                    <div class="p-5 rounded-xl border border-slate-800 bg-slate-950/60 space-y-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                    </svg>
                                </div>
                                <span class="font-bold text-sm text-white">4. Tripay Payment Gateway (QRIS Dinamis Otomatis)</span>
                            </div>

                            <span class="inline-flex items-center space-x-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                <span><?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'Siap Pakai (' . strtoupper($tripayMode) . ')' : 'Belum Lengkap'; ?></span>
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Memungkinkan pelanggan membeli saldo instan via QRIS di Telegram/Mobile/Web dengan verifikasi callback otomatis 3 detik.</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Merchant Code</label>
                                <input type="text" name="TRIPAY_MERCHANT_CODE" value="<?php echo htmlspecialchars($tripayMerchant); ?>" placeholder="Contoh: T12345" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Environment Mode</label>
                                <select name="TRIPAY_MODE" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-semibold">
                                    <option value="sandbox" <?php echo $tripayMode === 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testing Mode)</option>
                                    <option value="production" <?php echo $tripayMode === 'production' ? 'selected' : ''; ?>>Production (Live Mode)</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">API Key</label>
                            <input type="password" name="TRIPAY_API_KEY" placeholder="<?php echo $tripayApiKey ? '••••••••' . substr($tripayApiKey, -4) : 'DEV-xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Private Key (Secret Key Callback)</label>
                            <input type="password" name="TRIPAY_PRIVATE_KEY" placeholder="<?php echo $tripayPrivKey ? '••••••••' . substr($tripayPrivKey, -4) : 'xxxxxxxxxxxxxxxxxxxx'; ?>" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-slate-900 text-slate-100 font-mono placeholder:text-slate-600">
                        </div>

                        <div class="p-3.5 bg-indigo-500/10 border border-indigo-500/20 rounded-xl text-xs text-indigo-300 space-y-1">
                            <span class="font-bold flex items-center space-x-1.5">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                </svg>
                                <span>URL Callback Webhook untuk Dashboard Tripay:</span>
                            </span>
                            <code class="block font-mono bg-slate-950 px-3 py-1.5 rounded-lg border border-indigo-500/30 text-indigo-200 select-all font-semibold">https://ciptaone.com/api/v1/webhooks/tripay</code>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button type="submit" class="inline-flex items-center space-x-2 px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-500 shadow-lg shadow-indigo-600/30 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                            </svg>
                            <span>Simpan Semua Pengaturan</span>
                        </button>
                    </div>
                </form>
            </div>

        <!-- ========================================== -->
        <!-- TAB 2: MODELS & PRICING                    -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'models'): ?>
            <div class="space-y-6">
                <!-- Add Model Card -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
                    <h3 class="text-base font-bold text-white mb-4 flex items-center space-x-2">
                        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Tambah Model AI Baru</span>
                    </h3>
                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
                        <input type="hidden" name="admin_action" value="add_model">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">

                        <div>
                            <label class="block font-bold text-slate-300 uppercase mb-1.5">Public Model ID</label>
                            <input type="text" name="public_id" required placeholder="Contoh: deepseek/deepseek-chat" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-300 uppercase mb-1.5">Provider</label>
                            <select name="provider_slug" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-semibold">
                                <?php foreach ($providers as $prov): ?>
                                    <option value="<?php echo htmlspecialchars($prov['slug']); ?>" <?php echo !$prov['enabled'] ? 'class="text-slate-500"' : ''; ?>>
                                        <?php echo htmlspecialchars($prov['display_name']); ?><?php echo $prov['enabled'] ? '' : ' (Nonaktif)'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-300 uppercase mb-1.5">Upstream Model Name</label>
                            <input type="text" name="upstream_model" placeholder="Sama dengan Model ID jika kosong" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-300 uppercase mb-1.5">Tarif Input / 1M Token (Rp)</label>
                            <input type="number" name="input_price_per_million" value="15000" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-300 uppercase mb-1.5">Tarif Output / 1M Token (Rp)</label>
                            <input type="number" name="output_price_per_million" value="30000" class="w-full px-3.5 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full py-2 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl shadow-lg shadow-indigo-600/30 transition flex items-center justify-center space-x-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                <span>Tambahkan Model</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Models Table -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/80 border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4">Model ID</th>
                                <th class="px-6 py-4">Provider</th>
                                <th class="px-6 py-4">Tarif Input (1M)</th>
                                <th class="px-6 py-4">Tarif Output (1M)</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/80">
                            <?php foreach ($models as $m): ?>
                                <tr class="hover:bg-slate-800/40 transition">
                                    <td class="px-6 py-4 font-mono font-bold text-white"><?php echo htmlspecialchars($m['public_id']); ?></td>
                                    <td class="px-6 py-4 text-slate-300 font-semibold"><?php echo htmlspecialchars($m['provider_slug']); ?></td>
                                    <td class="px-6 py-4 text-slate-300 font-semibold">Rp<?php echo number_format((int)$m['input_price_per_million'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-4 text-slate-300 font-semibold">Rp<?php echo number_format((int)$m['output_price_per_million'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $m['enabled'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-500 border border-slate-700'; ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?php echo $m['enabled'] ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                            <span><?php echo $m['enabled'] ? 'Online' : 'Disabled'; ?></span>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="admin_action" value="toggle_model">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">
                                            <input type="hidden" name="model_id" value="<?php echo htmlspecialchars($m['id']); ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $m['enabled'] ? '0' : '1'; ?>">
                                            <button type="submit" class="inline-flex items-center space-x-1 px-3 py-1 text-xs font-semibold rounded-lg transition <?php echo $m['enabled'] ? 'bg-rose-500/10 text-rose-400 border border-rose-500/20 hover:bg-rose-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20'; ?>">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                </svg>
                                                <span><?php echo $m['enabled'] ? 'Matikan' : 'Aktifkan'; ?></span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Providers Management Card -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-bold text-white flex items-center space-x-2">
                                <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                                <span>Daftar Provider AI Terdaftar</span>
                            </h3>
                            <p class="text-xs text-slate-400 mt-1">Aktifkan atau matikan provider secara instan. Semua model di bawah provider yang dinonaktifkan akan otomatis berhenti melayani permintaan.</p>
                        </div>
                    </div>

                    <!-- Provider List Table -->
                    <div class="border border-slate-800 rounded-xl overflow-hidden">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-950/80 border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                                <tr>
                                    <th class="px-5 py-3">Nama Provider</th>
                                    <th class="px-5 py-3">Slug</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/80">
                                <?php foreach ($providers as $prov): ?>
                                    <tr class="hover:bg-slate-800/40 transition">
                                        <td class="px-5 py-3 font-bold text-white"><?php echo htmlspecialchars($prov['display_name']); ?></td>
                                        <td class="px-5 py-3 font-mono text-slate-300"><?php echo htmlspecialchars($prov['slug']); ?></td>
                                        <td class="px-5 py-3">
                                            <span class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $prov['enabled'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-500 border border-slate-700'; ?>">
                                                <span class="w-1.5 h-1.5 rounded-full <?php echo $prov['enabled'] ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                                <span><?php echo $prov['enabled'] ? 'Aktif' : 'Nonaktif'; ?></span>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-right">
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="admin_action" value="toggle_provider">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">
                                                <input type="hidden" name="provider_id" value="<?php echo htmlspecialchars($prov['id']); ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $prov['enabled'] ? '0' : '1'; ?>">
                                                <button type="submit" class="inline-flex items-center space-x-1 px-2.5 py-1 text-xs font-semibold rounded-lg transition <?php echo $prov['enabled'] ? 'bg-rose-500/10 text-rose-400 border border-rose-500/20 hover:bg-rose-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20'; ?>">
                                                    <span><?php echo $prov['enabled'] ? 'Nonaktifkan' : 'Aktifkan'; ?></span>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Custom Provider Form -->
                    <div class="pt-4 border-t border-slate-800">
                        <h4 class="text-xs font-bold text-slate-300 uppercase mb-3 flex items-center space-x-1.5">
                            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            <span>Daftarkan Provider Kustom Baru (Any OpenAI-Compatible)</span>
                        </h4>
                        <form method="POST" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                            <input type="hidden" name="admin_action" value="add_provider">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">

                            <div>
                                <label class="block font-semibold text-slate-400 mb-1">Provider Slug</label>
                                <input type="text" name="slug" required placeholder="misal: together, novita" class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-400 mb-1">Display Name</label>
                                <input type="text" name="display_name" required placeholder="misal: Together AI" class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-400 mb-1">Base URL (Opsional)</label>
                                <input type="text" name="base_url" placeholder="https://api.together.xyz/v1" class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-400 mb-1">API Key (Opsional)</label>
                                <input type="password" name="api_key" placeholder="Kunci API Upstream" class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-950 text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono">
                            </div>
                            <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                                <button type="submit" class="py-2 px-5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl border border-slate-700 transition flex items-center space-x-1.5 shadow">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                    <span>Simpan Provider Baru</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <!-- ========================================== -->
        <!-- TAB 3: TOP-UPS                             -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'topups'): ?>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950/80 border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Order ID</th>
                            <th class="px-6 py-4">Pengguna</th>
                            <th class="px-6 py-4">Metode</th>
                            <th class="px-6 py-4">Nominal</th>
                            <th class="px-6 py-4">Kredit</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Bukti Transfer</th>
                            <th class="px-6 py-4 text-right">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        <?php if (empty($topups)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center text-slate-500">Belum ada riwayat transaksi top-up.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($topups as $t): ?>
                                <tr class="hover:bg-slate-800/40 transition">
                                    <td class="px-6 py-4 font-mono font-semibold text-white"><?php echo htmlspecialchars($t['order_id']); ?></td>
                                    <td class="px-6 py-4 text-slate-300"><?php echo htmlspecialchars($t['user_email']); ?></td>
                                    <td class="px-6 py-4 text-slate-400 font-mono text-[11px]"><?php echo htmlspecialchars($t['payment_method']); ?></td>
                                    <td class="px-6 py-4 font-bold text-white">Rp<?php echo number_format((int)$t['amount_minor'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-4 font-semibold text-indigo-400">+<?php echo number_format((int)$t['credits_to_grant'], 0, ',', '.'); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?php echo $t['status'] === 'paid' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : ($t['status'] === 'rejected' ? 'bg-rose-500/10 text-rose-400 border border-rose-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20'); ?>">
                                            <span class="w-1.5 h-1.5 rounded-full <?php echo $t['status'] === 'paid' ? 'bg-emerald-400' : ($t['status'] === 'rejected' ? 'bg-rose-400' : 'bg-amber-400'); ?>"></span>
                                            <span><?php echo htmlspecialchars($t['status']); ?></span>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (!empty($t['proof_file_key'])): ?>
                                            <a href="/api/v1/admin/topups/<?php echo urlencode($t['order_id']); ?>/proof" target="_blank" class="inline-flex items-center space-x-1 text-indigo-400 hover:text-indigo-300 font-semibold underline">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                                <span>Buka Bukti</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-600">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right space-x-2">
                                        <?php if ($t['status'] !== 'paid' && $t['status'] !== 'rejected'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="admin_action" value="review_topup">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">
                                                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($t['order_id']); ?>">
                                                <input type="hidden" name="decision" value="paid">
                                                <button type="submit" class="inline-flex items-center space-x-1 px-3 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 font-semibold rounded-lg transition">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    <span>Setujui</span>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="admin_action" value="review_topup">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($adminCsrfToken); ?>">
                                                <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($t['order_id']); ?>">
                                                <input type="hidden" name="decision" value="rejected">
                                                <button type="submit" class="inline-flex items-center space-x-1 px-3 py-1 bg-rose-500/10 text-rose-400 border border-rose-500/20 hover:bg-rose-500/20 font-semibold rounded-lg transition">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                    <span>Tolak</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <!-- ========================================== -->
        <!-- TAB 4: USERS & BALANCES                    -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'users'): ?>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950/80 border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-4">User ID / Email</th>
                            <th class="px-6 py-4">Role</th>
                            <th class="px-6 py-4">Saldo Kredit</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Tanggal Daftar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        <?php foreach ($usersList as $u): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-white"><?php echo htmlspecialchars($u['email_normalized']); ?></div>
                                    <div class="font-mono text-[10px] text-slate-500"><?php echo htmlspecialchars($u['id']); ?></div>
                                </td>
                                <td class="px-6 py-4 font-bold uppercase text-slate-400"><?php echo htmlspecialchars($u['role']); ?></td>
                                <td class="px-6 py-4 font-bold text-indigo-400"><?php echo number_format((int)($u['balance_credits'] ?? 0), 0, ',', '.'); ?> Kredit</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                        <span><?php echo htmlspecialchars($u['status']); ?></span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-400 font-mono"><?php echo date('Y-m-d H:i', strtotime($u['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <!-- ========================================== -->
        <!-- TAB 5: AUDIT LOGS                          -->
        <!-- ========================================== -->
        <?php elseif ($activeTab === 'audit'): ?>
            <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-950/80 border-b border-slate-800 text-slate-400 font-bold uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Waktu</th>
                            <th class="px-6 py-4">Admin Actor</th>
                            <th class="px-6 py-4">Aksi</th>
                            <th class="px-6 py-4">Target</th>
                            <th class="px-6 py-4">Metadata</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80">
                        <?php foreach ($auditLogs as $l): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="px-6 py-4 font-mono text-slate-400"><?php echo date('Y-m-d H:i:s', strtotime($l['created_at'])); ?></td>
                                <td class="px-6 py-4 font-semibold text-slate-200"><?php echo htmlspecialchars($l['admin_user_id']); ?></td>
                                <td class="px-6 py-4 font-mono text-indigo-400 font-bold"><?php echo htmlspecialchars($l['action']); ?></td>
                                <td class="px-6 py-4 font-mono text-slate-300"><?php echo htmlspecialchars($l['target_type'] . ':' . $l['target_id']); ?></td>
                                <td class="px-6 py-4 font-mono text-[11px] text-slate-400 max-w-xs truncate"><?php echo htmlspecialchars($l['metadata_json'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <footer class="border-t border-slate-800/60 bg-slate-950 py-4 text-center text-xs text-slate-500">
        AI Ciptaone Router &copy; <?php echo date('Y'); ?> &bull; Production Ready PHP 8.x + MySQL / SQLite
    </footer>
</body>
</html>
