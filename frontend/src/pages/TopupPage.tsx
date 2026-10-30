import React, { useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { apiFetch, ApiError } from "../api/client";
import {
  Coins,
  CreditCard,
  QrCode,
  CheckCircle2,
  Clock,
  AlertCircle,
  Copy,
  Check,
  ShieldCheck,
  ExternalLink,
  Zap,
  XCircle,
} from "lucide-react";

interface TopupResponse {
  topup_id: string;
  order_id: string;
  amount_minor: number;
  currency: string;
  credits_to_grant: number;
  payment_method: string;
  status: string;
  expires_at: string;
  checkout_url?: string;
  qr_url?: string;
  qr_string?: string;
  va_number?: string;
  instructions: {
    method?: string;
    metode?: string;
    title?: string;
    instruction?: string;
    keterangan?: string;
    checkout_url?: string;
    qr_url?: string;
    va_number?: string;
    order_id?: string;
    amount?: number;
    nominal?: number;
  };
}

interface TopupItem {
  id: string;
  order_id: string;
  amount_minor: number;
  credits_to_grant: number;
  payment_method: string;
  status: string;
  expires_at: string;
  created_at: string;
}

export const TopupPage: React.FC = () => {
  const { wallet, refreshWallet } = useAuth();
  const [selectedAmount, setSelectedAmount] = useState<number>(50000);
  const [customAmount, setCustomAmount] = useState<string>("");
  const [paymentMethod, setPaymentMethod] = useState<string>("duitku");
  const [activeInvoice, setActiveInvoice] = useState<TopupResponse | null>(null);
  const [history, setHistory] = useState<TopupItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [copied, setCopied] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  const fetchHistory = async (autoSelect = true) => {
    try {
      const list = await apiFetch<TopupItem[]>("/api/v1/topups");
      setHistory(list);
      // Auto-select pending topup on initial load if no invoice is currently displayed
      if (autoSelect) {
        const pending = list.find((t) => t.status === "pending");
        if (pending) {
          setActiveInvoice((current) => {
            if (current) return current;
            apiFetch<any>(`/api/v1/topups/${pending.order_id}`)
              .then((fullDetails) => {
                setActiveInvoice((curr) => {
                  if (curr) return curr;
                  return {
                    topup_id: fullDetails.id,
                    order_id: fullDetails.order_id,
                    amount_minor: fullDetails.amount_minor,
                    currency: fullDetails.currency,
                    credits_to_grant: fullDetails.credits_to_grant,
                    payment_method: fullDetails.payment_method,
                    status: fullDetails.status,
                    expires_at: fullDetails.expires_at,
                    checkout_url: fullDetails.checkout_url,
                    qr_url: fullDetails.qr_url,
                    qr_string: fullDetails.qr_string,
                    va_number: fullDetails.va_number,
                    instructions: fullDetails.instructions || {},
                  };
                });
              })
              .catch(console.error);
            return null;
          });
        }
      }
    } catch (e) {
      console.error(e);
    }
  };

  useEffect(() => {
    // Check if returning from Duitku or payment gateway redirect (merchantOrderId in query parameters)
    const searchParams = new URLSearchParams(window.location.search);
    const returnOrderId = searchParams.get("merchantOrderId") || searchParams.get("order_id");
    const resultCode = searchParams.get("resultCode");

    if (returnOrderId) {
      apiFetch<any>(`/api/v1/topups/${returnOrderId}`)
        .then(async (details) => {
          setActiveInvoice({
            topup_id: details.id,
            order_id: details.order_id,
            amount_minor: details.amount_minor,
            currency: details.currency,
            credits_to_grant: details.credits_to_grant,
            payment_method: details.payment_method,
            status: details.status,
            expires_at: details.expires_at,
            checkout_url: details.checkout_url,
            qr_url: details.qr_url,
            qr_string: details.qr_string,
            va_number: details.va_number,
            instructions: details.instructions || {},
          });
          if (details.status === "paid" || resultCode === "00") {
            setSuccessMsg("🎉 Pembayaran Anda telah BERHASIL diverifikasi! Saldo telah bertambah.");
            await refreshWallet();
          }
        })
        .catch(console.error)
        .finally(() => {
          fetchHistory(false);
        });
    } else {
      fetchHistory(true);
    }
  }, []);

  const handleCreateTopup = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);
    setSuccessMsg(null);

    const amount = customAmount ? parseInt(customAmount, 10) : selectedAmount;
    if (isNaN(amount) || amount < 10000) {
      setErrorMsg("Nominal minimum top-up adalah Rp10.000.");
      return;
    }

    setLoading(true);
    try {
      const res = await apiFetch<TopupResponse>("/api/v1/topups", {
        method: "POST",
        body: JSON.stringify({
          amount_minor: amount,
          payment_method: paymentMethod,
        }),
      });
      setActiveInvoice(res);
      setSuccessMsg("Tagihan berhasil dibuat! Silakan selesaikan pembayaran otomatis di bawah.");
      await fetchHistory(false);
    } catch (err: any) {
      setErrorMsg(err.message || "Gagal membuat tagihan top-up.");
    } finally {
      setLoading(false);
    }
  };

  const copyText = (txt: string) => {
    navigator.clipboard.writeText(txt);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const handleRefreshStatus = async () => {
    if (!activeInvoice) return;
    try {
      const fullDetails = await apiFetch<any>(`/api/v1/topups/${activeInvoice.order_id}`);
      setActiveInvoice({
        topup_id: fullDetails.id,
        order_id: fullDetails.order_id,
        amount_minor: fullDetails.amount_minor,
        currency: fullDetails.currency,
        credits_to_grant: fullDetails.credits_to_grant,
        payment_method: fullDetails.payment_method,
        status: fullDetails.status,
        expires_at: fullDetails.expires_at,
        checkout_url: fullDetails.checkout_url,
        qr_url: fullDetails.qr_url,
        qr_string: fullDetails.qr_string,
        va_number: fullDetails.va_number,
        instructions: fullDetails.instructions || {},
      });
      if (fullDetails.status === "paid") {
        setSuccessMsg("🎉 Pembayaran Anda telah BERHASIL diverifikasi! Saldo telah bertambah.");
        await refreshWallet();
      }
      await fetchHistory();
    } catch (err: any) {
      setErrorMsg(err.message || "Gagal memperbarui status.");
    }
  };

  const handleCancelTopup = async (orderId?: string) => {
    const targetOrderId = orderId || activeInvoice?.order_id;
    if (!targetOrderId) return;

    if (!window.confirm(`Apakah Anda yakin ingin membatalkan tagihan pembayaran ${targetOrderId}?`)) {
      return;
    }

    setCancelling(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    try {
      await apiFetch(`/api/v1/topups/${targetOrderId}/cancel`, {
        method: "POST",
      });
      if (activeInvoice && activeInvoice.order_id === targetOrderId) {
        setActiveInvoice(null);
      }
      setSuccessMsg(`Tagihan ${targetOrderId} berhasil dibatalkan.`);
      await fetchHistory(false);
    } catch (err: any) {
      setErrorMsg(err.message || "Gagal membatalkan tagihan pembayaran.");
    } finally {
      setCancelling(false);
    }
  };

  const packages = [10000, 25000, 50000, 100000, 250000];

  return (
    <div className="space-y-8">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Top-up Saldo Kredit</h1>
        <p className="text-sm text-slate-500 mt-1">
          Beli kredit untuk pemanggilan model AI. 1 Kredit = Rp1. Pembayaran diverifikasi otomatis 24/7.
        </p>
      </div>

      {errorMsg && (
        <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-start space-x-2">
          <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5 text-rose-500" />
          <span>{errorMsg}</span>
        </div>
      )}

      {successMsg && (
        <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-start space-x-2">
          <CheckCircle2 className="w-5 h-5 flex-shrink-0 mt-0.5 text-emerald-600" />
          <span>{successMsg}</span>
        </div>
      )}

      {/* Main Grid: Form / Invoice vs History */}
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
        {/* Left: Active Invoice or Create Top-up Form */}
        <div className="lg:col-span-7 space-y-6">
          {activeInvoice ? (
            /* Active Invoice Card */
            <div className="bg-white rounded-2xl border border-indigo-200 shadow-sm p-6 space-y-6">
              <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                <div>
                  <span className="text-xs font-bold text-indigo-600 uppercase tracking-wider">
                    Tagihan Pembayaran Otomatis
                  </span>
                  <div className="text-lg font-bold text-slate-900">{activeInvoice.order_id}</div>
                </div>

                <span
                  className={`px-3 py-1 rounded-full text-xs font-semibold capitalize ${
                    activeInvoice.status === "paid"
                      ? "bg-emerald-100 text-emerald-800"
                      : "bg-amber-100 text-amber-800"
                  }`}
                >
                  {activeInvoice.status === "paid" ? "✅ Lunas (Terkreditasi)" : "⏳ Menunggu Pembayaran"}
                </span>
              </div>

              {/* Amount Box */}
              <div className="bg-slate-50 p-4 rounded-xl border border-slate-200 flex items-center justify-between">
                <div>
                  <div className="text-xs text-slate-500">Total Nominal Pembayaran:</div>
                  <div className="text-2xl font-black text-slate-900">
                    Rp{activeInvoice.amount_minor.toLocaleString("id-ID")}
                  </div>
                </div>
                <div className="text-right">
                  <div className="text-xs text-slate-500">Kredit didapat:</div>
                  <div className="text-lg font-bold text-indigo-600">
                    +{activeInvoice.credits_to_grant.toLocaleString("id-ID")} Kredit
                  </div>
                </div>
              </div>

              {/* Virtual Account (VA) Number Card */}
              {activeInvoice.va_number && activeInvoice.status !== "paid" && (
                <div className="p-5 rounded-2xl bg-gradient-to-br from-indigo-950 via-slate-900 to-indigo-900 text-white space-y-4 shadow-lg border border-indigo-700/40">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold uppercase tracking-wider text-indigo-300 flex items-center space-x-2">
                      <CreditCard className="w-4 h-4 text-emerald-400" />
                      <span>Nomor Virtual Account (VA) Resmi</span>
                    </span>
                    <span className="text-[11px] font-bold px-2.5 py-0.5 bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 rounded-full">
                      Verifikasi Otomatis 24/7
                    </span>
                  </div>

                  <div className="bg-white/10 backdrop-blur-md p-4 rounded-xl border border-white/15 flex items-center justify-between gap-3">
                    <div className="min-w-0">
                      <div className="text-[11px] text-slate-300 font-medium">Nomor Rekening VA:</div>
                      <div className="text-2xl sm:text-3xl font-mono font-black tracking-wider text-emerald-300 select-all truncate">
                        {activeInvoice.va_number}
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => copyText(activeInvoice.va_number!)}
                      className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold flex items-center space-x-1.5 transition shadow-sm shrink-0"
                    >
                      {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                      <span>{copied ? "Disalin!" : "Salin VA"}</span>
                    </button>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div className="bg-white/5 p-3 rounded-xl border border-white/10">
                      <div className="text-slate-400 text-[11px]">Kode Referensi / Order ID:</div>
                      <div className="font-semibold text-slate-200 flex items-center justify-between mt-1">
                        <span className="truncate">{activeInvoice.order_id}</span>
                        <button
                          type="button"
                          onClick={() => copyText(activeInvoice.order_id)}
                          className="text-indigo-400 hover:text-indigo-300 ml-2"
                          title="Salin Order ID"
                        >
                          <Copy className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>

                    <div className="bg-white/5 p-3 rounded-xl border border-white/10">
                      <div className="text-slate-400 text-[11px]">Nominal Bayar Tepat:</div>
                      <div className="font-bold text-emerald-400 flex items-center justify-between mt-1">
                        <span>Rp{activeInvoice.amount_minor.toLocaleString("id-ID")}</span>
                        <button
                          type="button"
                          onClick={() => copyText(activeInvoice.amount_minor.toString())}
                          className="text-emerald-400 hover:text-emerald-300 ml-2"
                          title="Salin Nominal"
                        >
                          <Copy className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </div>
                  </div>

                  <div className="p-3 bg-indigo-500/10 rounded-xl border border-indigo-400/20 text-[11px] text-slate-300 leading-relaxed">
                    💡 <b>Petunjuk Pembayaran:</b> Buka m-Banking atau ATM &rarr; Pilih menu <b>Transfer ke Virtual Account</b> &rarr; Masukkan nomor VA di atas &rarr; Periksa nama akun &rarr; Konfirmasi pembayaran. Saldo akun akan langsung terisi seketika tanpa perlu kirim bukti transfer.
                  </div>
                </div>
              )}

              {/* Online Payment Link (Duitku) */}
              {activeInvoice.checkout_url &&
                activeInvoice.checkout_url !== activeInvoice.qr_url &&
                activeInvoice.status !== "paid" && (
                  <div className="p-4 rounded-xl bg-emerald-50 border border-emerald-200 space-y-3">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold uppercase tracking-wider text-emerald-800">
                        Pembayaran Otomatis Duitku
                      </span>
                      <span className="text-[11px] font-bold px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full">
                        Verifikasi Seketika 24/7
                      </span>
                    </div>
                    <p className="text-xs text-emerald-700 leading-relaxed">
                      Klik tombol di bawah untuk membuka halaman pembayaran resmi Duitku. Anda dapat memilih <b>QRIS</b>, <b>Virtual Account</b> (BCA, Mandiri, BRI, BNI, Permata, CIMB), atau <b>E-Wallet</b>. Saldo kredit akan langsung masuk seketika setelah pembayaran.
                    </p>
                    <a
                      href={activeInvoice.checkout_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="flex items-center justify-center w-full py-3 px-4 rounded-xl text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-md shadow-emerald-600/20 transition space-x-2"
                    >
                      <span>💳 Buka Halaman Pembayaran Duitku</span>
                      <ExternalLink className="w-4 h-4 ml-1" />
                    </a>
                  </div>
              )}

              {/* QRIS Image if available */}
              {activeInvoice.qr_url && activeInvoice.status !== "paid" && (
                <div className="p-4 rounded-xl border border-slate-200 bg-white text-center space-y-3">
                  <div className="text-xs font-bold uppercase tracking-wider text-slate-700">
                    Pindai QRIS Dinamis Otomatis
                  </div>
                  <div className="flex justify-center py-2">
                    <img
                      src={activeInvoice.qr_url}
                      alt="QRIS Dinamis"
                      className="w-56 h-56 rounded-lg border border-slate-200 shadow-sm object-contain"
                    />
                  </div>
                  <p className="text-xs text-slate-500">
                    Buka GoPay, OVO, ShopeePay, DANA, BCA Mobile, atau m-Banking Anda lalu pindai kode QR di atas.
                  </p>
                </div>
              )}

              {/* Fallback if URL or QR is not yet rendered */}
              {!activeInvoice.checkout_url && !activeInvoice.qr_url && activeInvoice.status !== "paid" && (
                <div className="p-4 rounded-xl bg-amber-50 border border-amber-200 space-y-3 text-amber-900">
                  <div className="flex items-center space-x-2 font-bold text-sm">
                    <AlertCircle className="w-5 h-5 text-amber-600 flex-shrink-0" />
                    <span>Menghubungkan ke Saluran Pembayaran...</span>
                  </div>
                  <p className="text-xs text-amber-800 leading-relaxed">
                    Tautan pembayaran atau kode QRIS sedang diproses oleh gateway. Silakan klik tombol di bawah untuk memuat tautan atau buat tagihan baru.
                  </p>
                  <div className="flex flex-wrap items-center gap-2 pt-1">
                    <button
                      type="button"
                      onClick={handleRefreshStatus}
                      className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow-sm transition"
                    >
                      🔄 Muat Ulang Tautan Pembayaran
                    </button>
                    <button
                      type="button"
                      disabled={cancelling}
                      onClick={() => handleCancelTopup()}
                      className="px-4 py-2 bg-rose-50 border border-rose-200 text-rose-700 hover:bg-rose-100 rounded-xl text-xs font-semibold shadow-sm transition disabled:opacity-50"
                    >
                      {cancelling ? "Membatalkan..." : "✕ Batalkan Tagihan"}
                    </button>
                    <button
                      type="button"
                      onClick={() => setActiveInvoice(null)}
                      className="px-4 py-2 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-xl text-xs font-semibold shadow-sm transition"
                    >
                      ← Buat Tagihan Baru
                    </button>
                  </div>
                </div>
              )}

              {/* Instant Automation Notice */}
              <div className="p-3.5 rounded-xl bg-indigo-50/60 border border-indigo-100 flex items-start space-x-3 text-xs text-indigo-900">
                <ShieldCheck className="w-5 h-5 text-indigo-600 flex-shrink-0 mt-0.5" />
                <div className="leading-relaxed">
                  <span className="font-bold block">Tanpa Verifikasi Manual / Kirim Bukti Transfer:</span>
                  Semua transaksi diproses otomatis oleh payment gateway. Saldo akun dan API Key Anda akan langsung bertambah seketika dalam hitungan detik setelah pembayaran selesai.
                </div>
              </div>

              <div className="flex flex-col sm:flex-row justify-between items-center gap-3 pt-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setActiveInvoice(null)}
                  className="text-xs font-semibold text-slate-500 hover:text-slate-800"
                >
                  ← Buat Tagihan Baru
                </button>
                <div className="flex items-center space-x-2">
                  {activeInvoice.status === "pending" && (
                    <button
                      type="button"
                      disabled={cancelling}
                      onClick={() => handleCancelTopup()}
                      className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-rose-600 bg-rose-50 hover:bg-rose-100 transition disabled:opacity-50"
                      title="Batalkan tagihan pembayaran ini"
                    >
                      <XCircle className="w-3.5 h-3.5" />
                      <span>{cancelling ? "Membatalkan..." : "Batalkan Pembayaran"}</span>
                    </button>
                  )}
                  <button
                    type="button"
                    onClick={handleRefreshStatus}
                    className="inline-flex items-center space-x-1.5 px-3 py-1.5 rounded-lg text-xs font-bold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 transition"
                  >
                    <Zap className="w-3.5 h-3.5" />
                    <span>Periksa Status Pembayaran</span>
                  </button>
                </div>
              </div>
            </div>
          ) : (
            /* Create Top-up Form */
            <form onSubmit={handleCreateTopup} className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-3">
                  Pilih Paket Nominal
                </label>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                  {packages.map((pkg) => (
                    <button
                      key={pkg}
                      type="button"
                      onClick={() => {
                        setSelectedAmount(pkg);
                        setCustomAmount("");
                      }}
                      className={`p-3.5 rounded-xl border text-left transition ${
                        selectedAmount === pkg && !customAmount
                          ? "border-indigo-600 bg-indigo-50/50 ring-2 ring-indigo-500/20"
                          : "border-slate-200 hover:border-slate-300 bg-white"
                      }`}
                    >
                      <div className="font-bold text-slate-900 text-sm">
                        Rp{pkg.toLocaleString("id-ID")}
                      </div>
                      <div className="text-xs text-indigo-600 font-medium mt-0.5">
                        +{pkg.toLocaleString("id-ID")} Kredit
                      </div>
                    </button>
                  ))}
                </div>

                <div className="mt-3">
                  <input
                    type="number"
                    min={10000}
                    step={1000}
                    value={customAmount}
                    onChange={(e) => setCustomAmount(e.target.value)}
                    placeholder="Atau ketik nominal custom (min. Rp10.000)"
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              {/* Payment Method Selector */}
              <div>
                <label className="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-3">
                  Metode Pembayaran (Otomatis & Terverifikasi Gateway)
                </label>

                <div className="space-y-3">
                  {/* Duitku Full Service (QRIS, VA Semua Bank, E-Wallet) */}
                  <label
                    className={`flex items-start p-3.5 rounded-xl border cursor-pointer transition ${
                      paymentMethod === "duitku"
                        ? "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                        : "border-slate-200 hover:border-slate-300"
                    }`}
                  >
                    <input
                      type="radio"
                      name="payment_method"
                      value="duitku"
                      checked={paymentMethod === "duitku"}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="mt-1 text-emerald-600 focus:ring-emerald-500"
                    />
                    <div className="ml-3">
                      <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                        <span>💳 Duitku Gateway (Halaman Pembayaran Lengkap)</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full">
                          Rekomendasi
                        </span>
                      </div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        Bebas pilih QRIS, Semua Bank Virtual Account, atau E-Wallet di halaman Duitku resmi.
                      </div>
                    </div>
                  </label>

                  {/* BCA Virtual Account */}
                  <label
                    className={`flex items-start p-3.5 rounded-xl border cursor-pointer transition ${
                      paymentMethod === "bca_va"
                        ? "border-indigo-600 bg-indigo-50/40 ring-1 ring-indigo-600"
                        : "border-slate-200 hover:border-slate-300"
                    }`}
                  >
                    <input
                      type="radio"
                      name="payment_method"
                      value="bca_va"
                      checked={paymentMethod === "bca_va"}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="mt-1 text-indigo-600 focus:ring-indigo-500"
                    />
                    <div className="ml-3">
                      <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                        <span>🏦 BCA Virtual Account</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 bg-indigo-100 text-indigo-800 rounded-full">
                          Instan VA
                        </span>
                      </div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        Dapatkan nomor VA BCA langsung untuk transfer via BCA Mobile, myBCA, KlikBCA, atau ATM BCA.
                      </div>
                    </div>
                  </label>

                  {/* Mandiri Virtual Account */}
                  <label
                    className={`flex items-start p-3.5 rounded-xl border cursor-pointer transition ${
                      paymentMethod === "mandiri_va"
                        ? "border-indigo-600 bg-indigo-50/40 ring-1 ring-indigo-600"
                        : "border-slate-200 hover:border-slate-300"
                    }`}
                  >
                    <input
                      type="radio"
                      name="payment_method"
                      value="mandiri_va"
                      checked={paymentMethod === "mandiri_va"}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="mt-1 text-indigo-600 focus:ring-indigo-500"
                    />
                    <div className="ml-3">
                      <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                        <span>🏦 Mandiri Virtual Account</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 bg-indigo-100 text-indigo-800 rounded-full">
                          Instan VA
                        </span>
                      </div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        Dapatkan nomor VA Mandiri langsung untuk bayar di Livin' by Mandiri atau ATM Mandiri.
                      </div>
                    </div>
                  </label>

                  {/* BRI Virtual Account */}
                  <label
                    className={`flex items-start p-3.5 rounded-xl border cursor-pointer transition ${
                      paymentMethod === "bri_va"
                        ? "border-indigo-600 bg-indigo-50/40 ring-1 ring-indigo-600"
                        : "border-slate-200 hover:border-slate-300"
                    }`}
                  >
                    <input
                      type="radio"
                      name="payment_method"
                      value="bri_va"
                      checked={paymentMethod === "bri_va"}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="mt-1 text-indigo-600 focus:ring-indigo-500"
                    />
                    <div className="ml-3">
                      <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                        <span>🏦 BRI Virtual Account (BRIVA)</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 bg-indigo-100 text-indigo-800 rounded-full">
                          Instan VA
                        </span>
                      </div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        Dapatkan kode BRIVA langsung untuk bayar via BRImo, Internet Banking, atau ATM BRI.
                      </div>
                    </div>
                  </label>

                  {/* BNI Virtual Account */}
                  <label
                    className={`flex items-start p-3.5 rounded-xl border cursor-pointer transition ${
                      paymentMethod === "bni_va"
                        ? "border-indigo-600 bg-indigo-50/40 ring-1 ring-indigo-600"
                        : "border-slate-200 hover:border-slate-300"
                    }`}
                  >
                    <input
                      type="radio"
                      name="payment_method"
                      value="bni_va"
                      checked={paymentMethod === "bni_va"}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="mt-1 text-indigo-600 focus:ring-indigo-500"
                    />
                    <div className="ml-3">
                      <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                        <span>🏦 BNI Virtual Account</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 bg-indigo-100 text-indigo-800 rounded-full">
                          Instan VA
                        </span>
                      </div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        Dapatkan nomor VA BNI langsung untuk bayar via BNI Mobile Banking atau ATM BNI.
                      </div>
                    </div>
                  </label>

                  {/* QRIS Otomatis */}
                  <label
                    className={`flex items-start p-3.5 rounded-xl border cursor-pointer transition ${
                      paymentMethod === "duitku_qris"
                        ? "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                        : "border-slate-200 hover:border-slate-300"
                    }`}
                  >
                    <input
                      type="radio"
                      name="payment_method"
                      value="duitku_qris"
                      checked={paymentMethod === "duitku_qris"}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                      className="mt-1 text-emerald-600 focus:ring-emerald-500"
                    />
                    <div className="ml-3">
                      <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                        <span>📱 QRIS Dinamis Otomatis</span>
                        <span className="text-[10px] font-bold px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-full">
                          Instan QR
                        </span>
                      </div>
                      <div className="text-xs text-slate-500 mt-0.5">
                        Pindai kode QRIS menggunakan GoPay, OVO, ShopeePay, DANA, BCA Mobile, atau aplikasi QRIS apa saja.
                      </div>
                    </div>
                  </label>
                </div>
              </div>

              <button
                type="submit"
                disabled={loading}
                className="w-full py-3 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-sm disabled:opacity-50"
              >
                {loading ? "Menyiapkan Tagihan..." : "Lanjutkan ke Pembayaran Otomatis"}
              </button>
            </form>
          )}
        </div>

        {/* Right: History & Info */}
        <div className="lg:col-span-5 space-y-6">
          <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <h3 className="font-bold text-slate-900 text-sm uppercase tracking-wider mb-4 flex items-center">
              <Clock className="w-4 h-4 mr-2 text-slate-400" />
              Riwayat Transaksi Top-up
            </h3>

            {history.length === 0 ? (
              <div className="text-center py-8 text-xs text-slate-400">
                Belum ada riwayat transaksi.
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {history.map((item) => (
                  <div key={item.id} className="py-3 flex items-center justify-between text-xs">
                    <div>
                      <div className="font-semibold text-slate-800 flex items-center space-x-1.5">
                        <span>{item.order_id}</span>
                        {item.status === "pending" && (
                          <button
                            type="button"
                            onClick={() => {
                              apiFetch<any>(`/api/v1/topups/${item.order_id}`).then((fullDetails) => {
                                setActiveInvoice({
                                  topup_id: fullDetails.id,
                                  order_id: fullDetails.order_id,
                                  amount_minor: fullDetails.amount_minor,
                                  currency: fullDetails.currency,
                                  credits_to_grant: fullDetails.credits_to_grant,
                                  payment_method: fullDetails.payment_method,
                                  status: fullDetails.status,
                                  expires_at: fullDetails.expires_at,
                                  checkout_url: fullDetails.checkout_url,
                                  qr_url: fullDetails.qr_url,
                                  qr_string: fullDetails.qr_string,
                                  instructions: fullDetails.instructions || {},
                                });
                              }).catch(console.error);
                            }}
                            className="text-[10px] text-indigo-600 hover:underline font-normal"
                          >
                            (Lihat Tagihan)
                          </button>
                        )}
                      </div>
                      <div className="text-slate-400 text-[11px]">
                        Rp{item.amount_minor.toLocaleString("id-ID")} •{" "}
                        {item.payment_method} •{" "}
                        {new Date(item.created_at).toLocaleDateString("id-ID")}
                      </div>
                    </div>

                    <div className="text-right flex items-center space-x-2">
                      <span
                        className={`inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${
                          item.status === "paid"
                            ? "bg-emerald-100 text-emerald-800"
                            : item.status === "rejected"
                            ? "bg-rose-100 text-rose-800"
                            : "bg-blue-100 text-blue-800"
                        }`}
                      >
                        {item.status === "paid"
                          ? "Lunas"
                          : item.status === "rejected"
                          ? "Dibatalkan"
                          : item.status === "pending"
                          ? "Menunggu"
                          : item.status}
                      </span>
                      {item.status === "pending" && (
                        <button
                          type="button"
                          disabled={cancelling}
                          onClick={() => handleCancelTopup(item.order_id)}
                          className="text-[10px] text-rose-600 hover:text-rose-800 font-medium px-1.5 py-0.5 border border-rose-200 rounded hover:bg-rose-50 transition disabled:opacity-50"
                          title="Batalkan tagihan ini"
                        >
                          Batalkan
                        </button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};
