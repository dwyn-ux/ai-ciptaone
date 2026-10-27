import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { apiFetch } from "../api/client";
import {
  Coins,
  Cpu,
  KeyRound,
  History,
  ArrowUpRight,
  Sparkles,
  TrendingUp,
  Clock,
} from "lucide-react";

interface UsageItem {
  id: string;
  request_id: string;
  model_id: string;
  input_tokens: number;
  output_tokens: number;
  billed_credits: number;
  created_at: string;
}

interface TransactionItem {
  id: string;
  event_type: string;
  amount_credits: number;
  balance_after: number;
  created_at: string;
}

export const DashboardPage: React.FC = () => {
  const { user, wallet } = useAuth();
  const [recentUsage, setRecentUsage] = useState<UsageItem[]>([]);
  const [recentTx, setRecentTx] = useState<TransactionItem[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const loadDashboardData = async () => {
      try {
        const usageData = await apiFetch<{ items: UsageItem[] }>("/api/v1/usage?limit=5");
        setRecentUsage(usageData.items || []);

        const txData = await apiFetch<{ items: TransactionItem[] }>("/api/v1/transactions?limit=5");
        setRecentTx(txData.items || []);
      } catch (e) {
        console.error("Gagal memuat data dashboard:", e);
      } finally {
        setLoading(false);
      }
    };

    loadDashboardData();
  }, []);

  return (
    <div className="space-y-8">
      {/* Welcome Banner */}
      <div className="bg-gradient-to-r from-indigo-900 via-indigo-800 to-slate-900 rounded-3xl p-8 text-white relative overflow-hidden shadow-sm">
        <div className="relative z-10 max-w-2xl">
          <div className="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-500/30 text-indigo-200 text-xs font-semibold mb-4 backdrop-blur-sm border border-indigo-400/20">
            <Sparkles className="w-3.5 h-3.5" />
            <span>AI Router Universal Gateway</span>
          </div>
          <h1 className="text-3xl font-extrabold tracking-tight">
            Selamat datang, {user?.email.split("@")[0]}!
          </h1>
          <p className="mt-2 text-indigo-100 text-sm leading-relaxed">
            Gunakan playground untuk menguji prompt di berbagai model AI, atau integrasikan endpoint kompatibel OpenAI menggunakan API key Anda.
          </p>

          <div className="mt-6 flex flex-wrap gap-3">
            <Link
              to="/playground"
              className="inline-flex items-center px-4 py-2 rounded-xl bg-white text-indigo-900 font-semibold text-sm hover:bg-indigo-50 transition shadow-sm"
            >
              <Cpu className="w-4 h-4 mr-2 text-indigo-600" />
              Buka Playground
            </Link>
            <Link
              to="/topup"
              className="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-600/80 hover:bg-indigo-600 text-white font-semibold text-sm transition border border-indigo-400/30 backdrop-blur-sm"
            >
              <Coins className="w-4 h-4 mr-2" />
              Isi Ulang Saldo
            </Link>
          </div>
        </div>

        {/* Decorative background glow */}
        <div className="absolute right-0 bottom-0 translate-x-12 translate-y-12 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl pointer-events-none" />
      </div>

      {/* Metric Cards */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {/* Wallet Balance Card */}
        <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between text-slate-500 mb-2">
              <span className="text-xs font-semibold uppercase tracking-wider">Saldo Kredit Tersedia</span>
              <div className="p-2 bg-emerald-50 text-emerald-600 rounded-xl">
                <Coins className="w-5 h-5" />
              </div>
            </div>
            <div className="text-3xl font-bold text-slate-900 tracking-tight">
              {wallet ? wallet.balance_credits.toLocaleString("id-ID") : "0"}{" "}
              <span className="text-xs font-semibold text-emerald-600 uppercase">Kredit</span>
            </div>
            <p className="text-xs text-slate-500 mt-1">
              Setara Rp{wallet ? wallet.balance_credits.toLocaleString("id-ID") : "0"} (1 Kredit = Rp1)
            </p>
          </div>

          <div className="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
            <Link
              to="/topup"
              className="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center"
            >
              <span>Tambah Saldo</span>
              <ArrowUpRight className="w-3.5 h-3.5 ml-1" />
            </Link>
            <span className="text-[11px] text-slate-400">Diperbarui atomik</span>
          </div>
        </div>

        {/* API Key Card */}
        <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between text-slate-500 mb-2">
              <span className="text-xs font-semibold uppercase tracking-wider">Integrasi API</span>
              <div className="p-2 bg-indigo-50 text-indigo-600 rounded-xl">
                <KeyRound className="w-5 h-5" />
              </div>
            </div>
            <div className="text-lg font-bold text-slate-900 tracking-tight">
              OpenAI Compatible
            </div>
            <p className="text-xs text-slate-500 mt-1">
              Gunakan URL base <code className="bg-slate-100 px-1 py-0.5 rounded text-indigo-600">/v1</code> dengan Authorization header API key Anda.
            </p>
          </div>

          <div className="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
            <Link
              to="/api-keys"
              className="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center"
            >
              <span>Kelola API Keys</span>
              <ArrowUpRight className="w-3.5 h-3.5 ml-1" />
            </Link>
            <span className="text-[11px] text-slate-400">Hash SHA-256</span>
          </div>
        </div>

        {/* Active Models Card */}
        <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between text-slate-500 mb-2">
              <span className="text-xs font-semibold uppercase tracking-wider">Model Terverifikasi</span>
              <div className="p-2 bg-amber-50 text-amber-600 rounded-xl">
                <Cpu className="w-5 h-5" />
              </div>
            </div>
            <div className="text-lg font-bold text-slate-900 tracking-tight">
              Mock Echo & Komersial
            </div>
            <p className="text-xs text-slate-500 mt-1">
              Tiap model dipatok tarif input/output transparan per satu juta token.
            </p>
          </div>

          <div className="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between">
            <Link
              to="/playground"
              className="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center"
            >
              <span>Coba di Playground</span>
              <ArrowUpRight className="w-3.5 h-3.5 ml-1" />
            </Link>
            <span className="text-[11px] text-emerald-600 font-medium">Aktif</span>
          </div>
        </div>
      </div>

      {/* Recent Activity Sections */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {/* Recent AI Usage */}
        <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-bold text-slate-900 text-sm uppercase tracking-wider flex items-center">
              <Clock className="w-4 h-4 mr-2 text-slate-400" />
              Pemakaian AI Terakhir
            </h3>
            <Link to="/history" className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
              Lihat Semua
            </Link>
          </div>

          {recentUsage.length === 0 ? (
            <div className="py-8 text-center text-slate-400 text-xs">
              Belum ada riwayat pemakaian AI. Mulai di playground!
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {recentUsage.map((u) => (
                <div key={u.id} className="py-3 flex items-center justify-between text-xs">
                  <div>
                    <div className="font-semibold text-slate-800">{u.request_id}</div>
                    <div className="text-slate-400 text-[11px]">
                      {u.input_tokens} input + {u.output_tokens} output tokens
                    </div>
                  </div>
                  <div className="text-right">
                    <span className="font-semibold text-rose-600">
                      -{u.billed_credits} Kredit
                    </span>
                    <div className="text-[11px] text-slate-400">
                      {new Date(u.created_at).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Recent Ledger Transactions */}
        <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-bold text-slate-900 text-sm uppercase tracking-wider flex items-center">
              <History className="w-4 h-4 mr-2 text-slate-400" />
              Mutasi Saldo Terakhir
            </h3>
            <Link to="/history" className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">
              Lihat Semua
            </Link>
          </div>

          {recentTx.length === 0 ? (
            <div className="py-8 text-center text-slate-400 text-xs">
              Belum ada transaksi saldo tercatat.
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {recentTx.map((t) => (
                <div key={t.id} className="py-3 flex items-center justify-between text-xs">
                  <div>
                    <div className="font-semibold text-slate-800 capitalize">
                      {t.event_type.replace("_", " ")}
                    </div>
                    <div className="text-slate-400 text-[11px]">
                      Saldo akhir: {t.balance_after.toLocaleString("id-ID")} Kredit
                    </div>
                  </div>
                  <div className="text-right">
                    <span
                      className={`font-semibold ${
                        t.amount_credits > 0 ? "text-emerald-600" : "text-rose-600"
                      }`}
                    >
                      {t.amount_credits > 0 ? `+${t.amount_credits.toLocaleString("id-ID")}` : t.amount_credits.toLocaleString("id-ID")}{" "}
                      Kredit
                    </span>
                    <div className="text-[11px] text-slate-400">
                      {new Date(t.created_at).toLocaleDateString("id-ID", { day: "numeric", month: "short" })}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
