/**
 * CompanionService — Récupère le skin du companion depuis le back-office.
 *
 * L'image du companion (Awa ou autre) est configurable depuis l'atelier
 * visuel admin. Ce service interroge GET /api/config/companion au démarrage,
 * met en cache le résultat dans AsyncStorage, et fournit une URL valide
 * avec fallback sur l'asset local CHAR-05.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import { ImageSourcePropType } from 'react-native';
import { ServerConfig } from './ServerConfig';
import { GENERATED_VISUAL_SOURCES } from '../theme/generatedVisualSources';

const CACHE_KEY   = 'companion_skin_v1';
const CACHE_TTL   = __DEV__ ? 10 * 1000 : 3600 * 1000; // 10s en dev, 1h en prod

export type CompanionSkin = {
  assetId: string;
  remoteUrl: string | null;
  source: ImageSourcePropType;
  cachedAt: number;
};

const LOCAL_FALLBACK: ImageSourcePropType = GENERATED_VISUAL_SOURCES['CHAR-05'];

let _current: CompanionSkin | null = null;

/**
 * Charge le companion depuis le cache ou depuis l'API.
 * À appeler une fois au démarrage (ex: dans AuthContext ou App.tsx).
 */
export async function loadCompanionSkin(): Promise<CompanionSkin> {
  // 1. Mémoire vive
  if (_current && Date.now() - _current.cachedAt < CACHE_TTL) {
    return _current;
  }

  // 2. AsyncStorage
  try {
    const raw = await AsyncStorage.getItem(CACHE_KEY);
    if (raw) {
      const cached: CompanionSkin = JSON.parse(raw);
      if (cached && Date.now() - cached.cachedAt < CACHE_TTL) {
        _current = { ...cached, source: resolveSource(cached.assetId, cached.remoteUrl) };
        return _current;
      }
    }
  } catch (_) {}

  // 3. API réseau
  try {
    const baseUrl = await ServerConfig.getServerUrl();
    const endpoint = baseUrl.replace(/\/api\/?$/, '') + '/api/config/companion';
    const resp = await fetch(endpoint, { method: 'GET' });
    if (resp.ok) {
      const json = await resp.json();
      if (json?.success && json?.companion) {
        const skin: CompanionSkin = {
          assetId:   json.companion.asset_id ?? 'CHAR-05',
          remoteUrl: json.companion.url ?? null,
          source:    resolveSource(json.companion.asset_id, json.companion.url),
          cachedAt:  Date.now(),
        };
        await AsyncStorage.setItem(CACHE_KEY, JSON.stringify({ ...skin, source: undefined }));
        _current = skin;
        return skin;
      }
    }
  } catch (_) {}

  // 4. Fallback local
  const fallback: CompanionSkin = {
    assetId:  'CHAR-05',
    remoteUrl: null,
    source:    LOCAL_FALLBACK,
    cachedAt:  Date.now() - CACHE_TTL + 60_000, // court-circuite le TTL pour retry rapide
  };
  _current = fallback;
  return fallback;
}

/**
 * Retourne le companion actuel (sync — utiliser après loadCompanionSkin).
 */
export function getCompanionSkin(): CompanionSkin {
  return _current ?? {
    assetId:   'CHAR-05',
    remoteUrl: null,
    source:    LOCAL_FALLBACK,
    cachedAt:  0,
  };
}

/**
 * Force un rechargement réseau du companion (ex: après un changement admin).
 */
export async function refreshCompanionSkin(): Promise<CompanionSkin> {
  _current = null;
  await AsyncStorage.removeItem(CACHE_KEY);
  return loadCompanionSkin();
}

function resolveSource(assetId: string, remoteUrl: string | null): ImageSourcePropType {
  // URL distante en priorité
  if (remoteUrl && remoteUrl.startsWith('http')) {
    return { uri: remoteUrl };
  }
  // Asset local par assetId (CHAR-01..CHAR-06)
  if (assetId && GENERATED_VISUAL_SOURCES[assetId]) {
    return GENERATED_VISUAL_SOURCES[assetId];
  }
  return LOCAL_FALLBACK;
}
