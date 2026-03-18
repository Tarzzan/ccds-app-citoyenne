/**
 * Ma Commune — Service i18n
 * Phase actuelle : français uniquement.
 * Le support multi-langue reviendra plus tard sur une base déjà stabilisée.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useState, useEffect, useCallback } from 'react';

import fr from './fr.json';

const LANGUAGE_KEY = '@ma_commune_language';
const LEGACY_LANGUAGE_KEY = '@ccds_language';

// ----------------------------------------------------------------
// Types
// ----------------------------------------------------------------

export type Language = 'fr';

type TranslationDict = Record<string, unknown>;

// ----------------------------------------------------------------
// Catalogue des langues disponibles
// ----------------------------------------------------------------

export const LANGUAGES: { code: Language; label: string; nativeLabel: string; flag: string }[] = [
  { code: 'fr', label: 'Français', nativeLabel: 'Français', flag: '🇫🇷' },
];

const translations: Record<Language, TranslationDict> = {
  fr: fr as TranslationDict,
};

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

function getNestedValue(obj: TranslationDict, path: string): string | undefined {
  const keys = path.split('.');
  let current: unknown = obj;
  for (const key of keys) {
    if (current === null || typeof current !== 'object') return undefined;
    current = (current as TranslationDict)[key];
  }
  return typeof current === 'string' ? current : undefined;
}

function interpolate(str: string, vars?: Record<string, string | number>): string {
  if (!vars) return str;
  return str.replace(/\{\{(\w+)\}\}/g, (_, key) =>
    vars[key] !== undefined ? String(vars[key]) : `{{${key}}}`
  );
}

// ----------------------------------------------------------------
// Classe I18nService (singleton)
// ----------------------------------------------------------------

class I18nService {
  private static instance: I18nService;
  private currentLang: Language = 'fr';
  private listeners: Set<() => void> = new Set();

  static getInstance(): I18nService {
    if (!I18nService.instance) {
      I18nService.instance = new I18nService();
    }
    return I18nService.instance;
  }

  async init(): Promise<void> {
    try {
      const saved = await AsyncStorage.getItem(LANGUAGE_KEY)
        ?? await AsyncStorage.getItem(LEGACY_LANGUAGE_KEY);
      if (saved === 'fr') {
        this.currentLang = saved;
        await AsyncStorage.setItem(LANGUAGE_KEY, saved);
        await AsyncStorage.removeItem(LEGACY_LANGUAGE_KEY);
      }
    } catch {
      // Utiliser la langue par défaut
    }
  }

  async setLanguage(lang: Language): Promise<void> {
    this.currentLang = 'fr';
    try {
      await AsyncStorage.setItem(LANGUAGE_KEY, 'fr');
      await AsyncStorage.removeItem(LEGACY_LANGUAGE_KEY);
    } catch {
      // Ignorer
    }
    this.listeners.forEach(cb => cb());
  }

  getLanguage(): Language {
    return this.currentLang;
  }

  /**
   * Traduit une clé avec interpolation optionnelle.
   * Retourne la clé elle-même si introuvable.
   */
  t(key: string, vars?: Record<string, string | number>): string {
    let value = getNestedValue(translations[this.currentLang], key);

    if (value === undefined) {
      if (__DEV__) console.warn(`[i18n] Clé manquante : "${key}"`);
      return key;
    }

    return interpolate(value, vars);
  }

  subscribe(callback: () => void): () => void {
    this.listeners.add(callback);
    return () => this.listeners.delete(callback);
  }
}

export const i18n = I18nService.getInstance();

// ----------------------------------------------------------------
// Fonction t() globale (rétrocompatibilité avec v1.2)
// ----------------------------------------------------------------

export function t(key: string, vars?: Record<string, string | number>): string {
  return i18n.t(key, vars);
}

// ----------------------------------------------------------------
// Hook React : useTranslation
// ----------------------------------------------------------------

export function useTranslation() {
  const [, forceUpdate] = useState(0);

  useEffect(() => {
    const unsubscribe = i18n.subscribe(() => forceUpdate(n => n + 1));
    return unsubscribe;
  }, []);

  const translate = useCallback(
    (key: string, params?: Record<string, string | number>) => i18n.t(key, params),
    []
  );

  const setLanguage = useCallback(
    (lang: Language) => i18n.setLanguage(lang),
    []
  );

  return {
    t: translate,
    language: i18n.getLanguage(),
    setLanguage,
    languages: LANGUAGES,
  };
}

// ----------------------------------------------------------------
// Raccourcis pratiques (rétrocompatibilité)
// ----------------------------------------------------------------

export const STATUS_LABELS: Record<string, string> = {
  submitted:    t('incidents.status_submitted'),
  in_progress:  t('incidents.status_in_progress'),
  resolved:     t('incidents.status_resolved'),
  rejected:     t('incidents.status_rejected'),
};

export const PRIORITY_LABELS: Record<string, string> = {
  low:      'Faible',
  medium:   'Moyen',
  high:     'Élevé',
  critical: 'Critique',
};

export default { t, i18n, useTranslation, STATUS_LABELS, PRIORITY_LABELS };
