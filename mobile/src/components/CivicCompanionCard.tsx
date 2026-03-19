import React from 'react';
import { ImageSourcePropType, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { CompanionVisual } from './CompanionVisual';

type CivicCompanionTone = 'guide' | 'thanks' | 'status' | 'uplift';

type Props = {
  eyebrow?: string;
  title: string;
  body: string;
  tone?: CivicCompanionTone;
  bullets?: string[];
  ctaLabel?: string;
  onPress?: () => void;
  compact?: boolean;
  visualSource?: ImageSourcePropType;
  visualBadgeLabel?: string;
};

const TONES: Record<CivicCompanionTone, { card: string; border: string; accent: string; halo: string }> = {
  guide: {
    card: '#F7F1E6',
    border: '#E3D7C1',
    accent: BRAND.colors.canopy,
    halo: '#DCEADF',
  },
  thanks: {
    card: '#FFF5E5',
    border: '#E8D3AB',
    accent: BRAND.colors.awara,
    halo: '#F8E3B7',
  },
  status: {
    card: '#EAF2EE',
    border: '#C8D9CF',
    accent: BRAND.colors.river,
    halo: '#D7E8E4',
  },
  uplift: {
    card: '#F4ECE3',
    border: '#DECBB8',
    accent: BRAND.colors.laterite,
    halo: '#EEDDCF',
  },
};

export function CivicCompanionCard({
  eyebrow,
  title,
  body,
  tone = 'guide',
  bullets = [],
  ctaLabel,
  onPress,
  compact = false,
  visualSource,
  visualBadgeLabel,
}: Props) {
  const palette = TONES[tone];

  return (
    <View
      style={[
        styles.card,
        compact && styles.cardCompact,
        { backgroundColor: palette.card, borderColor: palette.border },
      ]}
    >
      <View style={styles.topRow}>
        <View style={[styles.avatarWrap, compact && styles.avatarWrapCompact]}>
          <CompanionVisual
            accent={palette.accent}
            halo={palette.halo}
            compact={compact}
            imageSource={visualSource}
            badgeLabel={visualBadgeLabel ?? 'MC'}
            variant="card"
          />
        </View>
        <View style={styles.copy}>
          <Text style={[styles.eyebrow, { color: palette.accent }]}>
            {eyebrow ?? `${BRAND.companion.name} · ${BRAND.companion.role}`}
          </Text>
          <Text style={[styles.title, compact && styles.titleCompact]}>{title}</Text>
          <Text style={[styles.body, compact && styles.bodyCompact]}>{body}</Text>
        </View>
      </View>

      {bullets.length > 0 && (
        <View style={styles.bullets}>
          {bullets.slice(0, compact ? 2 : 3).map((bullet) => (
            <View key={bullet} style={styles.bulletRow}>
              <View style={[styles.bulletDot, { backgroundColor: palette.accent }]} />
              <Text style={styles.bulletText}>{bullet}</Text>
            </View>
          ))}
        </View>
      )}

      {ctaLabel && onPress ? (
        <TouchableOpacity
          style={[styles.cta, { backgroundColor: palette.accent }]}
          onPress={onPress}
          activeOpacity={0.88}
        >
          <Text style={styles.ctaText}>{ctaLabel}</Text>
        </TouchableOpacity>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: 22,
    borderWidth: 1,
    padding: 16,
    gap: 14,
    ...BRAND_SHADOW,
  },
  cardCompact: {
    padding: 14,
    gap: 12,
  },
  topRow: {
    flexDirection: 'row',
    gap: 14,
    alignItems: 'flex-start',
  },
  avatarWrap: {
    width: 66,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarWrapCompact: {
    width: 58,
  },
  copy: {
    flex: 1,
    gap: 6,
  },
  eyebrow: {
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 0.9,
  },
  title: {
    color: BRAND.colors.ink,
    fontSize: 18,
    fontWeight: '800',
    lineHeight: 23,
    fontFamily: BRAND.displayFont,
  },
  titleCompact: {
    fontSize: 16,
    lineHeight: 20,
  },
  body: {
    color: '#355248',
    fontSize: 13,
    lineHeight: 20,
  },
  bodyCompact: {
    fontSize: 12.5,
    lineHeight: 18,
  },
  bullets: {
    gap: 8,
  },
  bulletRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 8,
  },
  bulletDot: {
    width: 7,
    height: 7,
    borderRadius: 999,
    marginTop: 6,
  },
  bulletText: {
    flex: 1,
    color: BRAND.colors.slate,
    fontSize: 12.5,
    lineHeight: 18,
  },
  cta: {
    minHeight: 44,
    borderRadius: 14,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 16,
  },
  ctaText: {
    color: '#FFFFFF',
    fontSize: 13,
    fontWeight: '800',
  },
});
