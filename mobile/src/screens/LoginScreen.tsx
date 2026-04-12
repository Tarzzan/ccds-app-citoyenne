/**
 * Ma Commune — Écran de connexion
 */

import React, { useState } from 'react';
import {
  View, Text, StyleSheet, ScrollView, Image,
  TouchableOpacity, KeyboardAvoidingView, Platform, Alert,
} from 'react-native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { useAuth } from '../services/AuthContext';
import { TwoFactorRequiredError } from '../services/AuthContext';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { Button, Input, COLORS } from '../components/ui';
import { AuthStackParamList } from '../navigation/RootNavigator';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';

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
      if (err instanceof TwoFactorRequiredError) {
        navigation.navigate('TwoFactorValidate', {
          userId: err.userId,
          method: err.method,
        });
        return;
      }
      Alert.alert(
        `${BRAND.companion.name} n'a pas pu vous faire entrer`,
        err?.message ?? 'Vérifiez votre email et votre mot de passe, puis réessayez.'
      );
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

        {/* En-tête */}
        <View style={styles.header}>
          <Image source={require('../../assets/icon.png')} style={styles.logo} />
          <Text style={styles.eyebrow}>{BRAND.missionLabel}</Text>
          <Text style={styles.appName}>{BRAND.name}</Text>
          <Text style={styles.tagline}>{BRAND.territory}</Text>
          <Text style={styles.subTagline}>{BRAND.copy.heroTitle}</Text>
          <View style={styles.pillRow}>
            <View style={styles.pill}>
              <Text style={styles.pillText}>Signaler</Text>
            </View>
            <View style={styles.pill}>
              <Text style={styles.pillText}>Suivre</Text>
            </View>
            <View style={styles.pill}>
              <Text style={styles.pillText}>Servir le quartier</Text>
            </View>
          </View>
        </View>

        <View style={styles.companionWrap}>
          <CivicCompanionCard
            compact
            tone="guide"
            title={`${BRAND.companion.name} vous oriente vers la bonne suite`}
            body="Connectez-vous pour signaler, suivre un dossier ou relire une mise a jour utile sans perdre le fil de la prise en charge."
            visualSource={COMPANION_VISUAL_SLOTS.login.source}
            bullets={[
              'un point d entree simple',
              'un suivi clair dossier par dossier',
            ]}
          />
        </View>

        {/* Formulaire */}
        <View style={styles.form}>
          <Text style={styles.title}>Connexion</Text>
          <Text style={styles.formIntro}>
            Accedez a votre espace citoyen pour declarer un probleme utile, suivre sa prise en charge et garder une preuve claire de l action communale.
          </Text>

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

          <TouchableOpacity
            style={styles.secondaryLinkRow}
            onPress={() => navigation.navigate('ServerConfig')}
          >
            <Text style={styles.secondaryLinkText}>
              Adresse du serveur incorrecte ?{' '}
              <Text style={styles.link}>Configurer le serveur</Text>
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
    height: 300,
    backgroundColor: BRAND.colors.canopyDeep,
  },
  riverShape: {
    position: 'absolute',
    top: 90,
    right: -30,
    width: 160,
    height: 160,
    borderRadius: 80,
    backgroundColor: '#2D6F8633',
  },
  earthShape: {
    position: 'absolute',
    top: 150,
    left: -20,
    width: 110,
    height: 110,
    borderRadius: 55,
    backgroundColor: '#A64B2A22',
  },
  header: {
    marginBottom: 36,
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
    fontSize: 34,
    fontWeight: '800',
    color: BRAND.colors.white,
    fontFamily: BRAND.displayFont,
  },
  tagline: {
    fontSize: 13,
    color: '#DCE7E0',
    marginTop: 4,
    fontWeight: '600',
    letterSpacing: 0.5,
  },
  subTagline: {
    fontSize: 18,
    color: '#F4F1E7',
    marginTop: 16,
    lineHeight: 25,
    maxWidth: 290,
    fontWeight: '700',
  },
  pillRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 16,
  },
  pill: {
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
    backgroundColor: '#FFFFFF16',
    borderWidth: 1,
    borderColor: '#FFFFFF22',
  },
  pillText: {
    color: '#F4F1E7',
    fontSize: 12,
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
  companionWrap: {
    marginBottom: 18,
  },
  title: {
    fontSize: 22,
    fontWeight: '800',
    color: COLORS.dark,
    marginBottom: 10,
  },
  formIntro: {
    fontSize: 14,
    lineHeight: 22,
    color: BRAND.colors.slate,
    marginBottom: 24,
  },
  linkRow: {
    alignItems: 'center',
    marginTop: 20,
  },
  secondaryLinkRow: {
    alignItems: 'center',
    marginTop: 12,
  },
  linkText: {
    fontSize: 14,
    color: COLORS.gray,
  },
  secondaryLinkText: {
    fontSize: 13,
    color: BRAND.colors.slate,
    textAlign: 'center',
    lineHeight: 20,
  },
  link: {
    color: COLORS.primary,
    fontWeight: '600',
  },
});
