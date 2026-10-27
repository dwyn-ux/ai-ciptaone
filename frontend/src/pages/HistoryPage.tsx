import React, { useEffect, useState } from "react";
import { apiFetch } from "../api/client";
import { History, Cpu, FileText, ArrowDownLeft, ArrowUpRight } from "lucide-react";

interface UsageItem {
  id: string;
  request_id: string;
  model_id: string;
  input_tokens: number;
  output_tokens: number;
  billed_credits: number;
  latency_ms: number;
  status: string;
  streaming: boolean;
  created_at: string;
}

interface TransactionItem {
  id: string;
  event_type: string;
  amount_credits: number;
  balance_after: number;
  reference_type: string;
  reference_id: string;
  created_at: string;
}

export const HistoryPage: React.FC = () => {
  const [activeTab, setActiveTab] = useState<"usage" | "ledger">("usage");
  const [usageList, setUsageList] = useState<UsageItem[]>([]);
  const [txList, setTxList] = useState<TransactionItem[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true);
      try {
        const u = await apiFetch<{ items: UsageItem[] }>("/api/v1/usage?limit=50");
        setUsageList(u.items || []);

        const t = await apiFetch<{ items: TransactionItem[] }>("/api/v1/transactions?limit=50");
        setTxList(t.items || []);
      } catch (e) {
        console.error(e);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, []);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Riwayat & Log Transaksi</h1>
        <p className="text-sm text-slate-500 mt-1">
          Lacak setiap penggunaan token AI dan rekonsiliasi mutasi saldo kredit secara transparan.
        </p>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-slate-200 space-x-6 text-sm font-semibold">
        <button
          onClick={() => setActiveTab("usage")}
          className={`pb-3 flex items-center space-x-2 transition ${
            activeTab === "usage"
              ? "border-b-2 border-indigo-600 text-indigo-600"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <Cpu className="w-4 h-4" />
          <span>Pemakaian Token AI ({usageList.length})</span>
        </button>

        <button
          onClick={() => setActiveTab("ledger")}
          className={`pb-3 flex items-center space-x-2 transition ${
            activeTab === "ledger"
              ? "border-b-2 border-indigo-600 text-indigo-600"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <History className="w-4 h-4" />
          <span>Ledger Mutasi Saldo ({txList.length})</span>
        </button>
      </div>

      {/* Table Content */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-xs text-slate-400">Memuat data riwayat...</div>
        ) : activeTab === "usage" ? (
          /* Usage Events Table */
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                <tr>
                  <th className="px-6 py-3.5">Request ID</th>
                  <th className="px-6 py-3.5">Model</th>
                  <th className="px-6 py-3.5">Input Tokens</th>
                  <th className="px-6 py-3.5">Output Tokens</th>
                  <th className="px-6 py-3.5">Biaya Kredit</th>
                  <th className="px-6 py-3.5">Latency</th>
                  <th className="px-6 py-3.5">Waktu</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {usageList.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-8 text-center text-slate-400">
                      Belum ada log penggunaan token AI.
                    </td>
                  </tr>
                ) : (
                  usageList.map((item) => (
                    <tr key={item.id} className="hover:bg-slate-50/50">
                      <td className="px-6 py-3.5 font-mono text-slate-800 font-medium">
                        {item.request_id}
                      </td>
                      <td className="px-6 py-3.5 text-slate-600 font-medium">{item.model_id}</td>
                      <td className="px-6 py-3.5 text-slate-500">{item.input_tokens.toLocaleString()}</td>
                      <td className="px-6 py-3.5 text-slate-500">{item.output_tokens.toLocaleString()}</td>
                      <td className="px-6 py-3.5 font-bold text-rose-600">
                        -{item.billed_credits}
                      </td>
                      <td className="px-6 py-3.5 text-slate-500">{item.latency_ms} ms</td>
                      <td className="px-6 py-3.5 text-slate-400">
                        {new Date(item.created_at).toLocaleString("id-ID")}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        ) : (
          /* Credit Ledger Table */
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                <tr>
                  <th className="px-6 py-3.5">ID Transaksi</th>
                  <th className="px-6 py-3.5">Jenis Event</th>
                  <th className="px-6 py-3.5">Perubahan Saldo</th>
                  <th className="px-6 py-3.5">Saldo Akhir</th>
                  <th className="px-6 py-3.5">Referensi</th>
                  <th className="px-6 py-3.5">Waktu</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {txList.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-6 py-8 text-center text-slate-400">
                      Belum ada riwayat mutasi saldo.
                    </td>
                  </tr>
                ) : (
                  txList.map((tx) => (
                    <tr key={tx.id} className="hover:bg-slate-50/50">
                      <td className="px-6 py-3.5 font-mono text-slate-700">{tx.id}</td>
                      <td className="px-6 py-3.5 font-semibold text-slate-800 capitalize">
                        {tx.event_type.replace("_", " ")}
                      </td>
                      <td
                        className={`px-6 py-3.5 font-bold ${
                          tx.amount_credits > 0 ? "text-emerald-600" : "text-rose-600"
                        }`}
                      >
                        {tx.amount_credits > 0 ? `+${tx.amount_credits.toLocaleString("id-ID")}` : tx.amount_credits.toLocaleString("id-ID")} Kredit
                      </td>
                      <td className="px-6 py-3.5 font-medium text-slate-700">
                        {tx.balance_after.toLocaleString("id-ID")} Kredit
                      </td>
                      <td className="px-6 py-3.5 font-mono text-slate-500 text-[11px]">
                        {tx.reference_type}: {tx.reference_id}
                      </td>
                      <td className="px-6 py-3.5 text-slate-400">
                        {new Date(tx.created_at).toLocaleString("id-ID")}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};
