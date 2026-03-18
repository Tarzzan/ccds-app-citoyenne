/**
 * Ma Commune — Application civique territoriale
 * Point d'entree principal de l'application React Native.
 * v1.5 : GestureHandlerRootView requis pour react-native-gesture-handler v2
 */

import 'react-native-gesture-handler';
import React, { useEffect, useRef } from 'react';
import { StyleSheet } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import * as Notifications from 'expo-notifications';

import { AuthProvider, useAuth } from './src/services/AuthContext';
import RootNavigator             from './src/navigation/RootNavigator';
import { ThemeProvider } from './src/theme/ThemeContext';
import {
  registerForPushNotifications,
  addNotificationResponseListener,
} from './src/services/NotificationService';
import { OfflineQueue } from './src/services/OfflineQueue';
import { navigateToEvents, navigateToIncidentDetail, navigateToPolls } from './src/navigation/navigationRef';

function routeFromNotificationData(data: Record<string, unknown>) {
  const incidentIdValue = data.incident_id;
  const incidentReference = typeof data.incident_reference === 'string'
    ? data.incident_reference
    : undefined;

  const incidentId = typeof incidentIdValue === 'number'
    ? incidentIdValue
    : typeof incidentIdValue === 'string'
      ? Number.parseInt(incidentIdValue, 10)
      : NaN;

  if (Number.isInteger(incidentId) && incidentId > 0) {
    navigateToIncidentDetail(incidentId, incidentReference);
    return;
  }

  if (data.type === 'event' || data.screen === 'Events') {
    navigateToEvents();
    return;
  }

  if (data.screen === 'Polls') {
    navigateToPolls();
  }
}

// ── Wrapper qui accède au contexte Auth ──────────────────────────────────────
function AppWithNotifications() {
  const { isAuthenticated } = useAuth();
  const notifResponseListener = useRef<Notifications.Subscription | null>(null);

  useEffect(() => {
    // Initialiser les notifications push après connexion
    if (isAuthenticated) {
      registerForPushNotifications().catch(console.error);
    }

    if (!isAuthenticated) {
      notifResponseListener.current?.remove();
      notifResponseListener.current = null;
      return;
    }

    // Écouter les appuis sur les notifications (app en arrière-plan)
    notifResponseListener.current = addNotificationResponseListener((response) => {
      const data = response.notification.request.content.data as Record<string, unknown>;
      console.log('[Push] Notification appuyée :', data);
      routeFromNotificationData(data);
    });

    Notifications.getLastNotificationResponseAsync()
      .then((response) => {
        if (!response) {
          return;
        }

        const data = response.notification.request.content.data as Record<string, unknown>;
        routeFromNotificationData(data);
      })
      .catch(console.error);

    return () => {
      notifResponseListener.current?.remove();
      notifResponseListener.current = null;
    };
  }, [isAuthenticated]);

  // Démarrer la synchronisation offline au montage
  useEffect(() => {
    OfflineQueue.sync().then(({ synced }) => {
      if (synced > 0) {
        console.log(`[${process.env.EXPO_PUBLIC_APP_NAME ?? 'Ma Commune'}] ${synced} signalement(s) hors-ligne synchronisé(s) au démarrage`);
      }
    });
  }, []);

  return <RootNavigator />;
}

// ── Composant racine ─────────────────────────────────────────────────────────
export default function App() {
  return (
    // GestureHandlerRootView est OBLIGATOIRE pour react-native-gesture-handler v2
    // Sans ce wrapper, l'app crash silencieusement sur iOS (écran blanc)
    <GestureHandlerRootView style={styles.root}>
      <SafeAreaProvider>
        <ThemeProvider>
          <AuthProvider>
            <StatusBar style="auto" />
            <AppWithNotifications />
          </AuthProvider>
        </ThemeProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
});
