import React, { createContext, useContext, useEffect, useState } from "react";
import { apiFetch } from "../api/client";

export interface User {
  id: string;
  email: string;
  email_verified: boolean;
  status: string;
  role: string;
  created_at: string;
}

export interface Wallet {
  balance_credits: number;
  currency: string;
  updated_at: string;
}

interface AuthContextType {
  user: User | null;
  wallet: Wallet | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshWallet: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchCurrentUser = async () => {
    try {
      const u = await apiFetch<User>("/api/v1/me");
      setUser(u);
      const w = await apiFetch<Wallet>("/api/v1/wallet");
      setWallet(w);
    } catch {
      setUser(null);
      setWallet(null);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchCurrentUser();
  }, []);

  const refreshWallet = async () => {
    try {
      const w = await apiFetch<Wallet>("/api/v1/wallet");
      setWallet(w);
    } catch (e) {
      console.error("Gagal memperbarui saldo:", e);
    }
  };

  const login = async (email: string, password: string) => {
    const res = await apiFetch<{ user: User }>("/api/v1/auth/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    setUser(res.user);
    await refreshWallet();
  };

  const register = async (email: string, password: string) => {
    const res = await apiFetch<{ user: User }>("/api/v1/auth/register", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    // Auto-login after successful registration
    await login(email, password);
  };

  const logout = async () => {
    try {
      await apiFetch("/api/v1/auth/logout", { method: "POST" });
    } catch (e) {
      console.error("Logout error:", e);
    }
    setUser(null);
    setWallet(null);
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        wallet,
        loading,
        login,
        register,
        logout,
        refreshWallet,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
};
