/**
 * CCDS — Contexte d'authentification global
 * Fournit l'état de connexion et les fonctions login/logout à toute l'application.
 */

import React, { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { authApi, getToken, saveToken, removeToken, User } from './api';

// ----------------------------------------------------------------
// Types
// ----------------------------------------------------------------
interface AuthState {
  user: User | null;
  token: string | null;
  isLoading: boolean;
  isAuthenticated: boolean;
}

interface AuthContextType extends AuthState {
  login: (email: string, password: string) => Promise<void>;
  register: (data: { email: string; password: string; full_name: string }) => Promise<void>;
  logout: () => Promise<void>;
}

// ----------------------------------------------------------------
// Contexte
// ----------------------------------------------------------------
const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({
    user: null,
    token: null,
    isLoading: true,
    isAuthenticated: false,
  });

  // Vérifier si un token est déjà stocké au démarrage de l'app
  useEffect(() => {
    (async () => {
      try {
        const token = await getToken();
        if (token) {
          // Token présent : on considère l'utilisateur connecté
          // Dans une version avancée, on pourrait appeler GET /api/me pour valider
          setState(s => ({ ...s, token, isAuthenticated: true, isLoading: false }));
        } else {
          setState(s => ({ ...s, isLoading: false }));
        }
      } catch {
        setState(s => ({ ...s, isLoading: false }));
      }
    })();
  }, []);

  const login = async (email: string, password: string) => {
    const res = await authApi.login({ email, password });
    if (res.data) {
      await saveToken(res.data.token);
      setState({
        user: res.data.user,
        token: res.data.token,
        isLoading: false,
        isAuthenticated: true,
      });
    }
  };

  const register = async (data: { email: string; password: string; full_name: string }) => {
    const res = await authApi.register(data);
    if (res.data) {
      await saveToken(res.data.token);
      setState({
        user: res.data.user,
        token: res.data.token,
        isLoading: false,
        isAuthenticated: true,
      });
    }
  };

  const logout = async () => {
    await removeToken();
    setState({ user: null, token: null, isLoading: false, isAuthenticated: false });
  };

  return (
    <AuthContext.Provider value={{ ...state, login, register, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
