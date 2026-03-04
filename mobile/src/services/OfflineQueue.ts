/**
 * Service de Queue Hors-Ligne — CCDS Citoyen v1.1
 *
 * Permet de créer des signalements sans connexion internet.
 * Les signalements sont stockés localement et synchronisés
 * automatiquement dès que le réseau est disponible.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import * as FileSystem from 'expo-file-system';
import { createIncident } from './api';

const QUEUE_KEY = 'ccds_offline_queue';

export interface OfflineIncident {
  offline_id: string;          // UUID unique généré localement
  title: string;
  description: string;
  category_id: number;
  latitude: number;
  longitude: number;
  address: string;
  photo_uri?: string;          // URI locale de la photo
  created_at: string;          // ISO date
  retry_count: number;
  status: 'pending' | 'syncing' | 'failed';
}

// ── Générer un UUID v4 simple ────────────────────────────────────────────────
const generateUUID = (): string => {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
};

// ── Lire la queue ────────────────────────────────────────────────────────────
export const getQueue = async (): Promise<OfflineIncident[]> => {
  try {
    const raw = await AsyncStorage.getItem(QUEUE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
};

// ── Sauvegarder la queue ─────────────────────────────────────────────────────
const saveQueue = async (queue: OfflineIncident[]): Promise<void> => {
  await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
};

// ── Ajouter un signalement à la queue ───────────────────────────────────────
export const addToQueue = async (
  incident: Omit<OfflineIncident, 'offline_id' | 'created_at' | 'retry_count' | 'status'>
): Promise<OfflineIncident> => {
  const queue = await getQueue();

  const newItem: OfflineIncident = {
    ...incident,
    offline_id:  generateUUID(),
    created_at:  new Date().toISOString(),
    retry_count: 0,
    status:      'pending',
  };

  queue.push(newItem);
  await saveQueue(queue);

  return newItem;
};

// ── Supprimer un élément de la queue ────────────────────────────────────────
export const removeFromQueue = async (offline_id: string): Promise<void> => {
  const queue = await getQueue();
  const filtered = queue.filter((item) => item.offline_id !== offline_id);
  await saveQueue(filtered);
};

// ── Mettre à jour le statut d'un élément ────────────────────────────────────
const updateItemStatus = async (
  offline_id: string,
  status: OfflineIncident['status'],
  incrementRetry = false
): Promise<void> => {
  const queue = await getQueue();
  const updated = queue.map((item) => {
    if (item.offline_id === offline_id) {
      return {
        ...item,
        status,
        retry_count: incrementRetry ? item.retry_count + 1 : item.retry_count,
      };
    }
    return item;
  });
  await saveQueue(updated);
};

// ── Synchroniser la queue avec le serveur ───────────────────────────────────
export const syncQueue = async (): Promise<{ synced: number; failed: number }> => {
  const netState = await NetInfo.fetch();
  if (!netState.isConnected) {
    return { synced: 0, failed: 0 };
  }

  const queue = await getQueue();
  const pending = queue.filter((item) => item.status === 'pending' && item.retry_count < 3);

  let synced = 0;
  let failed = 0;

  for (const item of pending) {
    await updateItemStatus(item.offline_id, 'syncing');

    try {
      // Préparer le FormData pour l'upload
      const formData = new FormData();
      formData.append('title',       item.title);
      formData.append('description', item.description);
      formData.append('category_id', String(item.category_id));
      formData.append('latitude',    String(item.latitude));
      formData.append('longitude',   String(item.longitude));
      formData.append('address',     item.address);
      formData.append('offline_id',  item.offline_id);

      // Attacher la photo si elle existe encore
      if (item.photo_uri) {
        const fileInfo = await FileSystem.getInfoAsync(item.photo_uri);
        if (fileInfo.exists) {
          const filename = item.photo_uri.split('/').pop() ?? 'photo.jpg';
          formData.append('photo', {
            uri:  item.photo_uri,
            name: filename,
            type: 'image/jpeg',
          } as any);
        }
      }

      await createIncident(formData);
      await removeFromQueue(item.offline_id);
      synced++;
    } catch (error) {
      await updateItemStatus(item.offline_id, 'failed', true);
      failed++;
    }
  }

  return { synced, failed };
};

// ── Écouter les changements de connectivité et synchroniser auto ─────────────
export const startOfflineSync = (): (() => void) => {
  const unsubscribe = NetInfo.addEventListener((state) => {
    if (state.isConnected) {
      syncQueue().then(({ synced }) => {
        if (synced > 0) {
          console.log(`[CCDS Offline] ${synced} signalement(s) synchronisé(s)`);
        }
      });
    }
  });

  return unsubscribe;
};

// ── Obtenir le nombre de signalements en attente ─────────────────────────────
export const getPendingCount = async (): Promise<number> => {
  const queue = await getQueue();
  return queue.filter((item) => item.status === 'pending').length;
};
