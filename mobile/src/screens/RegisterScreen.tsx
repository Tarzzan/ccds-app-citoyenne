/**
 * Ma Commune — Écran d'inscription
 */

import React, { useState } from 'react';
import {
  View, Text, StyleSheet, ScrollView, Image,
  TouchableOpacity, KeyboardAvoidingView, Platform, Alert,
} from 'react-native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useAuth } from '../services/AuthContext';
import { CivicCompanionStage } from '../components/CivicCompanionStage';
import { Button, Input, COLORS } from '../components/ui';
import { AuthStackParamList } from '../navigation/RootNavigator';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';
import { GENERATED_VISUAL_SOURCES } from '../theme/generatedVisualSources';

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
        <View style={styles.heroBackdrop} />
        <View style={styles.riverShape} />
        <View style={styles.earthShape} />

        <View style={styles.header}>
          <Image source={require('../../assets/icon.png')} style={styles.logo} />
          <Text style={styles.eyebrow}>Engagement citoyen</Text>
          <Text style={styles.appName}>{BRAND.name}</Text>
          <Text style={styles.territory}>{BRAND.territory}</Text>
          <Text style={styles.statement}>
            Rejoignez une application qui aide les habitants à protéger leur cadre de vie et à mieux dialoguer avec la commune.
          </Text>
        </View>

        <View style={styles.stageWrap}>
          <CivicCompanionStage
            eyebrow="Relais communal · Accueil citoyen"
            title="Creer un compte pour agir sans perdre le fil."
            body="Votre compte sert a signaler, suivre, voter et relire les reponses utiles de la commune dans un seul espace."
            aside="Le but n est pas seulement de declarer un probleme, mais de garder une preuve claire de sa prise en charge."
            visualSource={GENERATED_VISUAL_SOURCES['CHAR-05'] ?? COMPANION_VISUAL_SLOTS.register.source}
            visualBadgeLabel="Compte"
          />
        </View>

        <View style={styles.form}>
          <Text style={styles.title}>Créer un compte</Text>
          <Text style={styles.subtitle}>
            Votre compte vous permet de signaler, suivre, voter et documenter les besoins du territoire.
          </Text>

          <Input
            label="Nom complet"
            placeholder="Jean Dupont"
            value={fullName}
            onChangeText={setFullName}
            autoCapitalize="words"
            autoComplete="name"
            error={errors.fullName}
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
          />

          <Input
            label="Mot de passe"
            placeholder="8 caractères minimum"
            value={password}
            onChangeText={setPassword}
            secureTextEntry
            error={errors.password}
          />

          <Input
            label="Confirmer le mot de passe"
            placeholder="••••••••"
            value={confirm}
            onChangeText={setConfirm}
            secureTextEntry
            error={errors.confirm}
          />

          <Button
            title="Créer mon compte"
            onPress={handleRegister}
            loading={loading}
            style={{ marginTop: 8 }}
          />

          <TouchableOpacity
            style={styles.linkRow}
            onPress={() => navigation.goBack()}
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
    backgroundColor: BRAND.colors.mist,
    justifyContent: 'center',
    padding: 24,
  },
  heroBackdrop: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 320,
    backgroundColor: BRAND.colors.canopyDeep,
  },
  riverShape: {
    position: 'absolute',
    top: 84,
    right: -34,
    width: 170,
    height: 170,
    borderRadius: 85,
    backgroundColor: '#2D6F8630',
  },
  earthShape: {
    position: 'absolute',
    top: 170,
    left: -18,
    width: 96,
    height: 96,
    borderRadius: 48,
    backgroundColor: '#A64B2A24',
  },
  header: {
    marginBottom: 30,
  },
  logo: {
    width: 78,
    height: 78,
    borderRadius: 22,
    marginBottom: 14,
  },
  eyebrow: {
    color: '#D2A13A',
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    marginBottom: 8,
  },
  appName: {
    fontSize: 32,
    fontWeight: '800',
    color: BRAND.colors.white,
    fontFamily: BRAND.displayFont,
  },
  territory: {
    fontSize: 13,
    color: '#DCE7E0',
    marginTop: 6,
    fontWeight: '600',
    letterSpacing: 0.4,
  },
  statement: {
    fontSize: 17,
    color: '#F4F1E7',
    lineHeight: 24,
    marginTop: 16,
    maxWidth: 320,
    fontWeight: '700',
  },
  form: {
    backgroundColor: '#FFFCF6',
    borderRadius: 24,
    padding: 24,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  stageWrap: {
    marginBottom: 18,
  },
  title:    { fontSize: 22, fontWeight: '800', color: COLORS.dark, marginBottom: 6 },
  subtitle: { fontSize: 14, color: COLORS.gray, marginBottom: 24, lineHeight: 22 },
  linkRow:  { alignItems: 'center', marginTop: 20 },
  linkText: { fontSize: 14, color: COLORS.gray },
  link:     { color: COLORS.primary, fontWeight: '600' },
});
