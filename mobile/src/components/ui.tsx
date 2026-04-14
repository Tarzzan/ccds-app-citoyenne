import React, { useState } from 'react';
import {
  TouchableOpacity, Text, TextInput, View, ActivityIndicator,
  StyleSheet, TextInputProps, ViewStyle, TextStyle,
} from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { CategoryMark } from './CategoryMark';

// ----------------------------------------------------------------
// Palette de couleurs Ma Commune
// ----------------------------------------------------------------
export const COLORS = {
  primary:       BRAND.colors.canopy,
  primaryDark:   BRAND.colors.canopyDeep,
  primaryLight:  '#DCEBDD',
  secondary:     BRAND.colors.river,
  success:       BRAND.colors.success,
  warning:       BRAND.colors.warning,
  danger:        BRAND.colors.danger,
  gray:          BRAND.colors.slate,
  lightGray:     '#F1ECE0',
  white:         BRAND.colors.white,
  dark:          BRAND.colors.ink,
  border:        BRAND.colors.border,
  light:         BRAND.colors.cloud,
  textSecondary: BRAND.colors.slate,
};

// Couleurs par statut de signalement
export const STATUS_COLORS: Record<string, string> = {
  submitted:    BRAND.status.submitted,
  acknowledged: BRAND.status.acknowledged,
  in_progress:  BRAND.status.in_progress,
  resolved:     BRAND.status.resolved,
  rejected:     BRAND.status.rejected,
};

export const STATUS_LABELS: Record<string, string> = {
  submitted:    'Soumis',
  acknowledged: 'Pris en charge',
  in_progress:  'En cours',
  resolved:     'Résolu',
  rejected:     'Rejeté',
};

export const PRIORITY_COLORS: Record<string, string> = {
  low: '#5E6C67',
  medium: '#D48B2C',
  high: '#A64B2A',
  critical: '#C94B3C',
};

