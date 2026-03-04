/**
 * CCDS v1.2 — Service d'internationalisation (I18N-01)
 *
 * Usage :
 *   import { t } from '../i18n/i18n';
 *   t('auth.login')                          → "Connexion"
 *   t('incidents.vote_count', { count: 5 })  → "5 personne(s) concernée(s)"
 */

import fr from './fr.json';

type TranslationValue = string | Record<string, unknown>;

// Récupère une valeur imbriquée par chemin pointé (ex: 'auth.errors.email_taken')
function getNestedValue(obj: Record<string, unknown>, path: string): string | undefined {
  const keys = path.split('.');
  let current: unknown = obj;

  for (const key of keys) {
    if (current === null || typeof current !== 'object') return undefined;
    current = (current as Record<string, unknown>)[key];
  }

  return typeof current === 'string' ? current : undefined;
}

// Interpole les variables {{key}} dans une chaîne
function interpolate(str: string, vars?: Record<string, string | number>): string {
  if (!vars) return str;
  return str.replace(/\{\{(\w+)\}\}/g, (_, key) => {
    return vars[key] !== undefined ? String(vars[key]) : `{{${key}}}`;
  });
}

/**
 * Traduit une clé avec interpolation optionnelle.
 * Retourne la clé elle-même si la traduction est introuvable.
 */
export function t(key: string, vars?: Record<string, string | number>): string {
  const value = getNestedValue(fr as Record<string, unknown>, key);
  if (value === undefined) {
    if (__DEV__) console.warn(`[i18n] Clé manquante : "${key}"`);
    return key;
  }
  return interpolate(value, vars);
}

// Raccourcis pratiques pour les clés fréquentes
export const STATUS_LABELS: Record<string, string> = {
  submitted:    t('status.submitted'),
  acknowledged: t('status.acknowledged'),
  in_progress:  t('status.in_progress'),
  resolved:     t('status.resolved'),
  rejected:     t('status.rejected'),
};

export const PRIORITY_LABELS: Record<string, string> = {
  low:      t('priority.low'),
  medium:   t('priority.medium'),
  high:     t('priority.high'),
  critical: t('priority.critical'),
};

export default { t, STATUS_LABELS, PRIORITY_LABELS };
