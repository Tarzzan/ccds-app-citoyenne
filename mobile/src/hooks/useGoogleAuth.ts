/**
 * useGoogleAuth — Hook pour l'authentification Google via expo-auth-session
 */

import { useEffect } from 'react';
import { Platform, Alert } from 'react-native';
import * as Google from 'expo-auth-session/providers/google';
import * as WebBrowser from 'expo-web-browser';
import { useAuth } from '../services/AuthContext';
import { BRAND } from '../theme/brand';

// Required for expo-auth-session web redirect
WebBrowser.maybeCompleteAuthSession();

// ── Google Client IDs ──────────────────────────────────────────────
// These will be set when the Google Cloud Console project is configured.
// For now, they are placeholders.
const GOOGLE_WEB_CLIENT_ID     = ''; // TODO: set from Google Cloud Console
const GOOGLE_ANDROID_CLIENT_ID = ''; // TODO: set from Google Cloud Console
const GOOGLE_IOS_CLIENT_ID     = ''; // TODO: set from Google Cloud Console

export function useGoogleAuth() {
  const { loginWithGoogle } = useAuth();

  const [request, response, promptAsync] = Google.useAuthRequest({
    webClientId:     GOOGLE_WEB_CLIENT_ID,
    androidClientId: GOOGLE_ANDROID_CLIENT_ID,
    iosClientId:     GOOGLE_IOS_CLIENT_ID,
  });

  useEffect(() => {
    if (response?.type === 'success') {
      const idToken = response.authentication?.idToken;
      if (idToken) {
        loginWithGoogle(idToken).catch((err: any) => {
          Alert.alert(
            `${BRAND.companion.name} — Connexion Google`,
            err?.message ?? 'Impossible de se connecter avec Google.'
          );
        });
      }
    }
  }, [response]);

  const isConfigured = !!(GOOGLE_WEB_CLIENT_ID || GOOGLE_ANDROID_CLIENT_ID || GOOGLE_IOS_CLIENT_ID);

  return {
    /** Whether Google Auth Client IDs are configured */
    isConfigured,
    /** Whether the auth request is ready to be prompted */
    isReady: !!request && isConfigured,
    /** Trigger the Google Sign-In flow */
    signIn: () => promptAsync(),
  };
}
