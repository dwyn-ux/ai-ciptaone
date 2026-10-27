import React from "react";
import { Navbar } from "./Navbar";

export const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  return (
    <div className="min-h-screen flex flex-col bg-slate-50 overflow-x-hidden">
      <Navbar />
      <main className="flex-1 max-w-7xl w-full mx-auto px-3 sm:px-6 lg:px-8 py-5 sm:py-8">
        {children}
      </main>
      <footer className="border-t border-slate-200 bg-white py-6 mt-auto">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-2 text-center sm:text-left">
          <span>&copy; {new Date().getFullYear()} AI Ciptaone Router. Hak cipta dilindungi.</span>
          <div className="flex items-center justify-center space-x-3">
            <span className="text-slate-400 hidden xs:inline">Arsitektur: Multi-Cloud Always Free</span>
            <span className="hidden xs:inline">•</span>
            <span className="text-emerald-600 font-medium">Status: Operasional</span>
          </div>
        </div>
      </footer>
    </div>
  );
};
