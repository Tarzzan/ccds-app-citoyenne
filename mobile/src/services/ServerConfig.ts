/**
 * Ma Commune — Service de Configuration Serveur
 * Gère la persistance de l'URL du serveur API via AsyncStorage.
 * Permet à l'application de fonctionner avec n'importe quel serveur Ma Commune.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';

const SERVER_URL_KEY = 'ma_commune_server_url';
const DEFAULT_URL    = process.env.EXPO_PUBLIC_API_URL ?? 'https://votre-domaine.com/api';

export const ServerConfig = {

  /**
   * Récupère l'URL du serveur stockée.
   * Retourne l'URL par défaut si aucune n'est configurée.
   */
  async getServerUrl(): Promise<string> {
    try {
      const url = await AsyncStorage.getItem(SERVER_URL_KEY);
      return url ?? DEFAULT_URL;
    } catch {
      return DEFAULT_URL;
    }
  },

  /**
   * Enregistre l'URL du serveur de manière persistante.
   */
  async setServerUrl(url: string): Promise<void> {
    const clean = url.trim().replace(/\/$/, ''); // Supprimer le slash final
    await AsyncStorage.setItem(SERVER_URL_KEY, clean);
  },

  /**
   * Vérifie si un serveur est déjà configuré.
   * Retourne toujours true car l'URL Railway par défaut est utilisée
   * si aucune URL personnalisée n'est stockée.
   */
  async isConfigured(): Promise<boolean> {
    return true;
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
    const clean = url.trim().replace(/\/$/, '');
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
        return { success: true, message: 'Connexion réussie !' };
      } else {
        return { success: false, message: `Erreur serveur : code ${response.status}` };
      }
    } catch (err: any) {
      if (err?.name === 'AbortError') {
        return { success: false, message: 'Délai dépassé. Vérifiez l\'URL et votre connexion.' };
      }
      return { success: false, message: 'Impossible de joindre le serveur. Vérifiez l\'URL.' };
    }
  },
};
