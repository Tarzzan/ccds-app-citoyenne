/**
 * Ma Commune — Écran de configuration serveur
 * Affiché au premier lancement ou depuis les paramètres.
 * Permet de saisir et tester l'URL du serveur API Ma Commune.
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
  Image,
} from 'react-native';
import { isPlaceholderServerUrl, ServerConfig } from '../services/ServerConfig';
import { CivicCompanionStage } from '../components/CivicCompanionStage';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COLORS } from '../components/ui';

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
      if (url && !isPlaceholderServerUrl(url)) {
        setServerUrl(url);
      }
    });
  }, []);

  const handleTest = async () => {
    if (!serverUrl.trim()) {
      Alert.alert(
        'Adresse requise',
        'Saisissez d abord l adresse complete du serveur communal.'
      );
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
      Alert.alert(
        'Adresse requise',
        'Saisissez d abord l adresse complete du serveur communal.'
      );
      return;
    }
    if (testStatus !== 'success') {
      Alert.alert(
        `${BRAND.companion.name} prefere verifier avant d enregistrer`,
        'Testez d abord la connexion pour confirmer que cette adresse repond bien.',
        [
          { text: 'Verifier maintenant', onPress: handleTest },
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
      case 'error':   return COLORS.danger;
      case 'testing': return COLORS.warning;
      default:        return COLORS.gray;
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
          <Text style={styles.eyebrow}>Connexion territoire</Text>
          <Image source={require('../../assets/icon.png')} style={styles.logo} />
          <Text style={styles.title}>{BRAND.name}</Text>
          <Text style={styles.subtitle}>
            {isFirstLaunch
              ? 'Reliez l’application au serveur communal pour commencer à agir.'
              : 'Modifier la configuration du serveur de la commune'}
          </Text>
        </View>

        <View style={styles.tipCard}>
          <Text style={styles.tipTitle}>Accès rapide en local</Text>
          <Text style={styles.tipText}>
            Si la tablette est reliée en USB avec `adb reverse`, utilisez `http://127.0.0.1:8080/api`.
          </Text>
        </View>

        <View style={styles.stageWrap}>
          <CivicCompanionStage
            eyebrow="Awa · Connexion territoire"
            title="Verifier d abord la bonne porte d entree."
            body="Une URL API juste suffit a rendre l application pleinement utile. L objectif ici est de connecter la commune, pas de perdre du temps dans des reglages techniques."
            aside="La bonne adresse doit toujours se terminer par /api."
          />
        </View>

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Adresse du serveur API</Text>
          <Text style={styles.hint}>
            Saisissez l'URL exacte de l'API fournie par votre administrateur ou votre poste de développement.
            {'\n'}Exemple local : <Text style={styles.code}>http://127.0.0.1:8080/api</Text>
          </Text>

          <TextInput
            style={styles.input}
            value={serverUrl}
            onChangeText={(text) => {
              setServerUrl(text);
              setTestStatus('idle');
              setTestMessage('');
            }}
            placeholder="https://votre-serveur.fr/api"
            placeholderTextColor={COLORS.gray}
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
              <Text style={styles.btnTestText}>Tester la connexion</Text>
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

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>Exemples d'URL</Text>
          {[
            { label: 'Débogage USB local', url: 'http://127.0.0.1:8080/api' },
            { label: 'API production', url: 'https://api.netetfix.com/api' },
            { label: 'Serveur local en Wi-Fi', url: 'http://192.168.1.100:8080/api' },
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
          <Text style={styles.hint}>
            Back-office web : <Text style={styles.code}>https://admin.netetfix.com/admin/?page=login</Text>
          </Text>
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
              {testStatus === 'success' ? 'Enregistrer et continuer' : 'Testez d\'abord la connexion'}
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
    backgroundColor: BRAND.colors.mist,
  },
  scroll: {
    padding: 20,
    paddingTop: 36,
  },
  header: {
    alignItems: 'center',
    marginBottom: 28,
  },
  eyebrow: {
    color: BRAND.colors.canopy,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.1,
    marginBottom: 10,
  },
  logo: {
    width: 86,
    height: 86,
    borderRadius: 24,
    marginBottom: 10,
  },
  title: {
    fontSize: 28,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
    fontFamily: BRAND.displayFont,
    marginBottom: 6,
  },
  subtitle: {
    fontSize: 15,
    color: COLORS.gray,
    textAlign: 'center',
    lineHeight: 22,
  },
  tipCard: {
    backgroundColor: BRAND.colors.canopyDeep,
    borderRadius: 22,
    padding: 18,
    marginBottom: 16,
    ...BRAND_SHADOW,
  },
  tipTitle: {
    color: BRAND.colors.awara,
    fontSize: 13,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: 8,
  },
  tipText: {
    color: '#D7E7DF',
    fontSize: 14,
    lineHeight: 21,
  },
  stageWrap: {
    marginBottom: 16,
  },
  card: {
    backgroundColor: COLORS.white,
    borderRadius: 18,
    padding: 18,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: COLORS.dark,
    marginBottom: 8,
  },
  hint: {
    fontSize: 13,
    color: COLORS.gray,
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
    borderColor: COLORS.border,
    borderRadius: 14,
    padding: 12,
    fontSize: 14,
    color: COLORS.dark,
    backgroundColor: '#FFF9F0',
    marginBottom: 12,
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
  },
  btnTest: {
    backgroundColor: COLORS.primary,
    borderRadius: 14,
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
    borderRadius: 14,
    padding: 10,
    backgroundColor: '#FFF9F0',
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
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#F2EBDE',
  },
  exampleLabel: {
    fontSize: 13,
    fontWeight: '700',
    color: COLORS.dark,
  },
  exampleUrl: {
    fontSize: 11,
    color: COLORS.primary,
    fontFamily: Platform.OS === 'ios' ? 'Courier' : 'monospace',
  },
  exampleArrow: {
    fontSize: 18,
    color: BRAND.colors.awara,
  },
  btnSave: {
    backgroundColor: COLORS.primary,
    borderRadius: 18,
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
    color: COLORS.gray,
    textAlign: 'center',
    lineHeight: 18,
    marginBottom: 40,
  },
});
