import React, { useEffect, useState } from "react";
import { apiFetch, ApiError } from "../api/client";
import {
  KeyRound,
  Plus,
  Copy,
  Check,
  Trash2,
  AlertTriangle,
  Code2,
  Terminal,
} from "lucide-react";

interface ApiKeyItem {
  id: string;
  name: string;
  key_prefix: string;
  last_used_at: string | null;
  created_at: string;
}

export const ApiKeysPage: React.FC = () => {
  const [keys, setKeys] = useState<ApiKeyItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [newKeyName, setNewKeyName] = useState("");
  const [createdKey, setCreatedKey] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchKeys = async () => {
    try {
      const data = await apiFetch<ApiKeyItem[]>("/api/v1/api-keys");
      setKeys(data);
    } catch (e: any) {
      setError("Gagal memuat API key.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchKeys();
  }, []);

  const handleCreateKey = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newKeyName.trim()) return;

    try {
      const res = await apiFetch<{ key: string }>("/api/v1/api-keys", {
        method: "POST",
        body: JSON.stringify({ name: newKeyName.trim() }),
      });
      setCreatedKey(res.key);
      setNewKeyName("");
      await fetchKeys();
    } catch (err: any) {
      alert(err.message || "Gagal membuat API key");
    }
  };

  const handleRevokeKey = async (id: string, name: string) => {
    if (!window.confirm(`Apakah Anda yakin ingin mencabut API key "${name}"? Kunci ini tidak dapat digunakan lagi.`)) {
      return;
    }

    try {
      await apiFetch(`/api/v1/api-keys/${id}`, { method: "DELETE" });
      await fetchKeys();
    } catch (err: any) {
      alert(err.message || "Gagal mencabut API key");
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="space-y-8">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-slate-900 tracking-tight">API Keys</h1>
          <p className="text-sm text-slate-500 mt-1">
            Gunakan API key untuk mengakses endpoint OpenAI-compatible dari skrip atau aplikasi Anda.
          </p>
        </div>

        <button
          onClick={() => {
            setCreatedKey(null);
            setIsModalOpen(true);
          }}
          className="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm transition shadow-sm"
        >
          <Plus className="w-4 h-4 mr-2" />
          Buat API Key Baru
        </button>
      </div>

      {/* Keys Table */}
      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
          <span className="text-xs font-bold text-slate-700 uppercase tracking-wider">
            Daftar Kunci Aktif ({keys.length})
          </span>
          <span className="text-xs text-slate-400">
            Kunci disimpan dalam bentuk hash SHA-256
          </span>
        </div>

        {keys.length === 0 ? (
          <div className="p-8 text-center text-slate-500 text-sm">
            Belum ada API key. Klik "Buat API Key Baru" untuk membuat kunci pertama Anda.
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {keys.map((k) => (
              <div key={k.id} className="p-5 flex flex-wrap items-center justify-between gap-4">
                <div>
                  <div className="font-semibold text-slate-900 text-sm flex items-center space-x-2">
                    <KeyRound className="w-4 h-4 text-indigo-600" />
                    <span>{k.name}</span>
                  </div>
                  <div className="font-mono text-xs text-slate-500 mt-1">
                    {k.key_prefix}
                  </div>
                  <div className="text-[11px] text-slate-400 mt-1">
                    Dibuat: {new Date(k.created_at).toLocaleDateString("id-ID")} • Terakhir digunakan:{" "}
                    {k.last_used_at ? new Date(k.last_used_at).toLocaleDateString("id-ID") : "Belum pernah"}
                  </div>
                </div>

                <button
                  onClick={() => handleRevokeKey(k.id, k.name)}
                  className="px-3 py-1.5 text-xs font-semibold text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition border border-rose-200"
                >
                  Cabut Kunci
                </button>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Quick Documentation & Integration Snippets */}
      <div className="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
        <h3 className="font-bold text-slate-900 text-sm uppercase tracking-wider flex items-center">
          <Code2 className="w-4 h-4 mr-2 text-indigo-600" />
          Panduan Integrasi (OpenAI SDK Compatible)
        </h3>
        <p className="text-xs text-slate-600 leading-relaxed">
          Ganti <code className="bg-slate-100 px-1 py-0.5 rounded text-indigo-600">base_url</code> pada library OpenAI resmi ke endpoint server ini:
        </p>

        {/* Python Snippet */}
        <div className="bg-slate-900 rounded-xl p-4 text-slate-100 font-mono text-xs overflow-x-auto">
          <div className="text-slate-400 mb-2"># Python OpenAI SDK</div>
          <pre>{`from openai import OpenAI

client = OpenAI(
    base_url="https://ciptaone.com/v1",  # atau http://localhost:8000/v1
    api_key="sk-live-your-api-key-here"
)

response = client.chat.completions.create(
    model="mock/echo-v1",
    messages=[{"role": "user", "content": "Halo AI Router!"}]
)
print(response.choices[0].message.content)`}</pre>
        </div>
      </div>

      {/* Create / Reveal Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200">
            {!createdKey ? (
              <form onSubmit={handleCreateKey} className="space-y-4">
                <h3 className="text-lg font-bold text-slate-900">Buat API Key Baru</h3>
                <p className="text-xs text-slate-500">
                  Beri nama API key ini untuk memudahkan pelacakan (misal: "Aplikasi Web", "Skrip Bot").
                </p>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    Nama Kunci
                  </label>
                  <input
                    type="text"
                    required
                    value={newKeyName}
                    onChange={(e) => setNewKeyName(e.target.value)}
                    placeholder="Contoh: Production App"
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                </div>

                <div className="flex justify-end space-x-3 pt-3">
                  <button
                    type="button"
                    onClick={() => setIsModalOpen(false)}
                    className="px-4 py-2 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-100"
                  >
                    Batal
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-2 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 shadow-sm"
                  >
                    Buat Kunci
                  </button>
                </div>
              </form>
            ) : (
              <div className="space-y-4">
                <div className="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs flex items-start space-x-2">
                  <AlertTriangle className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
                  <div>
                    <strong>PENTING:</strong> Simpan kunci ini sekarang di tempat aman. Demi alasan keamanan, kunci lengkap tidak akan pernah ditampilkan kembali.
                  </div>
                </div>

                <div>
                  <label className="block text-xs font-semibold text-slate-700 uppercase mb-1">
                    API Key Anda:
                  </label>
                  <div className="flex items-center space-x-2">
                    <input
                      type="text"
                      readOnly
                      value={createdKey}
                      className="w-full font-mono text-xs px-3 py-2.5 rounded-xl border border-slate-300 bg-slate-50 text-slate-800 select-all"
                    />
                    <button
                      onClick={() => copyToClipboard(createdKey)}
                      className="p-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl shadow-sm transition"
                      title="Salin Kunci"
                    >
                      {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                    </button>
                  </div>
                </div>

                <div className="pt-3">
                  <button
                    onClick={() => {
                      setCreatedKey(null);
                      setIsModalOpen(false);
                    }}
                    className="w-full py-2.5 rounded-xl text-sm font-semibold text-white bg-slate-900 hover:bg-slate-800 shadow-sm"
                  >
                    Saya Sudah Menyimpan Kunci Ini
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};
