/**
 * CCDS — Écran de Connexion
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

type Props = { navigation: NativeStackNavigationProp<AuthStackParamList, 'Login'> };

export default function LoginScreen({ navigation }: Props) {
  const { login } = useAuth();
  const [email,    setEmail]    = useState('');
  const [password, setPassword] = useState('');
  const [loading,  setLoading]  = useState(false);
  const [errors,   setErrors]   = useState<Record<string, string>>({});

  const validate = () => {
    const e: Record<string, string> = {};
    if (!email.trim())    e.email    = 'L\'email est obligatoire.';
    if (!password.trim()) e.password = 'Le mot de passe est obligatoire.';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleLogin = async () => {
    if (!validate()) return;
    setLoading(true);
    try {
      await login(email.trim().toLowerCase(), password);
    } catch (err: any) {
      Alert.alert('Erreur de connexion', err?.message ?? 'Email ou mot de passe incorrect.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">

        {/* En-tête */}
        <View style={styles.header}>
          <Text style={styles.logo}>🌿</Text>
          <Text style={styles.appName}>CCDS Citoyen</Text>
          <Text style={styles.tagline}>Kourou · Sinnamary · Iracoubo</Text>
          <Text style={styles.subTagline}>Signalez. Suivez. Améliorez.</Text>
        </View>

        {/* Formulaire */}
        <View style={styles.form}>
          <Text style={styles.title}>Connexion</Text>

          <Input
            label="Adresse email"
            placeholder="citoyen@commune.fr"
            value={email}
            onChangeText={setEmail}
            keyboardType="email-address"
            autoCapitalize="none"
            autoComplete="email"
            error={errors.email}
          />

          <Input
            label="Mot de passe"
            placeholder="••••••••"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            autoComplete="password"
            error={errors.password}
          />

          <Button
            title="Se connecter"
            onPress={handleLogin}
            loading={loading}
            style={{ marginTop: 8 }}
          />

          <TouchableOpacity
            style={styles.linkRow}
            onPress={() => navigation.navigate('Register')}
          >
            <Text style={styles.linkText}>
              Pas encore de compte ?{' '}
              <Text style={styles.link}>Créer un compte</Text>
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
    marginBottom: 40,
  },
  logo: {
    fontSize: 56,
    marginBottom: 8,
  },
  appName: {
    fontSize: 32,
    fontWeight: '800',
    color: COLORS.primary,
    letterSpacing: 2,
  },
  tagline: {
    fontSize: 15,
    color: '#a7f3d0',
    marginTop: 4,
    fontWeight: '600',
    letterSpacing: 1,
  },
  subTagline: {
    fontSize: 13,
    color: '#6ee7b7',
    marginTop: 2,
  },
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
  title: {
    fontSize: 22,
    fontWeight: '700',
    color: COLORS.dark,
    marginBottom: 24,
  },
  linkRow: {
    alignItems: 'center',
    marginTop: 20,
  },
  linkText: {
    fontSize: 14,
    color: COLORS.gray,
  },
  link: {
    color: COLORS.primary,
    fontWeight: '600',
  },
});
