import React from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { getCategoryAccentColor } from '../theme/categoryVisuals';
import { resolveCategorySceneSlot } from '../theme/categorySceneSlots';

type Props = {
  icon?: string | null;
  name?: string | null;
  title?: string;
  body?: string;
};

export function CategoryScenePanel({ icon, name, title, body }: Props) {
  const slot = resolveCategorySceneSlot(icon, name);
  if (!slot?.source) {
    return null;
  }

  const accent = getCategoryAccentColor(icon, name, null);

  return (
    <View style={[styles.card, { borderColor: `${accent}3D` }]}>
      <Image source={slot.source} style={styles.image} resizeMode="cover" />
      <View style={styles.overlay}>
        <Text style={[styles.eyebrow, { color: accent }]}>Scene terrain</Text>
        <Text style={styles.title}>{title ?? slot.label}</Text>
        <Text style={styles.body}>
          {body ?? "Cette illustration validera visuellement le type de situation traitee, sans remplacer l'icone fonctionnelle de la categorie."}
        </Text>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: BRAND.colors.white,
    borderRadius: 22,
    overflow: 'hidden',
    borderWidth: 1,
    marginTop: 14,
    ...BRAND_SHADOW,
  },
  image: {
    width: '100%',
    aspectRatio: 4 / 3,
    backgroundColor: '#E6DCC8',
  },
  overlay: {
    padding: 14,
    backgroundColor: '#FFFDF8',
  },
  eyebrow: {
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: 4,
  },
  title: {
    fontSize: 16,
    fontWeight: '800',
    color: BRAND.colors.ink,
    marginBottom: 6,
  },
  body: {
    fontSize: 13,
    lineHeight: 19,
    color: BRAND.colors.slate,
  },
});
