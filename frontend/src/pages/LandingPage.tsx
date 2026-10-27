import React, { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import {
  ArrowRight,
  BookOpen,
  Check,
  CheckCircle2,
  ChevronDown,
  Code2,
  Coins,
  Copy,
  Cpu,
  CreditCard,
  ExternalLink,
  Flame,
  Globe2,
  HelpCircle,
  KeyRound,
  Layers,
  Lock,
  Menu,
  Play,
  QrCode,
  ShieldCheck,
  Sparkles,
  Terminal,
  Wallet,
  X,
  Zap,
} from "lucide-react";

export const LandingPage: React.FC = () => {
  const { user } = useAuth();
  const [activeCodeTab, setActiveCodeTab] = useState<"python" | "curl" | "node">("python");
  const [copiedSnippet, setCopiedSnippet] = useState(false);
  const [openFaq, setOpenFaq] = useState<number | null>(null);
  const [promptTokens, setPromptTokens] = useState<number>(5000);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const baseUrl = window.location.origin;

  const copyCode = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedSnippet(true);
    setTimeout(() => setCopiedSnippet(false), 2000);
  };

  const codeSnippets = {
    python: `from openai import OpenAI

# 1. Arahkan base_url ke AI Ciptaone Router
client = OpenAI(
    base_url="${baseUrl}/v1",
    api_key="sk-live-YOUR_API_KEY"
)

# 2. Panggil model AI apa saja dengan sintaks OpenAI resmi
response = client.chat.completions.create(
    model="deepseek/deepseek-chat",
    messages=[
        {"role": "system", "content": "Kamu asisten AI yang cerdas & hemat."},
        {"role": "user", "content": "Jelaskan strategi hemat token dalam 1 poin."}
    ],
    stream=True  # Mendukung streaming SSE langsung
)

for chunk in response:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="", flush=True)`,
    curl: `curl ${baseUrl}/v1/chat/completions \\
  -H "Content-Type: application/json" \\
  -H "Authorization: Bearer sk-live-YOUR_API_KEY" \\
  -d '{
    "model": "deepseek/deepseek-chat",
    "messages": [
      {"role": "user", "content": "Halo AI Ciptaone Router!"}
    ],
    "temperature": 0.7
  }'`,
    node: `import OpenAI from "openai";

const openai = new OpenAI({
  baseURL: "${baseUrl}/v1",
  apiKey: "sk-live-YOUR_API_KEY",
});

const completion = await openai.chat.completions.create({
  model: "deepseek/deepseek-chat",
  messages: [{ role: "user", content: "Halo dari Node.js!" }],
});

console.log(completion.choices[0].message.content);`,
  };

  const models = [
    {
      id: "deepseek/deepseek-chat",
      name: "DeepSeek V3",
      badge: "Paling Hemat & Pintar",
      badgeColor: "bg-emerald-500/10 text-emerald-600 border-emerald-500/20",
      desc: "Model reasoning dan coding kelas atas dengan efisiensi biaya tertinggi di dunia.",
      inPrice: "Rp 2,5",
      outPrice: "Rp 10,0",
      context: "64k Context",
      recommended: true,
    },
    {
      id: "openai/gpt-4o-mini",
      name: "GPT-4o Mini",
      badge: "Cepat & Stabil",
      badgeColor: "bg-blue-500/10 text-blue-600 border-blue-500/20",
      desc: "Model ringan OpenAI yang sangat responsif untuk tugas NLP sehari-hari dan parsing JSON.",
      inPrice: "Rp 3,0",
      outPrice: "Rp 12,0",
      context: "128k Context",
      recommended: false,
    },
    {
      id: "anthropic/claude-3-5-sonnet",
      name: "Claude 3.5 Sonnet",
      badge: "Juara Coding",
      badgeColor: "bg-amber-500/10 text-amber-600 border-amber-500/20",
      desc: "Standar emas dunia untuk software engineering, debugging, dan pemahaman logika rumit.",
      inPrice: "Rp 45,0",
      outPrice: "Rp 225,0",
      context: "200k Context",
      recommended: false,
    },
    {
      id: "mock/echo-v1",
      name: "Echo Sandbox",
      badge: "Gratis Uji Coba",
      badgeColor: "bg-purple-500/10 text-purple-600 border-purple-500/20",
      desc: "Model uji coba deterministik untuk pengujian unit test backend Anda tanpa biaya token.",
      inPrice: "Rp 1,0",
      outPrice: "Rp 1,0",
      context: "8k Context",
      recommended: false,
    },
  ];

  const faqs = [
    {
      q: "Apakah saya butuh kartu kredit berlogo Visa/Mastercard luar negeri?",
      a: "Sama sekali tidak! Anda cukup mengisi saldo dompet mulai dari Rp 10.000 melalui QRIS instan (GoPay, OVO, Dana, BCA Mobile) atau transfer bank lokal. Tanpa potongan kurs valuta asing.",
    },
    {
      q: "Bagaimana cara beralih dari API OpenAI resmi ke AI Ciptaone Router?",
      a: "Cukup ganti satu parameter pada inisialisasi SDK Anda: tambahkan `base_url='https://ai.ciptaone.site/v1'` (atau domain router) dan gunakan API key `sk-live-...` yang Anda buat di dashboard. Seluruh kode program lainnya tidak perlu diubah sama sekali.",
    },
    {
      q: "Bagaimana cara kerja saldo kredit dan reservasi atomik?",
      a: "1 Kredit bernilai tepat Rp 1,00. Saat request masuk, sistem mencadangkan sejumlah kredit berdasarkan estimasi token. Setelah jawaban model selesai, sistem hanya mendebet token riil yang terpakai dan sisa kredit langsung dikembalikan seketika. Saldo Anda terlindungi dan tidak pernah menjadi minus.",
    },
    {
      q: "Apakah saldo kredit saya memiliki masa kedaluwarsa?",
      a: "Tidak ada masa kedaluwarsa. Saldo kredit yang sudah diisi akan tetap aktif dan siap dipakai kapan pun Anda membutuhkannya.",
    },
    {
      q: "Apakah saya bisa memakai router ini di tools seperti Cursor, VS Code Continue, atau Open WebUI?",
      a: "Bisa 100%! Cukup masukkan Custom OpenAI Endpoint pada preferensi tool Anda dan paste API Key dari dashboard kami.",
    },
  ];

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-indigo-500 selection:text-white overflow-hidden">
      
      {/* Background Glows */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-[600px] pointer-events-none overflow-hidden -z-10">
        <div className="absolute top-[-100px] left-1/4 w-[500px] h-[500px] bg-indigo-600/20 rounded-full blur-[120px]" />
        <div className="absolute top-[-50px] right-1/4 w-[400px] h-[400px] bg-purple-600/15 rounded-full blur-[140px]" />
      </div>

      {/* Navigation Header */}
      <header className="sticky top-0 z-50 bg-slate-950/90 backdrop-blur-md border-b border-slate-800/80">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <Link to="/" className="flex items-center space-x-2.5 group shrink-0">
            <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-white shadow-md shadow-indigo-500/20 group-hover:scale-105 transition-transform">
              <Cpu className="w-5 h-5" />
            </div>
            <div className="flex items-center space-x-1.5">
              <span className="font-extrabold text-base tracking-tight text-white">AI Ciptaone</span>
              <span className="hidden xs:inline-block text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 bg-indigo-500/10 text-indigo-400 rounded-md border border-indigo-500/30">
                AI Router
              </span>
            </div>
          </Link>

          {/* Desktop Nav links */}
          <nav className="hidden lg:flex items-center space-x-5 text-xs font-semibold text-slate-300">
            <a href="#fitur" className="hover:text-indigo-400 transition-colors">Keunggulan</a>
            <a href="#models" className="hover:text-indigo-400 transition-colors">Katalog Model</a>
            <a href="#kalkulator" className="hover:text-indigo-400 transition-colors">Tarif Rupiah</a>
            <a href="#faq" className="hover:text-indigo-400 transition-colors">Tanya Jawab</a>
            <Link to="/docs" className="flex items-center space-x-1 text-indigo-400 hover:text-indigo-300 transition-colors">
              <BookOpen className="w-3.5 h-3.5" />
              <span>Dokumentasi API</span>
            </Link>
          </nav>

          {/* Action CTAs & Mobile Toggle */}
          <div className="flex items-center space-x-2 sm:space-x-3 text-xs">
            {user ? (
              <Link
                to="/dashboard"
                className="flex items-center space-x-1.5 px-3.5 sm:px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold transition-all shadow-sm shadow-indigo-600/30"
              >
                <span>Dashboard</span>
                <ArrowRight className="w-3.5 h-3.5" />
              </Link>
            ) : (
              <>
                <Link
                  to="/login"
                  className="px-3 py-2 rounded-xl text-slate-300 hover:text-white font-medium hover:bg-slate-900 transition-colors"
                >
                  Masuk
                </Link>
                <Link
                  to="/register"
                  className="hidden sm:flex items-center space-x-1 px-4 py-2 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 hover:from-indigo-500 hover:to-indigo-400 text-white font-bold transition-all shadow-md shadow-indigo-600/25"
                >
                  <span>Mulai</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </Link>
              </>
            )}

            {/* Mobile Hamburger Button */}
            <button
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="lg:hidden p-2 rounded-xl text-slate-400 hover:text-white hover:bg-slate-900 transition focus:outline-none"
              aria-label="Toggle navigation menu"
            >
              {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
            </button>
          </div>
        </div>

        {/* Mobile Navigation Drawer */}
        {mobileMenuOpen && (
          <div className="lg:hidden border-t border-slate-800 bg-slate-950 px-4 pt-3 pb-6 space-y-3 shadow-2xl">
            <nav className="space-y-1 text-sm font-medium text-slate-300">
              <a
                href="#fitur"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2.5 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Keunggulan
              </a>
              <a
                href="#models"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2.5 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Katalog Model
              </a>
              <a
                href="#kalkulator"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2.5 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Tarif Rupiah
              </a>
              <a
                href="#faq"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2.5 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Tanya Jawab
              </a>
              <Link
                to="/docs"
                onClick={() => setMobileMenuOpen(false)}
                className="flex items-center space-x-2 px-3 py-2.5 rounded-xl text-indigo-400 hover:bg-slate-900 transition"
              >
                <BookOpen className="w-4 h-4" />
                <span>Dokumentasi API</span>
              </Link>
            </nav>

            {!user && (
              <div className="pt-2 border-t border-slate-800/80 grid grid-cols-2 gap-2">
                <Link
                  to="/login"
                  onClick={() => setMobileMenuOpen(false)}
                  className="w-full text-center py-2.5 rounded-xl text-sm font-semibold border border-slate-800 text-slate-300 hover:bg-slate-900 transition"
                >
                  Masuk
                </Link>
                <Link
                  to="/register"
                  onClick={() => setMobileMenuOpen(false)}
                  className="w-full text-center py-2.5 rounded-xl text-sm font-bold bg-indigo-600 text-white hover:bg-indigo-500 transition shadow-sm"
                >
                  Mulai Sekarang
                </Link>
              </div>
            )}
          </div>
        )}
      </header>

      {/* Hero Section */}
      <section className="relative pt-10 pb-16 sm:pt-16 sm:pb-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-6 sm:space-y-8">
        
        {/* Announcement Pill */}
        <div className="inline-flex items-center space-x-2 px-3.5 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-500/25 text-indigo-300 text-xs font-semibold backdrop-blur-sm">
          <Sparkles className="w-3.5 h-3.5 text-indigo-400 shrink-0" />
          <span className="truncate max-w-[280px] xs:max-w-none">Universal AI Router • 100% Format OpenAI</span>
        </div>

        {/* Headline */}
        <div className="max-w-4xl mx-auto space-y-3 sm:space-y-4">
          <h1 className="text-3xl xs:text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight text-white leading-[1.18] sm:leading-[1.15]">
            Semua Model AI Terbaik Dunia,{" "}
            <span className="bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">
              Satu Saldo & Satu API Key.
            </span>
          </h1>
          <p className="text-xs sm:text-base lg:text-lg text-slate-300 max-w-2xl mx-auto leading-relaxed">
            Hentikan langganan bulanan dollar ($) yang mahal. Akses <strong>DeepSeek V3</strong>, <strong>GPT-4o</strong>, <strong>Claude 3.5 Sonnet</strong>, dan <strong>Mistral</strong> dengan top-up saldo via <strong>QRIS & Bank Lokal</strong>. Hemat hingga 80% biaya token!
          </p>
        </div>

        {/* CTA Buttons */}
        <div className="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2 max-w-md sm:max-w-none mx-auto w-full">
          <Link
            to="/register"
            className="w-full sm:w-auto px-6 sm:px-7 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold shadow-lg shadow-indigo-600/30 flex items-center justify-center space-x-2 transition-all hover:scale-[1.02]"
          >
            <Zap className="w-4 h-4 fill-white shrink-0" />
            <span>Daftar Akun Gratis</span>
            <ArrowRight className="w-4 h-4 shrink-0" />
          </Link>

          <Link
            to="/docs"
            className="w-full sm:w-auto px-5 sm:px-6 py-3.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 border border-slate-800 text-sm font-semibold flex items-center justify-center space-x-2 transition-all"
          >
            <BookOpen className="w-4 h-4 text-indigo-400 shrink-0" />
            <span>Dokumentasi API</span>
          </Link>

          <Link
            to="/playground"
            className="w-full sm:w-auto px-5 sm:px-6 py-3.5 rounded-xl bg-slate-900/60 hover:bg-slate-800 text-slate-300 border border-slate-800 text-sm font-semibold flex items-center justify-center space-x-2 transition-all"
          >
            <Play className="w-4 h-4 text-emerald-400 fill-emerald-400/20 shrink-0" />
            <span>Playground</span>
          </Link>
        </div>

        {/* Value Badges */}
        <div className="pt-2 flex flex-wrap items-center justify-center gap-y-2 gap-x-4 sm:gap-x-6 text-[11px] sm:text-xs text-slate-400">
          <div className="flex items-center space-x-1.5">
            <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-400 shrink-0" />
            <span>1 Kredit = Rp 1,00</span>
          </div>
          <div className="flex items-center space-x-1.5">
            <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-400 shrink-0" />
            <span>Tanpa Kartu Kredit Luar Negeri</span>
          </div>
          <div className="flex items-center space-x-1.5">
            <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-400 shrink-0" />
            <span>Reservasi Kredit Atomik (Anti Minus)</span>
          </div>
          <div className="flex items-center space-x-1.5">
            <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-400 shrink-0" />
            <span>Streaming SSE Ultra Cepat</span>
          </div>
        </div>

        {/* Hero Code Terminal Window */}
        <div className="pt-4 max-w-4xl mx-auto text-left w-full">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden w-full">
            {/* Window title bar */}
            <div className="flex flex-wrap items-center justify-between gap-2 px-3 sm:px-4 py-2.5 bg-slate-950/90 border-b border-slate-800">
              <div className="flex items-center space-x-2">
                <span className="w-3 h-3 rounded-full bg-rose-500/80 inline-block" />
                <span className="w-3 h-3 rounded-full bg-amber-500/80 inline-block" />
                <span className="w-3 h-3 rounded-full bg-emerald-500/80 inline-block" />
                <span className="text-xs font-mono text-slate-400 ml-1.5 hidden sm:inline">Quickstart Drop-in</span>
              </div>

              {/* Tabs & Copy Button */}
              <div className="flex items-center space-x-2 ml-auto sm:ml-0">
                <div className="flex items-center space-x-1 bg-slate-900 p-0.5 rounded-lg text-xs font-mono">
                  <button
                    onClick={() => setActiveCodeTab("python")}
                    className={`px-2 sm:px-2.5 py-1 rounded transition ${activeCodeTab === "python" ? "bg-indigo-600 text-white font-bold" : "text-slate-400 hover:text-white"}`}
                  >
                    Python
                  </button>
                  <button
                    onClick={() => setActiveCodeTab("curl")}
                    className={`px-2 sm:px-2.5 py-1 rounded transition ${activeCodeTab === "curl" ? "bg-indigo-600 text-white font-bold" : "text-slate-400 hover:text-white"}`}
                  >
                    cURL
                  </button>
                  <button
                    onClick={() => setActiveCodeTab("node")}
                    className={`px-2 sm:px-2.5 py-1 rounded transition ${activeCodeTab === "node" ? "bg-indigo-600 text-white font-bold" : "text-slate-400 hover:text-white"}`}
                  >
                    Node.js
                  </button>
                </div>

                <button
                  onClick={() => copyCode(codeSnippets[activeCodeTab])}
                  className="flex items-center space-x-1 px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded-lg transition"
                  title="Salin kode ke clipboard"
                >
                  {copiedSnippet ? (
                    <>
                      <Check className="w-3.5 h-3.5 text-emerald-400" />
                      <span className="hidden xs:inline">Tersalin!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="w-3.5 h-3.5" />
                      <span className="hidden xs:inline">Salin</span>
                    </>
                  )}
                </button>
              </div>
            </div>

            {/* Code Body */}
            <div className="p-3 sm:p-5 relative bg-slate-950 overflow-hidden">
              <pre className="overflow-x-auto text-xs sm:text-sm font-mono text-indigo-300 leading-relaxed custom-scrollbar">
                <code>{codeSnippets[activeCodeTab]}</code>
              </pre>
            </div>
          </div>
        </div>

      </section>

      {/* Section: Mengapa Memilih Kami (Features) */}
      <section id="fitur" className="py-16 sm:py-24 bg-slate-900/40 border-y border-slate-900">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
          
          <div className="text-center max-w-3xl mx-auto space-y-3">
            <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Keunggulan Utama</h2>
            <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
              Dirancang untuk Pengembang & Pelaku Usaha Indonesia
            </p>
            <p className="text-sm text-slate-400">
              Solusi cerdas menghindari biaya langganan tetap ChatGPT Plus ($20/bln) atau Claude Pro. Bayar murni sesuai konsumsi kata.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            {/* Feature 1 */}
            <div className="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-4 group">
              <div className="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                <Code2 className="w-6 h-6" />
              </div>
              <h3 className="text-lg font-bold text-white">100% Drop-In Replacement</h3>
              <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                Cukup arahkan <code className="text-indigo-400">base_url</code> pada library resmi OpenAI, LangChain, LlamaIndex, atau Cursor. Tanpa perlu belajar SDK baru.
              </p>
            </div>

            {/* Feature 2 */}
            <div className="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-4 group">
              <div className="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                <QrCode className="w-6 h-6" />
              </div>
              <h3 className="text-lg font-bold text-white">Top-up QRIS & Bank Lokal</h3>
              <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                Tidak perlu pusing kartu kredit decline. Top-up saldo mulai Rp 10.000 dengan QRIS instan atau transfer BCA/Mandiri/BRI secara fleksibel.
              </p>
            </div>

            {/* Feature 3 */}
            <div className="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-4 group">
              <div className="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                <ShieldCheck className="w-6 h-6" />
              </div>
              <h3 className="text-lg font-bold text-white">Reservasi Kredit Atomik</h3>
              <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                Didukung pembukuan ganda (Double-Entry Ledger) dan constraint saldo anti-minus. Saldo di-hold saat request, lalu sisa kredit dikembalikan seketika.
              </p>
            </div>

            {/* Feature 4 */}
            <div className="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-4 group">
              <div className="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                <Zap className="w-6 h-6" />
              </div>
              <h3 className="text-lg font-bold text-white">Streaming SSE Real-Time</h3>
              <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                Menyajikan output jawaban kata-demi-kata (Server-Sent Events) secara instan tanpa jeda buffer, memberikan pengalaman chat yang mulus bagi pengguna Anda.
              </p>
            </div>

            {/* Feature 5 */}
            <div className="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-4 group">
              <div className="w-12 h-12 rounded-xl bg-rose-500/10 text-rose-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                <Lock className="w-6 h-6" />
              </div>
              <h3 className="text-lg font-bold text-white">Keamanan Kriptografi SHA-256</h3>
              <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                API key Anda di-hash dengan SHA-256 di database server. Kunci secret hanya ditampilkan satu kali dan dapat dicabut seketika jika bocor.
              </p>
            </div>

            {/* Feature 6 */}
            <div className="p-6 sm:p-8 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-4 group">
              <div className="w-12 h-12 rounded-xl bg-cyan-500/10 text-cyan-400 flex items-center justify-center group-hover:scale-110 transition-transform">
                <Layers className="w-6 h-6" />
              </div>
              <h3 className="text-lg font-bold text-white">Multi-Model dalam 1 Akun</h3>
              <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                Bebas berganti model dari DeepSeek ke GPT-4o atau Claude hanya dengan mengubah parameter string <code className="text-indigo-400">model</code> di payload.
              </p>
            </div>

          </div>
        </div>
      </section>

      {/* Section: Model Showcase */}
      <section id="models" className="py-16 sm:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        <div className="text-center max-w-3xl mx-auto space-y-3">
          <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Pilihan Model AI</h2>
          <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
            Katalog Model Siap Pakai
          </p>
          <p className="text-sm text-slate-400">
            Pilih model yang tepat sesuai kebutuhan aplikasi dan anggaran Anda.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {models.map((m) => (
            <div
              key={m.id}
              className={`p-6 rounded-2xl bg-slate-900 border transition-all flex flex-col justify-between ${
                m.recommended
                  ? "border-indigo-500/80 shadow-lg shadow-indigo-500/10 relative"
                  : "border-slate-800 hover:border-slate-700"
              }`}
            >
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold border ${m.badgeColor}`}>
                    {m.badge}
                  </span>
                  <span className="text-[11px] font-mono text-slate-400">{m.context}</span>
                </div>

                <div>
                  <h3 className="text-xl font-bold text-white">{m.name}</h3>
                  <code className="text-[11px] font-mono text-indigo-400 break-all">{m.id}</code>
                </div>

                <p className="text-xs text-slate-400 leading-relaxed">{m.desc}</p>
              </div>

              <div className="pt-6 border-t border-slate-800/80 mt-6 space-y-2">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-slate-400">Tarif Input (1K):</span>
                  <span className="font-bold text-white">{m.inPrice}</span>
                </div>
                <div className="flex items-center justify-between text-xs">
                  <span className="text-slate-400">Tarif Output (1K):</span>
                  <span className="font-bold text-white">{m.outPrice}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Section: Estimator Biaya Token */}
      <section id="kalkulator" className="py-16 sm:py-20 bg-gradient-to-b from-slate-900/40 to-slate-950 border-t border-slate-900">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
          
          <div className="text-center space-y-3">
            <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Transparansi Biaya</h2>
            <p className="text-3xl font-extrabold text-white tracking-tight">Kalkulator Estimasi Biaya Pemakaian</p>
            <p className="text-sm text-slate-400">
              Geser jumlah token untuk melihat betapa hematnya memakai DeepSeek V3 dibanding langganan bulanan.
            </p>
          </div>

          <div className="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 space-y-6 shadow-xl">
            <div className="space-y-2">
              <div className="flex justify-between text-sm font-semibold text-slate-200">
                <span>Perkiraan Token per Bulan:</span>
                <span className="text-indigo-400 font-mono font-bold">{promptTokens.toLocaleString("id-ID")} Token (~{Math.round(promptTokens * 0.75).toLocaleString("id-ID")} Kata)</span>
              </div>
              <input
                type="range"
                min="1000"
                max="500000"
                step="5000"
                value={promptTokens}
                onChange={(e) => setPromptTokens(Number(e.target.value))}
                className="w-full accent-indigo-500 cursor-pointer h-2 bg-slate-800 rounded-lg"
              />
              <div className="flex justify-between text-[11px] text-slate-500">
                <span>1.000 Token</span>
                <span>250.000 Token</span>
                <span>500.000 Token</span>
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-4 border-t border-slate-800">
              <div className="p-4 rounded-xl bg-slate-950 border border-slate-800/80 text-center space-y-1">
                <div className="text-xs text-slate-400 font-medium">DeepSeek V3</div>
                <div className="text-2xl font-black text-emerald-400">
                  Rp {Math.max(1, Math.round((promptTokens / 1000) * 5)).toLocaleString("id-ID")}
                </div>
                <div className="text-[10px] text-slate-500">Super Ekonomis</div>
              </div>

              <div className="p-4 rounded-xl bg-slate-950 border border-slate-800/80 text-center space-y-1">
                <div className="text-xs text-slate-400 font-medium">GPT-4o Mini</div>
                <div className="text-2xl font-black text-indigo-400">
                  Rp {Math.max(1, Math.round((promptTokens / 1000) * 8)).toLocaleString("id-ID")}
                </div>
                <div className="text-[10px] text-slate-500">Cepat & Handal</div>
              </div>

              <div className="p-4 rounded-xl bg-slate-950 border border-rose-900/30 text-center space-y-1">
                <div className="text-xs text-slate-400 font-medium">ChatGPT Plus ($20/bln)</div>
                <div className="text-2xl font-black text-rose-400">
                  Rp 325.000
                </div>
                <div className="text-[10px] text-rose-500">Biaya tetap walau jarang dipakai</div>
              </div>
            </div>
          </div>

        </div>
      </section>

      {/* Section: 3 Langkah Mudah */}
      <section className="py-16 sm:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        <div className="text-center max-w-2xl mx-auto space-y-3">
          <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Alur Kerja</h2>
          <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">Mulai Dalam 3 Menit</p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 relative space-y-3">
            <div className="w-10 h-10 rounded-xl bg-indigo-600 text-white font-extrabold text-base flex items-center justify-center">1</div>
            <h3 className="text-lg font-bold text-white">Daftar & Isi Saldo</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Buat akun dengan email Anda, lalu lakukan top-up saldo via QRIS atau transfer bank mulai dari Rp 10.000.
            </p>
          </div>

          <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 relative space-y-3">
            <div className="w-10 h-10 rounded-xl bg-indigo-600 text-white font-extrabold text-base flex items-center justify-center">2</div>
            <h3 className="text-lg font-bold text-white">Buat API Key</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Buka menu API Keys dan klik buat kunci baru (<code className="text-indigo-400">sk-live-...</code>). Salin kunci untuk aplikasi Anda.
            </p>
          </div>

          <div className="p-6 rounded-2xl bg-slate-900 border border-slate-800 relative space-y-3">
            <div className="w-10 h-10 rounded-xl bg-indigo-600 text-white font-extrabold text-base flex items-center justify-center">3</div>
            <h3 className="text-lg font-bold text-white">Integrasikan & Nikmati</h3>
            <p className="text-xs text-slate-400 leading-relaxed">
              Arahkan <code className="text-indigo-400">base_url</code> ke router kami. Aplikasi Anda langsung terhubung ke AI tercanggih seketika!
            </p>
          </div>
        </div>
      </section>

      {/* Section: FAQ */}
      <section id="faq" className="py-16 sm:py-24 bg-slate-900/40 border-t border-slate-900">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">
          <div className="text-center space-y-3">
            <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Tanya Jawab</h2>
            <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">Pertanyaan yang Sering Diajukan</p>
          </div>

          <div className="space-y-3">
            {faqs.map((f, i) => (
              <div
                key={i}
                className="rounded-2xl bg-slate-900 border border-slate-800 overflow-hidden transition-all"
              >
                <button
                  onClick={() => setOpenFaq(openFaq === i ? null : i)}
                  className="w-full p-5 text-left text-sm font-bold text-white flex items-center justify-between hover:text-indigo-400 transition-colors"
                >
                  <span>{f.q}</span>
                  <ChevronDown
                    className={`w-4 h-4 text-slate-400 shrink-0 transition-transform ${
                      openFaq === i ? "rotate-180" : ""
                    }`}
                  />
                </button>
                {openFaq === i && (
                  <div className="px-5 pb-5 text-xs text-slate-300 leading-relaxed border-t border-slate-800/80 pt-3">
                    {f.a}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Bottom CTA Banner */}
      <section className="py-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="rounded-3xl bg-gradient-to-r from-indigo-900 via-indigo-950 to-slate-900 border border-indigo-700/50 p-8 sm:p-14 text-center space-y-6 relative overflow-hidden shadow-2xl">
          <div className="absolute top-0 right-0 w-80 h-80 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none" />
          
          <h2 className="text-3xl sm:text-5xl font-black text-white tracking-tight">
            Siap Membangun Aplikasi AI Anda?
          </h2>
          <p className="text-sm sm:text-base text-slate-300 max-w-xl mx-auto leading-relaxed">
            Daftar sekarang dan nikmati kebebasan memanggil model AI terbaik dunia dengan pembayaran fleksibel Rupiah.
          </p>

          <div className="flex flex-col sm:flex-row items-center justify-center gap-3 pt-2">
            <Link
              to="/register"
              className="px-8 py-3.5 rounded-xl bg-white hover:bg-slate-100 text-indigo-950 text-sm font-extrabold transition-all shadow-lg hover:scale-105"
            >
              Daftar Akun Sekarang
            </Link>
            <Link
              to="/docs"
              className="px-7 py-3.5 rounded-xl bg-indigo-800/60 hover:bg-indigo-800 text-white border border-indigo-600/50 text-sm font-semibold transition-all"
            >
              Lihat Dokumentasi API
            </Link>
          </div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-slate-900 bg-slate-950 py-10 text-xs text-slate-500 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
          <div className="flex items-center space-x-2">
            <div className="w-6 h-6 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-xs">
              <Cpu className="w-3.5 h-3.5" />
            </div>
            <span className="font-bold text-slate-300">AI Ciptaone Router</span>
            <span>•</span>
            <span>&copy; {new Date().getFullYear()} Seluruh hak cipta dilindungi.</span>
          </div>

          <div className="flex items-center space-x-6">
            <Link to="/docs" className="hover:text-slate-300 transition-colors">Dokumentasi</Link>
            <Link to="/playground" className="hover:text-slate-300 transition-colors">Playground</Link>
            <a href="/openapi.json" target="_blank" className="hover:text-slate-300 transition-colors">OpenAPI Spec</a>
          </div>
        </div>
      </footer>

    </div>
  );
};
