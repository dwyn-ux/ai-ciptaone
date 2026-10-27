import React, { useState, useEffect } from "react";
import { Link } from "react-router-dom";
import {
  BookOpen,
  Check,
  ChevronDown,
  ChevronRight,
  Code,
  Copy,
  Cpu,
  CreditCard,
  FileCode2,
  KeyRound,
  Layers,
  Play,
  Search,
  ShieldAlert,
  Sparkles,
  Terminal,
  Wallet,
  Zap,
} from "lucide-react";

interface ModelInfo {
  id: string;
  name: string;
  pricing: string;
  context: string;
  recommended?: boolean;
}

const DEFAULT_MODELS: ModelInfo[] = [
  {
    id: "deepseek/deepseek-chat",
    name: "DeepSeek V3",
    pricing: "Rp 2,5 / 1K In • Rp 10 / 1K Out",
    context: "64k tokens",
    recommended: true,
  },
  {
    id: "openai/gpt-4o-mini",
    name: "GPT-4o Mini",
    pricing: "Rp 3 / 1K In • Rp 12 / 1K Out",
    context: "128k tokens",
    recommended: true,
  },
  {
    id: "anthropic/claude-3-5-sonnet",
    name: "Claude 3.5 Sonnet",
    pricing: "Rp 45 / 1K In • Rp 225 / 1K Out",
    context: "200k tokens",
  },
  {
    id: "mock/echo-v1",
    name: "Echo Mock Model (Testing)",
    pricing: "Rp 1 / 1K In • Rp 1 / 1K Out",
    context: "8k tokens",
  },
];

