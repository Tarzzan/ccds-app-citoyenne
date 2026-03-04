/**
 * TwoFactorScreen — Configuration et validation 2FA (SEC-03)
 * Permet à l'utilisateur d'activer/désactiver la 2FA TOTP ou email.
 */

import React, { useState, useEffect } from 'react';
import {
  View, Text, TextInput, TouchableOpacity, StyleSheet,
  ScrollView, Alert, ActivityIndicator, Image, Clipboard,
} from 'react-native';
import { authApi } from '../services/api';

type Step = 'status' | 'setup' | 'verify' | 'backup_codes' | 'active';

export default function TwoFactorScreen() {
  const [step, setStep]             = useState<Step>('status');
  const [loading, setLoading]       = useState(true);
  const [enabled, setEnabled]       = useState(false);
  const [method, setMethod]         = useState<'none' | 'totp' | 'email'>('none');
  const [secret, setSecret]         = useState('');
  const [qrCodeUrl, setQrCodeUrl]   = useState('');
  const [backupCodes, setBackupCodes] = useState<string[]>([]);
  const [code, setCode]             = useState('');
  const [password, setPassword]     = useState('');
  const [error, setError]           = useState('');

  useEffect(() => {
    loadStatus();
  }, []);

  const loadStatus = async () => {
    try {
      setLoading(true);
      const res = await authApi.setup2FA();
      // Utiliser getStatus à la place
      setEnabled(false);
      setMethod('none');
      setStep('status');
    } catch {
      setEnabled(false);
    } finally {
      setLoading(false);
    }
  };

  const handleSetup = async () => {
    try {
      setLoading(true);
      setError('');
      const res = await authApi.setup2FA();
      if (res.data) {
        setSecret(res.data.secret);
        setQrCodeUrl(res.data.qr_code_url);
        setBackupCodes(res.data.backup_codes);
        setStep('setup');
      }
    } catch (e: any) {
      setError(e.message || 'Erreur lors de la configuration.');
    } finally {
      setLoading(false);
    }
  };

  const handleVerify = async () => {
    if (code.length !== 6) {
      setError('Entrez le code à 6 chiffres de votre application.');
      return;
    }
    try {
      setLoading(true);
      setError('');
      await authApi.verify2FA({ code });
      setStep('backup_codes');
    } catch (e: any) {
      setError('Code incorrect. Vérifiez l\'heure de votre appareil.');
    } finally {
      setLoading(false);
    }
  };

  const handleDisable = async () => {
    Alert.alert(
      'Désactiver la 2FA',
      'Êtes-vous sûr de vouloir désactiver la double authentification ?',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Désactiver',
          style: 'destructive',
          onPress: async () => {
            try {
              setLoading(true);
              await authApi.disable2FA({ password });
              setEnabled(false);
              setMethod('none');
              setStep('status');
            } catch {
              setError('Mot de passe incorrect.');
            } finally {
              setLoading(false);
            }
          },
        },
      ]
    );
  };

  const copyToClipboard = (text: string) => {
    Clipboard.setString(text);
    Alert.alert('Copié !', 'Le secret a été copié dans le presse-papiers.');
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#2E7D32" />
      </View>
    );
  }

  // ── Étape 1 : Statut actuel ──────────────────────────────────────────────
  if (step === 'status') {
    return (
      <ScrollView style={styles.container}>
        <View style={styles.header}>
          <Text style={styles.shield}>🔐</Text>
          <Text style={styles.title}>Double authentification</Text>
          <Text style={styles.subtitle}>
            Renforcez la sécurité de votre compte en activant la 2FA.
          </Text>
        </View>

        <View style={[styles.statusBadge, enabled ? styles.statusOn : styles.statusOff]}>
          <Text style={styles.statusText}>
            {enabled ? `✅ Activée (${method === 'totp' ? 'Application' : 'Email'})` : '❌ Désactivée'}
          </Text>
        </View>

        {!enabled ? (
          <TouchableOpacity style={styles.primaryBtn} onPress={handleSetup}>
            <Text style={styles.primaryBtnText}>Activer la 2FA</Text>
          </TouchableOpacity>
        ) : (
          <>
            <TextInput
              style={styles.input}
              placeholder="Mot de passe actuel"
              secureTextEntry
              value={password}
              onChangeText={setPassword}
            />
            <TouchableOpacity style={styles.dangerBtn} onPress={handleDisable}>
              <Text style={styles.dangerBtnText}>Désactiver la 2FA</Text>
            </TouchableOpacity>
          </>
        )}

        {error ? <Text style={styles.error}>{error}</Text> : null}
      </ScrollView>
    );
  }

  // ── Étape 2 : Affichage du QR code ──────────────────────────────────────
  if (step === 'setup') {
    return (
      <ScrollView style={styles.container}>
        <Text style={styles.title}>Configurer l'application</Text>
        <Text style={styles.instructions}>
          1. Installez Google Authenticator ou Authy sur votre téléphone.{'\n'}
          2. Scannez le QR code ci-dessous.{'\n'}
          3. Entrez le code à 6 chiffres affiché.
        </Text>

        {qrCodeUrl ? (
          <Image source={{ uri: qrCodeUrl }} style={styles.qrCode} />
        ) : null}

        <TouchableOpacity onPress={() => copyToClipboard(secret)}>
          <View style={styles.secretBox}>
            <Text style={styles.secretLabel}>Clé secrète (saisie manuelle)</Text>
            <Text style={styles.secretValue}>{secret}</Text>
            <Text style={styles.copyHint}>Appuyez pour copier</Text>
          </View>
        </TouchableOpacity>

        <TouchableOpacity style={styles.primaryBtn} onPress={() => setStep('verify')}>
          <Text style={styles.primaryBtnText}>J'ai scanné le QR code →</Text>
        </TouchableOpacity>
      </ScrollView>
    );
  }

  // ── Étape 3 : Vérification du code ──────────────────────────────────────
  if (step === 'verify') {
    return (
      <ScrollView style={styles.container}>
        <Text style={styles.title}>Vérifier le code</Text>
        <Text style={styles.instructions}>
          Entrez le code à 6 chiffres affiché dans votre application d'authentification.
        </Text>

        <TextInput
          style={[styles.input, styles.codeInput]}
          placeholder="000000"
          keyboardType="numeric"
          maxLength={6}
          value={code}
          onChangeText={setCode}
          textAlign="center"
        />

        {error ? <Text style={styles.error}>{error}</Text> : null}

        <TouchableOpacity style={styles.primaryBtn} onPress={handleVerify}>
          <Text style={styles.primaryBtnText}>Vérifier et activer</Text>
        </TouchableOpacity>
      </ScrollView>
    );
  }

  // ── Étape 4 : Codes de récupération ─────────────────────────────────────
  if (step === 'backup_codes') {
    return (
      <ScrollView style={styles.container}>
        <Text style={styles.title}>✅ 2FA activée !</Text>
        <Text style={[styles.instructions, styles.warning]}>
          ⚠️ Conservez ces codes de récupération en lieu sûr. Ils permettent d'accéder à votre compte si vous perdez votre téléphone.
        </Text>

        <View style={styles.codesGrid}>
          {backupCodes.map((c, i) => (
            <View key={i} style={styles.codeChip}>
              <Text style={styles.codeChipText}>{c}</Text>
            </View>
          ))}
        </View>

        <TouchableOpacity
          style={styles.primaryBtn}
          onPress={() => {
            setEnabled(true);
            setMethod('totp');
            setStep('status');
          }}
        >
          <Text style={styles.primaryBtnText}>J'ai sauvegardé mes codes →</Text>
        </TouchableOpacity>
      </ScrollView>
    );
  }

  return null;
}

