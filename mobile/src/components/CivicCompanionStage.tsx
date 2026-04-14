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
    borderRadius: 18,
    padding: 14,
    backgroundColor: '#FFF6E6',
    borderWidth: 1,
    borderColor: '#E8D3AB',
    flexDirection: 'row',
    gap: 12,
    ...BRAND_SHADOW,
  },
  avatarCluster: {
    width: 56,
    alignItems: 'center',
    justifyContent: 'center',
    position: 'relative',
  },
  copy: {
    flex: 1,
    gap: 5,
  },
  eyebrow: {
    color: BRAND.colors.awara,
    fontSize: 10,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 0.8,
  },
  title: {
    color: BRAND.colors.canopyDeep,
    fontSize: 16,
    lineHeight: 21,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
  },
  body: {
    color: '#355248',
    fontSize: 12,
    lineHeight: 17,
  },
  aside: {
    color: BRAND.colors.slate,
    fontSize: 11,
    lineHeight: 16,
  },
});
