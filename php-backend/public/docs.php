<?php
declare(strict_types=1);

/**
 * AI Ciptaone Router - Interactive Web API Documentation Portal
 * Standalone, high-performance, responsive UI with zero external runtime dependencies.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use Ciptaone\Core\Config;
use Ciptaone\Core\Database;

$config = Config::getInstance();
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000');

// Fetch active models from database if available
$models = [];
try {
    $db = Database::getInstance($config);
    $models = $db->fetchAll("SELECT id, name, upstream_model_id, input_cost_per_k_credits, output_cost_per_k_credits, is_active FROM models WHERE is_active = 1 ORDER BY name ASC");
} catch (\Throwable $e) {
    // Fallback mock models
    $models = [
        ['id' => 'mock/echo-v1', 'name' => 'Echo Model (Mock Testing)', 'input_cost_per_k_credits' => 1.0, 'output_cost_per_k_credits' => 1.0, 'is_active' => 1],
        ['id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek V3', 'input_cost_per_k_credits' => 2.5, 'output_cost_per_k_credits' => 10.0, 'is_active' => 1],
        ['id' => 'openai/gpt-4o-mini', 'name' => 'GPT-4o Mini', 'input_cost_per_k_credits' => 3.0, 'output_cost_per_k_credits' => 12.0, 'is_active' => 1]
    ];
}
?>
<!DOCTYPE html>
<html lang="id" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumentasi API & Integrasi - AI Ciptaone Router</title>
    <meta name="description" content="Dokumentasi resmi AI Ciptaone Router - API OpenAI Compatible multi-model dengan reservasi kredit atomik.">
    <!-- Tailwind CSS Play CDN for standalone zero-build deployment -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
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
        pre code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #0f172a;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #334155;
            border-radius: 9999px;
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col font-sans selection:bg-indigo-500 selection:text-white">

    <!-- Top Navigation Header -->
    <header class="sticky top-0 z-50 bg-slate-900/90 backdrop-blur-md border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="/docs" class="flex items-center space-x-2.5">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-white shadow-md shadow-indigo-500/20">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <span class="font-extrabold text-base tracking-tight text-white">AI Ciptaone Router</span>
                            <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 bg-indigo-500/10 text-indigo-400 rounded-md border border-indigo-500/30">Docs v1.0</span>
                        </div>
                        <p class="text-[11px] text-slate-400">OpenAI Compatible Gateway & Billing</p>
                    </div>
                </a>
            </div>

            <!-- Quick Links -->
            <div class="flex items-center space-x-2 sm:space-x-3 text-xs">
                <a href="/openapi.json" target="_blank" class="flex items-center space-x-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium border border-slate-700 transition">
                    <svg class="w-3.5 h-3.5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    <span class="hidden xs:inline">OpenAPI Spec (JSON)</span>
                    <span class="xs:hidden">OpenAPI</span>
                </a>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 flex-1 w-full grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Sidebar Navigation -->
        <aside class="lg:col-span-3 space-y-6">
            <div class="sticky top-24 space-y-4">
                <!-- Search Box -->
                <div class="relative">
                    <input 
                        type="text" 
                        id="endpointSearch" 
                        placeholder="Cari endpoint atau fitur..." 
                        class="w-full bg-slate-900 border border-slate-800 rounded-xl px-4 py-2 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition"
                        oninput="filterEndpoints(this.value)"
                    />
                    <svg class="w-4 h-4 text-slate-500 absolute right-3 top-2.5 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>

                <!-- Navigation List -->
                <nav class="space-y-1 text-xs font-medium max-h-[calc(100vh-180px)] overflow-y-auto custom-scrollbar pr-2">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1 mt-2">1. Memulai</div>
                    <a href="#quickstart" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">🚀 Ringkasan & Quickstart</a>
                    <a href="#authentication" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">🔑 Autentikasi API Key</a>
                    <a href="#sdks" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">💻 Contoh Kode (SDK)</a>

                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1 mt-4">2. OpenAI Compatible</div>
                    <a href="#ep-models" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition flex items-center justify-between">
                        <span>Daftar Model</span>
                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-400">GET</span>
                    </a>
                    <a href="#ep-chat" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition flex items-center justify-between">
                        <span>Chat Completions</span>
                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-400">POST</span>
                    </a>

                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1 mt-4">3. Dompet & Akun</div>
                    <a href="#ep-auth" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">Daftar & Login Sesi</a>
                    <a href="#ep-wallet" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition flex items-center justify-between">
                        <span>Cek Saldo Kredit</span>
                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-400">GET</span>
                    </a>
                    <a href="#ep-apikeys" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">Manajemen API Key</a>
                    <a href="#ep-topup" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">Top-up & Bukti Transfer</a>

                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-3 py-1 mt-4">4. Sistem & Spesifikasi</div>
                    <a href="#tokenomics" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">💰 Tarif & Reservasi Atomik</a>
                    <a href="#error-codes" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">⚠️ Kode Error & Penanganan</a>
                    <a href="#health" class="block px-3 py-2 rounded-lg text-slate-300 hover:text-white hover:bg-slate-900 transition">💓 Health Probe</a>
                </nav>
            </div>
        </aside>

        <!-- Main Documentation Content -->
        <main class="lg:col-span-9 space-y-12">

            <!-- Hero Section -->
            <section id="quickstart" class="bg-gradient-to-br from-slate-900 to-indigo-950/40 border border-slate-800 rounded-3xl p-6 sm:p-10 shadow-2xl relative overflow-hidden">
                <div class="absolute -right-16 -top-16 w-64 h-64 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>
                <div class="space-y-4 max-w-3xl">
                    <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-500/10 border border-indigo-500/30 text-indigo-400 text-xs font-semibold">
                        <span>⚡ Universal OpenAI-Compatible Gateway</span>
                    </div>
                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight leading-tight">
                        Dokumentasi API AI Ciptaone Router
                    </h1>
                    <p class="text-slate-300 text-sm sm:text-base leading-relaxed">
                        Gunakan model AI terkemuka (DeepSeek, GPT-4o, Claude, Mistral) melalui satu endpoint standar OpenAI. Nikmati proteksi saldo tanpa saldo negatif dengan sistem <span class="text-indigo-400 font-semibold">Atomic Credit Reservation</span> dan pembayaran terintegrasi Rupiah (Rp).
                    </p>

                    <!-- Base URL Box -->
                    <div class="pt-4">
                        <div class="text-xs font-semibold text-slate-400 mb-1.5 flex items-center justify-between">
                            <span>BASE URL API:</span>
                            <span class="text-emerald-400 font-mono text-[11px]">HTTP/1.1 & HTTP/2 Ready</span>
                        </div>
                        <div class="flex items-center space-x-2 bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 font-mono text-sm text-indigo-300">
                            <span class="text-slate-500 select-none">$</span>
                            <span id="baseUrlDisplay" class="flex-1 truncate"><?php echo htmlspecialchars($baseUrl); ?>/v1</span>
                            <button onclick="copyToClipboard('baseUrlDisplay')" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-xs rounded-lg text-slate-300 hover:text-white transition">
                                Salin
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Autentikasi -->
            <section id="authentication" class="space-y-4">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-6 bg-indigo-500 rounded-full"></span>
                    <h2 class="text-2xl font-bold text-white tracking-tight">Autentikasi API</h2>
                </div>
                <p class="text-slate-300 text-sm leading-relaxed">
                    Setiap panggilan ke endpoint gateway (<code class="bg-slate-900 text-indigo-300 px-1.5 py-0.5 rounded text-xs">/v1/*</code>) wajib menyertakan API Key aktif pada HTTP Header <code class="bg-slate-900 text-indigo-300 px-1.5 py-0.5 rounded text-xs">Authorization</code>.
                </p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-6 space-y-3 font-mono text-xs text-slate-300">
                    <div class="text-slate-500 text-[11px] font-sans font-semibold uppercase">HTTP Request Header:</div>
                    <div class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between text-indigo-300">
                        <code>Authorization: Bearer sk-live-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code>
                        <button onclick="navigator.clipboard.writeText('Authorization: Bearer sk-live-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')" class="text-slate-400 hover:text-white text-xs">Salin</button>
                    </div>
                    <p class="font-sans text-xs text-slate-400">
                        💡 <strong>Catatan Keamanan:</strong> API key di-hash dengan SHA-256 di database server. Secret plaintext hanya ditampilkan sekali saat dibuat melalui halaman web atau endpoint <code class="text-indigo-400 font-mono">/api/v1/api-keys</code>.
                    </p>
                </div>
            </section>

            <!-- SDKs & Integrasi Kode -->
            <section id="sdks" class="space-y-4">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-6 bg-indigo-500 rounded-full"></span>
                    <h2 class="text-2xl font-bold text-white tracking-tight">Contoh Integrasi Kode</h2>
                </div>
                <p class="text-slate-300 text-sm">
                    Karena AI Ciptaone Router 100% kompatibel dengan protokol OpenAI, Anda dapat menggunakan library resmi OpenAI tanpa perlu menginstal SDK baru.
                </p>

                <!-- Code Switcher Tabs -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
                    <div class="flex border-b border-slate-800 bg-slate-950/60 px-4 pt-2 space-x-2 text-xs font-semibold">
                        <button onclick="switchTab('tab-curl', this)" class="code-tab-btn py-2 px-4 border-b-2 border-indigo-500 text-indigo-400 font-bold transition">cURL</button>
                        <button onclick="switchTab('tab-python', this)" class="code-tab-btn py-2 px-4 border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition">Python (OpenAI SDK)</button>
                        <button onclick="switchTab('tab-node', this)" class="code-tab-btn py-2 px-4 border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition">Node.js / TypeScript</button>
                        <button onclick="switchTab('tab-php', this)" class="code-tab-btn py-2 px-4 border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition">PHP (cURL Native)</button>
                    </div>

                    <!-- cURL Tab -->
                    <div id="tab-curl" class="code-content p-4 sm:p-6 relative">
                        <button onclick="copyCode(this)" class="absolute top-4 right-4 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition">Salin</button>
                        <pre class="overflow-x-auto text-xs text-indigo-300 leading-relaxed custom-scrollbar"><code>curl <?php echo htmlspecialchars($baseUrl); ?>/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-live-YOUR_API_KEY" \
  -d '{
    "model": "deepseek/deepseek-chat",
    "messages": [
      {"role": "system", "content": "Kamu adalah asisten AI yang ramah dan cerdas."},
      {"role": "user", "content": "Berikan 3 tips belajar pemrograman efektif!"}
    ],
    "temperature": 0.7,
    "max_tokens": 512
  }'</code></pre>
                    </div>

                    <!-- Python Tab -->
                    <div id="tab-python" class="code-content p-4 sm:p-6 relative hidden">
                        <button onclick="copyCode(this)" class="absolute top-4 right-4 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition">Salin</button>
                        <pre class="overflow-x-auto text-xs text-emerald-300 leading-relaxed custom-scrollbar"><code>from openai import OpenAI

# Inisialisasi client OpenAI resmi dengan mengarahkan base_url ke AI Ciptaone Router
client = OpenAI(
    base_url="<?php echo htmlspecialchars($baseUrl); ?>/v1",
    api_key="sk-live-YOUR_API_KEY"
)

response = client.chat.completions.create(
    model="deepseek/deepseek-chat",
    messages=[
        {"role": "system", "content": "Kamu adalah asisten profesional."},
        {"role": "user", "content": "Buatkan ringkasan tren teknologi AI tahun 2026."}
    ],
    stream=True  # Dukungan Server-Sent Events (SSE) real-time
)

print("Respon AI: ", end="")
for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="", flush=True)
print()</code></pre>
                    </div>

                    <!-- Node.js Tab -->
                    <div id="tab-node" class="code-content p-4 sm:p-6 relative hidden">
                        <button onclick="copyCode(this)" class="absolute top-4 right-4 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition">Salin</button>
                        <pre class="overflow-x-auto text-xs text-amber-300 leading-relaxed custom-scrollbar"><code>import OpenAI from "openai";

const openai = new OpenAI({
  baseURL: "<?php echo htmlspecialchars($baseUrl); ?>/v1",
  apiKey: "sk-live-YOUR_API_KEY",
});

async function main() {
  const stream = await openai.chat.completions.create({
    model: "deepseek/deepseek-chat",
    messages: [{ role: "user", content: "Halo AI Router, perkenalkan dirimu!" }],
    stream: true,
  });

  for await (const chunk of stream) {
    process.stdout.write(chunk.choices[0]?.delta?.content || "");
  }
}

main().catch(console.error);</code></pre>
                    </div>

                    <!-- PHP Tab -->
                    <div id="tab-php" class="code-content p-4 sm:p-6 relative hidden">
                        <button onclick="copyCode(this)" class="absolute top-4 right-4 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition">Salin</button>
                        <pre class="overflow-x-auto text-xs text-sky-300 leading-relaxed custom-scrollbar"><code>&lt;?php
declare(strict_types=1);

$apiKey = 'sk-live-YOUR_API_KEY';
$endpoint = '<?php echo htmlspecialchars($baseUrl); ?>/v1/chat/completions';

$payload = [
    'model' => 'deepseek/deepseek-chat',
    'messages' => [
        ['role' => 'user', 'content' => 'Halo dari script PHP native!']
    ],
    'temperature' => 0.7
];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($payload)
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
echo $data['choices'][0]['message']['content'] ?? 'Error: ' . $response;</code></pre>
                    </div>
                </div>
            </section>

            <!-- ENDPOINT: GET /v1/models -->
            <section id="ep-models" class="endpoint-card space-y-4 pt-4 border-t border-slate-900">
                <div class="flex items-center space-x-3">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-blue-500/20 text-blue-400 border border-blue-500/30">GET</span>
                    <h3 class="text-xl font-bold text-white font-mono">/v1/models</h3>
                </div>
                <p class="text-slate-300 text-sm">
                    Mengambil daftar seluruh model AI aktif yang didukung router beserta metadata context length dan harga kredit per 1.000 token.
                </p>

                <!-- Model Table List -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-lg">
                    <div class="p-4 border-b border-slate-800 flex items-center justify-between">
                        <span class="text-xs font-semibold text-slate-300">Model Terdaftar Saat Ini:</span>
                        <a href="/v1/models" target="_blank" class="text-xs text-indigo-400 hover:underline">Lihat Response JSON Langsung &rarr;</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-950 text-slate-400 uppercase font-semibold text-[11px] border-b border-slate-800">
                                <tr>
                                    <th class="px-4 py-3">Model ID</th>
                                    <th class="px-4 py-3">Nama Tampilan</th>
                                    <th class="px-4 py-3">Tarif Input (1K)</th>
                                    <th class="px-4 py-3">Tarif Output (1K)</th>
                                    <th class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 text-slate-300">
                                <?php foreach ($models as $m): ?>
                                    <tr class="hover:bg-slate-800/40 transition">
                                        <td class="px-4 py-3 font-mono font-bold text-indigo-300"><?php echo htmlspecialchars((string)$m['id']); ?></td>
                                        <td class="px-4 py-3"><?php echo htmlspecialchars((string)$m['name']); ?></td>
                                        <td class="px-4 py-3"><?php echo number_format((float)$m['input_cost_per_k_credits'], 2); ?> Kredit</td>
                                        <td class="px-4 py-3"><?php echo number_format((float)$m['output_cost_per_k_credits'], 2); ?> Kredit</td>
                                        <td class="px-4 py-3">
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">Aktif</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ENDPOINT: POST /v1/chat/completions -->
            <section id="ep-chat" class="endpoint-card space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-3">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">POST</span>
                    <h3 class="text-xl font-bold text-white font-mono">/v1/chat/completions</h3>
                </div>
                <p class="text-slate-300 text-sm">
                    Mengirim pesan percakapan ke model AI terpilih. Mendukung respon langsung JSON atau Server-Sent Events (SSE) streaming secara real-time.
                </p>

                <!-- Parameter Table -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-6 space-y-4 shadow-lg">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Parameter Request Body (JSON)</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="text-slate-400 border-b border-slate-800 pb-2">
                                <tr>
                                    <th class="py-2 pr-4 font-mono">Parameter</th>
                                    <th class="py-2 pr-4">Tipe</th>
                                    <th class="py-2 pr-4">Wajib</th>
                                    <th class="py-2">Keterangan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800 text-slate-300 font-mono text-[11px]">
                                <tr>
                                    <td class="py-2.5 font-bold text-indigo-400">model</td>
                                    <td class="py-2.5 text-slate-400">string</td>
                                    <td class="py-2.5 text-amber-400 font-sans font-semibold">Ya</td>
                                    <td class="py-2.5 font-sans">ID model aktif, misalnya: <code class="text-indigo-300">deepseek/deepseek-chat</code></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 font-bold text-indigo-400">messages</td>
                                    <td class="py-2.5 text-slate-400">array</td>
                                    <td class="py-2.5 text-amber-400 font-sans font-semibold">Ya</td>
                                    <td class="py-2.5 font-sans">Array object berisi <code class="text-indigo-300">{"role": "user|system|assistant", "content": "..."}</code></td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 font-bold text-indigo-400">stream</td>
                                    <td class="py-2.5 text-slate-400">boolean</td>
                                    <td class="py-2.5 text-slate-500 font-sans">Opsional</td>
                                    <td class="py-2.5 font-sans">Default <code class="text-indigo-300">false</code>. Set <code class="text-indigo-300">true</code> untuk streaming SSE chunk.</td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 font-bold text-indigo-400">temperature</td>
                                    <td class="py-2.5 text-slate-400">number</td>
                                    <td class="py-2.5 text-slate-500 font-sans">Opsional</td>
                                    <td class="py-2.5 font-sans">Rentang 0.0 s.d 2.0 (default 0.7).</td>
                                </tr>
                                <tr>
                                    <td class="py-2.5 font-bold text-indigo-400">max_tokens</td>
                                    <td class="py-2.5 text-slate-400">integer</td>
                                    <td class="py-2.5 text-slate-500 font-sans">Opsional</td>
                                    <td class="py-2.5 font-sans">Batas token maksimal output (digunakan untuk kalkulasi kredit reservasi).</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Example Response -->
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-6 space-y-3 font-mono text-xs">
                    <div class="flex items-center justify-between text-slate-400 font-sans text-xs">
                        <span class="font-bold uppercase tracking-wider text-[11px]">Contoh Response Sukses (stream: false)</span>
                        <span class="text-emerald-400 font-semibold">200 OK</span>
                    </div>
                    <pre class="overflow-x-auto text-emerald-300 leading-relaxed custom-scrollbar bg-slate-950 p-4 rounded-xl border border-slate-800/80"><code>{
  "id": "chatcmpl-9b87c6d5e4f3a2b1",
  "object": "chat.completion",
  "created": 1714000000,
  "model": "deepseek/deepseek-chat",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "Halo! Ada yang bisa saya bantu hari ini?"
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 12,
    "completion_tokens": 10,
    "total_tokens": 22
  }
}</code></pre>
                </div>
            </section>

            <!-- ENDPOINT: GET /api/v1/wallet -->
            <section id="ep-wallet" class="endpoint-card space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-3">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-blue-500/20 text-blue-400 border border-blue-500/30">GET</span>
                    <h3 class="text-xl font-bold text-white font-mono">/api/v1/wallet</h3>
                </div>
                <p class="text-slate-300 text-sm">
                    Mengecek sisa saldo kredit pengguna yang saat ini terautentikasi (via Session Cookie atau Header Bearer).
                </p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-6 space-y-3 font-mono text-xs">
                    <div class="flex items-center justify-between text-slate-400 font-sans text-xs">
                        <span class="font-bold uppercase tracking-wider text-[11px]">Response Sukses</span>
                        <span class="text-emerald-400 font-semibold">200 OK</span>
                    </div>
                    <pre class="overflow-x-auto text-sky-300 leading-relaxed custom-scrollbar bg-slate-950 p-4 rounded-xl border border-slate-800/80"><code>{
  "balance_credits": 75000,
  "currency": "IDR",
  "updated_at": "2026-10-27T10:15:30Z"
}</code></pre>
                </div>
            </section>

            <!-- ENDPOINT: API Keys -->
            <section id="ep-apikeys" class="endpoint-card space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-3">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">POST</span>
                    <h3 class="text-xl font-bold text-white font-mono">/api/v1/api-keys</h3>
                </div>
                <p class="text-slate-300 text-sm">
                    Membuat secret API Key baru. Kunci rahasia (<code class="text-indigo-400 font-mono">sk-live-...</code>) hanya diberikan <strong>satu kali</strong> pada respons ini.
                </p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-6 space-y-3 font-mono text-xs">
                    <div class="flex items-center justify-between text-slate-400 font-sans text-xs">
                        <span class="font-bold uppercase tracking-wider text-[11px]">Request Payload</span>
                        <span class="text-indigo-400">application/json</span>
                    </div>
                    <pre class="overflow-x-auto text-indigo-300 leading-relaxed custom-scrollbar bg-slate-950 p-4 rounded-xl border border-slate-800/80"><code>{
  "name": "Backend Production Key"
}</code></pre>
                    <div class="flex items-center justify-between text-slate-400 font-sans text-xs pt-2">
                        <span class="font-bold uppercase tracking-wider text-[11px]">Response Sukses</span>
                        <span class="text-emerald-400 font-semibold">201 Created</span>
                    </div>
                    <pre class="overflow-x-auto text-emerald-300 leading-relaxed custom-scrollbar bg-slate-950 p-4 rounded-xl border border-slate-800/80"><code>{
  "id": "key_a9b8c7d6",
  "name": "Backend Production Key",
  "key_prefix": "sk-live-a9b8c7...",
  "api_key": "sk-live-a9b8c7d6e5f4g3h2i1j0k9l8m7n6o5p4",
  "created_at": "2026-10-27T10:20:00Z"
}</code></pre>
                </div>
            </section>

            <!-- ENDPOINT: Topup -->
            <section id="ep-topup" class="endpoint-card space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-3">
                    <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">POST</span>
                    <h3 class="text-xl font-bold text-white font-mono">/api/v1/topups</h3>
                </div>
                <p class="text-slate-300 text-sm">
                    Menerbitkan pesanan isi ulang saldo kredit baru. Mendukung metode transfer bank manual dan QRIS otomatis.
                </p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 sm:p-6 space-y-3 font-mono text-xs">
                    <pre class="overflow-x-auto text-indigo-300 leading-relaxed custom-scrollbar bg-slate-950 p-4 rounded-xl border border-slate-800/80"><code>{
  "amount_minor": 50000,
  "payment_method": "manual_transfer"
}</code></pre>
                    <p class="font-sans text-xs text-slate-400 pt-2">
                        Setelah transfer, unggah foto bukti transfer ke <code class="text-indigo-400 font-mono">POST /api/v1/topups/{id}/proof</code> (multipart form <code class="text-indigo-400">file</code>). Bukti disimpan privat di server dan diteruskan ke bot Telegram admin untuk persetujuan instan.
                    </p>
                </div>
            </section>

            <!-- SISTEM TARIF & ATOMIC RESERVATION -->
            <section id="tokenomics" class="space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-6 bg-indigo-500 rounded-full"></span>
                    <h2 class="text-2xl font-bold text-white tracking-tight">Sistem Tarif & Reservasi Kredit Atomik</h2>
                </div>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4 text-xs sm:text-sm text-slate-300 leading-relaxed shadow-xl">
                    <p>
                        AI Ciptaone Router menggunakan mekanisme <strong>Atomic Credit Reservation</strong> dengan pembukuan ganda (Double-Entry Ledger) yang mencegah saldo negatif sekalipun banyak request berjalan paralel:
                    </p>
                    <ol class="list-decimal pl-5 space-y-2 text-slate-300">
                        <li>
                            <strong class="text-white">Reservasi Awal:</strong> Saat request tiba, sistem menghitung estimasi kredit maksimal berdasarkan <code class="text-indigo-400 font-mono">max_tokens</code> dan harga model. Saldo langsung dipotong sementara (hold).
                        </li>
                        <li>
                            <strong class="text-white">Eksekusi Upstream:</strong> Request diteruskan ke upstream provider (OpenRouter / OpenAI / Anthropic).
                        </li>
                        <li>
                            <strong class="text-white">Settlement & Refund:</strong> Setelah jawaban selesai dihasilkan, sistem mencatat token riil yang terpakai (<code class="text-indigo-400 font-mono">prompt_tokens</code> + <code class="text-indigo-400 font-mono">completion_tokens</code>) dan mengembalikan selisih kredit yang belum terpakai ke dompet pengguna secara atomik.
                        </li>
                    </ol>
                    <div class="p-4 bg-slate-950 rounded-xl border border-indigo-950/60 font-mono text-xs text-indigo-300">
                        1 Kredit = Rp 1,00 (Satu Rupiah Indonesia)
                    </div>
                </div>
            </section>

            <!-- KODE ERROR -->
            <section id="error-codes" class="space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-6 bg-rose-500 rounded-full"></span>
                    <h2 class="text-2xl font-bold text-white tracking-tight">Kode Error Standar</h2>
                </div>
                <p class="text-slate-300 text-sm">
                    Seluruh respon error dibungkus dalam format seragam yang memudahkan penanganan exception di sisi aplikasi klien:
                </p>
                <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-lg">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950 text-slate-400 uppercase font-semibold text-[11px] border-b border-slate-800">
                            <tr>
                                <th class="px-4 py-3">HTTP Status</th>
                                <th class="px-4 py-3">Error Code</th>
                                <th class="px-4 py-3">Penyebab & Solusi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800 text-slate-300">
                            <tr>
                                <td class="px-4 py-3 font-mono font-bold text-rose-400">401 Unauthorized</td>
                                <td class="px-4 py-3 font-mono text-indigo-300">unauthorized</td>
                                <td class="px-4 py-3">API Key salah, dicabut, atau header Authorization tidak disertakan.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-mono font-bold text-amber-400">402 Payment Required</td>
                                <td class="px-4 py-3 font-mono text-indigo-300">insufficient_balance</td>
                                <td class="px-4 py-3">Saldo kredit tidak cukup untuk reservasi request. Silakan lakukan top-up saldo.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-mono font-bold text-amber-400">404 Not Found</td>
                                <td class="px-4 py-3 font-mono text-indigo-300">model_not_found</td>
                                <td class="px-4 py-3">Model yang diminta tidak terdaftar atau sedang dinonaktifkan oleh administrator.</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-mono font-bold text-rose-400">429 Too Many Requests</td>
                                <td class="px-4 py-3 font-mono text-indigo-300">rate_limit_exceeded</td>
                                <td class="px-4 py-3">Batas frekuensi pemanggilan tercapai (sliding window limiter). Kurangi laju request.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- HEALTH CHECK -->
            <section id="health" class="space-y-4 pt-8 border-t border-slate-900">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-6 bg-emerald-500 rounded-full"></span>
                    <h2 class="text-2xl font-bold text-white tracking-tight">Pemeriksaan Kesehatan (Health Probe)</h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-xs text-indigo-300">GET /health/live</span>
                            <span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/20 text-emerald-400">Liveness</span>
                        </div>
                        <p class="text-xs text-slate-400">Memastikan proses PHP / server sedang aktif dan mampu menerima request HTTP.</p>
                    </div>
                    <div class="p-4 bg-slate-900 border border-slate-800 rounded-2xl space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-mono font-bold text-xs text-indigo-300">GET /health/ready</span>
                            <span class="px-2 py-0.5 rounded text-[10px] bg-emerald-500/20 text-emerald-400">Readiness</span>
                        </div>
                        <p class="text-xs text-slate-400">Memastikan konektivitas database relasional (MySQL / MariaDB / SQLite) berfungsi normal.</p>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- Footer -->
    <footer class="border-t border-slate-900 bg-slate-950 py-8 mt-12 text-center text-xs text-slate-500 space-y-2">
        <p>&copy; <?php echo date('Y'); ?> AI Ciptaone Router. Seluruh hak cipta dilindungi.</p>
        <p class="text-slate-600">Dokumentasi API OpenAI-Compatible Multi-Model & Gateway Billing Terpadu.</p>
    </footer>

    <!-- Interactive Script -->
    <script>
        function switchTab(tabId, btn) {
            document.querySelectorAll('.code-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.code-tab-btn').forEach(el => {
                el.classList.remove('border-indigo-500', 'text-indigo-400', 'font-bold');
                el.classList.add('border-transparent', 'text-slate-400');
            });
            document.getElementById(tabId).classList.remove('hidden');
            btn.classList.add('border-indigo-500', 'text-indigo-400', 'font-bold');
            btn.classList.remove('border-transparent', 'text-slate-400');
        }

        function copyCode(btn) {
            const code = btn.parentElement.querySelector('code').innerText;
            navigator.clipboard.writeText(code).then(() => {
                const originalText = btn.innerText;
                btn.innerText = 'Tersalin! ✅';
                btn.classList.add('bg-emerald-600', 'text-white');
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.classList.remove('bg-emerald-600', 'text-white');
                }, 2000);
            });
        }

        function copyToClipboard(id) {
            const text = document.getElementById(id).innerText;
            navigator.clipboard.writeText(text).then(() => {
                alert('Tersalin ke clipboard: ' + text);
            });
        }

        function filterEndpoints(query) {
            const q = query.toLowerCase().trim();
            const cards = document.querySelectorAll('.endpoint-card');
            cards.forEach(card => {
                const text = card.innerText.toLowerCase();
                if (text.includes(q)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
