<?php
declare(strict_types=1);

/**
 * ========================================================
 * DECOMU AI ROUTER - Web Git Push Helper
 * Accessible locally at: http://127.0.0.1:8000/git_push.php
 * ========================================================
 */

$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || str_starts_with($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');

if (!$isLocal) {
    http_response_code(403);
    echo "403 Forbidden: Only accessible from localhost.";
    exit;
}

$rootDir = dirname(__DIR__, 2);
chdir($rootDir);

$repoUrl = 'https://github.com/dwyn-ux/ai-ciptaone.git';
$output = [];
$status = 0;

function runGit(string $cmd, string $dir): string {
    $descriptor = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($cmd, $descriptor, $pipes, $dir);
    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return trim($stdout . "\n" . $stderr);
    }
    return "Gagal menjalankan proses: {$cmd}";
}

$action = $_POST['action'] ?? '';
$results = [];

if ($action === 'push') {
    $results[] = "📁 Working Directory: " . getcwd();
    
    // 1. git init
    $results[] = "• git init: " . runGit("git init", $rootDir);
    
    // 2. branch -M main
    $results[] = "• git branch -M main: " . runGit("git branch -M main", $rootDir);
    
    // 3. remote
    runGit("git remote remove origin", $rootDir);
    $results[] = "• git remote add origin: " . runGit("git remote add origin {$repoUrl}", $rootDir);
    
    // 4. git add
    $results[] = "• git add . : " . runGit("git add .", $rootDir);
    
    // 5. git commit
    $results[] = "• git commit: " . runGit('git commit -m "feat: complete migration AI Router to PHP 8.x + MySQL for cPanel shared hosting"', $rootDir);
    
    // 6. git push
    $pushRes = runGit("git push -u origin main", $rootDir);
    $results[] = "• git push -u origin main:\n" . $pushRes;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Git Push to GitHub — Decomu AI Router</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen p-8 font-mono text-sm">
    <div class="max-w-3xl mx-auto space-y-6">
        <div class="border-b border-slate-800 pb-4">
            <h1 class="text-xl font-bold text-sky-400">🚀 Git Push Helper</h1>
            <p class="text-xs text-slate-400 mt-1">Target: <?php echo htmlspecialchars($repoUrl); ?></p>
        </div>

        <?php if ($action !== 'push'): ?>
            <div class="bg-slate-800/80 p-6 rounded-2xl border border-slate-700 space-y-4">
                <p class="text-slate-300">
                    Klik tombol di bawah untuk langsung mengunggah seluruh codebase yang sudah dimigrasikan ke repositori GitHub:
                </p>
                <div class="p-3 bg-slate-950 rounded-xl text-xs text-emerald-400">
                    Repo: <?php echo htmlspecialchars($repoUrl); ?><br>
                    Branch: main<br>
                    Secret Protected: .env dan storage database dikecualikan oleh .gitignore
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="push">
                    <button type="submit" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl shadow-lg transition">
                        🚀 Eksekusi Git Push Sekarang
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="bg-slate-950 p-6 rounded-2xl border border-slate-800 space-y-3">
                <h3 class="text-xs uppercase font-bold text-slate-400">Log Eksekusi Git:</h3>
                <pre class="text-xs leading-relaxed text-slate-200 overflow-x-auto whitespace-pre-wrap"><?php echo htmlspecialchars(implode("\n\n", $results)); ?></pre>
                
                <div class="pt-4 border-t border-slate-800 flex items-center justify-between">
                    <a href="?action=" class="text-xs text-sky-400 hover:underline">← Kembali</a>
                    <a href="https://github.com/dwyn-ux/ai-ciptaone" target="_blank" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-lg">
                        Buka Repositori di GitHub ↗
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
