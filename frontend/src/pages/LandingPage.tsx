import React, { useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import {
  Activity,
  ArrowRight,
  ArrowUpRight,
  BookOpen,
  Bot,
  Check,
  CheckCircle2,
  ChevronDown,
  Code2,
  Coins,
  Copy,
  Cpu,
  CreditCard,
  FileText,
  Flame,
  Globe2,
  Headphones,
  HelpCircle,
  KeyRound,
  Layers,
  Lock,
  Menu,
  MessageCircle,
  MessageSquare,
  Phone,
  Play,
  QrCode,
  RefreshCw,
  Send,
  ShieldCheck,
  Sparkles,
  Star,
  Terminal,
  TrendingUp,
  Users,
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

  // Live Interactive Simulator State
  const [selectedDemoPreset, setSelectedDemoPreset] = useState<number>(0);
  const [isSimulating, setIsSimulating] = useState(false);
  const [simulatedOutput, setSimulatedOutput] = useState<string>("");

  const baseUrl = window.location.origin;
  const whatsappNumber = "+62 821-2345-6789";
  const whatsappLink = "https://wa.me/6282123456789?text=Halo%20Admin%20AI%20Ciptaone%2C%20saya%20tertarik%20dengan%20layanan%20AI%20Router%20dan%20ingin%20bertanya.";

  const demoPresets = [
    {
      title: "Asisten Customer Service",
      prompt: "Buatkan respon ramah untuk pelanggan yang menanyakan status pengiriman pesanan #ORD-9821.",
      response: "Halo Kak! Terima kasih sudah menghubungi kami. Pesanan #ORD-9821 saat ini telah diserahkan ke kurir J&T Express dan sedang dalam perjalanan menuju kota Anda. Estimasi tiba besok sore. Ada yang bisa kami bantu lagi Kak? 😊",
      model: "deepseek/deepseek-chat",
      tokens: 65,
      costIdr: "Rp 0,32",
    },
    {
      title: "Fungsi Python Kustom",
      prompt: "Tuliskan fungsi Python untuk membersihkan dan memformat nomor HP Indonesia ke format +62.",
      response: `def format_nomor_id(phone: str) -> str:
    cleaned = ''.join(filter(str.isdigit, phone))
    if cleaned.startswith('0'):
        cleaned = '62' + cleaned[1:]
    elif not cleaned.startswith('62'):
        cleaned = '62' + cleaned
    return f"+{cleaned}"`,
      model: "deepseek/deepseek-chat",
      tokens: 82,
      costIdr: "Rp 0,41",
    },
    {
      title: "Copywriting Iklan Instagram",
      prompt: "Tuliskan caption iklan Instagram untuk promo kopi susu gula aren diskon 30% hari ini.",
      response: "Ngantuk di jam kritis? ☕✨ Jangan biarkan fokusmu buyar! Nikmati creamy & legitnya Kopi Susu Gula Aren dengan DISKON 30% khusus hari ini. Cukup tunjukkan postingan ini di kasir atau pesan via GoFood/GrabFood. Jangan sampai kehabisan ya Sahabat Kopi! 🔥",
      model: "openai/gpt-4o-mini",
      tokens: 95,
      costIdr: "Rp 0,76",
    },
  ];

  const handleRunSimulation = (index: number) => {
    setSelectedDemoPreset(index);
    setIsSimulating(true);
    setSimulatedOutput("");

    const fullText = demoPresets[index].response;
    let currentIdx = 0;

    const interval = setInterval(() => {
      currentIdx += 4;
      if (currentIdx >= fullText.length) {
        setSimulatedOutput(fullText);
        setIsSimulating(false);
        clearInterval(interval);
      } else {
        setSimulatedOutput(fullText.slice(0, currentIdx));
      }
    }, 20);
  };

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
      badgeColor: "bg-emerald-500/10 text-emerald-400 border-emerald-500/30",
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
      badgeColor: "bg-blue-500/10 text-blue-400 border-blue-500/30",
      desc: "Model ringan OpenAI yang sangat responsif untuk tugas NLP sehari-hari dan parsing JSON.",
      inPrice: "Rp 3,0",
      outPrice: "Rp 12,0",
      context: "128k Context",
      recommended: false,
    },
    {
      id: "anthropic/claude-3-5-sonnet",
      name: "Claude 3.5 Sonnet",
      badge: "Juara Coding & Logika",
      badgeColor: "bg-amber-500/10 text-amber-400 border-amber-500/30",
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
      badgeColor: "bg-purple-500/10 text-purple-400 border-purple-500/30",
      desc: "Model uji coba deterministik untuk pengujian unit test backend Anda tanpa biaya token.",
      inPrice: "Rp 1,0",
      outPrice: "Rp 1,0",
      context: "8k Context",
      recommended: false,
    },
  ];

  const useCases = [
    {
      icon: MessageCircle,
      title: "Chatbot WhatsApp & Customer Service",
      desc: "Hubungkan router ke WhatsApp Business API, Telegram Bot, atau Live Chat web untuk melayani ribuan pelanggan otomatis 24 jam nonstop.",
      highlight: "Balas Cepat & Natural",
      tag: "Customer Care",
    },
    {
      icon: Code2,
      title: "Coding Assistant & Developer Tools",
      desc: "Gunakan sebagai endpoint di Cursor IDE, VS Code Continue, Windsurf, atau Aider. Nikmati kemampuan coding Claude 3.5 Sonnet & DeepSeek V3.",
      highlight: "Kompatibel 100% Cursor",
      tag: "Software Engineering",
    },
    {
      icon: FileText,
      title: "Otomatisasi Konten & Copywriting SEO",
      desc: "Hasilkan artikel blog ribuan kata, deskripsi produk marketplace, caption media sosial, hingga email marketing dalam Bahasa Indonesia yang mengalir.",
      highlight: "Hemat Biaya Tim Konten",
      tag: "Marketing & Media",
    },
    {
      icon: Layers,
      title: "Analisis Dokumen PDF & Laporan Keuangan",
      desc: "Unggah teks laporan tahunan panjang, regulasi hukum, atau berkas transaksi. Context window hingga 200k token siap membedah informasi akurat.",
      highlight: "Konteks Raksasa 200k",
      tag: "Data Extraction",
    },
    {
      icon: Bot,
      title: "Multi-Agent AI (CrewAI, LangChain)",
      desc: "Jalankan arsitektur agen otonom yang melakukan penalaran berulang. Biaya DeepSeek V3 yang super terjangkau menjaga budget riset Anda tetap aman.",
      highlight: "Hemat Hingga 85%",
      tag: "Autonomous Agents",
    },
    {
      icon: Globe2,
      title: "Backend Microservices & SaaS AI",
      desc: "Integrasikan ke backend Laravel, Node.js Express, NestJS, FastAPI Python, atau Golang Anda dengan latency rendah dan rate-limit yang lapang.",
      highlight: "SLA Uptime 99.98%",
      tag: "Enterprise SaaS",
    },
  ];

  const features = [
    {
      icon: Code2,
      title: "100% Drop-In Replacement",
      desc: "Cukup ganti base_url di library OpenAI resmi Anda. Tidak perlu rombak kode atau pelajari library baru.",
    },
    {
      icon: QrCode,
      title: "Top-up QRIS & Bank Lokal",
      desc: "Bebas dari penolakan kartu kredit valuta asing. Bayar mulai Rp 10.000 dengan QRIS instan atau transfer BCA/Mandiri/BRI.",
    },
    {
      icon: ShieldCheck,
      title: "Reservasi Kredit Atomik (Double-Entry)",
      desc: "Sistem ledger anti-minus. Saldo dicadangkan saat request dan sisa token yang tidak terpakai dikembalikan seketika.",
    },
    {
      icon: Zap,
      title: "Streaming SSE Real-Time",
      desc: "Output jawaban kata-demi-kata (Server-Sent Events) mengalir instan tanpa jeda buffer untuk pengalaman chat interaktif.",
    },
    {
      icon: Lock,
      title: "Keamanan Kriptografi SHA-256",
      desc: "API Key Anda disimpan dalam bentuk hash kriptografis SHA-256 di database server. Secret key tidak pernah disimpan terbuka.",
    },
    {
      icon: Layers,
      title: "Multi-Model dalam 1 Akun",
      desc: "Gonta-ganti model dari DeepSeek V3 ke GPT-4o atau Claude 3.5 Sonnet hanya dengan mengganti parameter nama model.",
    },
    {
      icon: RefreshCw,
      title: "Smart Dynamic Failover",
      desc: "Jika salah satu upstream server mengalami gangguan, router otomatis menjaga request tetap berjalan dengan handal.",
    },
    {
      icon: TrendingUp,
      title: "Dashboard Analitik Real-time",
      desc: "Pantau penggunaan token input, token output, dan rincian rupiah pemakaian secara detail dan transparan per detik.",
    },
  ];

  const testimonials = [
    {
      quote: "Sebelumnya kantor kami bayar ratusan dollar tiap bulan untuk langganan OpenAI. Begitu switch ke AI Ciptaone Router dengan DeepSeek V3, tagihan bulanan turun lebih dari 75% tanpa penurunan kualitas!",
      name: "Rian Hendrawan",
      role: "CTO, SaaS Startup Jakarta",
      rating: 5,
    },
    {
      quote: "Fitur top-up QRIS-nya penyelamat banget! Tim kami nggak perlu minjam kartu kredit kantor setiap kali mau ngetes AI di Cursor IDE atau bikin bot WhatsApp pelanggan.",
      name: "Siti Rahmawati",
      role: "Lead Software Engineer, Bandung",
      rating: 5,
    },
    {
      quote: "Integrasinya beneran cuma 3 baris kode! Ganti base_url ke domain router, masukin API key, selesai. Latensinya mantap dan pembukuan kreditnya sangat transparan.",
      name: "Budi Santoso",
      role: "Fullstack Developer & Agency Owner, Surabaya",
      rating: 5,
    },
  ];

  const comparisonRows = [
    {
      feature: "Metode Pembayaran",
      openai: "Wajib Kartu Kredit Luar Negeri (Sering Ditolak)",
      ciptaone: "QRIS Instan (GoPay, OVO, Dana, BCA Mobile) & Bank Lokal",
    },
    {
      feature: "Mata Uang & Kurs",
      openai: "USD ($) Fluktuatif + Biaya Konversi Bank",
      ciptaone: "Rupiah Flat (1 Kredit = Tepat Rp 1,00)",
    },
    {
      feature: "Pilihan Ekosistem Model",
      openai: "Terbatas hanya model milik OpenAI",
      ciptaone: "Universal: DeepSeek V3, Claude 3.5, GPT-4o dlm 1 API",
    },
    {
      feature: "Deposit Minimum",
      openai: "$5 - $20 USD (~Rp 80.000 - Rp 320.000)",
      ciptaone: "Mulai dari Rp 10.000 saja",
    },
    {
      feature: "Customer Support & CS WhatsApp",
      openai: "Email bot berbahasa Inggris (Balasan hari/minggu)",
      ciptaone: "WhatsApp Resmi (+62) & Komunitas Telegram Aktif",
    },
    {
      feature: "Perlindungan Saldo",
      openai: "Dapat terus mendebet kartu kredit otomatis",
      ciptaone: "Atomic Double-Entry Ledger, Saldo Tidak Pernah Minus",
    },
  ];

  const faqs = [
    {
      q: "Apakah saya butuh kartu kredit berlogo Visa/Mastercard luar negeri?",
      a: "Sama sekali tidak! Anda cukup mengisi saldo dompet mulai dari Rp 10.000 melalui QRIS instan (GoPay, OVO, Dana, BCA Mobile, ShopeePay) atau transfer bank lokal (BCA, Mandiri, BRI). Tanpa potongan kurs valuta asing.",
    },
    {
      q: "Bagaimana cara beralih dari API OpenAI resmi ke AI Ciptaone Router?",
      a: "Cukup ganti satu parameter pada inisialisasi SDK Anda: tambahkan base_url='https://ai.ciptaone.site/v1' dan gunakan API key sk-live-... yang Anda buat di dashboard. Seluruh kode program lainnya tidak perlu diubah sama sekali.",
    },
    {
      q: "Apakah ada nomor WhatsApp resmi untuk bantuan atau kendala top-up?",
      a: `Ada! Layanan pelanggan kami siap membantu Anda melalui WhatsApp resmi di nomor ${whatsappNumber} setiap hari dari pukul 08:00 hingga 23:00 WIB. Anda juga bisa bergabung ke grup Telegram kami.`,
    },
    {
      q: "Bagaimana cara kerja saldo kredit dan reservasi atomik?",
      a: "1 Kredit bernilai tepat Rp 1,00. Saat request masuk, sistem mencadangkan sejumlah kredit berdasarkan estimasi token. Setelah jawaban model selesai, sistem hanya mendebet token riil yang terpakai dan sisa kredit langsung dikembalikan seketika. Saldo Anda terlindungi dan tidak pernah menjadi minus.",
    },
    {
      q: "Apakah saldo kredit saya memiliki masa kedaluwarsa?",
      a: "Tidak ada masa kedaluwarsa! Saldo kredit yang sudah Anda isi akan tetap aktif selamanya dan siap dipakai kapan pun Anda membutuhkannya.",
    },
    {
      q: "Apakah saya bisa memakai router ini di tools seperti Cursor, VS Code Continue, atau Open WebUI?",
      a: "Bisa 100%! Cukup masukkan Custom OpenAI Endpoint pada preferensi tool Anda dan paste API Key dari dashboard kami.",
    },
  ];

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-indigo-500 selection:text-white overflow-x-hidden relative">
      
      {/* Background Decorative Glows */}
      <div className="absolute top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-[650px] pointer-events-none overflow-hidden -z-10">
        <div className="absolute top-[-120px] left-1/4 w-[550px] h-[550px] bg-indigo-600/20 rounded-full blur-[140px]" />
        <div className="absolute top-[-80px] right-1/4 w-[450px] h-[450px] bg-purple-600/15 rounded-full blur-[160px]" />
      </div>

      {/* Top Status & WhatsApp Help Bar */}
      <div className="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 border-b border-indigo-900/40 text-[11px] sm:text-xs py-2 px-4">
        <div className="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-1.5 text-slate-300">
          <div className="flex items-center space-x-2">
            <span className="flex h-2 w-2 relative">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            <span className="font-medium text-slate-200">
              Sistem Operasional Normal <span className="text-slate-400">• Latensi ~180ms • Uptime 99.98%</span>
            </span>
          </div>

          <div className="flex items-center space-x-3 text-slate-300">
            <a
              href={whatsappLink}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center space-x-1.5 text-emerald-400 hover:text-emerald-300 font-semibold transition-colors"
            >
              <Phone className="w-3.5 h-3.5 shrink-0" />
              <span>CS WhatsApp: {whatsappNumber}</span>
            </a>
            <span className="hidden md:inline text-slate-600">|</span>
            <span className="hidden md:inline text-slate-400">Jam Layanan: 08:00 - 23:00 WIB</span>
          </div>
        </div>
      </div>

      {/* Main Navigation Header */}
      <header className="sticky top-0 z-50 bg-slate-950/90 backdrop-blur-md border-b border-slate-800/80">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          
          {/* Logo */}
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
            <a href="#fitur" className="hover:text-indigo-400 transition-colors">Fitur Unggulan</a>
            <a href="#fungsi" className="hover:text-indigo-400 transition-colors">Fungsi & Solusi</a>
            <a href="#perbandingan" className="hover:text-indigo-400 transition-colors">Perbandingan</a>
            <a href="#models" className="hover:text-indigo-400 transition-colors">Katalog Model</a>
            <a href="#kalkulator" className="hover:text-indigo-400 transition-colors">Kalkulator Tarif</a>
            <a href="#kontak" className="hover:text-indigo-400 transition-colors">Bantuan & Kontak</a>
            <Link to="/docs" className="flex items-center space-x-1 text-indigo-400 hover:text-indigo-300 transition-colors">
              <BookOpen className="w-3.5 h-3.5" />
              <span>Dokumentasi API</span>
            </Link>
          </nav>

          {/* Action CTAs & Mobile Toggle */}
          <div className="flex items-center space-x-2 sm:space-x-3 text-xs">
            <a
              href={whatsappLink}
              target="_blank"
              rel="noopener noreferrer"
              className="hidden sm:flex items-center space-x-1.5 px-3 py-1.5 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/20 transition-all font-semibold"
              title="Konsultasi langsung via WhatsApp"
            >
              <MessageCircle className="w-3.5 h-3.5" />
              <span>Chat CS</span>
            </a>

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
                className="block px-3 py-2 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Fitur Unggulan
              </a>
              <a
                href="#fungsi"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Fungsi & Solusi Nyata
              </a>
              <a
                href="#perbandingan"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Perbandingan vs OpenAI
              </a>
              <a
                href="#models"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Katalog Model AI
              </a>
              <a
                href="#kalkulator"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2 rounded-xl hover:bg-slate-900 hover:text-white transition"
              >
                Kalkulator Tarif
              </a>
              <a
                href="#kontak"
                onClick={() => setMobileMenuOpen(false)}
                className="block px-3 py-2 rounded-xl hover:bg-slate-900 hover:text-white transition text-emerald-400 font-semibold"
              >
                Nomor Kontak & Bantuan CS
              </a>
              <Link
                to="/docs"
                onClick={() => setMobileMenuOpen(false)}
                className="flex items-center space-x-2 px-3 py-2 rounded-xl text-indigo-400 hover:bg-slate-900 transition"
              >
                <BookOpen className="w-4 h-4" />
                <span>Dokumentasi API</span>
              </Link>
            </nav>

            <div className="pt-2 border-t border-slate-800/80 space-y-2">
              <a
                href={whatsappLink}
                target="_blank"
                rel="noopener noreferrer"
                className="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl text-sm font-bold bg-emerald-600 hover:bg-emerald-500 text-white transition shadow-sm"
              >
                <MessageCircle className="w-4 h-4" />
                <span>Chat WhatsApp: {whatsappNumber}</span>
              </a>

              {!user && (
                <div className="grid grid-cols-2 gap-2">
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
                    Daftar Akun
                  </Link>
                </div>
              )}
            </div>
          </div>
        )}
      </header>

      {/* Hero Section */}
      <section className="relative pt-10 pb-14 sm:pt-16 sm:pb-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-6 sm:space-y-8">
        
        {/* Announcement Pill */}
        <div className="inline-flex items-center space-x-2 px-4 py-1.5 rounded-full bg-indigo-500/10 border border-indigo-500/25 text-indigo-300 text-xs font-semibold backdrop-blur-sm">
          <Sparkles className="w-3.5 h-3.5 text-indigo-400 shrink-0" />
          <span>Universal AI Gateway Indonesia • 100% Kompatibel OpenAI API</span>
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
            Tinggalkan langganan dollar ($) yang boros. Akses <strong>DeepSeek V3</strong>, <strong>GPT-4o</strong>, dan <strong>Claude 3.5 Sonnet</strong> cukup dengan top-up saldo via <strong>QRIS & Bank Lokal</strong>. Hemat hingga 80% biaya token!
          </p>
        </div>

        {/* Action Buttons */}
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
            to="/playground"
            className="w-full sm:w-auto px-5 sm:px-6 py-3.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 border border-slate-800 text-sm font-semibold flex items-center justify-center space-x-2 transition-all"
          >
            <Play className="w-4 h-4 text-emerald-400 fill-emerald-400/20 shrink-0" />
            <span>Coba Playground</span>
          </Link>

          <a
            href={whatsappLink}
            target="_blank"
            rel="noopener noreferrer"
            className="w-full sm:w-auto px-5 sm:px-6 py-3.5 rounded-xl bg-emerald-950/40 hover:bg-emerald-900/40 text-emerald-300 border border-emerald-600/40 text-sm font-semibold flex items-center justify-center space-x-2 transition-all"
          >
            <MessageCircle className="w-4 h-4 text-emerald-400 shrink-0" />
            <span>Tanya CS WhatsApp</span>
          </a>
        </div>

        {/* Value Badges */}
        <div className="pt-2 flex flex-wrap items-center justify-center gap-y-2 gap-x-4 sm:gap-x-6 text-[11px] sm:text-xs text-slate-400">
          <div className="flex items-center space-x-1.5">
            <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-emerald-400 shrink-0" />
            <span>1 Kredit = Tepat Rp 1,00</span>
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
            <span>Streaming SSE Cepat</span>
          </div>
        </div>

        {/* Metrics Bar */}
        <div className="pt-4 max-w-4xl mx-auto grid grid-cols-2 sm:grid-cols-4 gap-3 text-left">
          <div className="p-4 rounded-2xl bg-slate-900/80 border border-slate-800">
            <div className="text-xl sm:text-2xl font-black text-indigo-400">100.000+</div>
            <div className="text-[11px] text-slate-400 font-medium">Request Terlayani</div>
          </div>
          <div className="p-4 rounded-2xl bg-slate-900/80 border border-slate-800">
            <div className="text-xl sm:text-2xl font-black text-emerald-400">~180ms</div>
            <div className="text-[11px] text-slate-400 font-medium">Rata-rata Respon</div>
          </div>
          <div className="p-4 rounded-2xl bg-slate-900/80 border border-slate-800">
            <div className="text-xl sm:text-2xl font-black text-purple-400">80%</div>
            <div className="text-[11px] text-slate-400 font-medium">Penghematan Biaya</div>
          </div>
          <div className="p-4 rounded-2xl bg-slate-900/80 border border-slate-800">
            <div className="text-xl sm:text-2xl font-black text-amber-400">Rp 10.000</div>
            <div className="text-[11px] text-slate-400 font-medium">Minimal Top-up Saldo</div>
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
                <span className="text-xs font-mono text-slate-400 ml-1.5 hidden sm:inline">Quickstart Drop-in (OpenAI SDK)</span>
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

      {/* Section: Interactive Live Simulator (Coba Langsung di Sini) */}
      <section className="py-14 sm:py-20 bg-gradient-to-b from-slate-950 via-indigo-950/20 to-slate-950 border-y border-slate-900">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
          <div className="text-center max-w-2xl mx-auto space-y-2">
            <span className="text-xs uppercase font-bold tracking-widest text-emerald-400 flex items-center justify-center space-x-1.5">
              <Sparkles className="w-3.5 h-3.5" />
              <span>Simulasi Interaktif Langsung</span>
            </span>
            <h2 className="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
              Rasakan Kecepatan & Efisiensi Biaya AI Ciptaone
            </h2>
            <p className="text-xs sm:text-sm text-slate-400">
              Pilih salah satu contoh skenario di bawah ini untuk melihat kecepatan respons dan perkiraan biaya riil dalam Rupiah.
            </p>
          </div>

          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-5 sm:p-8 space-y-6 shadow-2xl">
            {/* Presets Pills */}
            <div className="flex flex-wrap gap-2">
              {demoPresets.map((preset, idx) => (
                <button
                  key={idx}
                  onClick={() => handleRunSimulation(idx)}
                  className={`px-3.5 py-2 rounded-xl text-xs font-semibold transition-all flex items-center space-x-2 ${
                    selectedDemoPreset === idx
                      ? "bg-indigo-600 text-white shadow-md shadow-indigo-600/30"
                      : "bg-slate-800/80 text-slate-300 hover:bg-slate-800 hover:text-white"
                  }`}
                >
                  <Bot className="w-3.5 h-3.5" />
                  <span>{preset.title}</span>
                </button>
              ))}
            </div>

            {/* Prompt Box */}
            <div className="p-4 rounded-2xl bg-slate-950 border border-slate-800 space-y-2">
              <div className="text-[11px] font-bold uppercase tracking-wider text-slate-400 flex items-center justify-between">
                <span>Prompt Masukan (User Input):</span>
                <span className="text-indigo-400 font-mono text-[10px]">{demoPresets[selectedDemoPreset].model}</span>
              </div>
              <p className="text-xs sm:text-sm text-slate-200 font-medium">
                "{demoPresets[selectedDemoPreset].prompt}"
              </p>
            </div>

            {/* Response Output Box */}
            <div className="p-4 rounded-2xl bg-slate-950 border border-indigo-950/60 space-y-3 relative min-h-[140px]">
              <div className="flex items-center justify-between text-[11px] text-slate-400 border-b border-slate-900 pb-2">
                <span className="font-bold uppercase tracking-wider text-emerald-400 flex items-center space-x-1.5">
                  <Cpu className="w-3.5 h-3.5" />
                  <span>Output Respons AI:</span>
                </span>
                <div className="flex items-center space-x-3 text-[11px]">
                  <span>Estimasi Token: <strong className="text-white">{demoPresets[selectedDemoPreset].tokens}</strong></span>
                  <span className="text-emerald-400 font-bold bg-emerald-500/10 px-2 py-0.5 rounded-md border border-emerald-500/20">
                    Biaya: {demoPresets[selectedDemoPreset].costIdr}
                  </span>
                </div>
              </div>

              <div className="text-xs sm:text-sm font-sans text-slate-200 whitespace-pre-wrap leading-relaxed">
                {isSimulating ? (
                  <div>
                    {simulatedOutput}
                    <span className="inline-block w-2 h-4 bg-indigo-400 ml-1 animate-pulse" />
                  </div>
                ) : (
                  simulatedOutput || demoPresets[selectedDemoPreset].response
                )}
              </div>
            </div>

            {/* Action trigger button */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
              <div className="text-xs text-slate-400 flex items-center space-x-2">
                <ShieldCheck className="w-4 h-4 text-emerald-400 shrink-0" />
                <span>Hanya membayar token yang terpakai. Tanpa biaya bulanan tersembunyi.</span>
              </div>

              <button
                onClick={() => handleRunSimulation(selectedDemoPreset)}
                disabled={isSimulating}
                className="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white text-xs font-bold transition flex items-center justify-center space-x-2 shadow-md shadow-indigo-600/25"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${isSimulating ? "animate-spin" : ""}`} />
                <span>{isSimulating ? "Menghasilkan Respons..." : "Jalankan Simulasi Ulang"}</span>
              </button>
            </div>
          </div>
        </div>
      </section>

      {/* Section: 6 Fungsi & Solusi Nyata (Use Cases) */}
      <section id="fungsi" className="py-16 sm:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        <div className="text-center max-w-3xl mx-auto space-y-3">
          <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Solusi Nyata & Fleksibel</h2>
          <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
            Fungsi AI Ciptaone untuk Bisnis & Pengembang
          </p>
          <p className="text-sm text-slate-400">
            Dari otomatisasi percakapan hingga arsitektur backend skala enterprise, semua kebutuhan AI Anda dapat terlayani dengan satu integrasi mudah.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {useCases.map((uc, i) => {
            const Icon = uc.icon;
            return (
              <div
                key={i}
                className="p-6 sm:p-7 rounded-3xl bg-slate-900/80 border border-slate-800 hover:border-indigo-500/50 transition-all duration-300 flex flex-col justify-between group space-y-4"
              >
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <div className="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center group-hover:scale-110 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                      <Icon className="w-6 h-6" />
                    </div>
                    <span className="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full bg-slate-800 text-slate-300 border border-slate-700">
                      {uc.tag}
                    </span>
                  </div>

                  <h3 className="text-lg font-bold text-white group-hover:text-indigo-300 transition-colors">
                    {uc.title}
                  </h3>

                  <p className="text-xs sm:text-sm text-slate-400 leading-relaxed">
                    {uc.desc}
                  </p>
                </div>

                <div className="pt-4 border-t border-slate-800/80 flex items-center justify-between text-xs font-semibold text-emerald-400">
                  <span className="flex items-center space-x-1.5">
                    <CheckCircle2 className="w-4 h-4 shrink-0" />
                    <span>{uc.highlight}</span>
                  </span>
                  <ArrowUpRight className="w-4 h-4 text-slate-500 group-hover:text-indigo-400 transition-colors" />
                </div>
              </div>
            );
          })}
        </div>
      </section>

      {/* Section: Perbandingan Head-to-Head (OpenAI Langsung vs AI Ciptaone Router) */}
      <section id="perbandingan" className="py-16 sm:py-24 bg-slate-900/50 border-y border-slate-900">
        <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
          
          <div className="text-center max-w-3xl mx-auto space-y-3">
            <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Perbandingan Objektif</h2>
            <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
              Mengapa Berlangganan OpenAI Langsung Bikin Repot?
            </p>
            <p className="text-sm text-slate-400">
              Lihat bagaimana AI Ciptaone Router memecahkan kendala kartu kredit, kurs dolar, dan pembatasan model.
            </p>
          </div>

          <div className="bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden shadow-2xl">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs sm:text-sm">
                <thead>
                  <tr className="bg-slate-950 border-b border-slate-800 text-slate-400 uppercase font-mono text-[11px]">
                    <th className="py-4 px-5 sm:px-6">Fitur & Parameter</th>
                    <th className="py-4 px-5 sm:px-6 text-rose-400/90">OpenAI Resmi Langsung</th>
                    <th className="py-4 px-5 sm:px-6 text-emerald-400 bg-indigo-950/30">AI Ciptaone Router</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/80 text-slate-300">
                  {comparisonRows.map((row, idx) => (
                    <tr key={idx} className="hover:bg-slate-850/50 transition-colors">
                      <td className="py-4 px-5 sm:px-6 font-semibold text-white whitespace-nowrap">
                        {row.feature}
                      </td>
                      <td className="py-4 px-5 sm:px-6 text-slate-400">
                        <div className="flex items-center space-x-2">
                          <X className="w-4 h-4 text-rose-400 shrink-0" />
                          <span>{row.openai}</span>
                        </div>
                      </td>
                      <td className="py-4 px-5 sm:px-6 text-white font-medium bg-indigo-950/20">
                        <div className="flex items-center space-x-2 text-emerald-300">
                          <Check className="w-4 h-4 text-emerald-400 shrink-0 font-black" />
                          <span>{row.ciptaone}</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      {/* Section: 8 Fitur Unggulan Arsitektur Router */}
      <section id="fitur" className="py-16 sm:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        
        <div className="text-center max-w-3xl mx-auto space-y-3">
          <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Arsitektur & Fitur</h2>
          <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
            Fitur Canggih untuk Pengembang Modern
          </p>
          <p className="text-sm text-slate-400">
            Dirancang dari awal dengan performa tinggi, keamanan kriptografis, dan kemudahan integrasi.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
          {features.map((f, i) => {
            const Icon = f.icon;
            return (
              <div
                key={i}
                className="p-6 rounded-2xl bg-slate-900/90 border border-slate-800 hover:border-indigo-500/50 transition-all space-y-3 group"
              >
                <div className="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center group-hover:scale-110 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                  <Icon className="w-5 h-5" />
                </div>
                <h3 className="text-base font-bold text-white">{f.title}</h3>
                <p className="text-xs text-slate-400 leading-relaxed">{f.desc}</p>
              </div>
            );
          })}
        </div>
      </section>

      {/* Section: Model Showcase */}
      <section id="models" className="py-16 sm:py-24 bg-slate-900/30 border-t border-slate-900">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
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

          <div className="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 space-y-6 shadow-xl">
            <div className="space-y-2">
              <div className="flex justify-between text-sm font-semibold text-slate-200">
                <span>Perkiraan Token per Bulan:</span>
                <span className="text-indigo-400 font-mono font-bold">
                  {promptTokens.toLocaleString("id-ID")} Token (~{Math.round(promptTokens * 0.75).toLocaleString("id-ID")} Kata)
                </span>
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

      {/* Section: Testimoni Pengembang */}
      <section className="py-16 sm:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        <div className="text-center max-w-2xl mx-auto space-y-3">
          <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Ulasan Nyata</h2>
          <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
            Dipercaya Pengembang & Pebisnis Indonesia
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {testimonials.map((t, idx) => (
            <div
              key={idx}
              className="p-6 sm:p-7 rounded-3xl bg-slate-900 border border-slate-800 flex flex-col justify-between space-y-4 shadow-lg"
            >
              <div className="space-y-3">
                <div className="flex items-center space-x-1 text-amber-400">
                  {[...Array(t.rating)].map((_, i) => (
                    <Star key={i} className="w-4 h-4 fill-amber-400" />
                  ))}
                </div>
                <p className="text-xs sm:text-sm text-slate-300 leading-relaxed italic">
                  "{t.quote}"
                </p>
              </div>

              <div className="pt-4 border-t border-slate-800/80">
                <div className="font-bold text-sm text-white">{t.name}</div>
                <div className="text-[11px] text-indigo-400">{t.role}</div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Section: 3 Langkah Mudah */}
      <section className="py-16 sm:py-20 bg-slate-900/40 border-t border-slate-900">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
          <div className="text-center max-w-2xl mx-auto space-y-3">
            <h2 className="text-xs uppercase font-bold tracking-widest text-indigo-400">Alur Kerja Cepat</h2>
            <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">Mulai Dalam 3 Menit</p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div className="p-6 rounded-3xl bg-slate-900 border border-slate-800 relative space-y-3">
              <div className="w-10 h-10 rounded-2xl bg-indigo-600 text-white font-extrabold text-base flex items-center justify-center">1</div>
              <h3 className="text-lg font-bold text-white">Daftar & Isi Saldo</h3>
              <p className="text-xs text-slate-400 leading-relaxed">
                Buat akun dengan email Anda, lalu lakukan top-up saldo via QRIS atau transfer bank mulai dari Rp 10.000.
              </p>
            </div>

            <div className="p-6 rounded-3xl bg-slate-900 border border-slate-800 relative space-y-3">
              <div className="w-10 h-10 rounded-2xl bg-indigo-600 text-white font-extrabold text-base flex items-center justify-center">2</div>
              <h3 className="text-lg font-bold text-white">Buat API Key</h3>
              <p className="text-xs text-slate-400 leading-relaxed">
                Buka menu API Keys dan klik buat kunci baru (<code className="text-indigo-400">sk-live-...</code>). Salin kunci untuk aplikasi Anda.
              </p>
            </div>

            <div className="p-6 rounded-3xl bg-slate-900 border border-slate-800 relative space-y-3">
              <div className="w-10 h-10 rounded-2xl bg-indigo-600 text-white font-extrabold text-base flex items-center justify-center">3</div>
              <h3 className="text-lg font-bold text-white">Integrasikan & Nikmati</h3>
              <p className="text-xs text-slate-400 leading-relaxed">
                Arahkan <code className="text-indigo-400">base_url</code> ke router kami. Aplikasi Anda langsung terhubung ke AI tercanggih seketika!
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* Section: Kontak Bantuan, Nomor WhatsApp & FAQ */}
      <section id="kontak" className="py-16 sm:py-24 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        
        <div className="text-center max-w-3xl mx-auto space-y-3">
          <h2 className="text-xs uppercase font-bold tracking-widest text-emerald-400">Bantuan & Layanan Pelanggan</h2>
          <p className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">
            Kami Siap Membantu Anda Kapan Saja
          </p>
          <p className="text-sm text-slate-400">
            Punya pertanyaan mengenai integrasi, kendala pembayaran, atau kebutuhan kuota enterprise? Hubungi tim support kami.
          </p>
        </div>

        {/* Contact Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          
          {/* Card WhatsApp */}
          <div className="p-6 sm:p-7 rounded-3xl bg-gradient-to-br from-slate-900 to-emerald-950/40 border border-emerald-500/30 flex flex-col justify-between space-y-4">
            <div className="space-y-3">
              <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                <MessageCircle className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-lg font-bold text-white">WhatsApp Customer Support</h3>
                <p className="text-xs text-slate-400 mt-1">
                  Respon tercepat untuk kendala teknis, konfirmasi invoice, atau panduan setup API.
                </p>
              </div>
              <div className="text-sm font-bold text-emerald-400 font-mono">
                {whatsappNumber}
              </div>
            </div>

            <a
              href={whatsappLink}
              target="_blank"
              rel="noopener noreferrer"
              className="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs flex items-center justify-center space-x-2 transition shadow-md shadow-emerald-600/30"
            >
              <MessageSquare className="w-4 h-4" />
              <span>Chat WhatsApp Sekarang</span>
            </a>
          </div>

          {/* Card Email Support */}
          <div className="p-6 sm:p-7 rounded-3xl bg-slate-900 border border-slate-800 flex flex-col justify-between space-y-4">
            <div className="space-y-3">
              <div className="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                <Headphones className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-lg font-bold text-white">Email Resmi Bantuan</h3>
                <p className="text-xs text-slate-400 mt-1">
                  Untuk pertanyaan kerjasama, penawaran enterprise kuota besar, atau kendala formal.
                </p>
              </div>
              <div className="text-sm font-bold text-indigo-400 font-mono">
                support@ciptaone.site
              </div>
            </div>

            <a
              href="mailto:support@ciptaone.site?subject=Pertanyaan%20AI%20Ciptaone%20Router"
              className="w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs flex items-center justify-center space-x-2 transition"
            >
              <Send className="w-4 h-4" />
              <span>Kirim Email Support</span>
            </a>
          </div>

          {/* Card Jam Operasional */}
          <div className="p-6 sm:p-7 rounded-3xl bg-slate-900 border border-slate-800 flex flex-col justify-between space-y-4">
            <div className="space-y-3">
              <div className="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center">
                <Activity className="w-6 h-6" />
              </div>
              <div>
                <h3 className="text-lg font-bold text-white">Jam Operasional Layanan</h3>
                <p className="text-xs text-slate-400 mt-1">
                  Server beroperasi 24/7 otomatis. Tim helpdesk manusia siap memandu di jam operasional.
                </p>
              </div>
              <div className="text-xs text-slate-300 space-y-1">
                <div>• <strong>Senin - Minggu:</strong> 08:00 - 23:00 WIB</div>
                <div>• <strong>API Router:</strong> Aktif 24 Jam Nonstop</div>
              </div>
            </div>

            <Link
              to="/docs"
              className="w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-indigo-300 font-bold text-xs flex items-center justify-center space-x-2 transition"
            >
              <BookOpen className="w-4 h-4" />
              <span>Baca Dokumentasi API</span>
            </Link>
          </div>

        </div>

        {/* FAQ Accordion */}
        <div id="faq" className="pt-8 max-w-4xl mx-auto space-y-6">
          <div className="text-center space-y-2">
            <h3 className="text-xl sm:text-2xl font-bold text-white">Pertanyaan yang Sering Diajukan (FAQ)</h3>
            <p className="text-xs text-slate-400">Jawaban cepat untuk hal-hal yang sering ditanyakan pengguna baru.</p>
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
      <section className="py-16 sm:py-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
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
            <a
              href={whatsappLink}
              target="_blank"
              rel="noopener noreferrer"
              className="px-7 py-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold transition-all flex items-center space-x-2"
            >
              <MessageCircle className="w-4 h-4" />
              <span>Chat CS WhatsApp: {whatsappNumber}</span>
            </a>
          </div>
        </div>
      </section>

      {/* Comprehensive Footer */}
      <footer className="border-t border-slate-900 bg-slate-950 py-12 text-xs text-slate-400 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
          
          <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
            {/* Col 1: Brand & Bio */}
            <div className="space-y-3 md:col-span-1">
              <div className="flex items-center space-x-2">
                <div className="w-8 h-8 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold text-sm">
                  <Cpu className="w-4 h-4" />
                </div>
                <span className="font-extrabold text-base text-white">AI Ciptaone Router</span>
              </div>
              <p className="text-xs text-slate-400 leading-relaxed">
                Platform router AI universal untuk developer & pebisnis Indonesia. Satu API Key untuk DeepSeek, Claude, dan GPT-4o dengan pembayaran QRIS & Rupiah.
              </p>
              <div className="text-[11px] text-slate-500">
                &copy; {new Date().getFullYear()} AI Ciptaone. Seluruh hak cipta dilindungi.
              </div>
            </div>

            {/* Col 2: Navigasi Produk */}
            <div className="space-y-2">
              <div className="font-bold text-white text-xs uppercase tracking-wider">Produk & Fitur</div>
              <ul className="space-y-1.5 text-xs text-slate-400">
                <li><a href="#fitur" className="hover:text-white transition">Fitur Router</a></li>
                <li><a href="#fungsi" className="hover:text-white transition">Fungsi & Solusi</a></li>
                <li><a href="#models" className="hover:text-white transition">Katalog Model AI</a></li>
                <li><a href="#kalkulator" className="hover:text-white transition">Kalkulator Token</a></li>
                <li><Link to="/playground" className="hover:text-white transition">Playground Chat</Link></li>
              </ul>
            </div>

            {/* Col 3: Pengembang & Dokumentasi */}
            <div className="space-y-2">
              <div className="font-bold text-white text-xs uppercase tracking-wider">Pengembang</div>
              <ul className="space-y-1.5 text-xs text-slate-400">
                <li><Link to="/docs" className="hover:text-white transition">Dokumentasi API</Link></li>
                <li><a href="/openapi.json" target="_blank" className="hover:text-white transition">OpenAPI Specification</a></li>
                <li><Link to="/api-keys" className="hover:text-white transition">Manajemen API Key</Link></li>
                <li><a href="#perbandingan" className="hover:text-white transition">Perbandingan vs OpenAI</a></li>
              </ul>
            </div>

            {/* Col 4: Kontak & Bantuan */}
            <div className="space-y-2">
              <div className="font-bold text-white text-xs uppercase tracking-wider">Kontak & Bantuan</div>
              <ul className="space-y-2 text-xs text-slate-400">
                <li className="flex items-center space-x-2 text-emerald-400">
                  <Phone className="w-3.5 h-3.5 shrink-0" />
                  <a href={whatsappLink} target="_blank" rel="noopener noreferrer" className="hover:underline">
                    {whatsappNumber}
                  </a>
                </li>
                <li className="flex items-center space-x-2">
                  <Headphones className="w-3.5 h-3.5 shrink-0 text-indigo-400" />
                  <a href="mailto:support@ciptaone.site" className="hover:text-white">
                    support@ciptaone.site
                  </a>
                </li>
                <li className="text-[11px] text-slate-500 pt-1">
                  Layanan Bantuan: Senin - Minggu 08:00 - 23:00 WIB
                </li>
              </ul>
            </div>
          </div>

          <div className="pt-6 border-t border-slate-900 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-slate-500">
            <div>
              Dibuat dengan dedikasi untuk ekosistem AI dan inovasi digital Indonesia.
            </div>
            <div className="flex items-center space-x-4">
              <span className="flex items-center space-x-1">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                <span>All Systems Operational</span>
              </span>
            </div>
          </div>

        </div>
      </footer>

      {/* Floating WhatsApp Quick Help Pill (Bottom Right) */}
      <a
        href={whatsappLink}
        target="_blank"
        rel="noopener noreferrer"
        className="fixed bottom-5 right-5 z-40 flex items-center space-x-2.5 px-4 py-3 rounded-full bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-2xl hover:scale-105 transition-all group"
        title="Hubungi Customer Support WhatsApp"
      >
        <div className="relative">
          <MessageCircle className="w-5 h-5" />
          <span className="absolute -top-1 -right-1 flex h-2.5 w-2.5">
            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
            <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-300"></span>
          </span>
        </div>
        <span className="hidden xs:inline">Butuh Bantuan? Chat WhatsApp</span>
      </a>

    </div>
  );
};

export default LandingPage;
