import React, { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import { ApiError } from "../api/client";
import { Cpu, AlertCircle, ArrowRight } from "lucide-react";

export const LoginPage: React.FC = () => {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setLoading(true);

    try {
      await login(email, password);
      navigate("/");
    } catch (err: any) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError("Gagal terhubung ke server. Silakan coba lagi.");
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-50 px-4 py-12 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-8 bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
        <div className="text-center">
          <div className="w-12 h-12 bg-indigo-600 rounded-2xl mx-auto flex items-center justify-center text-white shadow-sm mb-4">
            <Cpu className="w-6 h-6" />
          </div>
          <h2 className="text-2xl font-bold text-slate-900 tracking-tight">Masuk ke AI Ciptaone</h2>
          <p className="mt-2 text-sm text-slate-600">
            Akses multi-model AI melalui satu saldo dan satu API key
          </p>
        </div>

        {error && (
          <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-start space-x-2.5">
            <AlertCircle className="w-5 h-5 flex-shrink-0 mt-0.5 text-rose-500" />
            <span>{error}</span>
          </div>
        )}

        <form className="mt-8 space-y-5" onSubmit={handleSubmit}>
          <div>
            <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider mb-1.5">
              Email
            </label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="nama@email.com"
              className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            />
          </div>

          <div>
            <div className="flex items-center justify-between mb-1.5">
              <label className="block text-xs font-semibold text-slate-700 uppercase tracking-wider">
                Kata Sandi
              </label>
            </div>
            <input
              type="password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="••••••••"
              className="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            />
          </div>

          <button
            type="submit"
            disabled={loading}
            className="w-full flex items-center justify-center py-2.5 px-4 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 shadow-sm transition-colors disabled:opacity-50"
          >
            {loading ? (
              <span className="inline-block animate-spin mr-2">⏳</span>
            ) : (
              <>
                <span>Masuk Sekarang</span>
                <ArrowRight className="w-4 h-4 ml-1.5" />
              </>
            )}
          </button>
        </form>

        <div className="text-center pt-2">
          <p className="text-xs text-slate-500">
            Belum memiliki akun?{" "}
            <Link to="/register" className="font-semibold text-indigo-600 hover:text-indigo-500">
              Daftar gratis di sini
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
};
