/**
 * Storybook — VoteButton
 * Documentation visuelle du composant VoteButton (v1.1)
 */

import React from 'react';
import { View } from 'react-native';
import { VoteButton } from '../VoteButton';

export default {
  title: 'CCDS/VoteButton',
  component: VoteButton,
  decorators: [
    (Story: React.FC) => (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
        <Story />
      </View>
    ),
  ],
  argTypes: {
    incidentId: { control: 'number', defaultValue: 1 },
    initialCount: { control: 'number', defaultValue: 0 },
    initialVoted: { control: 'boolean', defaultValue: false },
  },
};

// ── État par défaut (non voté, 0 votes) ──────────────────────────────────────
export const Default = {
  args: { incidentId: 1, initialCount: 0, initialVoted: false },
};

// ── Déjà voté ────────────────────────────────────────────────────────────────
export const AlreadyVoted = {
  args: { incidentId: 2, initialCount: 12, initialVoted: true },
};

// ── Compteur élevé ───────────────────────────────────────────────────────────
export const HighCount = {
  args: { incidentId: 3, initialCount: 847, initialVoted: false },
};