export const PRIORITY_LABELS: Record<string, string> = {
  low: 'Faible',
  medium: 'Normale',
  high: 'Haute',
  critical: 'Critique',
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
// Champ de saisie — avec toggle "afficher le mot de passe"
// ----------------------------------------------------------------
interface InputProps extends TextInputProps {
  label?: string;
  error?: string;
  containerStyle?: ViewStyle;
}

export function Input({ label, error, containerStyle, secureTextEntry, ...props }: InputProps) {
  const [hidePassword, setHidePassword] = useState(true);
  const isPassword = secureTextEntry === true;

  return (
    <View style={[{ marginBottom: 16 }, containerStyle]}>
      {label && <Text style={styles.label}>{label}</Text>}
      <View style={{ position: 'relative' }}>
        <TextInput
          style={[styles.input, error ? { borderColor: COLORS.danger } : {}, isPassword ? { paddingRight: 48 } : {}]}
          placeholderTextColor={COLORS.gray}
          secureTextEntry={isPassword ? hidePassword : false}
          {...props}
        />
        {isPassword && (
          <TouchableOpacity
            style={styles.eyeButton}
            onPress={() => setHidePassword(!hidePassword)}
            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
          >
            <Text style={styles.eyeIcon}>{hidePassword ? '👁' : '🙈'}</Text>
          </TouchableOpacity>
        )}
      </View>
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
  categoryIcon?: string;
  categoryColor: string;
  date: string;
  priority?: string;
  assignedToName?: string | null;
  serviceName?: string | null;
  planStateLabel?: string | null;
  planStateVariant?: 'blue' | 'green' | 'yellow' | 'gray';
  planSummary?: string | null;
  planCitizenMessage?: string | null;
  address?: string;
  onPress: () => void;
}

export function IncidentCard({
  reference, title, description, status, categoryName, categoryIcon, categoryColor, date, priority, assignedToName, serviceName, planStateLabel, planStateVariant = 'gray', planSummary, planCitizenMessage, address, onPress,
}: IncidentCardProps) {
  const priorityColor = priority ? (PRIORITY_COLORS[priority] ?? COLORS.gray) : null;
  const priorityLabel = priority ? (PRIORITY_LABELS[priority] ?? priority) : null;
  const planStateStyles = {
    blue: styles.planStateBlue,
    green: styles.planStateGreen,
    yellow: styles.planStateYellow,
    gray: styles.planStateGray,
  }[planStateVariant];

  return (
    <TouchableOpacity style={styles.card} onPress={onPress} activeOpacity={0.85}>
      <View style={styles.cardHeader}>
        <View style={styles.categoryHeader}>
          <CategoryMark icon={categoryIcon} name={categoryName} color={categoryColor} size={38} />
          <Text style={styles.categoryLabel}>{categoryName}</Text>
        </View>
        <StatusBadge status={status} />
      </View>
      <Text style={styles.cardRef}>{reference}</Text>
      {title ? <Text style={styles.cardTitle}>{title}</Text> : null}
      <Text style={styles.cardDesc} numberOfLines={2}>{description}</Text>
      {(priorityLabel || assignedToName || address) ? (
        <View style={styles.cardMetaWrap}>
          {priorityLabel ? (
            <View style={[styles.metaPill, { backgroundColor: `${priorityColor}18` }]}>
              <Text style={[styles.metaPillText, { color: priorityColor! }]}>
                Priorité {priorityLabel}
              </Text>
            </View>
          ) : null}
          {assignedToName ? (
            <View style={styles.metaPill}>
              <Text style={styles.metaPillText}>Assigné à {assignedToName}</Text>
            </View>
          ) : null}
          {address ? (
            <Text style={styles.cardAddress} numberOfLines={1}>
              📍 {address}
            </Text>
          ) : null}
        </View>
      ) : null}
      {(serviceName || planStateLabel || planSummary || planCitizenMessage) ? (
        <View style={styles.serviceWrap}>
          {serviceName ? (
            <View style={styles.servicePill}>
              <Text style={styles.servicePillText}>Service {serviceName}</Text>
            </View>
          ) : null}
          {planStateLabel ? (
            <View style={[styles.planStatePill, planStateStyles]}>
              <Text style={styles.planStateText}>{planStateLabel}</Text>
            </View>
          ) : null}
          {planSummary ? <Text style={styles.serviceText}>{planSummary}</Text> : null}
          {planCitizenMessage ? <Text style={styles.serviceHint}>{planCitizenMessage}</Text> : null}
        </View>
      ) : null}
      <Text style={styles.cardDate}>{new Date(date).toLocaleDateString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric' })}</Text>
    </TouchableOpacity>
  );
}

// ----------------------------------------------------------------
// Styles partagés
// ----------------------------------------------------------------
const styles = StyleSheet.create({
  btn: {
    paddingVertical: 15,
    paddingHorizontal: 24,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 54,
    ...BRAND_SHADOW,
  },
  btnText: {
    fontSize: 16,
    fontWeight: '700',
    letterSpacing: 0.2,
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
    borderRadius: 14,
    paddingHorizontal: 14,
    paddingVertical: 14,
    fontSize: 15,
    color: COLORS.dark,
    backgroundColor: '#FFFDF8',
  },
  eyeButton: {
    position: 'absolute' as const,
    right: 12,
    top: 0,
    bottom: 0,
    justifyContent: 'center' as const,
    alignItems: 'center' as const,
    width: 32,
  },
  eyeIcon: {
    fontSize: 18,
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
    backgroundColor: '#FFFDF8',
    borderRadius: 18,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  cardHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 8,
  },
  categoryHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    flex: 1,
    marginRight: 12,
  },
  categoryLabel: {
    fontSize: 13,
    color: COLORS.gray,
    fontWeight: '600',
    flexShrink: 1,
  },
  cardRef: {
    fontSize: 12,
    color: COLORS.gray,
    marginBottom: 4,
    fontFamily: 'monospace',
    letterSpacing: 0.2,
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.dark,
    marginBottom: 4,
  },
  cardDesc: {
    fontSize: 14,
    color: '#345046',
    lineHeight: 20,
    marginBottom: 8,
  },
  cardMetaWrap: {
    gap: 8,
    marginBottom: 8,
  },
  metaPill: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 999,
    backgroundColor: '#F3EEE2',
  },
  metaPillText: {
    fontSize: 12,
    fontWeight: '700',
    color: COLORS.gray,
  },
  cardAddress: {
    fontSize: 12,
    color: COLORS.gray,
  },
  serviceWrap: {
    marginBottom: 8,
    backgroundColor: '#F4F7F2',
    borderRadius: 14,
    padding: 12,
    borderWidth: 1,
    borderColor: '#D8E2D6',
  },
  servicePill: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 999,
    backgroundColor: '#E4EFE7',
    marginBottom: 8,
  },
  servicePillText: {
    fontSize: 11,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
  },
  planStatePill: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 999,
    marginBottom: 8,
  },
  planStateBlue: {
    backgroundColor: '#DCE6F8',
  },
  planStateGreen: {
    backgroundColor: '#DCEBDD',
  },
  planStateYellow: {
    backgroundColor: '#F4E7C9',
  },
  planStateGray: {
    backgroundColor: '#ECE7DB',
  },
  planStateText: {
    fontSize: 11,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
  },
  serviceText: {
    fontSize: 12.5,
    lineHeight: 18,
    color: BRAND.colors.canopyDeep,
    fontWeight: '700',
  },
  serviceHint: {
    marginTop: 5,
    fontSize: 12,
    lineHeight: 17,
    color: COLORS.gray,
  },
  cardDate: {
    fontSize: 12,
    color: COLORS.gray,
  },
});
