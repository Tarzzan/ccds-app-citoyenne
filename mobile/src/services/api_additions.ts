/**
 * Additions API v1.1 — CCDS Citoyen
 * Nouveaux endpoints : Votes, Notifications Push
 * À fusionner dans api.ts
 */

import { getBaseUrl } from './ServerConfig';

const request = async (endpoint: string, options: RequestInit = {}) => {
  const baseUrl = await getBaseUrl();
  const response = await fetch(`${baseUrl}${endpoint}`, {
    headers: { 'Content-Type': 'application/json', ...options.headers },
    ...options,
  });
  if (!response.ok) {
    const error = await response.json().catch(() => ({ error: 'Erreur réseau' }));
    throw new Error(error.error || `HTTP ${response.status}`);
  }
  return response.json();
};

// ── Votes "Moi aussi" ────────────────────────────────────────────────────────

export const getVotes = (incidentId: number) =>
  request(`/incidents/${incidentId}/votes`);

export const voteForIncident = (incidentId: number) =>
  request(`/incidents/${incidentId}/vote`, { method: 'POST' });

export const removeVote = (incidentId: number) =>
  request(`/incidents/${incidentId}/vote`, { method: 'DELETE' });

// ── Notifications Push ───────────────────────────────────────────────────────

export const registerPushToken = (token: string, platform: 'ios' | 'android') =>
  request('/notifications/token', {
    method: 'POST',
    body: JSON.stringify({ token, platform }),
  });

export const getNotifications = (page = 1) =>
  request(`/notifications?page=${page}`);

export const markNotificationRead = (id: number) =>
  request(`/notifications/${id}/read`, { method: 'PUT' });

export const markAllNotificationsRead = () =>
  request('/notifications/read-all', { method: 'PUT' });
