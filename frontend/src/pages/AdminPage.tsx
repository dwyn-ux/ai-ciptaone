import React, { useEffect, useState } from "react";
import { useAuth } from "../contexts/AuthContext";
import { apiFetch } from "../api/client";
import {
  ShieldCheck,
  CheckCircle2,
  XCircle,
  Eye,
  Sliders,
  Users,
  AlertCircle,
  Plus,
  Key,
  Settings,
  Edit2,
  Lock,
  Save,
  Cpu,
} from "lucide-react";

interface AdminTopup {
  id: string;
  order_id: string;
  user_email: string;
  amount_minor: number;
  credits_to_grant: number;
  payment_method: string;
  status: string;
  proof_file_key?: string;
  created_at: string;
}

interface AdminModel {
  id: string;
  public_id: string;
  display_name: string;
  provider_slug: string;
  upstream_model: string;
  enabled: boolean;
  input_price_per_million: number;
  output_price_per_million: number;
  pricing_version: number;
  max_input_tokens?: number;
  max_output_tokens?: number;
}

interface AdminUser {
  id: string;
  email: string;
  role: string;
  status: string;
  balance_credits: number;
  created_at: string;
}

interface AuditLog {
  id: string;
  admin_email: string;
  action: string;
  target_type: string;
  target_id: string;
  metadata?: any;
  created_at: string;
}

interface AdminSettings {
  openrouter: { configured: boolean; masked: string | null };
  openai: { configured: boolean; masked: string | null };
  telegram: { configured: boolean; admin_ids: string };
}

