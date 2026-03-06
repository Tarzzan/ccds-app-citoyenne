/**
 * CCDS — Application Citoyenne de Signalement
 * Point d'entrée principal de l'application React Native.
 * v1.4 : gestion explicite des mises à jour OTA + reload automatique
 */

import 'react-native-gesture-handler';
import React, { useEffect, useRef } from 'react';
import { StatusBar } from 'expo-status-bar';
import * as Notifications from 'expo-notifications';
import * as Updates from 'expo-updates';

import { AuthProvider, useAuth } from './src/services/AuthContext';
import RootNavigator             from './src/navigation/RootNavigator';
import {
  registerForPushNotifications,
  addNotificationResponseListener,
} from './src/services/NotificationService';
import { OfflineQueue } from './src/services/OfflineQueue';

// ── Wrapper qui accède au contexte Auth ──────────────────────────────────────
function AppWithNotifications() {
  const { isAuthenticated } = useAuth();
  const notifResponseListener = useRef<Notifications.Subscription | null>(null);

  useEffect(() => {
    // Vérifier et appliquer les mises à jour OTA au démarrage
    (async () => {
      try {
        if (!__DEV__) {
          const update = await Updates.checkForUpdateAsync();
          if (update.isAvailable) {
            await Updates.fetchUpdateAsync();
            await Updates.reloadAsync();
          }
        }
      } catch {
        // Ignorer les erreurs de mise à jour (pas de connexion, etc.)
      }
    })();
  }, []);

  useEffect(() => {
    // Initialiser les notifications push après connexion
    if (isAuthenticated) {
      registerForPushNotifications().catch(console.error);
    }

    // Écouter les appuis sur les notifications (app en arrière-plan)
    notifResponseListener.current = addNotificationResponseListener((response) => {
      const data = response.notification.request.content.data as any;
      console.log('[Push] Notification appuyée :', data);
    });

    return () => {
      notifResponseListener.current?.remove();
    };
  }, [isAuthenticated]);

  // Démarrer la synchronisation offline au montage
  useEffect(() => {
    OfflineQueue.sync().then(({ synced }) => {
      if (synced > 0) {
        console.log(`[CCDS] ${synced} signalement(s) hors-ligne synchronisé(s) au démarrage`);
      }
    });
  }, []);

  return <RootNavigator />;
}

// ── Composant racine ─────────────────────────────────────────────────────────
export default function App() {
  return (
    <AuthProvider>
      <StatusBar style="auto" />
      <AppWithNotifications />
    </AuthProvider>
  );
}
