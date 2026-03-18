import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';

type Props = {
  eyebrow: string;
  title: string;
  body: string;
  aside?: string;
};

export function CivicCompanionStage({ eyebrow, title, body, aside }: Props) {
  return (
    <View style={styles.stage}>
      <View style={styles.avatarCluster}>
        <View style={styles.avatarHalo} />
        <View style={styles.avatarFace}>
          <View style={styles.avatarEyes}>
            <View style={styles.avatarEye} />
            <View style={styles.avatarEye} />
          </View>
          <View style={styles.avatarSmile} />
        </View>
        <View style={styles.avatarLeaf} />
        <View style={styles.avatarTag}>
          <Text style={styles.avatarTagText}>{BRAND.companion.name}</Text>
        </View>
      </View>

      <View style={styles.copy}>
        <Text style={styles.eyebrow}>{eyebrow}</Text>
        <Text style={styles.title}>{title}</Text>
        <Text style={styles.body}>{body}</Text>
        {aside ? <Text style={styles.aside}>{aside}</Text> : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  stage: {
    borderRadius: 28,
    padding: 20,
    backgroundColor: '#FFF6E6',
    borderWidth: 1,
    borderColor: '#E8D3AB',
    flexDirection: 'row',
    gap: 16,
    ...BRAND_SHADOW,
  },
  avatarCluster: {
    width: 92,
    alignItems: 'center',
    justifyContent: 'center',
    position: 'relative',
  },
  avatarHalo: {
    width: 78,
    height: 78,
    borderRadius: 39,
    backgroundColor: '#F8E3B7',
  },
  avatarFace: {
    position: 'absolute',
    top: 10,
    width: 58,
    height: 58,
    borderRadius: 29,
    backgroundColor: BRAND.colors.canopy,
    alignItems: 'center',
    justifyContent: 'center',
  },
  avatarEyes: {
    flexDirection: 'row',
    gap: 14,
    marginBottom: 10,
  },
  avatarEye: {
    width: 5,
    height: 5,
    borderRadius: 2.5,
    backgroundColor: '#FFFFFF',
  },
  avatarSmile: {
    width: 18,
    height: 8,
    borderBottomWidth: 3,
    borderBottomColor: '#FFFFFF',
    borderRadius: 10,
  },
  avatarLeaf: {
    position: 'absolute',
    top: 4,
    right: 6,
    width: 20,
    height: 20,
    borderRadius: 20,
    backgroundColor: BRAND.colors.awara,
    transform: [{ rotate: '-24deg' }],
  },
  avatarTag: {
    position: 'absolute',
    bottom: 2,
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 5,
    backgroundColor: '#FFFFFFE8',
    borderWidth: 1,
    borderColor: '#E8D3AB',
  },
  avatarTagText: {
    color: BRAND.colors.canopyDeep,
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
  },
  copy: {
    flex: 1,
    gap: 8,
  },
  eyebrow: {
    color: BRAND.colors.awara,
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  title: {
    color: BRAND.colors.canopyDeep,
    fontSize: 22,
    lineHeight: 28,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
  },
  body: {
    color: '#355248',
    fontSize: 14,
    lineHeight: 21,
  },
  aside: {
    color: BRAND.colors.slate,
    fontSize: 12,
    lineHeight: 18,
  },
});
