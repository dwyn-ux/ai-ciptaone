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

// 1. Strict Environment & Auth Check (Never allow local IP bypass in admin)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$rateLimiter = new \Ciptaone\Core\RateLimiter($db);

$cookieName = (string)$config->get('SESSION_COOKIE_NAME', 'ciptaone_session');
$sessionCookie = $_COOKIE[$cookieName] ?? '';
$currentUser = $sessionCookie ? $authService->getUserFromSession($sessionCookie) : null;
// Strict admin privilege verification: MUST have active session with role 'admin'
$isAdmin = ($currentUser !== null && ($currentUser['role'] ?? '') === 'admin');

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

// 3. Handle Admin Login Form Action & Rate Limiting
$loginError = '';
$adminCount = 0;
try {
    $row = $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
    $adminCount = (int)($row['count'] ?? 0);
} catch (\Throwable) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'admin_login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        try {
            // Anti-Brute Force Protection: Max 5 failed attempts per 5 minutes per IP
            $rateLimiter->checkRateLimit('admin_login_ip_' . md5($clientIp), 5, 300);

            [$user, $rawToken] = $authService->login($email, $password);
            if (($user['role'] ?? '') !== 'admin') {
                throw new Exception('Akses ditolak: Akun Anda tidak memiliki hak akses administrator.');
            }

            $secureCookie = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443');
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
                <!-- Initial Setup View: CLI only for maximum security -->
                <div class="text-center space-y-3">
                    <div class="w-12 h-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center mx-auto text-amber-400 shadow-lg shadow-amber-500/10">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h1 class="text-xl font-extrabold text-white tracking-tight">Akun Admin Belum Dibuat</h1>
                    <p class="text-xs text-slate-400">Demi mencegah pengambilalihan akun via web oleh pihak luar, pembuatan akun administrator pertama hanya diizinkan melalui terminal server.</p>
                </div>

                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 space-y-2 text-xs">
                    <p class="text-slate-300 font-semibold flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <span>Jalankan di Terminal / SSH Server:</span>
                    </p>
                    <code class="block font-mono bg-slate-900 p-2.5 rounded-lg border border-slate-800 text-emerald-400 select-all break-all">php create_admin.php admin@decomu.site PasswordKuat123#</code>
                    <p class="text-[11px] text-slate-500 pt-1">Setelah akun admin dibuat melalui terminal, refresh halaman ini untuk login.</p>
                </div>
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
            throw new Exception('Pengubahan kunci API & secret gateway melalui web telah dikunci permanen demi keamanan server. Silakan edit file .env di server hosting Anda.');
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

$openrouterKey = (string)$config->get('OPENROUTER_API_KEY', '');
$openaiKey = (string)$config->get('OPENAI_API_KEY', '');
$groqKey = (string)$config->get('GROQ_API_KEY', '');
$deepseekKey = (string)$config->get('DEEPSEEK_API_KEY', '');
$geminiKey = (string)$config->get('GEMINI_API_KEY', '');
$mistralKey = (string)$config->get('MISTRAL_API_KEY', '');
$customBaseUrl = (string)$config->get('CUSTOM_PROVIDER_BASE_URL', '');
$customKey = (string)$config->get('CUSTOM_PROVIDER_API_KEY', '');

$telegramToken = (string)$config->get('TELEGRAM_BOT_TOKEN', '');
$telegramAdmins = (string)$config->get('TELEGRAM_ADMIN_IDS', '');
$tripayApiKey = (string)$config->get('TRIPAY_API_KEY', '');
$tripayPrivKey = (string)$config->get('TRIPAY_PRIVATE_KEY', '');
$tripayMerchant = (string)$config->get('TRIPAY_MERCHANT_CODE', '');
$tripayMode = (string)$config->get('TRIPAY_MODE', 'sandbox');

$duitkuApiKey = (string)$config->get('DUITKU_API_KEY', '');
$duitkuMerchant = (string)$config->get('DUITKU_MERCHANT_CODE', '');
$duitkuMode = (string)$config->get('DUITKU_MODE', 'sandbox');
$duitkuCallback = (string)$config->get('DUITKU_CALLBACK_URL', 'https://ciptaone.com/api/v1/webhooks/duitku');

$manualBankName = (string)$config->get('MANUAL_BANK_NAME', 'BCA / Mandiri / BRI');
$manualAccountNo = (string)$config->get('MANUAL_ACCOUNT_NO', '1234567890');
$manualAccountHolder = (string)$config->get('MANUAL_ACCOUNT_HOLDER', 'AI Ciptaone Router');
$manualQrisUrl = (string)$config->get('MANUAL_QRIS_IMAGE_URL', '');

