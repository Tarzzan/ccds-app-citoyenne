/**
 * Storybook — OfflineBanner
 * Documentation visuelle du composant OfflineBanner (v1.1)
 */

import React from 'react';
import { View } from 'react-native';
import { OfflineBanner } from '../OfflineBanner';

export default {
  title: 'CCDS/OfflineBanner',
  component: OfflineBanner,
  decorators: [
    (Story: React.FC) => (
      <View style={{ flex: 1, backgroundColor: '#f5f5f5' }}>
        <Story />
        <View style={{ height: 200 }} />
      </View>
    ),
  ],
};

// ── Mode hors-ligne avec signalements en attente ─────────────────────────────
export const OfflineWithQueue = {};

// ── Mode connecté ────────────────────────────────────────────────────────────
export const Online = {};
