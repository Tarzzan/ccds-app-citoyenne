/**
 * CCDS v1.3 — Service i18n (I18N-01 + I18N-02)
 * Support multi-langue : Français (fr) + Créole guyanais (cr)
 * Fonctions : t() avec interpolation, changement de langue, persistance.
 */
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useState, useEffect, useCallback } from 'react';

import fr from './fr.json';
import cr from './cr.json';

// ----------------------------------------------------------------
// Types
// ----------------------------------------------------------------

export type Language = 'fr' | 'cr';

type TranslationDict = Record<string, unknown>;

// ----------------------------------------------------------------
// Catalogue des langues disponibles
// ----------------------------------------------------------------

export const LANGUAGES: { code: Language; label: string; nativeLabel: string; flag: string }[] = [
  { code: 'fr', label: 'Français',        nativeLabel: 'Français',          flag: '🇫🇷' },
  { code: 'cr', label: 'Créole guyanais', nativeLabel: 'Kréyòl Gwiyannais', flag: '🇬🇫' },
];

const translations: Record<Language, TranslationDict> = {
  fr: fr as TranslationDict,
  cr: cr as TranslationDict,
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
      const saved = await AsyncStorage.getItem('@ccds_language');
      if (saved === 'fr' || saved === 'cr') {
        this.currentLang = saved;
      }
    } catch {
      // Utiliser la langue par défaut
    }
  }

  async setLanguage(lang: Language): Promise<void> {
    this.currentLang = lang;
    try {
      await AsyncStorage.setItem('@ccds_language', lang);
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
   * Fallback vers le français si la clé n'existe pas dans la langue courante.
   * Retourne la clé elle-même si introuvable même en français.
   */
  t(key: string, vars?: Record<string, string | number>): string {
    // Essayer la langue courante
    let value = getNestedValue(translations[this.currentLang], key);

    // Fallback vers le français
    if (value === undefined && this.currentLang !== 'fr') {
      value = getNestedValue(translations['fr'], key);
    }

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
