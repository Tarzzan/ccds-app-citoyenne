/**
 * Ma Commune — Service API centralisé v1.5
 * ─────────────────────────────────────────────────────────────────────────────
 * Fusion de api.ts + api_additions.ts (TECH-03)
 * Tous les appels HTTP vers le backend PHP sont gérés ici.
 *
 * Historique :
 *   v1.0 — Auth, Incidents, Commentaires, Catégories
 *   v1.1 — Votes "Moi aussi", Notifications Push
 *   v1.2 — Profil utilisateur, Édition signalement, Recherche/filtres
 *   v1.3 — Gamification, Carte temps réel
 *   v1.4 — Photos multiples, Commentaires threading, Partage social
 *   v1.5 — 2FA, Tableau de bord citoyen, Modération
 */

import * as SecureStore from 'expo-secure-store';
import { DEFAULT_SERVER_URL, ServerConfig }  from './ServerConfig';

// ─────────────────────────────────────────────────────────────────────────────
// Configuration
// ─────────────────────────────────────────────────────────────────────────────

export const API_BASE_URL = DEFAULT_SERVER_URL;
const TOKEN_KEY = 'ma_commune_jwt_token';
const USER_KEY = 'ma_commune_user';

export const getBaseUrl = async (): Promise<string> => ServerConfig.getServerUrl();

// ─────────────────────────────────────────────────────────────────────────────
// Gestion du token JWT
// ─────────────────────────────────────────────────────────────────────────────

export const saveToken   = (token: string)  => SecureStore.setItemAsync(TOKEN_KEY, token);
export const getToken    = ()               => SecureStore.getItemAsync(TOKEN_KEY);
export const removeToken = ()               => SecureStore.deleteItemAsync(TOKEN_KEY);
export const saveUser    = (user: User)     => SecureStore.setItemAsync(USER_KEY, JSON.stringify(user));
export const getUser     = async (): Promise<User | null> => {
  const raw = await SecureStore.getItemAsync(USER_KEY);
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as User;
  } catch {
    await SecureStore.deleteItemAsync(USER_KEY);
    return null;
  }
};
export const removeUser  = ()               => SecureStore.deleteItemAsync(USER_KEY);

// ─────────────────────────────────────────────────────────────────────────────
// Client HTTP de base
// ─────────────────────────────────────────────────────────────────────────────

interface ApiResponse<T = unknown> {
  success: boolean;
  message: string;
  data?: T;
  errors?: Record<string, string[]>;
}

async function parseApiBody<T>(response: Response, endpoint: string): Promise<ApiResponse<T>> {
  const raw = await response.text();

  if (!raw.trim()) {
    return {
      success: false,
      message: `Reponse vide recue depuis ${endpoint}.`,
    };
  }

  try {
    return JSON.parse(raw) as ApiResponse<T>;
  } catch {
    throw {
      status: response.status,
      success: false,
      message: `La reponse du serveur pour ${endpoint} n etait pas un JSON valide.`,
      raw,
    };
  }
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
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }

  const baseUrl  = await getBaseUrl();
  const response = await fetch(`${baseUrl}/${endpoint}`, { ...options, headers });
  const json     = await parseApiBody<T>(response, endpoint);

  if (!response.ok) throw { status: response.status, ...json };
  return json;
}

// ─────────────────────────────────────────────────────────────────────────────
// Types métier
// ─────────────────────────────────────────────────────────────────────────────

