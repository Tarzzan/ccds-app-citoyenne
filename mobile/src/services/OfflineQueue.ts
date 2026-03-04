/**
 * Service de Queue Hors-Ligne — CCDS Citoyen v1.1
 *
 * Permet de créer des signalements sans connexion internet.
 * Les signalements sont stockés localement (AsyncStorage) et synchronisés
 * automatiquement dès que le réseau est disponible.
 *
 * Interface en objet singleton pour faciliter l'utilisation dans les composants.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo, { NetInfoState } from '@react-native-community/netinfo';
import { incidentsApi } from './api';

const QUEUE_KEY = 'ccds_offline_queue';
const MAX_RETRIES = 3;

// ── Types ────────────────────────────────────────────────────────────────────

export interface OfflineIncidentData {
  category_id: number;
  description: string;
  latitude: number;
  longitude: number;
  title?: string;
  address?: string;
  photoUri?: string;
  photoType?: string;
  photoName?: string;
}

interface QueueItem extends OfflineIncidentData {
  offline_id:  string;
  created_at:  string;
  retry_count: number;
  status:      'pending' | 'syncing' | 'failed';
}

type ConnectivityListener = (isConnected: boolean) => void;
type QueueChangeListener  = (count: number) => void;

// ── Utilitaires ──────────────────────────────────────────────────────────────

const generateId = (): string =>
  'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });

// ── Singleton OfflineQueue ───────────────────────────────────────────────────

class OfflineQueueService {
  private connectivityListeners: ConnectivityListener[] = [];
  private queueListeners:        QueueChangeListener[]  = [];
  private netInfoUnsubscribe:    (() => void) | null     = null;
  private isConnected = true;

  constructor() {
    this.startListening();
  }

  // ── Démarrer l'écoute réseau ─────────────────────────────────────────────

  private startListening(): void {
    this.netInfoUnsubscribe = NetInfo.addEventListener((state: NetInfoState) => {
      const connected = state.isConnected ?? false;
      const wasConnected = this.isConnected;
      this.isConnected = connected;

      // Notifier les listeners de connectivité
      this.connectivityListeners.forEach((fn) => fn(connected));

      // Synchroniser automatiquement à la reconnexion
      if (!wasConnected && connected) {
        this.sync().then(({ synced }) => {
          if (synced > 0) {
            console.log(`[CCDS Offline] ${synced} signalement(s) synchronisé(s)`);
          }
        });
      }
    });
  }

  // ── Lecture / écriture de la queue ──────────────────────────────────────

  private async readQueue(): Promise<QueueItem[]> {
    try {
      const raw = await AsyncStorage.getItem(QUEUE_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch {
      return [];
    }
  }

  private async writeQueue(queue: QueueItem[]): Promise<void> {
    await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    const pending = queue.filter((i) => i.status === 'pending').length;
    this.queueListeners.forEach((fn) => fn(pending));
  }

  // ── API publique ─────────────────────────────────────────────────────────

  /**
   * Ajoute un signalement à la queue hors-ligne.
   */
  async addToQueue(data: OfflineIncidentData): Promise<void> {
    const queue = await this.readQueue();
    const item: QueueItem = {
      ...data,
      offline_id:  generateId(),
      created_at:  new Date().toISOString(),
      retry_count: 0,
      status:      'pending',
    };
    queue.push(item);
    await this.writeQueue(queue);
  }

  /**
   * Retourne le nombre de signalements en attente.
   */
  async getPendingCount(): Promise<number> {
    const queue = await this.readQueue();
    return queue.filter((i) => i.status === 'pending').length;
  }

  /**
   * Retourne tous les éléments de la queue.
   */
  async getAll(): Promise<QueueItem[]> {
    return this.readQueue();
  }

  /**
   * Synchronise les signalements en attente avec le serveur.
   */
  async sync(): Promise<{ synced: number; failed: number }> {
    const netState = await NetInfo.fetch();
    if (!netState.isConnected) return { synced: 0, failed: 0 };

    const queue   = await this.readQueue();
    const pending = queue.filter((i) => i.status === 'pending' && i.retry_count < MAX_RETRIES);

    let synced = 0;
    let failed = 0;

    for (const item of pending) {
      // Marquer comme en cours
      const updatedQueue = await this.readQueue();
      const idx = updatedQueue.findIndex((i) => i.offline_id === item.offline_id);
      if (idx !== -1) {
        updatedQueue[idx].status = 'syncing';
        await this.writeQueue(updatedQueue);
      }

      try {
        const formData = new FormData();
        formData.append('category_id',  String(item.category_id));
        formData.append('description',  item.description);
        formData.append('latitude',     String(item.latitude));
        formData.append('longitude',    String(item.longitude));
        formData.append('offline_id',   item.offline_id);
        if (item.title)   formData.append('title',   item.title);
        if (item.address) formData.append('address', item.address);
        if (item.photoUri) {
          formData.append('photo', {
            uri:  item.photoUri,
            type: item.photoType ?? 'image/jpeg',
            name: item.photoName ?? 'photo.jpg',
          } as any);
        }

        await incidentsApi.create(formData);

        // Supprimer de la queue après succès
        const afterSync = await this.readQueue();
        await this.writeQueue(afterSync.filter((i) => i.offline_id !== item.offline_id));
        synced++;
      } catch {
        // Incrémenter le compteur de tentatives
        const afterFail = await this.readQueue();
        const failIdx = afterFail.findIndex((i) => i.offline_id === item.offline_id);
        if (failIdx !== -1) {
          afterFail[failIdx].status      = 'failed';
          afterFail[failIdx].retry_count += 1;
          await this.writeQueue(afterFail);
        }
        failed++;
      }
    }

    return { synced, failed };
  }

  /**
   * Écoute les changements de connectivité.
   * Retourne une fonction de désabonnement.
   */
  onConnectivityChange(fn: ConnectivityListener): () => void {
    this.connectivityListeners.push(fn);
    return () => {
      this.connectivityListeners = this.connectivityListeners.filter((l) => l !== fn);
    };
  }

  /**
   * Écoute les changements de la queue (nombre d'éléments en attente).
   * Retourne une fonction de désabonnement.
   */
  onQueueChange(fn: QueueChangeListener): () => void {
    this.queueListeners.push(fn);
    return () => {
      this.queueListeners = this.queueListeners.filter((l) => l !== fn);
    };
  }

  /**
   * Retourne l'état de connectivité actuel.
   */
  getConnectionState(): boolean {
    return this.isConnected;
  }

  /**
   * Arrête l'écoute réseau (à appeler lors du démontage de l'app).
   */
  destroy(): void {
    this.netInfoUnsubscribe?.();
  }
}

// Export du singleton
export const OfflineQueue = new OfflineQueueService();
