import React from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import {
  BookOpen,
  Coins,
  Cpu,
  History,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Wallet,
} from "lucide-react";

export const Navbar: React.FC = () => {
  const { user, wallet, logout } = useAuth();
  const location = useLocation();

  const isActive = (path: string) => location.pathname === path;

  // Guest Navbar (When user is not logged in)
  if (!user) {
    return (
      <header className="sticky top-0 z-40 bg-white border-b border-slate-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <Link to="/" className="flex items-center space-x-2.5">
            <div className="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold shadow-sm">
              <Cpu className="w-5 h-5" />
            </div>
            <span className="font-bold text-lg tracking-tight text-slate-900">
              AI Ciptaone <span className="text-indigo-600 font-semibold text-xs px-2 py-0.5 bg-indigo-50 rounded-full border border-indigo-200">AI Router</span>
            </span>
          </Link>

          <nav className="flex items-center space-x-4">
            <Link
              to="/"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <span>Beranda</span>
            </Link>

            <Link
              to="/docs"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/docs")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <BookOpen className="w-4 h-4" />
              <span>Dokumentasi API</span>
            </Link>

            <Link
              to="/login"
              className="text-sm font-medium text-slate-700 hover:text-slate-900 px-3 py-2 transition-colors"
            >
              Masuk
            </Link>

            <Link
              to="/register"
              className="px-4 py-2 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition-colors shadow-sm"
            >
              Daftar Akun
            </Link>
          </nav>
        </div>
      </header>
    );
  }

  // Authenticated Navbar
  return (
    <header className="sticky top-0 z-40 bg-white border-b border-slate-200">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        {/* Brand */}
        <div className="flex items-center space-x-8">
          <Link to="/" className="flex items-center space-x-2.5">
            <div className="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold shadow-sm">
              <Cpu className="w-5 h-5" />
            </div>
            <span className="font-bold text-lg tracking-tight text-slate-900">
              AI Ciptaone <span className="text-indigo-600 font-semibold text-xs px-2 py-0.5 bg-indigo-50 rounded-full border border-indigo-200">AI Router</span>
            </span>
          </Link>

          {/* Navigation Links */}
          <nav className="hidden md:flex items-center space-x-1">
            <Link
              to="/dashboard"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/dashboard") || isActive("/")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <LayoutDashboard className="w-4 h-4" />
              <span>Dashboard</span>
            </Link>

            <Link
              to="/playground"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/playground")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <Cpu className="w-4 h-4" />
              <span>Playground</span>
            </Link>

            <Link
              to="/api-keys"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/api-keys")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <KeyRound className="w-4 h-4" />
              <span>API Keys</span>
            </Link>

            <Link
              to="/topup"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/topup")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <Coins className="w-4 h-4" />
              <span>Top-up</span>
            </Link>

            <Link
              to="/history"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/history")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <History className="w-4 h-4" />
              <span>Riwayat</span>
            </Link>

            <Link
              to="/docs"
              className={`px-3 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-1.5 ${
                isActive("/docs")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              <BookOpen className="w-4 h-4" />
              <span>Dokumentasi</span>
            </Link>
          </nav>
        </div>

        {/* Right Section: Balance & User Menu */}
        <div className="flex items-center space-x-4">
          <Link
            to="/topup"
            className="flex items-center space-x-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-800 text-sm font-semibold hover:bg-emerald-100 transition-colors"
            title="Klik untuk mengisi saldo kredit"
          >
            <Wallet className="w-4 h-4 text-emerald-600" />
            <span>
              {wallet ? wallet.balance_credits.toLocaleString("id-ID") : "0"} Kredit
            </span>
          </Link>

          <div className="flex items-center space-x-3 pl-3 border-l border-slate-200">
            <span className="text-xs text-slate-500 font-medium hidden sm:inline-block max-w-[150px] truncate">
              {user.email}
            </span>
            <button
              onClick={logout}
              className="p-1.5 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 transition-colors"
              title="Keluar"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </header>
  );
};
