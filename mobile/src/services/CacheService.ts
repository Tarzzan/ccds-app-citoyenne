/**
 * CCDS v1.3 — CacheService (PERF-01)
 * Cache mémoire + AsyncStorage pour les données fréquemment consultées.
 * Stratégie : stale-while-revalidate avec TTL configurable.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';

interface CacheEntry<T> {
  data: T;
  timestamp: number;
  ttl: number; // en secondes
}

class CacheService {
  private static instance: CacheService;
  private memoryCache = new Map<string, CacheEntry<unknown>>();

  static getInstance(): CacheService {
    if (!CacheService.instance) {
      CacheService.instance = new CacheService();
    }
    return CacheService.instance;
  }

  /**
   * Lire depuis le cache (mémoire d'abord, puis AsyncStorage)
   */
  async get<T>(key: string): Promise<T | null> {
    // 1. Vérifier le cache mémoire
    const memEntry = this.memoryCache.get(key) as CacheEntry<T> | undefined;
    if (memEntry && this.isValid(memEntry)) {
      return memEntry.data;
    }

    // 2. Vérifier AsyncStorage
    try {
      const raw = await AsyncStorage.getItem(`@ccds_cache_${key}`);
      if (raw) {
        const entry: CacheEntry<T> = JSON.parse(raw);
        if (this.isValid(entry)) {
          // Remettre en mémoire pour les prochains accès
          this.memoryCache.set(key, entry as CacheEntry<unknown>);
          return entry.data;
        }
        // Entrée expirée — nettoyer
        await AsyncStorage.removeItem(`@ccds_cache_${key}`);
      }
    } catch {
      // Ignorer les erreurs de cache
    }

    return null;
  }

  /**
   * Écrire dans le cache (mémoire + AsyncStorage)
   */
  async set<T>(key: string, data: T, ttlSeconds = 300): Promise<void> {
    const entry: CacheEntry<T> = {
      data,
      timestamp: Date.now(),
      ttl: ttlSeconds,
    };

    // Cache mémoire
    this.memoryCache.set(key, entry as CacheEntry<unknown>);

    // AsyncStorage (pour la persistance entre sessions)
    try {
      await AsyncStorage.setItem(`@ccds_cache_${key}`, JSON.stringify(entry));
    } catch {
      // Ignorer les erreurs d'écriture
    }
  }

  /**
   * Invalider une clé spécifique
   */
  async invalidate(key: string): Promise<void> {
    this.memoryCache.delete(key);
    try {
      await AsyncStorage.removeItem(`@ccds_cache_${key}`);
    } catch {
      // Ignorer
    }
  }

  /**
   * Invalider toutes les clés commençant par un préfixe
   */
  async invalidatePattern(prefix: string): Promise<void> {
    // Cache mémoire
    for (const key of this.memoryCache.keys()) {
      if (key.startsWith(prefix)) {
        this.memoryCache.delete(key);
      }
    }

    // AsyncStorage
    try {
      const allKeys = await AsyncStorage.getAllKeys();
      const toDelete = allKeys.filter(k => k.startsWith(`@ccds_cache_${prefix}`));
      if (toDelete.length > 0) {
        await AsyncStorage.multiRemove(toDelete);
      }
    } catch {
      // Ignorer
    }
  }

  /**
   * Vider tout le cache
   */
  async clear(): Promise<void> {
    this.memoryCache.clear();
    try {
      const allKeys = await AsyncStorage.getAllKeys();
      const cacheKeys = allKeys.filter(k => k.startsWith('@ccds_cache_'));
      if (cacheKeys.length > 0) {
        await AsyncStorage.multiRemove(cacheKeys);
      }
    } catch {
      // Ignorer
    }
  }

  private isValid<T>(entry: CacheEntry<T>): boolean {
    return Date.now() - entry.timestamp < entry.ttl * 1000;
  }
}

export const cache = CacheService.getInstance();

// ----------------------------------------------------------------
// Hook utilitaire : useCachedData
// ----------------------------------------------------------------
import { useState, useEffect, useCallback } from 'react';

interface UseCachedDataOptions {
  ttl?: number;       // TTL en secondes (défaut : 300)
  staleWhileRevalidate?: boolean; // Afficher les données périmées pendant le rechargement
}

export function useCachedData<T>(
  cacheKey: string,
  fetcher: () => Promise<T>,
  options: UseCachedDataOptions = {}
) {
  const { ttl = 300, staleWhileRevalidate = true } = options;
  const [data, setData]       = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState<string | null>(null);

  const load = useCallback(async (forceRefresh = false) => {
    try {
      // Essayer le cache d'abord
      if (!forceRefresh) {
        const cached = await cache.get<T>(cacheKey);
        if (cached !== null) {
          setData(cached);
          if (!staleWhileRevalidate) {
            setLoading(false);
            return;
          }
        }
      }

      // Charger depuis l'API
      const fresh = await fetcher();
      await cache.set(cacheKey, fresh, ttl);
      setData(fresh);
      setError(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Erreur de chargement');
    } finally {
      setLoading(false);
    }
  }, [cacheKey, fetcher, ttl, staleWhileRevalidate]);

  useEffect(() => {
    load();
  }, [load]);

  const refresh = useCallback(() => {
    setLoading(true);
    return load(true);
  }, [load]);

  const invalidate = useCallback(() => cache.invalidate(cacheKey), [cacheKey]);

  return { data, loading, error, refresh, invalidate };
}
