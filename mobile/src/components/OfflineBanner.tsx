/**
 * Composant OfflineBanner — CCDS Citoyen v1.1
 *
 * Affiche une bannière en haut de l'écran quand l'appareil est hors-ligne
 * et indique le nombre de signalements en attente de synchronisation.
 * v1.1 update : utilise le singleton OfflineQueue + bouton de sync manuelle.
 */

import React, { useEffect, useState } from 'react';
import {
  View, Text, StyleSheet, Animated,
  TouchableOpacity, ActivityIndicator,
} from 'react-native';
import { OfflineQueue } from '../services/OfflineQueue';

export const OfflineBanner: React.FC = () => {
  const [isConnected,  setIsConnected]  = useState(true);
  const [pendingCount, setPendingCount] = useState(0);
  const [syncing,      setSyncing]      = useState(false);
  const slideAnim = React.useRef(new Animated.Value(-60)).current;

  useEffect(() => {
    // Initialiser l'état courant
    setIsConnected(OfflineQueue.getConnectionState());
    OfflineQueue.getPendingCount().then(setPendingCount);

    // Afficher immédiatement si hors-ligne
    if (!OfflineQueue.getConnectionState()) {
      slideAnim.setValue(0);
    }

    const unsubConn = OfflineQueue.onConnectivityChange((connected) => {
      setIsConnected(connected);
      Animated.spring(slideAnim, {
        toValue: connected && pendingCount === 0 ? -60 : 0,
        useNativeDriver: true,
      }).start();
    });

    const unsubQueue = OfflineQueue.onQueueChange((count) => {
      setPendingCount(count);
      if (count === 0 && isConnected) {
        Animated.timing(slideAnim, { toValue: -60, duration: 400, useNativeDriver: true }).start();
      }
    });

    return () => {
      unsubConn();
      unsubQueue();
    };
  }, []);

  const handleSync = async () => {
    if (syncing || !isConnected) return;
    setSyncing(true);
    try {
      await OfflineQueue.sync();
    } finally {
      setSyncing(false);
    }
  };

  // Masquer si connecté et aucun signalement en attente
  if (isConnected && pendingCount === 0) return null;

  return (
    <Animated.View
      style={[
        styles.banner,
        isConnected ? styles.bannerOnline : styles.bannerOffline,
        { transform: [{ translateY: slideAnim }] },
      ]}
    >
      <Text style={styles.icon}>{isConnected ? '🔄' : '📴'}</Text>
      <View style={{ flex: 1 }}>
        <Text style={styles.title}>
          {isConnected ? 'Connexion rétablie' : 'Mode hors-ligne'}
        </Text>
        {pendingCount > 0 && (
          <Text style={styles.subtitle}>
            {pendingCount} signalement{pendingCount > 1 ? 's' : ''} en attente
          </Text>
        )}
      </View>

      {isConnected && pendingCount > 0 && (
        <TouchableOpacity
          style={styles.syncBtn}
          onPress={handleSync}
          disabled={syncing}
        >
          {syncing
            ? <ActivityIndicator size="small" color="#fff" />
            : <Text style={styles.syncBtnText}>Synchroniser</Text>
          }
        </TouchableOpacity>
      )}
    </Animated.View>
  );
};

const styles = StyleSheet.create({
  banner: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    paddingHorizontal: 16,
    gap: 10,
    zIndex: 999,
  },
  bannerOffline: { backgroundColor: '#dc2626' },
  bannerOnline:  { backgroundColor: '#16a34a' },
  icon:  { fontSize: 20 },
  title: { color: '#fff', fontWeight: '700', fontSize: 13 },
  subtitle: { color: 'rgba(255,255,255,0.85)', fontSize: 11, marginTop: 1 },
  syncBtn: {
    backgroundColor: 'rgba(255,255,255,0.25)',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 6,
    minWidth: 100,
    alignItems: 'center',
  },
  syncBtnText: { fontSize: 12, fontWeight: '700', color: '#fff' },
});

export default OfflineBanner;
