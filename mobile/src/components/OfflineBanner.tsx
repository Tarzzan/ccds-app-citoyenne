/**
 * Composant OfflineBanner — CCDS Citoyen v1.1
 *
 * Affiche une bannière en haut de l'écran quand l'appareil est hors-ligne
 * et indique le nombre de signalements en attente de synchronisation.
 */

import React, { useEffect, useState } from 'react';
import { View, Text, StyleSheet, Animated } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { getPendingCount } from '../services/OfflineQueue';
import { COLORS } from './ui';

export const OfflineBanner: React.FC = () => {
  const [isOffline, setIsOffline]       = useState(false);
  const [pendingCount, setPendingCount] = useState(0);
  const slideAnim = React.useRef(new Animated.Value(-60)).current;

  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener(async (state) => {
      const offline = !state.isConnected;
      setIsOffline(offline);

      if (offline) {
        const count = await getPendingCount();
        setPendingCount(count);
        Animated.spring(slideAnim, { toValue: 0, useNativeDriver: true }).start();
      } else {
        Animated.timing(slideAnim, { toValue: -60, duration: 300, useNativeDriver: true }).start();
      }
    });

    return unsubscribe;
  }, []);

  if (!isOffline) return null;

  return (
    <Animated.View style={[styles.banner, { transform: [{ translateY: slideAnim }] }]}>
      <Text style={styles.icon}>📡</Text>
      <View>
        <Text style={styles.title}>Mode hors-ligne</Text>
        <Text style={styles.subtitle}>
          {pendingCount > 0
            ? `${pendingCount} signalement${pendingCount > 1 ? 's' : ''} en attente de synchronisation`
            : 'Vos signalements seront envoyés dès la reconnexion'}
        </Text>
      </View>
    </Animated.View>
  );
};

const styles = StyleSheet.create({
  banner: {
    backgroundColor: '#92400e',
    paddingVertical: 10,
    paddingHorizontal: 16,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    zIndex: 999,
  },
  icon: {
    fontSize: 20,
  },
  title: {
    color: '#fef3c7',
    fontWeight: '700',
    fontSize: 13,
  },
  subtitle: {
    color: '#fde68a',
    fontSize: 11,
    marginTop: 1,
  },
});

export default OfflineBanner;
