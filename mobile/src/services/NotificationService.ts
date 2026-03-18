/**
 * Service de Notifications Push — Ma Commune
 * IMPORTANT : expo-notifications 0.32+ retourne { granted: boolean }
 * et non plus { status: 'granted' | 'denied' | 'undetermined' }
 * Utilise Expo Notifications pour iOS et Android
 * Documentation : https://docs.expo.dev/push-notifications/overview/
 */

import * as Notifications from 'expo-notifications';
import * as Device from 'expo-device';
import { Platform } from 'react-native';
import { registerPushToken } from './api';

const PUSH_ENABLED = process.env.EXPO_PUBLIC_ENABLE_PUSH === 'true';
const EXPO_PROJECT_ID =
  process.env.EXPO_PUBLIC_EXPO_PROJECT_ID ?? 'b3d38760-9ace-47eb-b84f-37419e550824';

// ── Configuration du comportement des notifications ──────────────────────────
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,  // remplace shouldShowAlert (expo-notifications 0.32+)
    shouldShowList:   true,
    shouldPlaySound:  true,
    shouldSetBadge:   true,
  }),
});

// ── Demander les permissions et enregistrer le token ────────────────────────
export const registerForPushNotifications = async (): Promise<string | null> => {
  if (!PUSH_ENABLED) {
    console.log('[Push] Notifications désactivées pour cette version locale');
    return null;
  }

  // Les notifications push ne fonctionnent pas sur simulateur
  if (!Device.isDevice) {
    console.log('[Push] Notifications non disponibles sur simulateur');
    return null;
  }

  // Vérifier les permissions existantes
  // expo-notifications 0.32+ : retourne { granted: boolean, ... }
  const existingPermissions = await Notifications.getPermissionsAsync();
  let isGranted = existingPermissions.granted;

  // Demander les permissions si pas encore accordées
  if (!isGranted) {
    const newPermissions = await Notifications.requestPermissionsAsync();
    isGranted = newPermissions.granted;
  }

  if (!isGranted) {
    console.log('[Push] Permission refusée par l\'utilisateur');
    return null;
  }

  // Configurer le canal Android
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('ma-commune-notifications', {
      name:        'Ma Commune',
      importance:  Notifications.AndroidImportance.HIGH,
      vibrationPattern: [0, 250, 250, 250],
      lightColor:  '#1a7a42',
      description: 'Notifications de l\'application Ma Commune',
    });
  }

  // Obtenir le token Expo Push
  try {
    const tokenData = await Notifications.getExpoPushTokenAsync({
      projectId: EXPO_PROJECT_ID,
    });

    const token = tokenData.data;
    const platform = Platform.OS as 'ios' | 'android';

    // Enregistrer le token sur le serveur
    await registerPushToken(token, platform);

    console.log('[Push] Token enregistré :', token);
    return token;
  } catch (error) {
    console.log('[Push] Configuration push incomplète, enregistrement ignoré pour cette build');
    return null;
  }
};

// ── Écouter les notifications reçues (app au premier plan) ──────────────────
export const addNotificationReceivedListener = (
  callback: (notification: Notifications.Notification) => void
): Notifications.Subscription => {
  return Notifications.addNotificationReceivedListener(callback);
};

// ── Écouter les appuis sur les notifications ─────────────────────────────────
export const addNotificationResponseListener = (
  callback: (response: Notifications.NotificationResponse) => void
): Notifications.Subscription => {
  return Notifications.addNotificationResponseReceivedListener(callback);
};

// ── Effacer le badge de l'icône ──────────────────────────────────────────────
export const clearBadge = async (): Promise<void> => {
  await Notifications.setBadgeCountAsync(0);
};

// ── Obtenir le nombre de notifications non lues ──────────────────────────────
export const getBadgeCount = async (): Promise<number> => {
  return await Notifications.getBadgeCountAsync();
};
