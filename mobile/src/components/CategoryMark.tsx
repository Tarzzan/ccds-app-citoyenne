import React from 'react';
import { Image, StyleSheet, View } from 'react-native';
import { resolveCategoryVisual } from '../theme/categoryVisuals';

interface Props {
  icon?: string | null;
  name?: string | null;
  color?: string | null;
  size?: number;
  framed?: boolean;
}

export function CategoryMark({
  icon,
  name,
  color,
  size = 52,
  framed = true,
}: Props) {
  const visual = resolveCategoryVisual(icon, name);
  const borderRadius = Math.round(size * 0.3);

  return (
    <View
      style={[
        styles.wrap,
        framed && {
          width: size,
          height: size,
          borderRadius,
          backgroundColor: `${color || visual.accent}18`,
          borderColor: `${color || visual.accent}44`,
        },
      ]}
    >
      <Image
        source={visual.source}
        style={{ width: size * 0.92, height: size * 0.92 }}
        resizeMode="contain"
      />
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    overflow: 'hidden',
  },
});
