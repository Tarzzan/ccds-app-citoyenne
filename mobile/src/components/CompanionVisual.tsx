import React from 'react';
import { Image, ImageSourcePropType, StyleSheet, Text, View } from 'react-native';
import { BRAND } from '../theme/brand';

type Props = {
  accent: string;
  halo: string;
  compact?: boolean;
  imageSource?: ImageSourcePropType;
  badgeLabel?: string;
  variant?: 'card' | 'stage';
};

export function CompanionVisual({
  accent,
  halo,
  compact = false,
  imageSource,
  badgeLabel = 'MC',
  variant = 'card',
}: Props) {
  const size = variant === 'stage' ? (compact ? 72 : 84) : compact ? 52 : 60;
  const haloSize = variant === 'stage' ? (compact ? 88 : 102) : compact ? 54 : 66;
  const badgeBottom = variant === 'stage' ? 0 : -2;

  if (imageSource) {
    return (
      <View style={[styles.wrap, compact && styles.wrapCompact]}>
        <View style={[styles.imageFrame, { width: haloSize, height: haloSize, borderColor: `${accent}22`, backgroundColor: '#FFFFFFCC' }]}>
          <Image source={imageSource} style={{ width: size, height: size }} resizeMode="contain" />
        </View>
        <View style={[styles.badge, { bottom: badgeBottom, borderColor: `${accent}33` }]}>
          <Text style={[styles.badgeText, { color: accent }]}>{badgeLabel}</Text>
        </View>
      </View>
    );
  }

  return (
    <View style={[styles.wrap, compact && styles.wrapCompact]}>
      <View style={[styles.halo, { width: haloSize, height: haloSize, borderRadius: haloSize / 2, backgroundColor: halo }]} />
      <View style={[styles.face, { top: variant === 'stage' ? 12 : 8, width: size, height: size, borderRadius: size / 2, backgroundColor: accent }]}>
        <View style={[styles.eyes, { gap: variant === 'stage' ? 14 : 10, marginBottom: variant === 'stage' ? 10 : 8 }]}>
          <View style={[styles.eye, variant === 'stage' && styles.eyeStage]} />
          <View style={[styles.eye, variant === 'stage' && styles.eyeStage]} />
        </View>
        <View style={[styles.smile, variant === 'stage' && styles.smileStage]} />
      </View>
      <View
        style={[
          styles.leaf,
          {
            top: variant === 'stage' ? 4 : 2,
            right: variant === 'stage' ? 6 : 6,
            width: variant === 'stage' ? 20 : 16,
            height: variant === 'stage' ? 20 : 16,
            borderRadius: variant === 'stage' ? 20 : 16,
            backgroundColor: BRAND.colors.awara,
          },
        ]}
      />
      <View style={[styles.badge, { bottom: badgeBottom, borderColor: `${accent}33` }]}>
        <Text style={[styles.badgeText, { color: accent }]}>{badgeLabel}</Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    alignItems: 'center',
    justifyContent: 'center',
    position: 'relative',
  },
  wrapCompact: {
    transform: [{ scale: 0.94 }],
  },
  halo: {
    opacity: 1,
  },
  imageFrame: {
    borderRadius: 28,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    overflow: 'hidden',
  },
  face: {
    position: 'absolute',
    alignItems: 'center',
    justifyContent: 'center',
  },
  eyes: {
    flexDirection: 'row',
  },
  eye: {
    width: 4,
    height: 4,
    borderRadius: 2,
    backgroundColor: '#FFFFFF',
  },
  eyeStage: {
    width: 5,
    height: 5,
    borderRadius: 2.5,
  },
  smile: {
    width: 14,
    height: 7,
    borderBottomWidth: 2,
    borderBottomColor: '#FFFFFF',
    borderRadius: 10,
  },
  smileStage: {
    width: 18,
    height: 8,
    borderBottomWidth: 3,
  },
  leaf: {
    position: 'absolute',
    transform: [{ rotate: '-24deg' }],
  },
  badge: {
    position: 'absolute',
    borderRadius: 999,
    paddingHorizontal: 8,
    paddingVertical: 3,
    backgroundColor: '#FFFFFFE8',
    borderWidth: 1,
  },
  badgeText: {
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 0.6,
  },
});
