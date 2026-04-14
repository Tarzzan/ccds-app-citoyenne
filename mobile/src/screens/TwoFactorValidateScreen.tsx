/**
 * Ma Commune — Écran de validation 2FA lors du login (SEC-03)
 * Affiché après la saisie email/mot de passe quand la 2FA est active.
 */

import React, { useState, useRef } from 'react';
import {
  View, Text, TextInput, TouchableOpacity,
  StyleSheet, ScrollView, ActivityIndicator,
  KeyboardAvoidingView, Platform,
} from 'react-native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { RouteProp } from '@react-navigation/native';
import { useAuth } from '../services/AuthContext';
import { AuthStackParamList } from '../navigation/RootNavigator';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { CivicCompanionCard } from '../components/CivicCompanionCard';

type Props = {
  navigation: NativeStackNavigationProp<AuthStackParamList, 'TwoFactorValidate'>;
  route: RouteProp<AuthStackParamList, 'TwoFactorValidate'>;
};

export default function TwoFactorValidateScreen({ navigation, route }: Props) {
  const { userId, method } = route.params;
  const { loginWithCode } = useAuth();

  const [code,     setCode]     = useState('');
  const [loading,  setLoading]  = useState(false);
  const [error,    setError]    = useState('');
  const inputRef = useRef<TextInput>(null);

  const isTotp  = method === 'totp';
  const isEmail = method === 'email';

  const handleValidate = async () => {
    if (code.trim().length < 6) {
      setError('Entrez le code à 6 chiffres.');
      return;
    }
    setLoading(true);
    setError('');
    try {
      await loginWithCode(userId, code.trim());
      // La navigation vers l'app principale est gérée automatiquement
      // par RootNavigator via le changement d'état isAuthenticated
    } catch (err: any) {
      setError(err?.message === '2FA_REQUIRED'
        ? 'Code incorrect ou expiré. Réessayez.'
        : (err?.message ?? 'Code incorrect. Vérifiez et réessayez.')
      );
      setCode('');
      inputRef.current?.focus();
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <View style={styles.heroBackdrop} />

        <View style={styles.header}>
          <Text style={styles.shield}>🔐</Text>
          <Text style={styles.eyebrow}>Sécurité</Text>
          <Text style={styles.title}>Vérification en deux étapes</Text>
          <Text style={styles.subtitle}>
            {isTotp
              ? "Entrez le code affiche dans votre application d'authentification."
              : isEmail
              ? "Entrez le code envoye sur votre adresse email."
              : "Entrez votre code de verification."}
          </Text>
        </View>

        <View style={styles.companionWrap}>
          <CivicCompanionCard
            compact
            tone="guide"
            title={`${BRAND.companion.name} vérifie votre identité`}
            body={isTotp
              ? 'Ouvrez votre app d\'authentification et copiez le code actuel.'
              : 'Vérifiez votre boite mail. Le code expire dans 10 minutes.'}
          />
        </View>

        <View style={styles.form}>
          <Text style={styles.formLabel}>Code à 6 chiffres</Text>
          <TextInput
            ref={inputRef}
            style={[styles.codeInput, error ? styles.codeInputError : null]}
            value={code}
            onChangeText={t => { setCode(t.replace(/\D/g, '').slice(0, 6)); setError(''); }}
            keyboardType="number-pad"
            maxLength={6}
            textAlign="center"
            autoFocus
            placeholder="------"
            placeholderTextColor="#B0BCB6"
            returnKeyType="done"
            onSubmitEditing={handleValidate}
          />

          {error ? <Text style={styles.error}>{error}</Text> : null}

          <TouchableOpacity
            style={[styles.btn, (loading || code.length < 6) && styles.btnDisabled]}
            onPress={handleValidate}
            disabled={loading || code.length < 6}
          >
            {loading
              ? <ActivityIndicator color="#fff" />
              : <Text style={styles.btnText}>Valider et se connecter</Text>
            }
          </TouchableOpacity>

          <TouchableOpacity
            style={styles.backBtn}
            onPress={() => navigation.goBack()}
          >
            <Text style={styles.backBtnText}>← Revenir à la connexion</Text>
          </TouchableOpacity>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flexGrow: 1,
    backgroundColor: BRAND.colors.mist,
    padding: 24,
    justifyContent: 'center',
  },
  heroBackdrop: {
    position: 'absolute',
    top: 0, left: 0, right: 0,
    height: 220,
    backgroundColor: BRAND.colors.canopyDeep,
  },
  header: {
    alignItems: 'center',
    marginBottom: 28,
  },
  shield: {
    fontSize: 52,
    marginBottom: 12,
  },
  eyebrow: {
    color: '#D2A13A',
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    marginBottom: 6,
  },
  title: {
    fontSize: 24,
    fontWeight: '800',
    color: BRAND.colors.white,
    textAlign: 'center',
    marginBottom: 10,
  },
  subtitle: {
    fontSize: 14,
    color: '#DCE7E0',
    textAlign: 'center',
    lineHeight: 21,
    maxWidth: 300,
  },
  companionWrap: {
    marginBottom: 18,
  },
  form: {
    backgroundColor: '#FFFCF6',
    borderRadius: 24,
    padding: 24,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  formLabel: {
    fontSize: 14,
    fontWeight: '700',
    color: '#355248',
    marginBottom: 12,
    textAlign: 'center',
  },
  codeInput: {
    fontSize: 36,
    fontWeight: '800',
    letterSpacing: 14,
    color: BRAND.colors.canopyDeep,
    borderWidth: 2,
    borderColor: '#D1C9B8',
    borderRadius: 14,
    padding: 16,
    backgroundColor: '#FFFDF8',
    marginBottom: 12,
  },
  codeInputError: {
    borderColor: '#C0392B',
  },
  error: {
    color: '#C0392B',
    fontSize: 13,
    textAlign: 'center',
    marginBottom: 12,
    fontWeight: '600',
  },
  btn: {
    backgroundColor: BRAND.colors.canopyDeep,
    borderRadius: 14,
    padding: 16,
    alignItems: 'center',
    marginTop: 8,
  },
  btnDisabled: {
    opacity: 0.45,
  },
  btnText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '800',
  },
  backBtn: {
    alignItems: 'center',
    marginTop: 20,
  },
  backBtnText: {
    fontSize: 14,
    color: BRAND.colors.slate,
  },
});
