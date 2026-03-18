/**
 * Ma Commune — Service de Configuration Serveur
 * Gère la persistance de l'URL du serveur API via AsyncStorage.
 * Permet à l'application de fonctionner avec n'importe quel serveur Ma Commune.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';

const SERVER_URL_KEY = 'ma_commune_server_url';

function getAppJsonApiUrl(): string | null {
  try {
    const appJson = require('../../app.json') as {
      expo?: { extra?: { API_BASE_URL?: unknown } };
    };
    const apiUrl = appJson.expo?.extra?.API_BASE_URL;
    return typeof apiUrl === 'string' ? apiUrl : null;
  } catch {
    return null;
  }
}

const expoConfiguredApiUrl = getAppJsonApiUrl();

export const DEFAULT_SERVER_URL = process.env.EXPO_PUBLIC_API_URL
  ?? expoConfiguredApiUrl
  ?? 'https://api.netetfix.com/api';
export const PLACEHOLDER_SERVER_URLS = new Set([
  'https://votre-domaine.com/api',
  'https://votre-domaine.com/backend',
  'https://api.netetfix.com/backend',
]);

function normalizeUrl(url: string): string {
  return url.trim().replace(/\/$/, '');
}

export function isPlaceholderServerUrl(url: string | null | undefined): boolean {
  if (!url) {
    return true;
  }

  return PLACEHOLDER_SERVER_URLS.has(normalizeUrl(url));
}

export const ServerConfig = {

  /**
   * Récupère l'URL du serveur stockée.
   * Retourne l'URL par défaut si aucune n'est configurée.
   */
  async getServerUrl(): Promise<string> {
    try {
      const url = await AsyncStorage.getItem(SERVER_URL_KEY);
      return url ?? DEFAULT_SERVER_URL;
    } catch {
      return DEFAULT_SERVER_URL;
    }
  },

  /**
   * Enregistre l'URL du serveur de manière persistante.
   */
  async setServerUrl(url: string): Promise<void> {
    const clean = normalizeUrl(url); // Supprimer le slash final
    await AsyncStorage.setItem(SERVER_URL_KEY, clean);
  },

  /**
   * Vérifie si un serveur est déjà configuré.
   * Ignore explicitement les URL placeholders de développement.
   */
  async isConfigured(): Promise<boolean> {
    try {
      const url = await AsyncStorage.getItem(SERVER_URL_KEY);
      const effectiveUrl = normalizeUrl(url ?? DEFAULT_SERVER_URL);
      return effectiveUrl.length > 0 && !isPlaceholderServerUrl(effectiveUrl);
    } catch {
      return false;
    }
  },

  /**
   * Réinitialise la configuration (pour les tests ou le changement de serveur).
   */
  async reset(): Promise<void> {
    await AsyncStorage.removeItem(SERVER_URL_KEY);
  },

  /**
   * Teste la connectivité avec un serveur donné.
   * Retourne true si le serveur répond correctement.
   */
  async testConnection(url: string): Promise<{ success: boolean; message: string }> {
    const clean = normalizeUrl(url);
    try {
      const controller = new AbortController();
      const timeout    = setTimeout(() => controller.abort(), 8000);

      const response = await fetch(`${clean}/categories`, {
        method:  'GET',
        signal:  controller.signal,
        headers: { 'Content-Type': 'application/json' },
      });

      clearTimeout(timeout);

      if (response.ok || response.status === 200) {
        return { success: true, message: 'Connexion validee. La commune est bien joignable.' };
      } else {
        return { success: false, message: `Le serveur a repondu, mais pas comme attendu (code ${response.status}).` };
      }
    } catch (err: any) {
      if (err?.name === 'AbortError') {
        return { success: false, message: 'Le serveur met trop de temps a repondre. Verifiez l URL et la connexion reseau.' };
      }
      return { success: false, message: 'Impossible de joindre ce serveur. Verifiez l adresse saisie et votre acces reseau.' };
    }
  },
};