$maxDailyCredits = (int)$config->get('MAX_DAILY_AI_CREDITS', 500000);
$todayStart = gmdate('Y-m-d 00:00:00');
$todayUsageRow = $db->fetchOne("SELECT COALESCE(SUM(billed_credits), 0) AS total_today FROM usage_events WHERE created_at >= ? AND status = 'success'", [$todayStart]);
$totalBilledToday = (int)($todayUsageRow['total_today'] ?? 0);

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
                
                <a href="/docs" target="_blank" class="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 font-semibold border border-indigo-500/30 transition">
                    <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                    <span>Dokumentasi API</span>
                </a>

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

            <a href="/docs" target="_blank" class="pb-3 px-3 flex items-center space-x-2 transition whitespace-nowrap border-b-2 border-transparent text-slate-400 hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                </svg>
                <span>Dokumentasi API ↗</span>
            </a>
        </div>

        <!-- ========================================== -->
        <!-- TAB 1: SECURITY & CREDENTIALS (.env)       -->
        <!-- ========================================== -->
        <?php if ($activeTab === 'settings'): ?>
            <div class="max-w-5xl space-y-8">
                <!-- Security Shield Notice Banner -->
                <div class="p-6 rounded-2xl bg-gradient-to-r from-emerald-950/60 via-slate-900 to-indigo-950/60 border border-emerald-500/30 shadow-xl space-y-3">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 shrink-0">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-white flex items-center space-x-2">
                                <span>Perlindungan Tingkat Tinggi: Kredensial Terkunci di .env</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Hardened</span>
                            </h2>
                            <p class="text-xs text-slate-300 mt-0.5">
                                Kunci API Provider AI, Secret Gateway Pembayaran, dan Nomor Rekening dikunci permanen pada file server <code class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-700 font-mono text-emerald-400">.env</code>.
                            </p>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 leading-relaxed pl-13">
                        Kredensial <strong>tidak disimpan di form database web</strong> dan tidak dapat diubah oleh siapapun melalui browser. Ini menjamin keamanan dari kebocoran kunci, pembajakan nomor rekening tujuan transaksi, maupun serangan injeksi web.
                    </p>
                </div>

                <!-- Financial Circuit Breaker (Rem Darurat Pengeluaran AI) -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
                    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-white">Circuit Breaker (Rem Darurat Tagihan AI Harian)</h3>
                                <p class="text-[11px] text-slate-400">Mencegah lonjakan tagihan tak terduga dengan membatasi total kredit harian seluruh sistem.</p>
                            </div>
                        </div>

                        <?php 
                            $isCircuitTripped = ($maxDailyCredits > 0 && $totalBilledToday >= $maxDailyCredits);
                            $usagePercent = ($maxDailyCredits > 0) ? min(100, round(($totalBilledToday / $maxDailyCredits) * 100, 1)) : 0;
                        ?>
                        <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-bold <?php echo $isCircuitTripped ? 'bg-rose-500/10 text-rose-400 border border-rose-500/30' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/30'; ?>">
                            <span class="w-2 h-2 rounded-full <?php echo $isCircuitTripped ? 'bg-rose-500 animate-pulse' : 'bg-emerald-400'; ?>"></span>
                            <span><?php echo $isCircuitTripped ? 'TERKUNCI (Batas Harian Tercapai)' : 'NORMAL / AKTIF'; ?></span>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                        <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-slate-400 block mb-1">Batas Maksimal Harian (.env)</span>
                            <span class="text-sm font-bold text-white font-mono"><?php echo $maxDailyCredits > 0 ? number_format($maxDailyCredits, 0, ',', '.') . ' Kredit' : 'Tidak Dibatasi'; ?></span>
                            <span class="text-[10px] text-slate-500 block mt-0.5"><?php echo $maxDailyCredits > 0 ? '(~Rp ' . number_format($maxDailyCredits, 0, ',', '.') . ' / hari)' : 'Unlimited'; ?></span>
                        </div>
                        <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-slate-400 block mb-1">Terpakai Hari Ini (00:00 UTC)</span>
                            <span class="text-sm font-bold font-mono <?php echo $isCircuitTripped ? 'text-rose-400' : 'text-emerald-400'; ?>"><?php echo number_format($totalBilledToday, 0, ',', '.'); ?> Kredit</span>
                            <span class="text-[10px] text-slate-500 block mt-0.5">(Rp <?php echo number_format($totalBilledToday, 0, ',', '.'); ?>)</span>
                        </div>
                        <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-slate-400 block mb-1">Persentase Kuota Hari Ini</span>
                            <span class="text-sm font-bold text-white font-mono"><?php echo $usagePercent; ?>%</span>
                            <div class="w-full bg-slate-800 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="h-full rounded-full <?php echo $usagePercent > 85 ? 'bg-rose-500' : ($usagePercent > 50 ? 'bg-amber-400' : 'bg-emerald-500'); ?>" style="width: <?php echo $usagePercent; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upstream AI Providers (.env Status) -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
                    <h3 class="text-sm font-bold text-white flex items-center space-x-2">
                        <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        <span>Status Kredensial AI Provider (Terkunci di .env)</span>
                    </h3>

                    <?php
                    $providerList = [
                        ['name' => 'OpenRouter (Aggregator 200+ Model)', 'env' => 'OPENROUTER_API_KEY', 'val' => $openrouterKey, 'desc' => 'LLaMA 3, DeepSeek Chat/Coder, Qwen, Mistral via openrouter.ai'],
                        ['name' => 'OpenAI Official API', 'env' => 'OPENAI_API_KEY', 'val' => $openaiKey, 'desc' => 'GPT-4o, GPT-4o-mini via api.openai.com'],
                        ['name' => 'Groq Cloud (LPU Ultra-Fast)', 'env' => 'GROQ_API_KEY', 'val' => $groqKey, 'desc' => 'Inference ultra-cepat 500+ tok/s untuk LLaMA 3.3 via api.groq.com'],
                        ['name' => 'DeepSeek Direct API', 'env' => 'DEEPSEEK_API_KEY', 'val' => $deepseekKey, 'desc' => 'Koneksi langsung ke DeepSeek V3 & R1 via api.deepseek.com'],
                        ['name' => 'Google Gemini (Official)', 'env' => 'GEMINI_API_KEY', 'val' => $geminiKey, 'desc' => 'Gemini 2.0 Flash & Gemini 1.5 Pro via Google AI Studio'],
                        ['name' => 'Mistral AI Direct', 'env' => 'MISTRAL_API_KEY', 'val' => $mistralKey, 'desc' => 'Mistral Large & Codestral via api.mistral.ai'],
                    ];
                    ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($providerList as $p): ?>
                            <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-xs text-white"><?php echo htmlspecialchars($p['name']); ?></span>
                                    <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo $p['val'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?php echo $p['val'] ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                        <span><?php echo $p['val'] ? 'Terkonfigurasi di .env' : 'Belum Disetel'; ?></span>
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($p['desc']); ?></p>
                                <div class="pt-1 flex items-center justify-between text-[11px] font-mono">
                                    <span class="text-slate-500">Key: <?php echo htmlspecialchars($p['env']); ?></span>
                                    <?php if ($p['val']): ?>
                                        <span class="text-emerald-400 font-bold"><?php echo substr($p['val'], 0, 4) . '••••••••' . substr($p['val'], -4); ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-600 italic">kosong</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Payment Gateways & Banking (.env Status) -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
                    <h3 class="text-sm font-bold text-white flex items-center space-x-2">
                        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                        <span>Status Gateway Pembayaran & Rekening Penampung (Terkunci di .env)</span>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Tripay Card -->
                        <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-xs text-white">Tripay (Closed Dynamic QRIS)</span>
                                <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                    <span><?php echo ($tripayApiKey && $tripayPrivKey && $tripayMerchant) ? 'Siap Pakai (' . strtoupper($tripayMode) . ')' : 'Belum Lengkap'; ?></span>
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-400">QRIS Dinamis otomatis dengan verifikasi signature callback HMAC-SHA256.</p>
                            <div class="space-y-1 text-[11px] font-mono text-slate-400 pt-1 border-t border-slate-800">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">TRIPAY_MERCHANT_CODE:</span>
                                    <span class="text-white"><?php echo $tripayMerchant ? htmlspecialchars($tripayMerchant) : '<span class="text-slate-600">belum diisi</span>'; ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">TRIPAY_PRIVATE_KEY:</span>
                                    <span class="text-emerald-400"><?php echo $tripayPrivKey ? '••••••••' . substr($tripayPrivKey, -4) : '<span class="text-slate-600">belum diisi</span>'; ?></span>
                                </div>
                            </div>
                            <div class="p-2.5 bg-slate-900 border border-slate-800 rounded-lg text-[10px] space-y-0.5">
                                <span class="text-slate-400 font-sans block">URL Webhook Callback Tripay:</span>
                                <code class="text-indigo-400 font-mono select-all block">https://ciptaone.com/api/v1/webhooks/tripay</code>
                            </div>
                        </div>

                        <!-- Duitku Card -->
                        <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-xs text-white">Duitku (Full Service: QRIS & VA)</span>
                                <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo ($duitkuApiKey && $duitkuMerchant) ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?php echo ($duitkuApiKey && $duitkuMerchant) ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                    <span><?php echo ($duitkuApiKey && $duitkuMerchant) ? 'Siap Pakai (' . strtoupper($duitkuMode) . ')' : 'Belum Lengkap'; ?></span>
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-400">Pembayaran resmi berlisensi Bank Indonesia via QRIS & Virtual Account bank.</p>
                            <div class="space-y-1 text-[11px] font-mono text-slate-400 pt-1 border-t border-slate-800">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">DUITKU_MERCHANT_CODE:</span>
                                    <span class="text-white"><?php echo $duitkuMerchant ? htmlspecialchars($duitkuMerchant) : '<span class="text-slate-600">belum diisi</span>'; ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">DUITKU_API_KEY:</span>
                                    <span class="text-emerald-400"><?php echo $duitkuApiKey ? '••••••••' . substr($duitkuApiKey, -4) : '<span class="text-slate-600">belum diisi</span>'; ?></span>
                                </div>
                            </div>
                            <div class="p-2.5 bg-slate-900 border border-slate-800 rounded-lg text-[10px] space-y-0.5">
                                <span class="text-slate-400 font-sans block">URL Webhook Callback Duitku:</span>
                                <code class="text-emerald-400 font-mono select-all block">https://ciptaone.com/api/v1/webhooks/duitku</code>
                            </div>
                        </div>

                        <!-- Rekening Bank Manual -->
                        <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-xs text-white">Rekening Bank Manual (Transfer)</span>
                                <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    <span>Terkunci di .env</span>
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-400">Rekening tujuan transfer manual. Kredit hanya bertambah jika Anda menekan Setujui.</p>
                            <div class="space-y-1 text-[11px] font-mono text-slate-400 pt-1 border-t border-slate-800">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">MANUAL_BANK_NAME:</span>
                                    <span class="text-white font-bold"><?php echo htmlspecialchars($manualBankName); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">MANUAL_ACCOUNT_NO:</span>
                                    <span class="text-amber-400 font-bold"><?php echo htmlspecialchars($manualAccountNo); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">MANUAL_ACCOUNT_HOLDER:</span>
                                    <span class="text-white"><?php echo htmlspecialchars($manualAccountHolder); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Telegram Bot -->
                        <div class="p-4 rounded-xl border border-slate-800 bg-slate-950/70 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-xs text-white">Bot Telegram Admin & Notifier</span>
                                <span class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-[10px] font-semibold <?php echo $telegramToken ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-slate-800 text-slate-400 border border-slate-700'; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?php echo $telegramToken ? 'bg-emerald-400' : 'bg-slate-500'; ?>"></span>
                                    <span><?php echo $telegramToken ? 'Terkonfigurasi' : 'Belum Diisi'; ?></span>
                                </span>
                            </div>
                            <p class="text-[11px] text-slate-400">Mengirimkan notifikasi invoice top-up & tombol persetujuan admin via Telegram pribadi.</p>
                            <div class="space-y-1 text-[11px] font-mono text-slate-400 pt-1 border-t border-slate-800">
                                <div class="flex justify-between">
                                    <span class="text-slate-500">TELEGRAM_BOT_TOKEN:</span>
                                    <span class="text-emerald-400"><?php echo $telegramToken ? '••••••••' . substr($telegramToken, -4) : '<span class="text-slate-600">kosong</span>'; ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-slate-500">TELEGRAM_ADMIN_IDS:</span>
                                    <span class="text-white"><?php echo $telegramAdmins ? htmlspecialchars($telegramAdmins) : '<span class="text-slate-600">kosong</span>'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- How to edit credentials guide -->
                <div class="p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs text-slate-300 space-y-2">
                    <p class="font-bold text-slate-200 flex items-center space-x-2">
                        <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Cara Mengedit atau Menambah Kunci (.env Server):</span>
                    </p>
                    <p class="text-slate-400 leading-relaxed">
                        Buka file <code class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-700 text-emerald-400 font-mono">.env</code> di root hosting atau folder <code class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-700 text-emerald-400 font-mono">php-backend/.env</code> melalui cPanel File Manager / SSH terminal. Simpan perubahan, dan konfigurasi baru akan otomatis aktif seketika tanpa perlu restart.
                    </p>
                </div>
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
