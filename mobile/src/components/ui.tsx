/**
 * CCDS — Composants UI réutilisables
 */

import React from 'react';
import {
  TouchableOpacity, Text, TextInput, View, ActivityIndicator,
  StyleSheet, TextInputProps, ViewStyle, TextStyle,
} from 'react-native';

// ----------------------------------------------------------------
// Palette de couleurs CCDS
// ----------------------------------------------------------------
export const COLORS = {
  primary:      '#1a7a42',   // Vert forêt CCDS Guyane
  primaryDark:  '#0f4c2a',   // Vert profond Amazonie
  primaryLight: '#dcfce7',   // Vert clair
  secondary:    '#22c55e',   // Vert secondaire
  success:      '#22c55e',
  warning:      '#f59e0b',
  danger:       '#ef4444',
  gray:         '#6b7280',
  lightGray:    '#f3f4f6',
  white:        '#ffffff',
  dark:         '#111827',
  border:       '#e5e7eb',
};

// Couleurs par statut de signalement
export const STATUS_COLORS: Record<string, string> = {
  submitted:    '#6b7280',
  acknowledged: '#1a7a42',
  in_progress:  '#f59e0b',
  resolved:     '#22c55e',
  rejected:     '#ef4444',
};

export const STATUS_LABELS: Record<string, string> = {
  submitted:    'Soumis',
  acknowledged: 'Pris en charge',
  in_progress:  'En cours',
  resolved:     'Résolu',
  rejected:     'Rejeté',
};

// ----------------------------------------------------------------
// Bouton principal
// ----------------------------------------------------------------
interface ButtonProps {
  title: string;
  onPress: () => void;
  loading?: boolean;
  disabled?: boolean;
  variant?: 'primary' | 'secondary' | 'danger' | 'outline';
  style?: ViewStyle;
}

export function Button({ title, onPress, loading, disabled, variant = 'primary', style }: ButtonProps) {
  const bg = {
    primary:   COLORS.primary,
    secondary: COLORS.secondary,
    danger:    COLORS.danger,
    outline:   'transparent',
  }[variant];

  const textColor = variant === 'outline' ? COLORS.primary : COLORS.white;
  const borderColor = variant === 'outline' ? COLORS.primary : 'transparent';

  return (
    <TouchableOpacity
      style={[styles.btn, { backgroundColor: bg, borderColor, borderWidth: variant === 'outline' ? 2 : 0, opacity: disabled || loading ? 0.6 : 1 }, style]}
      onPress={onPress}
      disabled={disabled || loading}
      activeOpacity={0.8}
    >
      {loading
        ? <ActivityIndicator color={textColor} />
        : <Text style={[styles.btnText, { color: textColor }]}>{title}</Text>
      }
    </TouchableOpacity>
  );
}

// ----------------------------------------------------------------
// Champ de saisie
// ----------------------------------------------------------------
interface InputProps extends TextInputProps {
  label?: string;
  error?: string;
  containerStyle?: ViewStyle;
}

export function Input({ label, error, containerStyle, ...props }: InputProps) {
  return (
    <View style={[{ marginBottom: 16 }, containerStyle]}>
      {label && <Text style={styles.label}>{label}</Text>}
      <TextInput
        style={[styles.input, error ? { borderColor: COLORS.danger } : {}]}
        placeholderTextColor={COLORS.gray}
        {...props}
      />
      {error && <Text style={styles.errorText}>{error}</Text>}
    </View>
  );
}

// ----------------------------------------------------------------
// Badge de statut
// ----------------------------------------------------------------
export function StatusBadge({ status }: { status: string }) {
  const color = STATUS_COLORS[status] ?? COLORS.gray;
  const label = STATUS_LABELS[status] ?? status;
  return (
    <View style={[styles.badge, { backgroundColor: color + '22', borderColor: color }]}>
      <Text style={[styles.badgeText, { color }]}>{label}</Text>
    </View>
  );
}

// ----------------------------------------------------------------
// Carte de signalement (pour les listes)
// ----------------------------------------------------------------
interface IncidentCardProps {
  reference: string;
  title?: string;
  description: string;
  status: string;
  categoryName: string;
  categoryColor: string;
  date: string;
  onPress: () => void;
}

export function IncidentCard({
  reference, title, description, status, categoryName, categoryColor, date, onPress,
}: IncidentCardProps) {
  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.85}>
      <View style={styles.cardHeader}>
        <View style={[styles.categoryDot, { backgroundColor: categoryColor }]} />
        <Text style={styles.categoryLabel}>{categoryName}</Text>
        <StatusBadge status={status} />
      </View>
      <Text style={styles.cardRef}>{reference}</Text>
      {title ? <Text style={styles.cardTitle}>{title}</Text> : null}
      <Text style={styles.cardDesc} numberOfLines={2}>{description}</Text>
      <Text style={styles.cardDate}>{new Date(date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })}</Text>
    </TouchableOpacity>
  );
}

// ----------------------------------------------------------------
// Styles partagés
// ----------------------------------------------------------------
const styles = StyleSheet.create({
  btn: {
    paddingVertical: 14,
    paddingHorizontal: 24,
    borderRadius: 12,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 50,
  },
  btnText: {
    fontSize: 16,
    fontWeight: '600',
  },
  label: {
    fontSize: 14,
    fontWeight: '600',
    color: COLORS.dark,
    marginBottom: 6,
  },
  input: {
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
    color: COLORS.dark,
    backgroundColor: COLORS.white,
  },
  errorText: {
    color: COLORS.danger,
    fontSize: 12,
    marginTop: 4,
  },
  badge: {
    paddingHorizontal: 10,
    paddingVertical: 3,
    borderRadius: 20,
    borderWidth: 1,
  },
  badgeText: {
    fontSize: 12,
    fontWeight: '600',
  },
  card: {
    backgroundColor: COLORS.white,
    borderRadius: 14,
    padding: 16,
    marginBottom: 12,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.06,
    shadowRadius: 8,
    elevation: 3,
  },
  cardHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 8,
    gap: 8,
  },
  categoryDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
  },
  categoryLabel: {
    flex: 1,
    fontSize: 13,
    color: COLORS.gray,
    fontWeight: '500',
  },
  cardRef: {
    fontSize: 12,
    color: COLORS.gray,
    marginBottom: 4,
    fontFamily: 'monospace',
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.dark,
    marginBottom: 4,
  },
  cardDesc: {
    fontSize: 14,
    color: '#374151',
    lineHeight: 20,
    marginBottom: 8,
  },
  cardDate: {
    fontSize: 12,
    color: COLORS.gray,
  },
});
