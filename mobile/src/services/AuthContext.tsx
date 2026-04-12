/**
 * Ma Commune — Contexte d'authentification global
 * Fournit l'état de connexion et les fonctions login/logout à toute l'application.
 */

import React, { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { authApi, getToken, getUser, saveToken, saveUser, removeToken, removeUser, User } from './api';
import { loadCompanionSkin } from './CompanionService';

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
  isStaff:        boolean;
  login:          (email: string, password: string) => Promise<void>;
  loginWithCode:  (userId: number, code: string) => Promise<void>;
  register:       (data: { email: string; password: string; full_name: string }) => Promise<void>;
  updateCurrentUser: (patch: Partial<User>) => Promise<void>;
  logout:         () => Promise<void>;
}

// Erreur structurée lancée quand la 2FA est requise
export class TwoFactorRequiredError extends Error {
  constructor(
    public readonly userId: number,
    public readonly method: string,
  ) {
    super('2FA_REQUIRED');
    this.name = 'TwoFactorRequiredError';
  }
}

// ----------------------------------------------------------------
// Contexte
// ----------------------------------------------------------------
const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({
    user:            null,
    token:           null,
    isLoading:       true,
    isAuthenticated: false,
  });

  // Vérifier si un token est déjà stocké au démarrage de l'app
  useEffect(() => {
    (async () => {
      try {
        const [token, user] = await Promise.all([getToken(), getUser(), loadCompanionSkin()]);
        const storedToken = token as string | null;
        if (token) {
          setState({ user, token, isAuthenticated: true, isLoading: false });
        } else {
          setState(s => ({ ...s, isLoading: false }));
        }
      } catch {
        setState(s => ({ ...s, isLoading: false }));
      }
    })();
  }, []);

  const _applySession = async (token: string, user: User) => {
    await Promise.all([saveToken(token), saveUser(user)]);
    setState({ user, token, isLoading: false, isAuthenticated: true });
  };

  const login = async (email: string, password: string) => {
    const res = await authApi.login({ email, password });
    if (res.data?.two_factor_required) {
      // Challenge 2FA — on ne sauvegarde pas le token
      throw new TwoFactorRequiredError(
        res.data.user_id as unknown as number,
        res.data.two_factor_method as unknown as string,
      );
    }
    if (res.data?.token) {
      await _applySession(res.data.token, res.data.user);
    }
  };

  const loginWithCode = async (userId: number, code: string) => {
    const res = await authApi.validate2FA({ user_id: userId, code });
    if (res.data?.token) {
      await _applySession(res.data.token, res.data.user);
    }
  };

  const register = async (data: { email: string; password: string; full_name: string }) => {
    const res = await authApi.register(data);
    if (res.data) {
      await Promise.all([saveToken(res.data.token), saveUser(res.data.user)]);
      setState({
        user: res.data.user,
        token: res.data.token,
        isLoading: false,
        isAuthenticated: true,
      });
    }
  };

  const updateCurrentUser = async (patch: Partial<User>) => {
    setState((current) => {
      if (!current.user) {
        return current;
      }

      const nextUser = { ...current.user, ...patch };
      void saveUser(nextUser);

      return {
        ...current,
        user: nextUser,
      };
    });
  };

  const logout = async () => {
    await Promise.all([removeToken(), removeUser()]);
    setState({ user: null, token: null, isLoading: false, isAuthenticated: false });
  };

  return (
    <AuthContext.Provider
      value={{
        ...state,
        isStaff: state.user?.role === 'admin' || state.user?.role === 'agent',
        login,
        loginWithCode,
        register,
        updateCurrentUser,
        logout,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextType {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
