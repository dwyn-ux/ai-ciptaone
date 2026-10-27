import React from "react";
import { Navbar } from "./Navbar";

export const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  return (
    <div className="min-h-screen flex flex-col bg-slate-50">
      <Navbar />
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {children}
      </main>
      <footer className="border-t border-slate-200 bg-white py-6 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-2">
          <span>&copy; {new Date().getFullYear()} AI Ciptaone Router. Hak cipta dilindungi.</span>
          <div className="flex space-x-4">
            <span className="text-slate-400">Arsitektur: Oracle Cloud Always Free + Cloudflare D1</span>
            <span>•</span>
            <span className="text-emerald-600 font-medium">Status: Operasional</span>
          </div>
        </div>
      </footer>
    </div>
  );
};
