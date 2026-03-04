/**
 * CCDS — Service API centralisé
 * Gère tous les appels HTTP vers le backend PHP.
 * v1.1 : ajout votes "Moi aussi", notifications push, mode hors-ligne
 */

import * as SecureStore from 'expo-secure-store';
import { ServerConfig }  from './ServerConfig';

// ----------------------------------------------------------------
// Configuration dynamique
// ----------------------------------------------------------------
export const API_BASE_URL = 'https://votre-domaine.com/api'; // Valeur par défaut (fallback)
const TOKEN_KEY = 'ccds_jwt_token';

export const getBaseUrl = async (): Promise<string> => {
  return ServerConfig.getServerUrl();
};

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

  const baseUrl  = await getBaseUrl();
  const response = await fetch(`${baseUrl}/${endpoint}`, {
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
  votes_count?: number;       // v1.1
  user_has_voted?: boolean;   // v1.1
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

// v1.1 — Notification
export interface Notification {
  id: number;
  type: 'status_change' | 'new_comment' | 'vote_milestone' | 'system';
  title: string;
  body: string;
  is_read: boolean;
  sent_at: string;
  incident_reference?: string;
  incident_title?: string;
}

export interface NotificationsResponse {
  notifications: Notification[];
  unread_count: number;
  pagination: {
    total: number;
    page: number;
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
    const token   = await getToken();
    const baseUrl = await getBaseUrl();
    const response = await fetch(`${baseUrl}/incidents`, {
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

// ----------------------------------------------------------------
// Endpoints Votes "Moi aussi" (v1.1)
// ----------------------------------------------------------------
export const votesApi = {
  /**
   * Récupère l'état du vote pour un incident (count + user_has_voted)
   */
  getState: (incidentId: number) =>
    request<{ votes_count: number; user_has_voted: boolean }>(
      `incidents/${incidentId}/votes`
    ),

  /**
   * Vote pour un incident ("Moi aussi")
   */
  vote: (incidentId: number) =>
    request<{ votes_count: number; user_has_voted: boolean }>(
      `incidents/${incidentId}/vote`,
      { method: 'POST' }
    ),

  /**
   * Retire son vote
   */
  removeVote: (incidentId: number) =>
    request<{ votes_count: number; user_has_voted: boolean }>(
      `incidents/${incidentId}/vote`,
      { method: 'DELETE' }
    ),
};

// Exports compatibles avec api_additions.ts
export const voteForIncident = (id: number) => votesApi.vote(id).then(r => r.data!);
export const removeVote      = (id: number) => votesApi.removeVote(id).then(r => r.data!);
export const getVotes        = (id: number) => votesApi.getState(id).then(r => r.data!);

// ----------------------------------------------------------------
// Endpoints Notifications (v1.1)
// ----------------------------------------------------------------
export const notificationsApi = {
  /**
   * Enregistre le token push de l'appareil
   */
  registerToken: (token: string, platform: 'ios' | 'android') =>
    request('notifications/token', {
      method: 'POST',
      body: JSON.stringify({ token, platform }),
    }),

  /**
   * Liste les notifications de l'utilisateur connecté
   */
  list: (page = 1) =>
    request<NotificationsResponse>(`notifications?page=${page}`),

  /**
   * Marque une notification comme lue
   */
  markRead: (id: number) =>
    request(`notifications/${id}/read`, { method: 'PUT' }),

  /**
   * Marque toutes les notifications comme lues
   */
  markAllRead: () =>
    request('notifications/read-all', { method: 'PUT' }),
};

// Exports compatibles avec NotificationsScreen / NotificationService
export const registerPushToken      = (token: string, platform: 'ios' | 'android') =>
  notificationsApi.registerToken(token, platform);
export const getNotifications       = (page = 1) =>
  notificationsApi.list(page).then(r => r.data!);
export const markNotificationRead   = (id: number) =>
  notificationsApi.markRead(id);
export const markAllNotificationsRead = () =>
  notificationsApi.markAllRead();