export const DocsPage: React.FC = () => {
  const [activeCodeLang, setActiveCodeLang] = useState<"curl" | "python" | "node" | "php">("curl");
  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedModel, setSelectedModel] = useState("deepseek/deepseek-chat");
  const [testPrompt, setTestPrompt] = useState("Jelaskan cara kerja AI Router dalam satu kalimat.");
  const [isStreaming, setIsStreaming] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  const baseUrl = window.location.origin;

  const copyText = (text: string, key: string) => {
    navigator.clipboard.writeText(text);
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  const getCodeSnippet = () => {
    if (activeCodeLang === "curl") {
      return `curl ${baseUrl}/v1/chat/completions \\
  -H "Content-Type: application/json" \\
  -H "Authorization: Bearer sk-live-YOUR_API_KEY" \\
  -d '{
    "model": "${selectedModel}",
    "messages": [
      {"role": "system", "content": "Kamu adalah asisten AI yang ramah dan cerdas."},
      {"role": "user", "content": "${testPrompt}"}
    ],
    "temperature": 0.7,
    "stream": ${isStreaming}
  }'`;
    }

    if (activeCodeLang === "python") {
      return `from openai import OpenAI

# Gunakan library resmi OpenAI, arahkan base_url ke AI Ciptaone Router
client = OpenAI(
    base_url="${baseUrl}/v1",
    api_key="sk-live-YOUR_API_KEY"
)

response = client.chat.completions.create(
    model="${selectedModel}",
    messages=[
        {"role": "system", "content": "Kamu adalah asisten profesional."},
        {"role": "user", "content": "${testPrompt}"}
    ],
    temperature=0.7,
    stream=${isStreaming ? "True" : "False"}
)

${
  isStreaming
    ? `for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="", flush=True)`
    : `print(response.choices[0].message.content)`
}`;
    }

    if (activeCodeLang === "node") {
      return `import OpenAI from "openai";

const openai = new OpenAI({
  baseURL: "${baseUrl}/v1",
  apiKey: "sk-live-YOUR_API_KEY",
});

async function main() {
  const completion = await openai.chat.completions.create({
    model: "${selectedModel}",
    messages: [{ role: "user", content: "${testPrompt}" }],
    stream: ${isStreaming},
  });

  ${
    isStreaming
      ? `for await (const chunk of completion) {
    process.stdout.write(chunk.choices[0]?.delta?.content || "");
  }`
      : `console.log(completion.choices[0].message.content);`
  }
}

main().catch(console.error);`;
    }

    return `<?php
declare(strict_types=1);

$apiKey = 'sk-live-YOUR_API_KEY';
$url = '${baseUrl}/v1/chat/completions';

$payload = [
    'model' => '${selectedModel}',
    'messages' => [
        ['role' => 'user', 'content' => '${testPrompt}']
    ],
    'stream' => ${isStreaming ? "true" : "false"}
];

$ch = curl_init($url);
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

$result = json_decode($response, true);
echo $result['choices'][0]['message']['content'] ?? $response;`;
  };

  const sections = [
    { id: "quickstart", label: "Ringkasan & Quickstart", icon: Zap },
    { id: "auth", label: "Autentikasi & API Key", icon: KeyRound },
    { id: "code-examples", label: "Contoh Kode & SDK", icon: Code },
    { id: "ep-models", label: "Daftar Model (/v1/models)", icon: Cpu },
    { id: "ep-chat", label: "Chat (/v1/chat/completions)", icon: Terminal },
    { id: "ep-wallet", label: "Saldo & Billing (/api/v1)", icon: Wallet },
    { id: "tokenomics", label: "Reservasi Kredit Atomik", icon: Layers },
    { id: "errors", label: "Daftar Kode Error", icon: ShieldAlert },
  ];

  return (
    <div className="space-y-10 max-w-7xl mx-auto pb-16">
      {/* Hero Banner */}
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-900 via-slate-900 to-slate-950 p-8 sm:p-12 text-white border border-indigo-800/40 shadow-xl">
        <div className="absolute top-0 right-0 -mt-10 -mr-10 w-80 h-80 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none" />
        <div className="relative z-10 max-w-3xl space-y-4">
          <div className="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-500/20 border border-indigo-400/30 text-indigo-300 text-xs font-semibold">
            <Sparkles className="w-3.5 h-3.5" />
            <span>Dokumentasi API v1.0 • OpenAI Drop-In Compatible</span>
          </div>
          <h1 className="text-3xl sm:text-5xl font-black tracking-tight leading-tight">
            Integrasikan AI Terkuat dengan Satu Endpoint
          </h1>
          <p className="text-slate-300 text-sm sm:text-base leading-relaxed">
            AI Ciptaone Router menghubungkan aplikasi Anda ke berbagai model AI kelas dunia melalui antarmuka
            standar OpenAI. Didukung sistem penagihan transparan berbasis Rupiah (1 Kredit = Rp 1) dan reservasi
            kredit atomik anti-saldo-negatif.
          </p>

          <div className="pt-2 flex flex-wrap items-center gap-3">
            <div className="flex items-center space-x-2 bg-slate-950/80 border border-slate-700/80 px-4 py-2.5 rounded-xl font-mono text-xs text-indigo-300">
              <span className="text-slate-500 select-none">Base URL:</span>
              <span className="font-semibold">{baseUrl}/v1</span>
              <button
                onClick={() => copyText(`${baseUrl}/v1`, "hero-base-url")}
                className="ml-2 hover:text-white transition"
                title="Salin Base URL"
              >
                {copiedKey === "hero-base-url" ? (
                  <Check className="w-4 h-4 text-emerald-400" />
                ) : (
                  <Copy className="w-4 h-4" />
                )}
              </button>
            </div>

            <a
              href="/openapi.json"
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center space-x-1.5 px-4 py-2.5 rounded-xl bg-slate-800/80 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-semibold transition"
            >
              <FileCode2 className="w-4 h-4 text-amber-400" />
              <span>Unduh OpenAPI JSON</span>
            </a>
          </div>
        </div>
      </div>

      {/* Grid Layout: Sidebar & Main Content */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        {/* Sticky Sidebar Nav */}
        <aside className="lg:col-span-3 lg:sticky lg:top-24 space-y-4">
          <div className="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm space-y-3">
            {/* Mobile Sidebar Toggle Button */}
            <button
              onClick={() => setMobileNavOpen(!mobileNavOpen)}
              className="lg:hidden w-full flex items-center justify-between text-xs font-bold text-slate-800 p-1"
            >
              <div className="flex items-center space-x-2 text-indigo-600">
                <BookOpen className="w-4 h-4" />
                <span>Daftar Isi Dokumentasi ({sections.length} Topik)</span>
              </div>
              <ChevronDown className={`w-4 h-4 text-slate-400 transition-transform ${mobileNavOpen ? "rotate-180" : ""}`} />
            </button>

            {/* Nav Container (Always visible on lg, toggled on mobile) */}
            <div className={`space-y-3 ${mobileNavOpen ? "block" : "hidden lg:block"}`}>
              <div className="relative">
                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5 pointer-events-none" />
                <input
                  type="text"
                  placeholder="Cari endpoint..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="w-full pl-9 pr-3 py-1.5 rounded-lg border border-slate-200 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <nav className="space-y-1 text-xs">
                {sections
                  .filter(
                    (s) =>
                      !searchQuery ||
                      s.label.toLowerCase().includes(searchQuery.toLowerCase())
                  )
                  .map((s) => {
                    const Icon = s.icon;
                    return (
                      <a
                        key={s.id}
                        href={`#${s.id}`}
                        onClick={() => setMobileNavOpen(false)}
                        className="flex items-center space-x-2 px-3 py-2 rounded-lg text-slate-600 hover:text-indigo-600 hover:bg-slate-50 font-medium transition"
                      >
                        <Icon className="w-4 h-4 text-slate-400 shrink-0" />
                        <span className="truncate">{s.label}</span>
                      </a>
                    );
                  })}
              </nav>
            </div>
          </div>

          {/* Quick links box */}
          <div className="hidden lg:block bg-indigo-50/60 rounded-2xl border border-indigo-100 p-4 space-y-2 text-xs">
            <div className="font-bold text-indigo-950 flex items-center space-x-1.5">
              <BookOpen className="w-4 h-4 text-indigo-600" />
              <span>Butuh API Key?</span>
            </div>
            <p className="text-slate-600 leading-relaxed">
              Buat API Key Anda di dashboard untuk langsung mulai memanggil model.
            </p>
            <Link
              to="/api-keys"
              className="inline-flex items-center space-x-1 text-indigo-600 font-bold hover:underline pt-1"
            >
              <span>Buka Menu API Keys</span>
              <ChevronRight className="w-3.5 h-3.5" />
            </Link>
          </div>
        </aside>

        {/* Content Area */}
        <div className="lg:col-span-9 space-y-10">
          {/* Quickstart Section */}
          <section id="quickstart" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-4 shadow-sm">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-6 bg-indigo-600 rounded-full" />
              <h2 className="text-xl font-bold text-slate-900 tracking-tight">1. Ringkasan & Langkah Awal</h2>
            </div>
            <p className="text-sm text-slate-600 leading-relaxed">
              AI Ciptaone Router adalah proxy cerdas yang memungkinkan Anda menggunakan berbagai LLM upstream
              (OpenRouter, OpenAI, dsb.) dengan format request dan response yang 100% identik dengan API OpenAI.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-1.5">
                <div className="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs">1</div>
                <h3 className="font-bold text-xs text-slate-800">Dapatkan API Key</h3>
                <p className="text-[11px] text-slate-500">Buat API key baru bertipe secret (<code className="text-indigo-600">sk-live-...</code>) di akun Anda.</p>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-1.5">
                <div className="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs">2</div>
                <h3 className="font-bold text-xs text-slate-800">Atur Base URL</h3>
                <p className="text-[11px] text-slate-500">Arahkan OpenAI SDK ke <code className="text-indigo-600 font-mono">{baseUrl}/v1</code>.</p>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-1.5">
                <div className="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-xs">3</div>
                <h3 className="font-bold text-xs text-slate-800">Isi Saldo Kredit</h3>
                <p className="text-[11px] text-slate-500">Top-up via transfer bank atau QRIS. Kredit didebet per request token riil.</p>
              </div>
            </div>
          </section>

          {/* Autentikasi Section */}
          <section id="auth" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-4 shadow-sm">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-6 bg-indigo-600 rounded-full" />
              <h2 className="text-xl font-bold text-slate-900 tracking-tight">2. Autentikasi HTTP Header</h2>
            </div>
            <p className="text-sm text-slate-600 leading-relaxed">
              Kirimkan API Key aktif pada setiap HTTP request melalui header <code className="bg-slate-100 text-indigo-700 px-1.5 py-0.5 rounded text-xs font-mono">Authorization</code>:
            </p>

            <div className="bg-slate-900 rounded-xl p-4 flex items-center justify-between text-indigo-300 font-mono text-xs">
              <span>Authorization: Bearer sk-live-YOUR_SECRET_API_KEY</span>
              <button
                onClick={() => copyText("Authorization: Bearer sk-live-YOUR_SECRET_API_KEY", "auth-header")}
                className="text-slate-400 hover:text-white transition"
              >
                {copiedKey === "auth-header" ? <Check className="w-4 h-4 text-emerald-400" /> : <Copy className="w-4 h-4" />}
              </button>
            </div>
          </section>

          {/* Interactive Code Generator & Examples */}
          <section id="code-examples" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-6 shadow-sm">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div className="flex items-center space-x-2">
                <span className="w-2 h-6 bg-indigo-600 rounded-full" />
                <h2 className="text-xl font-bold text-slate-900 tracking-tight">3. Generator Kode & Contoh SDK</h2>
              </div>

              {/* Language Switcher */}
              <div className="flex bg-slate-100 p-1 rounded-xl text-xs font-semibold">
                <button
                  onClick={() => setActiveCodeLang("curl")}
                  className={`px-3 py-1.5 rounded-lg transition ${
                    activeCodeLang === "curl" ? "bg-white text-indigo-600 shadow-sm" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  cURL
                </button>
                <button
                  onClick={() => setActiveCodeLang("python")}
                  className={`px-3 py-1.5 rounded-lg transition ${
                    activeCodeLang === "python" ? "bg-white text-indigo-600 shadow-sm" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  Python
                </button>
                <button
                  onClick={() => setActiveCodeLang("node")}
                  className={`px-3 py-1.5 rounded-lg transition ${
                    activeCodeLang === "node" ? "bg-white text-indigo-600 shadow-sm" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  Node.js
                </button>
                <button
                  onClick={() => setActiveCodeLang("php")}
                  className={`px-3 py-1.5 rounded-lg transition ${
                    activeCodeLang === "php" ? "bg-white text-indigo-600 shadow-sm" : "text-slate-600 hover:text-slate-900"
                  }`}
                >
                  PHP
                </button>
              </div>
            </div>

            {/* Interactive parameter controls */}
            <div className="p-4 rounded-xl bg-slate-50 border border-slate-100 grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
              <div>
                <label className="font-bold text-slate-700 block mb-1">Pilih Model:</label>
                <select
                  value={selectedModel}
                  onChange={(e) => setSelectedModel(e.target.value)}
                  className="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 font-mono text-xs focus:ring-1 focus:ring-indigo-500"
                >
                  {DEFAULT_MODELS.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.id})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="font-bold text-slate-700 block mb-1">Pesan Uji (Prompt):</label>
                <input
                  type="text"
                  value={testPrompt}
                  onChange={(e) => setTestPrompt(e.target.value)}
                  className="w-full bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs focus:ring-1 focus:ring-indigo-500"
                />
              </div>

              <div className="flex items-center space-x-3 pt-5">
                <label className="flex items-center space-x-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={isStreaming}
                    onChange={(e) => setIsStreaming(e.target.checked)}
                    className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                  />
                  <span className="font-bold text-slate-700">Stream SSE Chunk</span>
                </label>
              </div>
            </div>

            {/* Generated Code Block */}
            <div className="relative rounded-2xl bg-slate-950 p-4 sm:p-6 overflow-hidden border border-slate-800 shadow-inner">
              <button
                onClick={() => copyText(getCodeSnippet(), "code-snippet")}
                className="absolute top-4 right-4 flex items-center space-x-1.5 px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs rounded-lg transition"
              >
                {copiedKey === "code-snippet" ? (
                  <>
                    <Check className="w-3.5 h-3.5 text-emerald-400" />
                    <span>Tersalin!</span>
                  </>
                ) : (
                  <>
                    <Copy className="w-3.5 h-3.5" />
                    <span>Salin Kode</span>
                  </>
                )}
              </button>
              <pre className="overflow-x-auto text-xs text-indigo-300 font-mono leading-relaxed pt-6">
                <code>{getCodeSnippet()}</code>
              </pre>
            </div>
          </section>

          {/* Catalog Model Section */}
          <section id="ep-models" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-4 shadow-sm">
            <div className="flex items-center justify-between">
              <div className="flex items-center space-x-3">
                <span className="px-2 py-0.5 rounded-lg text-xs font-mono font-bold bg-blue-100 text-blue-700">GET</span>
                <h2 className="text-xl font-bold text-slate-900 font-mono">/v1/models</h2>
              </div>
              <a
                href={`${baseUrl}/v1/models`}
                target="_blank"
                rel="noreferrer"
                className="text-xs text-indigo-600 font-semibold hover:underline"
              >
                Uji Endpoint Langsung &rarr;
              </a>
            </div>
            <p className="text-sm text-slate-600 leading-relaxed">
              Menampilkan daftar model AI yang aktif dan siap dipakai beserta informasi konteks dan tarifnya.
            </p>

            <div className="overflow-x-auto border border-slate-200 rounded-xl">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 border-b border-slate-200 font-bold text-slate-700">
                  <tr>
                    <th className="px-4 py-3">ID Model</th>
                    <th className="px-4 py-3">Nama</th>
                    <th className="px-4 py-3">Tarif (Per 1K Token)</th>
                    <th className="px-4 py-3">Context Window</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-slate-600">
                  {DEFAULT_MODELS.map((m) => (
                    <tr key={m.id} className="hover:bg-slate-50 transition">
                      <td className="px-4 py-3 font-mono font-bold text-indigo-600">{m.id}</td>
                      <td className="px-4 py-3 font-medium text-slate-900">{m.name}</td>
                      <td className="px-4 py-3">{m.pricing}</td>
                      <td className="px-4 py-3">{m.context}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          {/* Chat Completions Section */}
          <section id="ep-chat" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-4 shadow-sm">
            <div className="flex items-center space-x-3">
              <span className="px-2 py-0.5 rounded-lg text-xs font-mono font-bold bg-emerald-100 text-emerald-700">POST</span>
              <h2 className="text-xl font-bold text-slate-900 font-mono">/v1/chat/completions</h2>
            </div>
            <p className="text-sm text-slate-600 leading-relaxed">
              Mengirim instruksi dan pesan percakapan ke model LLM. Mendukung parameter OpenAI standar seperti
              <code className="bg-slate-100 text-slate-800 px-1 rounded text-xs font-mono ml-1">temperature</code>,{" "}
              <code className="bg-slate-100 text-slate-800 px-1 rounded text-xs font-mono">max_tokens</code>, dan{" "}
              <code className="bg-slate-100 text-slate-800 px-1 rounded text-xs font-mono">stream</code>.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Contoh Request Body</span>
                <pre className="text-xs font-mono text-slate-800 bg-white p-3 rounded-lg border border-slate-200 overflow-x-auto">
{`{
  "model": "deepseek/deepseek-chat",
  "messages": [
    {"role": "user", "content": "Halo AI!"}
  ],
  "temperature": 0.7,
  "stream": false
}`}
                </pre>
              </div>

              <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                <span className="text-xs font-bold uppercase tracking-wider text-slate-500">Contoh Response Sukses (200 OK)</span>
                <pre className="text-xs font-mono text-emerald-800 bg-emerald-50/50 p-3 rounded-lg border border-emerald-200 overflow-x-auto">
{`{
  "id": "chatcmpl-9x1y2z",
  "object": "chat.completion",
  "model": "deepseek/deepseek-chat",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "Halo! Bagaimana saya dapat membantu?"
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 10,
    "completion_tokens": 8,
    "total_tokens": 18
  }
}`}
                </pre>
              </div>
            </div>
          </section>

          {/* Tokenomics & Atomic Reservation */}
          <section id="tokenomics" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-4 shadow-sm">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-6 bg-indigo-600 rounded-full" />
              <h2 className="text-xl font-bold text-slate-900 tracking-tight">4. Sistem Billing & Reservasi Kredit</h2>
            </div>
            <div className="space-y-3 text-sm text-slate-600 leading-relaxed">
              <p>
                Setiap <strong>1 Kredit bernilai Rp 1</strong>. Untuk mencegah eksploitasi dan saldo minus saat request
                berjalan paralel secara konkuren, AI Ciptaone Router menerapkan:
              </p>
              <div className="p-4 rounded-xl bg-indigo-50 border border-indigo-100 space-y-2 text-indigo-950 text-xs">
                <div className="font-bold text-sm">Prinsip Reservasi Atomik:</div>
                <p>
                  1. Saldo dipotong sementara (hold) berdasarkan perkiraan token maksimal (<code className="font-mono">max_tokens</code>).
                </p>
                <p>
                  2. Jika saldo tidak cukup, request langsung ditolak dengan status <code className="font-mono font-bold text-rose-600">402 Payment Required</code>.
                </p>
                <p>
                  3. Setelah respon selesai, hanya token aktual yang didebet ke pembukuan ganda (ledger). Sisa kredit reservasi langsung dikembalikan seketika.
                </p>
              </div>
            </div>
          </section>

          {/* Error Codes Section */}
          <section id="errors" className="bg-white rounded-2xl border border-slate-200 p-6 sm:p-8 space-y-4 shadow-sm">
            <div className="flex items-center space-x-2">
              <span className="w-2 h-6 bg-rose-600 rounded-full" />
              <h2 className="text-xl font-bold text-slate-900 tracking-tight">5. Penanganan Error Standar</h2>
            </div>
            <p className="text-sm text-slate-600">
              Format error seragam mempermudah parsing pesan kesalahan pada aplikasi Anda:
            </p>

            <div className="overflow-x-auto border border-slate-200 rounded-xl">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 border-b border-slate-200 font-bold text-slate-700">
                  <tr>
                    <th className="px-4 py-3">HTTP Status</th>
                    <th className="px-4 py-3">Error Code</th>
                    <th className="px-4 py-3">Penyebab</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 text-slate-600">
                  <tr>
                    <td className="px-4 py-3 font-bold text-rose-600">401 Unauthorized</td>
                    <td className="px-4 py-3 font-mono text-indigo-600">unauthorized</td>
                    <td className="px-4 py-3">API Key salah, dicabut, atau header Authorization tidak terkirim.</td>
                  </tr>
                  <tr>
                    <td className="px-4 py-3 font-bold text-amber-600">402 Payment Required</td>
                    <td className="px-4 py-3 font-mono text-indigo-600">insufficient_balance</td>
                    <td className="px-4 py-3">Saldo dompet kredit tidak mencukupi untuk reservasi estimasi token.</td>
                  </tr>
                  <tr>
                    <td className="px-4 py-3 font-bold text-slate-700">404 Not Found</td>
                    <td className="px-4 py-3 font-mono text-indigo-600">model_not_found</td>
                    <td className="px-4 py-3">Model ID yang dikirim tidak terdaftar atau sedang dimatikan.</td>
                  </tr>
                  <tr>
                    <td className="px-4 py-3 font-bold text-rose-600">429 Too Many Requests</td>
                    <td className="px-4 py-3 font-mono text-indigo-600">rate_limit_exceeded</td>
                    <td className="px-4 py-3">Melebihi batas kuota pemanggilan (sliding window limit).</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </div>
    </div>
  );
};