const styles = StyleSheet.create({
  container:      { flex: 1, backgroundColor: '#F5F5F5', padding: 20 },
  center:         { flex: 1, alignItems: 'center', justifyContent: 'center' },
  header:         { alignItems: 'center', marginBottom: 24 },
  shield:         { fontSize: 56, marginBottom: 12 },
  title:          { fontSize: 22, fontWeight: '700', color: '#1B5E20', marginBottom: 8 },
  subtitle:       { fontSize: 14, color: '#666', textAlign: 'center', lineHeight: 20 },
  instructions:   { fontSize: 14, color: '#444', lineHeight: 22, marginBottom: 20 },
  warning:        { color: '#E65100', backgroundColor: '#FFF3E0', padding: 12, borderRadius: 8 },
  statusBadge:    { padding: 16, borderRadius: 12, alignItems: 'center', marginBottom: 24 },
  statusOn:       { backgroundColor: '#E8F5E9' },
  statusOff:      { backgroundColor: '#FFEBEE' },
  statusText:     { fontSize: 16, fontWeight: '600' },
  input:          { backgroundColor: '#FFF', borderWidth: 1, borderColor: '#DDD', borderRadius: 10, padding: 14, fontSize: 16, marginBottom: 16 },
  codeInput:      { fontSize: 28, fontWeight: '700', letterSpacing: 8, height: 70 },
  primaryBtn:     { backgroundColor: '#2E7D32', padding: 16, borderRadius: 12, alignItems: 'center', marginBottom: 12 },
  primaryBtnText: { color: '#FFF', fontSize: 16, fontWeight: '700' },
  dangerBtn:      { backgroundColor: '#C62828', padding: 16, borderRadius: 12, alignItems: 'center' },
  dangerBtnText:  { color: '#FFF', fontSize: 16, fontWeight: '700' },
  error:          { color: '#C62828', textAlign: 'center', marginBottom: 12 },
  qrCode:         { width: 200, height: 200, alignSelf: 'center', marginBottom: 20 },
  secretBox:      { backgroundColor: '#FFF', padding: 16, borderRadius: 10, marginBottom: 20, borderWidth: 1, borderColor: '#E0E0E0' },
  secretLabel:    { fontSize: 12, color: '#999', marginBottom: 4 },
  secretValue:    { fontSize: 14, fontFamily: 'monospace', letterSpacing: 2, color: '#333' },
  copyHint:       { fontSize: 11, color: '#2E7D32', marginTop: 6 },
  codesGrid:      { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 24 },
  codeChip:       { backgroundColor: '#FFF', borderWidth: 1, borderColor: '#DDD', borderRadius: 8, padding: 10 },
  codeChipText:   { fontFamily: 'monospace', fontSize: 14, fontWeight: '600', color: '#333' },
});
