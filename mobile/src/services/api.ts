/**
 * CCDS — Service API centralisé
 * Gère tous les appels HTTP vers le backend PHP.
 */

import * as SecureStore from 'expo-secure-store';

// ----------------------------------------------------------------
// Configuration
// ----------------------------------------------------------------
// Remplacez par l'URL de votre serveur en production
export const API_BASE_URL = 'https://votre-domaine.com/api';
const TOKEN_KEY = 'ccds_jwt_token';

// ----------------------------------------------------------------
// Gestion du token JWT
// ----------------------------------------------------------------
export const saveToken = async (token: string): Promise<void> => {
  await SecureStore.setItemAsync(TOKEN_KEY, token);
};

export const getToken = async (): Promise<string | null> => {
  return SecureStore.getItemAsync(TOKEN_KEY);
};

export const removeToken = async (): Promise<void> => {
  await SecureStore.deleteItemAsync(TOKEN_KEY);
};

// ----------------------------------------------------------------
// Client HTTP de base
// ----------------------------------------------------------------
interface ApiResponse<T = unknown> {
  success: boolean;
  message: string;
  data?: T;
  errors?: Record<string, string[]>;
}

async function request<T>(
  endpoint: string,
  options: RequestInit = {},
  authenticated = true
): Promise<ApiResponse<T>> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string>),
  };

  if (authenticated) {
    const token = await getToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }
  }

  const response = await fetch(`${API_BASE_URL}/${endpoint}`, {
    ...options,
    headers,
  });

  const json = await response.json();

  if (!response.ok) {
    throw { status: response.status, ...json };
  }

  return json;
}

// ----------------------------------------------------------------
// Types métier
// ----------------------------------------------------------------
export interface User {
  id: number;
  email: string;
  full_name: string;
  role: 'citizen' | 'agent' | 'admin';
}

export interface AuthResponse {
  token: string;
  expires_in: number;
  user: User;
}

export interface Category {
  id: number;
  name: string;
  slug: string;
  icon: string;
  color: string;
  description: string;
}

export interface Incident {
  id: number;
  reference: string;
  title: string;
  description: string;
  latitude: number;
  longitude: number;
  address: string;
  status: 'submitted' | 'acknowledged' | 'in_progress' | 'resolved' | 'rejected';
  priority: 'low' | 'medium' | 'high' | 'critical';
  category_id: number;
  category_name: string;
  category_icon: string;
  category_color: string;
  reporter_name: string;
  thumbnail?: string;
  photos?: Photo[];
  status_history?: StatusHistory[];
  created_at: string;
  updated_at: string;
}

export interface Photo {
  id: number;
  url: string;
  file_name: string;
  uploaded_at: string;
}

export interface StatusHistory {
  old_status: string;
  new_status: string;
  note: string;
  changed_at: string;
  changed_by: string;
}

export interface Comment {
  id: number;
  comment: string;
  is_internal: boolean;
  created_at: string;
  user_id: number;
  user_name: string;
  user_role: string;
}

export interface PaginatedIncidents {
  incidents: Incident[];
  pagination: {
    total: number;
    page: number;
    limit: number;
    total_pages: number;
  };
}

// ----------------------------------------------------------------
// Endpoints Auth
// ----------------------------------------------------------------
export const authApi = {
  register: (data: { email: string; password: string; full_name: string; phone?: string }) =>
    request<AuthResponse>('register', { method: 'POST', body: JSON.stringify(data) }, false),

  login: (data: { email: string; password: string }) =>
    request<AuthResponse>('login', { method: 'POST', body: JSON.stringify(data) }, false),
};

// ----------------------------------------------------------------
// Endpoints Catégories
// ----------------------------------------------------------------
export const categoriesApi = {
  list: () => request<Category[]>('categories', {}, false),
};

// ----------------------------------------------------------------
// Endpoints Incidents
// ----------------------------------------------------------------
export const incidentsApi = {
  list: (params?: { page?: number; status?: string; category?: number }) => {
    const qs = new URLSearchParams();
    if (params?.page)     qs.set('page',     String(params.page));
    if (params?.status)   qs.set('status',   params.status);
    if (params?.category) qs.set('category', String(params.category));
    const query = qs.toString() ? `?${qs.toString()}` : '';
    return request<PaginatedIncidents>(`incidents${query}`, {}, false);
  },

  get: (id: number) =>
    request<Incident>(`incidents/${id}`),

  create: async (formData: FormData) => {
    const token = await getToken();
    const response = await fetch(`${API_BASE_URL}/incidents`, {
      method: 'POST',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      body: formData,
    });
    const json = await response.json();
    if (!response.ok) throw { status: response.status, ...json };
    return json;
  },
};

// ----------------------------------------------------------------
// Endpoints Commentaires
// ----------------------------------------------------------------
export const commentsApi = {
  list: (incidentId: number) =>
    request<Comment[]>(`incidents/${incidentId}/comments`),

  add: (incidentId: number, data: { comment: string; is_internal?: boolean }) =>
    request(`incidents/${incidentId}/comments`, { method: 'POST', body: JSON.stringify(data) }),
};
