import React, { useState, useEffect } from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import {
  BookOpen,
  ChevronRight,
  Coins,
  Cpu,
  History,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Menu,
  Wallet,
  X,
} from "lucide-react";

export const Navbar: React.FC = () => {
  const { user, wallet, logout } = useAuth();
  const location = useLocation();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  const isActive = (path: string) => location.pathname === path;

  // Auto-close mobile menu on route change
  useEffect(() => {
    setMobileMenuOpen(false);
  }, [location.pathname]);

  // Guest Navbar (When user is not logged in)
  if (!user) {
    return (
      <header className="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <Link to="/" className="flex items-center space-x-2.5 shrink-0">
            <div className="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold shadow-sm">
              <Cpu className="w-5 h-5" />
            </div>
            <div className="flex items-center space-x-1.5">
              <span className="font-extrabold text-base tracking-tight text-slate-900">
                AI Ciptaone
              </span>
              <span className="hidden xs:inline-block text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 bg-indigo-50 text-indigo-600 rounded-md border border-indigo-200">
                AI Router
              </span>
            </div>
          </Link>

          {/* Desktop Guest Menu */}
          <nav className="hidden md:flex items-center space-x-2 text-sm font-medium">
            <Link
              to="/"
              className={`px-3 py-1.5 rounded-lg transition-colors ${
                isActive("/")
                  ? "bg-slate-100 text-indigo-600 font-semibold"
                  : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
              }`}
            >
              Beranda
            </Link>

            <Link
              to="/docs"
              className={`px-3 py-1.5 rounded-lg transition-colors flex items-center space-x-1.5 ${
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
              className="text-slate-700 hover:text-slate-900 px-3 py-1.5 rounded-lg hover:bg-slate-50 transition-colors"
            >
              Masuk
            </Link>

            <Link
              to="/register"
              className="px-4 py-2 rounded-xl font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition-colors shadow-sm"
            >
              Daftar Akun
            </Link>
          </nav>

          {/* Mobile Guest Toggle */}
          <div className="flex items-center space-x-2 md:hidden">
            <Link
              to="/login"
              className="text-xs font-semibold px-3 py-1.5 rounded-lg text-slate-700 hover:bg-slate-100 transition"
            >
              Masuk
            </Link>
            <button
              onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
              className="p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
              aria-label="Toggle Navigation"
            >
              {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
            </button>
          </div>
        </div>

        {/* Mobile Guest Dropdown Drawer */}
        {mobileMenuOpen && (
          <div className="md:hidden border-t border-slate-200 bg-white px-4 pt-3 pb-6 space-y-3 shadow-lg">
            <nav className="space-y-1 text-sm font-medium">
              <Link
                to="/"
                className={`flex items-center justify-between px-3 py-2.5 rounded-xl transition ${
                  isActive("/")
                    ? "bg-indigo-50 text-indigo-600 font-semibold"
                    : "text-slate-700 hover:bg-slate-50"
                }`}
              >
                <span>Beranda</span>
                <ChevronRight className="w-4 h-4 text-slate-400" />
              </Link>
              <Link
                to="/docs"
                className={`flex items-center justify-between px-3 py-2.5 rounded-xl transition ${
                  isActive("/docs")
                    ? "bg-indigo-50 text-indigo-600 font-semibold"
                    : "text-slate-700 hover:bg-slate-50"
                }`}
              >
                <div className="flex items-center space-x-2">
                  <BookOpen className="w-4 h-4 text-indigo-600" />
                  <span>Dokumentasi API</span>
                </div>
                <ChevronRight className="w-4 h-4 text-slate-400" />
              </Link>
            </nav>

            <div className="pt-2 border-t border-slate-100 grid grid-cols-2 gap-2">
              <Link
                to="/login"
                className="w-full text-center py-2.5 rounded-xl text-sm font-semibold border border-slate-200 text-slate-700 hover:bg-slate-50 transition"
              >
                Masuk
              </Link>
              <Link
                to="/register"
                className="w-full text-center py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 text-white hover:bg-indigo-700 transition shadow-sm"
              >
                Daftar Akun
              </Link>
            </div>
          </div>
        )}
      </header>
    );
  }

  // Authenticated Navbar
  const navItems = [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard, active: isActive("/dashboard") || isActive("/") },
    { to: "/playground", label: "Playground", icon: Cpu, active: isActive("/playground") },
    { to: "/api-keys", label: "API Keys", icon: KeyRound, active: isActive("/api-keys") },
    { to: "/topup", label: "Top-up", icon: Coins, active: isActive("/topup") },
    { to: "/history", label: "Riwayat", icon: History, active: isActive("/history") },
    { to: "/docs", label: "Dokumentasi", icon: BookOpen, active: isActive("/docs") },
  ];

  return (
    <header className="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-slate-200">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        
        {/* Left: Brand Logo */}
        <div className="flex items-center space-x-4 xl:space-x-8">
          <Link to="/dashboard" className="flex items-center space-x-2.5 shrink-0">
            <div className="w-9 h-9 rounded-xl bg-indigo-600 flex items-center justify-center text-white font-bold shadow-sm">
              <Cpu className="w-5 h-5" />
            </div>
            <div className="flex items-center space-x-1.5">
              <span className="font-extrabold text-base tracking-tight text-slate-900">
                AI Ciptaone
              </span>
              <span className="hidden sm:inline-block text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 bg-indigo-50 text-indigo-600 rounded-md border border-indigo-200">
                Router
              </span>
            </div>
          </Link>

          {/* Desktop Navigation Links (>= lg: 1024px) */}
          <nav className="hidden lg:flex items-center space-x-1 text-xs xl:text-sm font-medium">
            {navItems.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.to}
                  to={item.to}
                  className={`px-2.5 xl:px-3 py-1.5 rounded-lg transition-colors flex items-center space-x-1.5 whitespace-nowrap ${
                    item.active
                      ? "bg-slate-100 text-indigo-600 font-semibold"
                      : "text-slate-600 hover:text-slate-900 hover:bg-slate-50"
                  }`}
                >
                  <Icon className="w-4 h-4 shrink-0" />
                  <span>{item.label}</span>
                </Link>
              );
            })}
          </nav>
        </div>

        {/* Right Section: Balance, Account & Mobile Hamburger */}
        <div className="flex items-center space-x-2 sm:space-x-3">
          {/* Wallet Balance Pill */}
          <Link
            to="/topup"
            className="flex items-center space-x-1.5 sm:space-x-2 px-2.5 sm:px-3 py-1.5 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-800 text-xs sm:text-sm font-semibold hover:bg-emerald-100 transition-colors shrink-0"
            title="Klik untuk mengisi saldo kredit"
          >
            <Wallet className="w-4 h-4 text-emerald-600 shrink-0" />
            <span className="whitespace-nowrap">
              {wallet ? wallet.balance_credits.toLocaleString("id-ID") : "0"}
              <span className="hidden xs:inline ml-1 text-emerald-600 font-normal">Kredit</span>
            </span>
          </Link>

          {/* Desktop User Info & Logout (>= lg) */}
          <div className="hidden lg:flex items-center space-x-3 pl-2 border-l border-slate-200">
            <span
              className="text-xs text-slate-500 font-medium max-w-[140px] xl:max-w-[180px] truncate"
              title={user.email}
            >
              {user.email}
            </span>
            <button
              onClick={logout}
              className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition-colors"
              title="Keluar dari akun"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>

          {/* Mobile & Tablet Hamburger Button (< lg: 1024px) */}
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="lg:hidden p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none"
            aria-label="Toggle Navigation Menu"
          >
            {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
        </div>
      </div>

      {/* Mobile & Tablet Full Navigation Drawer (< lg) */}
      {mobileMenuOpen && (
        <div className="lg:hidden border-t border-slate-200 bg-white px-4 pt-3 pb-6 space-y-4 shadow-xl max-h-[calc(100vh-4rem)] overflow-y-auto">
          {/* User Account Info Card in Mobile Menu */}
          <div className="p-3 bg-slate-50 rounded-2xl border border-slate-200/80 flex items-center justify-between">
            <div className="min-w-0 pr-2">
              <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                Akun Terhubung
              </div>
              <div className="text-xs font-bold text-slate-800 truncate" title={user.email}>
                {user.email}
              </div>
            </div>
            <Link
              to="/topup"
              className="shrink-0 px-2.5 py-1 text-xs font-semibold text-emerald-700 bg-emerald-100/80 rounded-lg hover:bg-emerald-200 transition"
            >
              + Top-up
            </Link>
          </div>

          {/* Nav Links */}
          <nav className="space-y-1 text-sm font-medium">
            {navItems.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.to}
                  to={item.to}
                  className={`flex items-center justify-between px-3.5 py-2.5 rounded-xl transition ${
                    item.active
                      ? "bg-indigo-50 text-indigo-600 font-semibold"
                      : "text-slate-700 hover:bg-slate-50"
                  }`}
                >
                  <div className="flex items-center space-x-3">
                    <Icon className={`w-4 h-4 ${item.active ? "text-indigo-600" : "text-slate-400"}`} />
                    <span>{item.label}</span>
                  </div>
                  <ChevronRight className="w-4 h-4 text-slate-300" />
                </Link>
              );
            })}
          </nav>

          {/* Mobile Logout Button */}
          <div className="pt-2 border-t border-slate-100">
            <button
              onClick={logout}
              className="w-full flex items-center justify-center space-x-2 py-2.5 rounded-xl text-sm font-semibold text-rose-600 bg-rose-50 hover:bg-rose-100 transition"
            >
              <LogOut className="w-4 h-4" />
              <span>Keluar dari Akun</span>
            </button>
          </div>
        </div>
      )}
    </header>
  );
};