export interface User {
  id: number;
  email: string;
  full_name: string;
  phone?: string;
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

export interface Photo {
  id: number;
  url: string;
  file_name: string;
  sort_order: number;
  uploaded_at: string;
}

export interface StatusHistory {
  old_status: string;
  new_status: string;
  note: string;
  changed_at: string;
  changed_by: string;
}

export interface IncidentServiceContext {
  id: number | null;
  code?: string | null;
  name?: string | null;
  source?: string | null;
}

export interface IncidentPlan {
  id: number;
  status: 'draft' | 'scheduled' | 'rescheduled' | 'in_progress' | 'completed' | 'cancelled';
  service_id?: number | null;
  service_code?: string | null;
  service_name?: string | null;
  planned_by_user_id?: number | null;
  planned_by_name?: string | null;
  assigned_user_id?: number | null;
  assigned_user_name?: string | null;
  scheduled_date?: string | null;
  time_window_start?: string | null;
  time_window_end?: string | null;
  internal_note?: string | null;
  citizen_message?: string | null;
  source_type?: 'internal' | 'provider' | null;
  provider_name?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface IncidentServiceHistoryEntry {
  id: number;
  service_id?: number | null;
  service_code?: string | null;
  service_name?: string | null;
  plan_id?: number | null;
  actor_user_id?: number | null;
  actor_name?: string | null;
  event_type?: string | null;
  event_label?: string | null;
  citizen_label?: string | null;
  payload_json?: string | null;
  created_at?: string | null;
}

export interface CitizenTimelineEntry {
  type: 'status' | 'service';
  label: string;
  detail?: string | null;
  created_at?: string | null;
}

export interface Comment {
  id: number;
  comment: string;
  is_internal: boolean;
  is_edited: boolean;
  parent_id: number | null;
  replies?: Comment[];
  created_at: string;
  updated_at: string | null;
  user_id: number;
  user_name: string;
  author_name?: string;  // alias pour user_name (rétrocompatibilité)
  user_role: string;
}

function normalizeComment(raw: any): Comment {
  const replies = Array.isArray(raw?.replies) ? raw.replies.map(normalizeComment) : [];

  return {
    ...raw,
    replies,
    user_name: raw?.user_name ?? raw?.author_name ?? 'Utilisateur',
    author_name: raw?.author_name ?? raw?.user_name ?? 'Utilisateur',
    user_role: raw?.user_role ?? raw?.author_role ?? 'citizen',
  };
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
  assigned_to?: number | null;
  assigned_to_name?: string | null;
  thumbnail?: string;
  photos?: Photo[];
  status_history?: StatusHistory[];
  service?: IncidentServiceContext | null;
  service_id?: number | null;
  service_name?: string | null;
  current_plan?: IncidentPlan | null;
  service_history?: IncidentServiceHistoryEntry[];
  citizen_timeline?: CitizenTimelineEntry[];
  votes_count?: number;
  user_has_voted?: boolean;
  created_at: string;
  updated_at: string;
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

export interface Notification {
  id: number;
  type: 'status_change' | 'new_comment' | 'vote_milestone' | 'system' | 'event';
  title: string;
  body: string;
  is_read: boolean;
  sent_at: string;
  incident_id?: number;
  incident_reference?: string;
  incident_title?: string;
}

export interface NotificationsResponse {
  notifications: Notification[];
  unread_count: number;
  pagination: { total: number; page: number; total_pages: number };
}

export interface UserProfile {
  id: number;
  email: string;
  full_name: string;
  phone?: string;
  role: string;
  language: string;
  dark_mode: boolean;
  two_factor_enabled: boolean;
  created_at: string;
  notification_preferences: {
    status_change: boolean;
    new_comment: boolean;
    vote_milestone: boolean;
  };
}

export interface UserStats {
  incidents_count: number;
  resolved_count: number;
  in_progress_count: number;
  pending_count: number;
  avg_resolution_hours: number | null;
  votes_cast: number;
  comments_count: number;
  points: number;
  rank: number;
  total_users: number;
  badges: Array<{ key: string; label: string; icon: string; awarded_at: string }>;
  recent_incidents: Incident[];
  monthly_activity: Array<{ month: string; count: number }>;
}

// ─────────────────────────────────────────────────────────────────────────────
// Auth API
// ─────────────────────────────────────────────────────────────────────────────

export const authApi = {
  register: (data: { email: string; password: string; full_name: string; phone?: string }) =>
    request<AuthResponse>('register', { method: 'POST', body: JSON.stringify(data) }, false),

  login: (data: { email: string; password: string }) =>
    request<AuthResponse>('login', { method: 'POST', body: JSON.stringify(data) }, false),

  getProfile: () =>
    request<UserProfile>('profile'),

  updateProfile: (data: {
    full_name?: string;
    phone?: string;
    language?: string;
    dark_mode?: boolean;
    notification_preferences?: {
      status_change: boolean;
      new_comment: boolean;
      vote_milestone: boolean;
    };
  }) => request<{ updated: boolean }>('profile', { method: 'PUT', body: JSON.stringify(data) }),

  changePassword: (data: { current_password: string; new_password: string }) =>
    request<{ updated: boolean }>('profile/password', { method: 'PUT', body: JSON.stringify(data) }),

  // v1.5 — 2FA
  setup2FA: () =>
    request<{ secret: string; qr_code_url: string; backup_codes: string[] }>('auth/2fa/setup'),

  verify2FA: (data: { code: string }) =>
    request<{ enabled: boolean }>('auth/2fa/verify', { method: 'POST', body: JSON.stringify(data) }),

  disable2FA: (data: { password: string }) =>
    request<{ disabled: boolean }>('auth/2fa/disable', { method: 'DELETE', body: JSON.stringify(data) }),

  // v1.5 — Tableau de bord citoyen
  getStats: () =>
    request<UserStats>('profile/stats'),

  // v1.6 — Recherche d'utilisateurs pour les mentions (@)
  searchUsers: (query: string) =>
    request<User[]>(`users/search?q=${encodeURIComponent(query)}`)
      .then(r => r.data ?? []),
};

// ─────────────────────────────────────────────────────────────────────────────
// Catégories API
// ─────────────────────────────────────────────────────────────────────────────

export const categoriesApi = {
  list: () => request<Category[]>('categories', {}, false),
};

// ─────────────────────────────────────────────────────────────────────────────
// Incidents API
// ─────────────────────────────────────────────────────────────────────────────

export const incidentsApi = {
  get: (id: number) =>
    request<Incident>(`incidents/${id}`),

  list: (params?: Record<string, unknown>) => {
    const qs = new URLSearchParams();
    if (params) {
      Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
      });
    }
    const query = qs.toString() ? `?${qs.toString()}` : '';
    return request<PaginatedIncidents>(`incidents${query}`);
  },

