/**
 * CCDS — Écran de Configuration Serveur
 * Affiché au premier lancement ou depuis les paramètres.
 * Permet de saisir et tester l'URL du serveur API CCDS.
 */

import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  ScrollView,
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
} from 'react-native';
import { ServerConfig } from '../services/ServerConfig';

const COLORS = {
  primary:     '#1a7a42',
  primaryDark: '#0f4c2a',
  white:       '#ffffff',
  gray100:     '#f0fdf4',
  gray300:     '#86efac',
  gray500:     '#6b7280',
  gray700:     '#374151',
  success:     '#16a34a',
  error:       '#dc2626',
  warning:     '#d97706',
};

type TestStatus = 'idle' | 'testing' | 'success' | 'error';

interface Props {
  onConfigured: () => void;
  isFirstLaunch?: boolean;
}

export default function ServerConfigScreen({ onConfigured, isFirstLaunch = true }: Props) {
  const [serverUrl, setServerUrl]   = useState('');
  const [testStatus, setTestStatus] = useState<TestStatus>('idle');
  const [testMessage, setTestMessage] = useState('');
  const [isSaving, setIsSaving]     = useState(false);

  // Charger l'URL existante si déjà configurée
  useEffect(() => {
    ServerConfig.getServerUrl().then((url) => {
      if (url && url !== 'https://votre-domaine.com/api') {
        setServerUrl(url);
      }
    });
  }, []);

  const handleTest = async () => {
    if (!serverUrl.trim()) {
      Alert.alert('Champ requis', 'Veuillez saisir l\'URL du serveur.');
      return;
    }
    setTestStatus('testing');
    setTestMessage('');
    const result = await ServerConfig.testConnection(serverUrl);
    setTestStatus(result.success ? 'success' : 'error');
    setTestMessage(result.message);
  };

  const handleSave = async () => {
    if (!serverUrl.trim()) {
      Alert.alert('Champ requis', 'Veuillez saisir l\'URL du serveur.');
      return;
    }
    if (testStatus !== 'success') {
      Alert.alert(
        'Test requis',
        'Veuillez tester la connexion avant de sauvegarder.',
        [
          { text: 'Tester maintenant', onPress: handleTest },
          { text: 'Annuler', style: 'cancel' },
        ]
      );
      return;
    }
    setIsSaving(true);
    await ServerConfig.setServerUrl(serverUrl);
    setIsSaving(false);
    onConfigured();
  };

  const getTestStatusColor = () => {
    switch (testStatus) {
      case 'success': return COLORS.success;
      case 'error':   return COLORS.error;
      case 'testing': return COLORS.warning;
      default:        return COLORS.gray500;
    }
  };

  const getTestStatusIcon = () => {
    switch (testStatus) {
      case 'success': return '✅';
      case 'error':   return '❌';
      case 'testing': return '⏳';
      default:        return '🔌';
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      <ScrollView contentContainerStyle={styles.scroll} keyboardShouldPersistTaps="handled">

        {/* En-tête */}
        <View style={styles.header}>
          <Text style={styles.logo}>🌿</Text>
          <Text style={styles.title}>CCDS Citoyen</Text>
          <Text style={styles.subtitle}>
            {isFirstLaunch
              ? 'Bienvenue ! Configurez votre serveur pour commencer.'
              : 'Modifier la configuration du serveur'}
          </Text>
        </View>

        {/* Carte de configuration */}
        <View style={styles.card}>
          <Text style={styles.sectionTitle}>🖥️ Adresse du serveur</Text>
          <Text style={styles.hint}>
            Saisissez l'URL de l'API fournie par votre administrateur.{'\n'}
            Exemple : <Text style={styles.code}>https://admin.ccds-guyane.fr/backend</Text>
          </Text>

          <TextInput
            style={styles.input}
            value={serverUrl}
            onChangeText={(text) => {
              setServerUrl(text);
              setTestStatus('idle');
              setTestMessage('');
            }}
            placeholder="https://votre-serveur.fr/backend"
            placeholderTextColor={COLORS.gray500}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="url"
          />

          {/* Bouton Tester */}
          <TouchableOpacity
            style={[styles.btnTest, testStatus === 'testing' && styles.btnDisabled]}
            onPress={handleTest}
            disabled={testStatus === 'testing'}
          >
            {testStatus === 'testing' ? (
              <ActivityIndicator color={COLORS.white} size="small" />
            ) : (
              <Text style={styles.btnTestText}>🔍 Tester la connexion</Text>
            )}
          </TouchableOpacity>

          {/* Résultat du test */}
          {testStatus !== 'idle' && (
            <View style={[styles.testResult, { borderColor: getTestStatusColor() }]}>
              <Text style={[styles.testResultText, { color: getTestStatusColor() }]}>
                {getTestStatusIcon()}  {testMessage}
              </Text>
            </View>
          )}
        </View>

        {/* Exemples */}
        <View style={styles.card}>
          <Text style={styles.sectionTitle}>💡 Exemples d'URL</Text>
          {[
            { label: 'Production CCDS', url: 'https://admin.ccds-guyane.fr/backend' },
            { label: 'Serveur local (Wi-Fi)', url: 'http://192.168.1.100/ccds/backend' },
            { label: 'Démo Manus', url: 'https://demo.manus.space/backend' },
          ].map((ex) => (
            <TouchableOpacity
              key={ex.url}
              style={styles.exampleRow}
              onPress={() => {
                setServerUrl(ex.url);
                setTestStatus('idle');
                setTestMessage('');
              }}
            >
              <View>
                <Text style={styles.exampleLabel}>{ex.label}</Text>
                <Text style={styles.exampleUrl}>{ex.url}</Text>
              </View>
              <Text style={styles.exampleArrow}>→</Text>
            </TouchableOpacity>
          ))}
        </View>

        {/* Bouton Enregistrer */}
        <TouchableOpacity
          style={[
            styles.btnSave,
            testStatus !== 'success' && styles.btnSaveDisabled,
            isSaving && styles.btnDisabled,
          ]}
          onPress={handleSave}
          disabled={isSaving || testStatus !== 'success'}
        >
          {isSaving ? (
            <ActivityIndicator color={COLORS.white} size="small" />
          ) : (
            <Text style={styles.btnSaveText}>
              {testStatus === 'success' ? '✅ Enregistrer et continuer' : '🔒 Testez d\'abord la connexion'}
            </Text>
          )}
        </TouchableOpacity>

        <Text style={styles.footer}>
          Vous pourrez modifier cette configuration à tout moment depuis les paramètres de l'application.
        </Text>

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: COLORS.gray100,
  },
  scroll: {
    padding: 20,
    paddingTop: 60,
  },
  header: {
    alignItems: 'center',
    marginBottom: 28,
  },
  logo: {
    fontSize: 56,
    marginBottom: 8,
  },
  title: {
    fontSize: 28,
    fontWeight: '700',
    color: COLORS.primaryDark,
    marginBottom: 6,
  },
  subtitle: {
    fontSize: 15,
    color: COLORS.gray500,
    textAlign: 'center',
    lineHeight: 22,
  },
  card: {
    backgroundColor: COLORS.white,
    borderRadius: 12,
    padding: 18,
    marginBottom: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.06,
    shadowRadius: 6,
    elevation: 2,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: COLORS.gray700,
    marginBottom: 8,
  },
  hint: {
    fontSize: 13,
    color: COLORS.gray500,
    marginBottom: 14,
    lineHeight: 20,
  },
  code: {
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
    color: COLORS.primary,
    fontSize: 12,
  },
  input: {
    borderWidth: 1.5,
    borderColor: COLORS.gray300,
    borderRadius: 8,
    padding: 12,
    fontSize: 14,
    color: COLORS.gray700,
    backgroundColor: '#f9fafb',
    marginBottom: 12,
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
  },
  btnTest: {
    backgroundColor: COLORS.primary,
    borderRadius: 8,
    padding: 13,
    alignItems: 'center',
  },
  btnTestText: {
    color: COLORS.white,
    fontWeight: '600',
    fontSize: 15,
  },
  btnDisabled: {
    opacity: 0.6,
  },
  testResult: {
    marginTop: 12,
    borderWidth: 1.5,
    borderRadius: 8,
    padding: 10,
    backgroundColor: '#f9fafb',
  },
  testResultText: {
    fontSize: 14,
    fontWeight: '600',
    textAlign: 'center',
  },
  exampleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#f0f0f0',
  },
  exampleLabel: {
    fontSize: 13,
    fontWeight: '600',
    color: COLORS.gray700,
  },
  exampleUrl: {
    fontSize: 11,
    color: COLORS.primary,
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
  },
  exampleArrow: {
    fontSize: 18,
    color: COLORS.gray300,
  },
  btnSave: {
    backgroundColor: COLORS.primary,
    borderRadius: 12,
    padding: 16,
    alignItems: 'center',
    marginTop: 4,
    marginBottom: 16,
  },
  btnSaveDisabled: {
    backgroundColor: '#9ca3af',
  },
  btnSaveText: {
    color: COLORS.white,
    fontWeight: '700',
    fontSize: 16,
  },
  footer: {
    fontSize: 12,
    color: COLORS.gray500,
    textAlign: 'center',
    lineHeight: 18,
    marginBottom: 40,
  },
});