export const AdminPage: React.FC = () => {
  const { user } = useAuth();
  const [activeTab, setActiveTab] = useState<"topups" | "models" | "settings" | "users" | "audit">("topups");
  const [topups, setTopups] = useState<AdminTopup[]>([]);
  const [models, setModels] = useState<AdminModel[]>([]);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [auditLogs, setAuditLogs] = useState<AuditLog[]>([]);
  const [settings, setSettings] = useState<AdminSettings | null>(null);
  const [loading, setLoading] = useState(false);
  const [selectedProofUrl, setSelectedProofUrl] = useState<string | null>(null);

  // Settings form states
  const [openrouterKeyInput, setOpenrouterKeyInput] = useState("");
  const [openaiKeyInput, setOpenaiKeyInput] = useState("");
  const [telegramTokenInput, setTelegramTokenInput] = useState("");
  const [telegramAdminsInput, setTelegramAdminsInput] = useState("");
  const [savingSettings, setSavingSettings] = useState(false);

  // Add Model modal state
  const [showAddModelModal, setShowAddModelModal] = useState(false);
  const [newModelPublicId, setNewModelPublicId] = useState("");
  const [newModelProvider, setNewModelProvider] = useState("openrouter");
  const [newModelUpstream, setNewModelUpstream] = useState("");
  const [newModelInputPrice, setNewModelInputPrice] = useState(15000);
  const [newModelOutputPrice, setNewModelOutputPrice] = useState(30000);

  // Edit Model modal state
  const [editingModel, setEditingModel] = useState<AdminModel | null>(null);
  const [editPublicId, setEditPublicId] = useState("");
  const [editUpstreamModel, setEditUpstreamModel] = useState("");
  const [editProviderSlug, setEditProviderSlug] = useState("openrouter");
  const [editInputPrice, setEditInputPrice] = useState(0);
  const [editOutputPrice, setEditOutputPrice] = useState(0);
  const [editMaxInput, setEditMaxInput] = useState(8192);
  const [editMaxOutput, setEditMaxOutput] = useState(4096);

  // Balance adjustment modal state
  const [adjustModalUser, setAdjustModalUser] = useState<AdminUser | null>(null);
  const [adjustAmount, setAdjustAmount] = useState<number>(0);
  const [adjustReason, setAdjustReason] = useState<string>("");

  const loadData = async () => {
    setLoading(true);
    try {
      if (activeTab === "topups") {
        const data = await apiFetch<AdminTopup[]>("/api/v1/admin/topups");
        setTopups(data);
      } else if (activeTab === "models") {
        const data = await apiFetch<AdminModel[]>("/api/v1/admin/models");
        setModels(data);
      } else if (activeTab === "settings") {
        const data = await apiFetch<AdminSettings>("/api/v1/admin/settings");
        setSettings(data);
        if (data.telegram?.admin_ids) {
          setTelegramAdminsInput(data.telegram.admin_ids);
        }
      } else if (activeTab === "users") {
        const data = await apiFetch<AdminUser[]>("/api/v1/admin/users");
        setUsers(data);
      } else if (activeTab === "audit") {
        const data = await apiFetch<AuditLog[]>("/api/v1/admin/audit-logs");
        setAuditLogs(data);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (user?.role === "admin") {
      loadData();
    }
  }, [activeTab, user]);

  if (user?.role !== "admin") {
    return (
      <div className="p-8 text-center text-rose-600 bg-rose-50 rounded-2xl border border-rose-200">
        <AlertCircle className="w-8 h-8 mx-auto mb-2 text-rose-500" />
        <h2 className="text-lg font-bold">Akses Ditolak</h2>
        <p className="text-xs text-rose-500 mt-1">Halaman ini hanya dapat diakses oleh Administrator sistem.</p>
      </div>
    );
  }

  const handleReviewTopup = async (orderId: string, action: "approve" | "reject") => {
    let reason: string | undefined = undefined;
    if (action === "reject") {
      const inputReason = prompt("Masukkan alasan penolakan:");
      if (!inputReason) return;
      reason = inputReason;
    }

    try {
      await apiFetch(`/api/v1/admin/topups/${orderId}/review`, {
        method: "POST",
        body: JSON.stringify({ action, rejection_reason: reason }),
      });
      alert(`Top-up ${orderId} berhasil di-${action === "approve" ? "setujui" : "tolak"}.`);
      await loadData();
    } catch (err: any) {
      alert(err.message || "Gagal memproses review top-up.");
    }
  };

  const handleToggleModel = async (modelId: string, currentEnabled: boolean) => {
    try {
      await apiFetch(`/api/v1/admin/models/${modelId}`, {
        method: "PATCH",
        body: JSON.stringify({ enabled: !currentEnabled }),
      });
      await loadData();
    } catch (err: any) {
      alert(err.message || "Gagal mengubah status model.");
    }
  };

  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingSettings(true);
    try {
      const payload: Record<string, string> = {};
      if (openrouterKeyInput.trim()) payload.OPENROUTER_API_KEY = openrouterKeyInput.trim();
      if (openaiKeyInput.trim()) payload.OPENAI_API_KEY = openaiKeyInput.trim();
      if (telegramTokenInput.trim()) payload.TELEGRAM_BOT_TOKEN = telegramTokenInput.trim();
      if (telegramAdminsInput.trim()) payload.TELEGRAM_ADMIN_IDS = telegramAdminsInput.trim();

      if (Object.keys(payload).length === 0) {
        alert("Tidak ada pengaturan baru yang diisi.");
        setSavingSettings(false);
        return;
      }

      await apiFetch("/api/v1/admin/settings", {
        method: "POST",
        body: JSON.stringify(payload),
      });

      alert("Pengaturan API Provider & Telegram berhasil disimpan ke sistem!");
      setOpenrouterKeyInput("");
      setOpenaiKeyInput("");
      setTelegramTokenInput("");
      await loadData();
    } catch (err: any) {
      alert(err.message || "Gagal menyimpan pengaturan.");
    } finally {
      setSavingSettings(false);
    }
  };

  const handleAddModel = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newModelPublicId.trim() || !newModelProvider) return;

    try {
      await apiFetch("/api/v1/admin/models", {
        method: "POST",
        body: JSON.stringify({
          public_id: newModelPublicId.trim(),
          provider_slug: newModelProvider,
          upstream_model: newModelUpstream.trim() || newModelPublicId.trim(),
          input_price_per_million: newModelInputPrice,
          output_price_per_million: newModelOutputPrice,
        }),
      });

      alert(`Model ${newModelPublicId} berhasil ditambahkan dan langsung online!`);
      setShowAddModelModal(false);
      setNewModelPublicId("");
      setNewModelUpstream("");
      await loadData();
    } catch (err: any) {
      alert(err.message || "Gagal menambahkan model baru.");
    }
  };

  const handleUpdateModelPricing = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingModel) return;

    try {
      await apiFetch(`/api/v1/admin/models/${editingModel.id || editingModel.public_id}`, {
        method: "PATCH",
        body: JSON.stringify({
          public_id: editPublicId.trim() || editingModel.public_id,
          upstream_model: editUpstreamModel.trim() || editPublicId.trim() || editingModel.public_id,
          provider_slug: editProviderSlug,
          input_price_per_million: editInputPrice,
          output_price_per_million: editOutputPrice,
          max_input_tokens: editMaxInput,
          max_output_tokens: editMaxOutput,
        }),
      });

      alert(`Model ${editPublicId || editingModel.public_id} berhasil diperbarui!`);
      setEditingModel(null);
      await loadData();
    } catch (err: any) {
      alert(err.message || "Gagal memperbarui model.");
    }
  };

  const handleAdjustBalance = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!adjustModalUser || adjustAmount === 0 || !adjustReason.trim()) return;

    try {
      await apiFetch("/api/v1/admin/users/balance-adjust", {
        method: "POST",
        body: JSON.stringify({
          user_id: adjustModalUser.id,
          amount_credits: adjustAmount,
          reason: adjustReason.trim(),
        }),
      });
      alert("Penyesuaian saldo berhasil dicatat dalam audit ledger!");
      setAdjustModalUser(null);
      setAdjustAmount(0);
      setAdjustReason("");
      await loadData();
    } catch (err: any) {
      alert(err.message || "Gagal menyesuaikan saldo pengguna.");
    }
  };

  return (
    <div className="space-y-6">
      {/* Admin Title */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900 tracking-tight flex items-center">
            <ShieldCheck className="w-6 h-6 mr-2 text-indigo-600" />
            Admin Control Center
          </h1>
          <p className="text-sm text-slate-500 mt-1">
            Input API master provider, kelola katalog model AI, review invoice top-up, dan kelola saldo pengguna.
          </p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-slate-200 space-x-6 text-sm font-semibold overflow-x-auto">
        <button
          onClick={() => setActiveTab("topups")}
          className={`pb-3 flex items-center space-x-2 transition shrink-0 ${
            activeTab === "topups"
              ? "border-b-2 border-indigo-600 text-indigo-600 font-bold"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <span>Review Top-up</span>
        </button>

        <button
          onClick={() => setActiveTab("settings")}
          className={`pb-3 flex items-center space-x-2 transition shrink-0 ${
            activeTab === "settings"
              ? "border-b-2 border-indigo-600 text-indigo-600 font-bold"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <Key className="w-4 h-4" />
          <span>Pengaturan API Provider</span>
        </button>

        <button
          onClick={() => setActiveTab("models")}
          className={`pb-3 flex items-center space-x-2 transition shrink-0 ${
            activeTab === "models"
              ? "border-b-2 border-indigo-600 text-indigo-600 font-bold"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <Cpu className="w-4 h-4" />
          <span>Katalog Model & Tarif</span>
        </button>

        <button
          onClick={() => setActiveTab("users")}
          className={`pb-3 flex items-center space-x-2 transition shrink-0 ${
            activeTab === "users"
              ? "border-b-2 border-indigo-600 text-indigo-600 font-bold"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <Users className="w-4 h-4" />
          <span>Pengguna & Saldo</span>
        </button>

        <button
          onClick={() => setActiveTab("audit")}
          className={`pb-3 flex items-center space-x-2 transition shrink-0 ${
            activeTab === "audit"
              ? "border-b-2 border-indigo-600 text-indigo-600 font-bold"
              : "text-slate-500 hover:text-slate-800"
          }`}
        >
          <span>Audit Logs</span>
        </button>
      </div>

      {/* Tab Panels */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        {loading ? (
          <div className="p-8 text-center text-xs text-slate-400">Memuat data admin...</div>
        ) : activeTab === "settings" ? (
          /* Settings: Upstream Provider Keys */
          <div className="p-6 space-y-6">
            <div>
              <h2 className="text-lg font-bold text-slate-900">Konfigurasi API Master Provider</h2>
              <p className="text-xs text-slate-500 mt-1">
                Kunci API yang Anda beli dari provider disimpan aman di database backend dan tidak pernah dikirim ke browser pengguna.
              </p>
            </div>

            <form onSubmit={handleSaveSettings} className="space-y-6 max-w-2xl">
              {/* OpenRouter Card */}
              <div className="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-2">
                    <Key className="w-4 h-4 text-indigo-600" />
                    <span className="font-bold text-sm text-slate-800">OpenRouter API Key</span>
                  </div>
                  <span
                    className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                      settings?.openrouter?.configured
                        ? "bg-emerald-100 text-emerald-800"
                        : "bg-slate-200 text-slate-600"
                    }`}
                  >
                    {settings?.openrouter?.configured ? `Aktif (${settings.openrouter.masked})` : "Belum Diisi"}
                  </span>
                </div>
                <p className="text-xs text-slate-500">
                  Untuk mengakses model LLaMA 3, DeepSeek, Mistral, Qwen, Claude, dll via OpenRouter.
                </p>
                <input
                  type="password"
                  placeholder={settings?.openrouter?.configured ? "Ketik untuk mengganti key..." : "sk-or-v1-..."}
                  value={openrouterKeyInput}
                  onChange={(e) => setOpenrouterKeyInput(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                />
              </div>

              {/* OpenAI Card */}
              <div className="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-2">
                    <Key className="w-4 h-4 text-emerald-600" />
                    <span className="font-bold text-sm text-slate-800">OpenAI API Key</span>
                  </div>
                  <span
                    className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                      settings?.openai?.configured
                        ? "bg-emerald-100 text-emerald-800"
                        : "bg-slate-200 text-slate-600"
                    }`}
                  >
                    {settings?.openai?.configured ? `Aktif (${settings.openai.masked})` : "Belum Diisi"}
                  </span>
                </div>
                <p className="text-xs text-slate-500">
                  Untuk model resmi OpenAI seperti GPT-4o, GPT-4o-mini, dll.
                </p>
                <input
                  type="password"
                  placeholder={settings?.openai?.configured ? "Ketik untuk mengganti key..." : "sk-proj-..."}
                  value={openaiKeyInput}
                  onChange={(e) => setOpenaiKeyInput(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                />
              </div>

              {/* Telegram Card */}
              <div className="p-5 rounded-xl border border-slate-200 bg-slate-50/50 space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center space-x-2">
                    <Key className="w-4 h-4 text-sky-600" />
                    <span className="font-bold text-sm text-slate-800">Telegram Bot Review Notifier</span>
                  </div>
                  <span
                    className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                      settings?.telegram?.configured
                        ? "bg-emerald-100 text-emerald-800"
                        : "bg-slate-200 text-slate-600"
                    }`}
                  >
                    {settings?.telegram?.configured ? "Aktif" : "Opsional / Kosong"}
                  </span>
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Bot Token</label>
                  <input
                    type="password"
                    placeholder="Contoh: 123456789:ABCdefGhIJKlmNoPQRstuVWxYz"
                    value={telegramTokenInput}
                    onChange={(e) => setTelegramTokenInput(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 mb-1">Admin Telegram IDs (Koma-terpisah)</label>
                  <input
                    type="text"
                    placeholder="Contoh: 12345678,98765432"
                    value={telegramAdminsInput}
                    onChange={(e) => setTelegramAdminsInput(e.target.value)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                  />
                </div>
              </div>

              <button
                type="submit"
                disabled={savingSettings}
                className="inline-flex items-center px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm disabled:opacity-50"
              >
                <Save className="w-4 h-4 mr-2" />
                {savingSettings ? "Menyimpan..." : "Simpan Pengaturan API"}
              </button>
            </form>
          </div>
        ) : activeTab === "topups" ? (
          /* Topup Approvals */
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                <tr>
                  <th className="px-6 py-3.5">Order ID</th>
                  <th className="px-6 py-3.5">Pengguna</th>
                  <th className="px-6 py-3.5">Nominal</th>
                  <th className="px-6 py-3.5">Kredit</th>
                  <th className="px-6 py-3.5">Status</th>
                  <th className="px-6 py-3.5">Bukti</th>
                  <th className="px-6 py-3.5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {topups.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-8 text-center text-slate-400">
                      Tidak ada permintaan top-up yang membutuhkan tindakan.
                    </td>
                  </tr>
                ) : (
                  topups.map((t) => (
                    <tr key={t.id} className="hover:bg-slate-50/50">
                      <td className="px-6 py-3.5 font-mono font-semibold text-slate-900">{t.order_id}</td>
                      <td className="px-6 py-3.5 text-slate-600">{t.user_email}</td>
                      <td className="px-6 py-3.5 font-bold text-slate-800">
                        Rp{t.amount_minor.toLocaleString("id-ID")}
                      </td>
                      <td className="px-6 py-3.5 font-semibold text-indigo-600">
                        +{t.credits_to_grant.toLocaleString("id-ID")}
                      </td>
                      <td className="px-6 py-3.5">
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                            t.status === "paid"
                              ? "bg-emerald-100 text-emerald-800"
                              : t.status === "rejected"
                              ? "bg-rose-100 text-rose-800"
                              : "bg-amber-100 text-amber-800"
                          }`}
                        >
                          {t.status}
                        </span>
                      </td>
                      <td className="px-6 py-3.5">
                        {t.proof_file_key ? (
                          <button
                            onClick={() =>
                              setSelectedProofUrl(`/api/v1/admin/topups/${t.order_id}/proof`)
                            }
                            className="inline-flex items-center text-indigo-600 hover:text-indigo-800 font-semibold"
                          >
                            <Eye className="w-3.5 h-3.5 mr-1" />
                            Lihat Bukti
                          </button>
                        ) : (
                          <span className="text-slate-400">Belum ada</span>
                        )}
                      </td>
                      <td className="px-6 py-3.5 text-right space-x-2">
                        {t.status !== "paid" && t.status !== "rejected" && (
                          <>
                            <button
                              onClick={() => handleReviewTopup(t.order_id, "approve")}
                              className="px-3 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 font-semibold rounded-lg border border-emerald-200"
                            >
                              Setujui
                            </button>
                            <button
                              onClick={() => handleReviewTopup(t.order_id, "reject")}
                              className="px-3 py-1 bg-rose-50 text-rose-700 hover:bg-rose-100 font-semibold rounded-lg border border-rose-200"
                            >
                              Tolak
                            </button>
                          </>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        ) : activeTab === "models" ? (
          /* Models & Kill Switches */
          <div>
            <div className="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
              <div>
                <span className="text-xs font-bold text-slate-700 uppercase">Daftar Model AI</span>
                <p className="text-[11px] text-slate-500">Atur harga per token dan kill switch per model.</p>
              </div>
              <button
                onClick={() => setShowAddModelModal(true)}
                className="inline-flex items-center px-3.5 py-1.5 rounded-xl text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm"
              >
                <Plus className="w-3.5 h-3.5 mr-1" />
                Tambah Model Baru
              </button>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                  <tr>
                    <th className="px-6 py-3.5">Model ID</th>
                    <th className="px-6 py-3.5">Provider</th>
                    <th className="px-6 py-3.5">Harga Input / 1M</th>
                    <th className="px-6 py-3.5">Harga Output / 1M</th>
                    <th className="px-6 py-3.5">Status</th>
                    <th className="px-6 py-3.5 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {models.map((m) => (
                    <tr key={m.id} className="hover:bg-slate-50/50">
                      <td className="px-6 py-3.5 font-mono font-bold text-slate-800">{m.public_id}</td>
                      <td className="px-6 py-3.5 text-slate-600 font-semibold">{m.provider_slug}</td>
                      <td className="px-6 py-3.5 text-slate-700 font-medium">Rp{m.input_price_per_million.toLocaleString("id-ID")}</td>
                      <td className="px-6 py-3.5 text-slate-700 font-medium">Rp{m.output_price_per_million.toLocaleString("id-ID")}</td>
                      <td className="px-6 py-3.5">
                        <span
                          className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase ${
                            m.enabled ? "bg-emerald-100 text-emerald-800" : "bg-slate-100 text-slate-600"
                          }`}
                        >
                          {m.enabled ? "Online" : "Disabled"}
                        </span>
                      </td>
                      <td className="px-6 py-3.5 text-right space-x-2">
                        <button
                          onClick={() => {
                            setEditingModel(m);
                            setEditPublicId(m.public_id);
                            setEditUpstreamModel(m.upstream_model || m.public_id);
                            setEditProviderSlug(m.provider_slug || "openrouter");
                            setEditInputPrice(m.input_price_per_million);
                            setEditOutputPrice(m.output_price_per_million);
                            setEditMaxInput(m.max_input_tokens || 8192);
                            setEditMaxOutput(m.max_output_tokens || 4096);
                          }}
                          className="px-2.5 py-1 text-slate-600 hover:text-indigo-600 font-semibold rounded-lg hover:bg-slate-100 border border-slate-200"
                        >
                          Edit
                        </button>
                        <button
                          onClick={() => handleToggleModel(m.public_id, m.enabled)}
                          className={`px-3 py-1 font-semibold rounded-lg transition ${
                            m.enabled
                              ? "bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200"
                              : "bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200"
                          }`}
                        >
                          {m.enabled ? "Matikan" : "Aktifkan"}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ) : activeTab === "users" ? (
          /* Users & Wallets */
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                <tr>
                  <th className="px-6 py-3.5">Email Pengguna</th>
                  <th className="px-6 py-3.5">Role</th>
                  <th className="px-6 py-3.5">Status</th>
                  <th className="px-6 py-3.5">Saldo Kredit</th>
                  <th className="px-6 py-3.5">Tanggal Daftar</th>
                  <th className="px-6 py-3.5 text-right">Tindakan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {users.map((u) => (
                  <tr key={u.id} className="hover:bg-slate-50/50">
                    <td className="px-6 py-3.5 font-medium text-slate-900">{u.email}</td>
                    <td className="px-6 py-3.5 uppercase font-semibold text-slate-600">{u.role}</td>
                    <td className="px-6 py-3.5">
                      <span className="px-2 py-0.5 bg-emerald-50 text-emerald-700 rounded text-[10px] font-bold">
                        {u.status}
                      </span>
                    </td>
                    <td className="px-6 py-3.5 font-bold text-indigo-600">
                      {u.balance_credits.toLocaleString("id-ID")} Kredit
                    </td>
                    <td className="px-6 py-3.5 text-slate-400">
                      {new Date(u.created_at).toLocaleDateString("id-ID")}
                    </td>
                    <td className="px-6 py-3.5 text-right">
                      <button
                        onClick={() => {
                          setAdjustModalUser(u);
                          setAdjustAmount(0);
                          setAdjustReason("");
                        }}
                        className="px-3 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold rounded-lg border border-indigo-200"
                      >
                        Sesuaikan Saldo
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          /* Audit Logs */
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-bold uppercase tracking-wider">
                <tr>
                  <th className="px-6 py-3.5">Waktu</th>
                  <th className="px-6 py-3.5">Admin</th>
                  <th className="px-6 py-3.5">Aksi</th>
                  <th className="px-6 py-3.5">Target</th>
                  <th className="px-6 py-3.5">Metadata</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {auditLogs.map((l) => (
                  <tr key={l.id} className="hover:bg-slate-50/50">
                    <td className="px-6 py-3.5 text-slate-400 font-mono">
                      {new Date(l.created_at).toLocaleString("id-ID")}
                    </td>
                    <td className="px-6 py-3.5 font-semibold text-slate-800">{l.admin_email}</td>
                    <td className="px-6 py-3.5 font-mono text-indigo-600">{l.action}</td>
                    <td className="px-6 py-3.5 text-slate-600 font-mono">
                      {l.target_type}:{l.target_id}
                    </td>
                    <td className="px-6 py-3.5 font-mono text-[11px] text-slate-500 max-w-xs truncate">
                      {JSON.stringify(l.metadata || {})}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal: Tambah Model Baru */}
      {showAddModelModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-lg w-full p-6 shadow-xl border border-slate-200">
            <form onSubmit={handleAddModel} className="space-y-4">
              <h3 className="text-lg font-bold text-slate-900">Tambah Model AI Baru</h3>
              <p className="text-xs text-slate-500">
                Model baru akan langsung muncul di katalog OpenAI-compatible (/v1/models) dan Playground.
              </p>

              <div>
                <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                  Public Model ID (Dilihat oleh klien)
                </label>
                <input
                  type="text"
                  required
                  placeholder="Contoh: deepseek/deepseek-chat atau openai/gpt-4o"
                  value={newModelPublicId}
                  onChange={(e) => setNewModelPublicId(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">Provider</label>
                <select
                  value={newModelProvider}
                  onChange={(e) => setNewModelProvider(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white"
                >
                  <option value="openrouter">OpenRouter</option>
                  <option value="openai">OpenAI</option>
                  <option value="mock">Mock (Testing Internal)</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                  Upstream Model Name (Kosongkan jika sama dengan Model ID)
                </label>
                <input
                  type="text"
                  placeholder="Contoh: deepseek/deepseek-chat"
                  value={newModelUpstream}
                  onChange={(e) => setNewModelUpstream(e.target.value)}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Tarif Input (Rp / 1M token)
                  </label>
                  <input
                    type="number"
                    required
                    value={newModelInputPrice}
                    onChange={(e) => setNewModelInputPrice(parseInt(e.target.value, 10) || 0)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Tarif Output (Rp / 1M token)
                  </label>
                  <input
                    type="number"
                    required
                    value={newModelOutputPrice}
                    onChange={(e) => setNewModelOutputPrice(parseInt(e.target.value, 10) || 0)}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div className="flex justify-end space-x-3 pt-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setShowAddModelModal(false)}
                  className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm"
                >
                  Simpan & Luncurkan Model
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Edit Model AI */}
      {editingModel && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-lg w-full p-6 shadow-xl border border-slate-200">
            <form onSubmit={handleUpdateModelPricing} className="space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 className="text-lg font-bold text-slate-900">Edit Konfigurasi & Tarif Model</h3>
                <button
                  type="button"
                  onClick={() => setEditingModel(null)}
                  className="text-slate-400 hover:text-slate-700"
                >
                  ✕
                </button>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Public Model ID
                  </label>
                  <input
                    type="text"
                    required
                    value={editPublicId}
                    onChange={(e) => setEditPublicId(e.target.value)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Provider
                  </label>
                  <select
                    value={editProviderSlug}
                    onChange={(e) => setEditProviderSlug(e.target.value)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500 font-semibold"
                  >
                    <option value="openrouter">OpenRouter</option>
                    <option value="openai">OpenAI</option>
                    <option value="groq">Groq</option>
                    <option value="deepseek">DeepSeek</option>
                    <option value="gemini">Google Gemini</option>
                    <option value="mistral">Mistral AI</option>
                    <option value="mock">Mock Testing</option>
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                  Upstream Model Name (Target Provider)
                </label>
                <input
                  type="text"
                  value={editUpstreamModel}
                  onChange={(e) => setEditUpstreamModel(e.target.value)}
                  className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Tarif Input (Rp / 1M token)
                  </label>
                  <input
                    type="number"
                    required
                    value={editInputPrice}
                    onChange={(e) => setEditInputPrice(parseInt(e.target.value, 10) || 0)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Tarif Output (Rp / 1M token)
                  </label>
                  <input
                    type="number"
                    required
                    value={editOutputPrice}
                    onChange={(e) => setEditOutputPrice(parseInt(e.target.value, 10) || 0)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Max Input Tokens
                  </label>
                  <input
                    type="number"
                    value={editMaxInput}
                    onChange={(e) => setEditMaxInput(parseInt(e.target.value, 10) || 8192)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Max Output Tokens
                  </label>
                  <input
                    type="number"
                    value={editMaxOutput}
                    onChange={(e) => setEditMaxOutput(parseInt(e.target.value, 10) || 4096)}
                    className="w-full px-3 py-2 rounded-xl border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>
              </div>

              <div className="flex justify-end space-x-3 pt-3 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setEditingModel(null)}
                  className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm"
                >
                  Simpan Perubahan
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Proof Viewer Modal */}
      {selectedProofUrl && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-xl w-full p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">Bukti Pembayaran Pengguna</h3>
              <button
                onClick={() => setSelectedProofUrl(null)}
                className="text-slate-400 hover:text-slate-600 font-bold"
              >
                ✕
              </button>
            </div>

            <div className="border border-slate-200 rounded-xl overflow-hidden bg-slate-50 flex items-center justify-center min-h-[300px]">
              <img
                src={selectedProofUrl}
                alt="Bukti Transfer"
                className="object-contain max-h-[70vh] w-full"
              />
            </div>

            <p className="text-xs text-slate-500">
              ⚠️ <em>Catatan:</em> Pastikan nominal dan mutasi rekening pada portal perbankan resmi Anda sesuai sebelum menyetujui.
            </p>
          </div>
        </div>
      )}

      {/* Balance Adjustment Modal */}
      {adjustModalUser && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200">
            <form onSubmit={handleAdjustBalance} className="space-y-4">
              <h3 className="text-lg font-bold text-slate-900">Penyesuaian Saldo Pengguna</h3>
              <p className="text-xs text-slate-500">
                Pengguna: <strong>{adjustModalUser.email}</strong> (Saldo saat ini:{" "}
                {adjustModalUser.balance_credits.toLocaleString("id-ID")} Kredit)
              </p>

              <div>
                <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                  Nominal Kredit (Gunakan tanda minus untuk mengurangi)
                </label>
                <input
                  type="number"
                  required
                  value={adjustAmount || ""}
                  onChange={(e) => setAdjustAmount(parseInt(e.target.value, 10) || 0)}
                  placeholder="Contoh: 10000 atau -5000"
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                  Alasan Penyesuaian (Wajib untuk audit trail)
                </label>
                <textarea
                  required
                  rows={2}
                  value={adjustReason}
                  onChange={(e) => setAdjustReason(e.target.value)}
                  placeholder="Contoh: Kompensasi downtime atau pengembalian manual"
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>

              <div className="flex justify-end space-x-3 pt-3">
                <button
                  type="button"
                  onClick={() => setAdjustModalUser(null)}
                  className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={adjustAmount === 0 || !adjustReason.trim()}
                  className="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm disabled:opacity-50"
                >
                  Simpan & Audit
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
