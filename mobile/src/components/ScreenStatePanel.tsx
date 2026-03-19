import React from 'react';
import { ActivityIndicator, ImageSourcePropType, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { CompanionVisual } from './CompanionVisual';

type LoadingProps = {
  title: string;
  body: string;
  visualSource?: ImageSourcePropType;
  visualBadgeLabel?: string;
};

type FeedbackProps = {
  icon?: string;
  title: string;
  body: string;
  tone?: 'neutral' | 'warning';
  actionLabel?: string;
  onPress?: () => void;
  visualSource?: ImageSourcePropType;
  visualBadgeLabel?: string;
};

export function ScreenLoadingState({ title, body, visualSource, visualBadgeLabel }: LoadingProps) {
  return (
    <View style={styles.centered}>
      <View style={styles.panel}>
        {visualSource ? (
          <View style={styles.visualWrap}>
            <CompanionVisual
              accent={BRAND.colors.canopy}
              halo="#DCEADF"
              imageSource={visualSource}
              badgeLabel={visualBadgeLabel ?? BRAND.companion.name}
              variant="card"
            />
          </View>
        ) : (
          <View style={styles.loadingBadge}>
            <ActivityIndicator size="small" color={BRAND.colors.canopy} />
          </View>
        )}
        <Text style={styles.eyebrow}>{BRAND.companion.name} prepare la suite</Text>
        <Text style={styles.title}>{title}</Text>
        <Text style={styles.body}>{body}</Text>
      </View>
    </View>
  );
}

export function ScreenFeedbackState({
  icon = 'ℹ️',
  title,
  body,
  tone = 'neutral',
  actionLabel,
  onPress,
  visualSource,
  visualBadgeLabel,
}: FeedbackProps) {
  return (
    <View style={styles.centered}>
      <View style={[styles.panel, tone === 'warning' && styles.panelWarning]}>
        {visualSource ? (
          <View style={styles.visualWrap}>
            <CompanionVisual
              accent={tone === 'warning' ? BRAND.colors.laterite : BRAND.colors.awara}
              halo={tone === 'warning' ? '#EEDDCF' : '#F8E3B7'}
              imageSource={visualSource}
              badgeLabel={visualBadgeLabel ?? icon}
              variant="card"
            />
          </View>
        ) : (
          <Text style={styles.icon}>{icon}</Text>
        )}
        <Text style={styles.eyebrow}>{tone === 'warning' ? 'Point a verifier' : `${BRAND.companion.name} vous guide`}</Text>
        <Text style={styles.title}>{title}</Text>
        <Text style={styles.body}>{body}</Text>
        {actionLabel && onPress ? (
          <TouchableOpacity style={styles.actionBtn} onPress={onPress} activeOpacity={0.85}>
            <Text style={styles.actionText}>{actionLabel}</Text>
          </TouchableOpacity>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  centered: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: 24,
    paddingVertical: 32,
  },
  panel: {
    width: '100%',
    maxWidth: 360,
    borderRadius: 24,
    padding: 24,
    backgroundColor: '#FFF8EC',
    borderWidth: 1,
    borderColor: '#E8D3AB',
    alignItems: 'center',
    ...BRAND_SHADOW,
  },
  panelWarning: {
    backgroundColor: '#F8EFEA',
    borderColor: '#DECBB8',
  },
  loadingBadge: {
    width: 52,
    height: 52,
    borderRadius: 26,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#EAF2EE',
    marginBottom: 14,
  },
  visualWrap: {
    marginBottom: 14,
  },
  icon: {
    fontSize: 36,
    marginBottom: 12,
  },
  eyebrow: {
    color: BRAND.colors.awara,
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: 8,
    textAlign: 'center',
  },
  title: {
    color: BRAND.colors.canopyDeep,
    fontSize: 22,
    lineHeight: 28,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
    textAlign: 'center',
    marginBottom: 10,
  },
  body: {
    color: '#355248',
    fontSize: 14,
    lineHeight: 21,
    textAlign: 'center',
  },
  actionBtn: {
    marginTop: 18,
    borderRadius: 999,
    paddingHorizontal: 18,
    paddingVertical: 11,
    backgroundColor: BRAND.colors.canopy,
  },
  actionText: {
    color: '#FFFFFF',
    fontSize: 13,
    fontWeight: '700',
  },
});