  create: async (formData: FormData) => {
    const token   = await getToken();
    const baseUrl = await getBaseUrl();
    const response = await fetch(`${baseUrl}/incidents`, {
      method: 'POST',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      body: formData,
    });
    const json = await parseApiBody<Incident>(response, 'incidents');
    if (!response.ok) throw { status: response.status, ...json };
    return json;
  },

  edit: (id: number, data: { title?: string; description?: string; address?: string }) =>
    request<{ id: number; updated: boolean }>(
      `incidents/${id}`,
      { method: 'PATCH', body: JSON.stringify(data) }
    ),

  updateStatus: (
    id: number,
    data: {
      status: 'acknowledged' | 'in_progress' | 'resolved' | 'rejected';
      note?: string;
      priority?: 'low' | 'medium' | 'high' | 'critical';
      assigned_to?: number;
    }
  ) =>
    request<{ id: number; status: string }>(
      `incidents/${id}`,
      { method: 'PUT', body: JSON.stringify(data) }
    ),
};

// ─────────────────────────────────────────────────────────────────────────────
// Commentaires API
// ─────────────────────────────────────────────────────────────────────────────

export const commentsApi = {
  list: (incidentId: number) =>
    request<Comment[] | { comments?: Comment[] }>(`incidents/${incidentId}/comments`)
      .then((response) => {
        const payload = response.data as any;
        const comments = Array.isArray(payload)
          ? payload
          : Array.isArray(payload?.comments)
            ? payload.comments
            : [];

        return {
          ...response,
          data: comments.map(normalizeComment),
        };
      }),

  add: (incidentId: number, data: { comment: string; is_internal?: boolean; parent_id?: number }) =>
    request(`incidents/${incidentId}/comments`, { method: 'POST', body: JSON.stringify(data) }),

  // alias pour rétrocompatibilité (CommentThread utilise create/reply/update)
  create: (incidentId: number, comment: string) =>
    request(`incidents/${incidentId}/comments`, { method: 'POST', body: JSON.stringify({ comment }) }),

  reply: (incidentId: number, parentId: number, comment: string) =>
    request(`incidents/${incidentId}/comments`, { method: 'POST', body: JSON.stringify({ comment, parent_id: parentId }) }),

  update: (incidentId: number, commentId: number, comment: string) =>
    request(`incidents/${incidentId}/comments/${commentId}`, { method: 'PUT', body: JSON.stringify({ comment }) }),

  edit: (incidentId: number, commentId: number, data: { comment: string }) =>
    request(`incidents/${incidentId}/comments/${commentId}`, { method: 'PUT', body: JSON.stringify(data) }),

  delete: (incidentId: number, commentId: number) =>
    request(`incidents/${incidentId}/comments/${commentId}`, { method: 'DELETE' }),

  flag: (incidentId: number, commentId: number) =>
    request(`incidents/${incidentId}/comments/${commentId}/flag`, { method: 'POST' }),
};

// ─────────────────────────────────────────────────────────────────────────────
// Votes API
// ─────────────────────────────────────────────────────────────────────────────

export const votesApi = {
  getState: (incidentId: number) =>
    request<{ votes_count: number; user_has_voted: boolean }>(`incidents/${incidentId}/votes`),

  vote: (incidentId: number) =>
    request<{ votes_count: number; user_has_voted: boolean }>(
      `incidents/${incidentId}/vote`,
      { method: 'POST' }
    ),

  removeVote: (incidentId: number) =>
    request<{ votes_count: number; user_has_voted: boolean }>(
      `incidents/${incidentId}/vote`,
      { method: 'DELETE' }
    ),
};

// Alias rétrocompatibles
export const voteForIncident      = (id: number) => votesApi.vote(id).then(r => r.data!);
export const removeVote           = (id: number) => votesApi.removeVote(id).then(r => r.data!);
export const getVotes             = (id: number) => votesApi.getState(id).then(r => r.data!);

// ─────────────────────────────────────────────────────────────────────────────
// Notifications API
// ─────────────────────────────────────────────────────────────────────────────

export const notificationsApi = {
  registerToken: (token: string, platform: 'ios' | 'android') =>
    request('notifications/token', { method: 'POST', body: JSON.stringify({ token, platform }) }),

  list: (page = 1) =>
    request<NotificationsResponse>(`notifications?page=${page}`),

  markRead: (id: number) =>
    request(`notifications/${id}/read`, { method: 'PUT' }),

  markAllRead: () =>
    request('notifications/read-all', { method: 'PUT' }),
};

// Alias rétrocompatibles
export const registerPushToken        = (token: string, platform: 'ios' | 'android') =>
  notificationsApi.registerToken(token, platform);
export const getNotifications         = (page = 1) =>
  notificationsApi.list(page).then(r => r.data!);
export const markNotificationRead     = (id: number) => notificationsApi.markRead(id);
export const markAllNotificationsRead = () => notificationsApi.markAllRead();

// ─────────────────────────────────────────────────────────────────────────────
// Photos API (v1.4)
// ─────────────────────────────────────────────────────────────────────────────

export const photosApi = {
  list: (incidentId: number) =>
    request<Photo[]>(`incidents/${incidentId}/photos`),

  upload: async (incidentId: number, formData: FormData) => {
    const token   = await getToken();
    const baseUrl = await getBaseUrl();
    const response = await fetch(`${baseUrl}/incidents/${incidentId}/photos`, {
      method: 'POST',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      body: formData,
    });
    const json = await parseApiBody<Photo>(response, `incidents/${incidentId}/photos`);
    if (!response.ok) throw { status: response.status, ...json };
    return json;
  },

  delete: (incidentId: number, photoId: number) =>
    request(`incidents/${incidentId}/photos/${photoId}`, { method: 'DELETE' }),
};

// ─────────────────────────────────────────────────────────────────────────────
// Alias utilitaires manquants
// ─────────────────────────────────────────────────────────────────────────────

/** Alias de getToken pour la rétrocompatibilité */
export const getAuthToken = getToken;

/** Fonction générique d'appel API exposée pour les services externes */
export const apiRequest = request;

// ─────────────────────────────────────────────────────────────────────────────
// Types Sondages & Événements (v1.6)
// ─────────────────────────────────────────────────────────────────────────────

export interface PollOption {
  id: number;
  text: string;
  votes_count: number;
}

export interface Poll {
  id: number;
  title: string;
  description: string;
  type: 'single';
  status: 'active' | 'closed';
  ends_at: string;
  total_votes: number;
  user_vote_id: number | null;
  options: PollOption[];
  created_at: string;
}

export interface Event {
  id: number;
  title: string;
  description: string;
  location: string;
  starts_at: string;
  ends_at: string;
  organizer: string;
  attendees_count: number;
  interested_count: number;
  user_rsvp: 'attending' | 'interested' | null;
  created_at: string;
}

function normalizePoll(raw: any): Poll {
  return {
    ...raw,
    type: 'single',
    options: Array.isArray(raw?.options) ? raw.options : [],
    total_votes: raw?.total_votes ?? 0,
    user_vote_id: raw?.user_vote_id ?? null,
  };
}

function normalizeEvent(raw: any): Event {
  const startsAt = raw?.starts_at ?? raw?.event_date ?? raw?.startsAt ?? null;
  const organizer = raw?.organizer ?? raw?.created_by_name ?? 'Commune';

  return {
    id: raw?.id,
    title: raw?.title ?? 'Événement communal',
    description: raw?.description ?? '',
    location: raw?.location ?? '',
    starts_at: startsAt,
    ends_at: raw?.ends_at ?? startsAt,
    organizer,
    attendees_count: raw?.attendees_count ?? 0,
    interested_count: raw?.interested_count ?? 0,
    user_rsvp: raw?.user_rsvp ?? null,
    created_at: raw?.created_at,
  };
}

// ─────────────────────────────────────────────────────────────────────────────
// Sondages API (v1.6 — UX-10)
// ─────────────────────────────────────────────────────────────────────────────

export const pollsApi = {
  list: () =>
    request<Poll[]>('polls').then((response) => ({
      ...response,
      data: Array.isArray(response.data) ? response.data.map(normalizePoll) : [],
    })),
  get: (id: number) =>
    request<Poll>(`polls/${id}`).then((response) => ({
      ...response,
      data: response.data ? normalizePoll(response.data) : response.data,
    })),
  vote: (pollId: number, optionId: number) =>
    request(`polls/${pollId}/vote`, { method: 'POST', body: JSON.stringify({ option_id: optionId }) }),
};

// ─────────────────────────────────────────────────────────────────────────────
// Événements API (v1.6 — UX-12)
// ─────────────────────────────────────────────────────────────────────────────

export const eventsApi = {
  list: () =>
    request<Event[]>('events').then((response) => ({
      ...response,
      data: Array.isArray(response.data) ? response.data.map(normalizeEvent) : [],
    })),
  get: (id: number) =>
    request<Event>(`events/${id}`).then((response) => ({
      ...response,
      data: response.data ? normalizeEvent(response.data) : response.data,
    })),
  rsvp: (eventId: number, status: 'attending' | 'interested' | null) =>
    request(`events/${eventId}/rsvp`, {
      method: 'POST',
      body: JSON.stringify({ status: status ?? 'not_attending' }),
    }),
};
