/**
 * CCDS — Écran d'Inscription
 */

import React, { useState } from 'react';
import {
  View, Text, StyleSheet, ScrollView,
  TouchableOpacity, KeyboardAvoidingView, Platform, Alert,
} from 'react-native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useAuth } from '../services/AuthContext';
import { Button, Input, COLORS } from '../components/ui';
import { AuthStackParamList } from '../navigation/RootNavigator';

type Props = { navigation: NativeStackNavigationProp<AuthStackParamList, 'Register'> };

export default function RegisterScreen({ navigation }: Props) {
  const { register } = useAuth();
  const [fullName,  setFullName]  = useState('');
  const [email,     setEmail]     = useState('');
  const [password,  setPassword]  = useState('');
  const [confirm,   setConfirm]   = useState('');
  const [loading,   setLoading]   = useState(false);
  const [errors,    setErrors]    = useState<Record<string, string>>({});

  const validate = () => {
    const e: Record<string, string> = {};
    if (!fullName.trim() || fullName.trim().length < 2)
      e.fullName = 'Le nom complet doit contenir au moins 2 caractères.';
    if (!email.trim() || !/\S+@\S+\.\S+/.test(email))
      e.email = 'Adresse email invalide.';
    if (!password || password.length < 8)
      e.password = 'Le mot de passe doit contenir au moins 8 caractères.';
    if (password !== confirm)
      e.confirm = 'Les mots de passe ne correspondent pas.';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleRegister = async () => {
    if (!validate()) return;
    setLoading(true);
    try {
      await register({ email: email.trim().toLowerCase(), password, full_name: fullName.trim() });
    } catch (err: any) {
      Alert.alert('Erreur', err?.message ?? 'Impossible de créer le compte.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">

        <View style={styles.header} accessibilityRole="header">
          <Text style={styles.logo} accessibilityLabel="Logo CCDS Citoyen" accessibilityRole="image">🌿</Text>
          <Text style={styles.appName}>CCDS Citoyen</Text>
          <Text style={{ fontSize: 12, color: '#6ee7b7', marginTop: 2 }}>Guyane Française</Text>
        </View>

        <View style={styles.form}>
          <Text style={styles.title}>Créer un compte</Text>
          <Text style={styles.subtitle}>Rejoignez les citoyens actifs de Kourou, Sinnamary et Iracoubo.</Text>

          <Input
            label="Nom complet"
            placeholder="Jean Dupont"
            value={fullName}
            onChangeText={setFullName}
            autoCapitalize="words"
            autoComplete="name"
            error={errors.fullName}
            accessibilityLabel="Nom complet"
            accessibilityHint="Entrez votre prénom et nom de famille"
            accessibilityRequired={true}
          />

          <Input
            label="Adresse email"
            placeholder="jean.dupont@email.fr"
            value={email}
            onChangeText={setEmail}
            keyboardType="email-address"
            autoCapitalize="none"
            autoComplete="email"
            error={errors.email}
            accessibilityLabel="Adresse email"
            accessibilityHint="Entrez une adresse email valide"
            accessibilityRequired={true}
          />

          <Input
            label="Mot de passe"
            placeholder="8 caractères minimum"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            error={errors.password}
            accessibilityLabel="Mot de passe"
            accessibilityHint="Minimum 8 caractères"
            accessibilityRequired={true}
          />

          <Input
            label="Confirmer le mot de passe"
            placeholder="••••••••"
            value={confirm}
            onChangeText={setConfirm}
            secureTextEntry
            error={errors.confirm}
            accessibilityLabel="Confirmer le mot de passe"
            accessibilityHint="Saisissez à nouveau votre mot de passe pour confirmation"
            accessibilityRequired={true}
          />

          <Button
            title="Créer mon compte"
            onPress={handleRegister}
            loading={loading}
            style={{ marginTop: 8 }}
            accessibilityLabel={loading ? 'Création du compte en cours...' : 'Créer mon compte'}
            accessibilityRole="button"
            accessibilityState={{ disabled: loading, busy: loading }}
          />

          <TouchableOpacity
            style={styles.linkRow}
            onPress={() => navigation.goBack()}
            accessibilityLabel="Se connecter"
            accessibilityRole="link"
            accessibilityHint="Retourner à l'écran de connexion"
          >
            <Text style={styles.linkText}>
              Déjà un compte ?{' '}
              <Text style={styles.link}>Se connecter</Text>
            </Text>
          </TouchableOpacity>
        </View>

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flexGrow: 1,
    backgroundColor: '#0f4c2a',
    justifyContent: 'center',
    padding: 24,
  },
  header: {
    alignItems: 'center',
    marginBottom: 32,
  },
  logo: { fontSize: 48, marginBottom: 6 },
  appName: { fontSize: 28, fontWeight: '800', color: COLORS.primary, letterSpacing: 2 },
  form: {
    backgroundColor: COLORS.white,
    borderRadius: 20,
    padding: 24,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.08,
    shadowRadius: 16,
    elevation: 4,
  },
  title:    { fontSize: 22, fontWeight: '700', color: COLORS.dark, marginBottom: 6 },
  subtitle: { fontSize: 14, color: COLORS.gray, marginBottom: 24 },
  linkRow:  { alignItems: 'center', marginTop: 20 },
  linkText: { fontSize: 14, color: COLORS.gray },
  link:     { color: COLORS.primary, fontWeight: '600' },
});
