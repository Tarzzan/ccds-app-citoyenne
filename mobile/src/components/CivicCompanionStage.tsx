import React from 'react';
import { ImageSourcePropType, StyleSheet, Text, View } from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { CompanionVisual } from './CompanionVisual';

type Props = {
  eyebrow: string;
  title: string;
  body: string;
  aside?: string;
  visualSource?: ImageSourcePropType;
  visualBadgeLabel?: string;
};

export function CivicCompanionStage({ eyebrow, title, body, aside, visualSource, visualBadgeLabel }: Props) {
  return (
    <View style={styles.stage}>
      <View style={styles.avatarCluster}>
        <CompanionVisual
          accent={BRAND.colors.canopy}
          halo="#F8E3B7"
          imageSource={visualSource}
          badgeLabel={visualBadgeLabel ?? BRAND.companion.name}
          variant="stage"
        />
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
