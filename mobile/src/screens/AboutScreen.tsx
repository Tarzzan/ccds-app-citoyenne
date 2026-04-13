/**
 * Ma Commune — Écran À propos & Feedback
 * Présentation de l'association Système D 3.0, du développeur, et formulaire de retour.
 */

import React, { useState } from 'react';
import {
  View, Text, StyleSheet, ScrollView, TextInput,
  TouchableOpacity, Alert, ActivityIndicator,
  Linking, Image, KeyboardAvoidingView, Platform,
} from 'react-native';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { useAuth } from '../services/AuthContext';
import { COLORS } from '../components/ui';

const APP_VERSION = '1.3.0';

const FEEDBACK_SUBJECTS = [
  { key: 'suggestion', label: '💡 Suggestion' },
  { key: 'bug',        label: '🐛 Signaler un bug' },
  { key: 'compliment', label: '👏 Félicitations' },
  { key: 'other',      label: '📝 Autre' },
];

export default function AboutScreen() {
  const { user } = useAuth();
  const [subject, setSubject]   = useState('suggestion');
  const [message, setMessage]   = useState('');
  const [sending, setSending]   = useState(false);

  const sendFeedback = async () => {
    if (!message.trim()) {
      Alert.alert('Message requis', 'Décrivez votre retour pour nous aider à progresser.');
      return;
    }
    setSending(true);
    try {
      // Envoi par email en fallback — peut être remplacé par un endpoint API
      const body = `[${FEEDBACK_SUBJECTS.find(s => s.key === subject)?.label}]\n\n${message.trim()}\n\n---\nUtilisateur : ${user?.full_name ?? 'Anonyme'} (${user?.email ?? '—'})\nVersion : ${APP_VERSION}\nPlateforme : ${Platform.OS}`;
      await Linking.openURL(`mailto:contact@cosmolan.fr?subject=${encodeURIComponent('Feedback Ma Commune — ' + subject)}&body=${encodeURIComponent(body)}`);
      setMessage('');
      Alert.alert('Merci !', 'Votre retour est précieux. Nous en tiendrons compte pour les prochaines versions.');
    } catch {
      Alert.alert('Erreur', "Impossible d'ouvrir le client mail. Écrivez-nous directement à contact@cosmolan.fr.");
    } finally {
      setSending(false);
    }
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : 'height'}>
      <ScrollView style={styles.scroll} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">

        {/* ── Hero ─────────────────────────────────── */}
        <View style={styles.hero}>
          <View style={styles.heroBadge}>
            <Text style={styles.heroBadgeText}>SYSTÈME D 3.0</Text>
          </View>
          <Text style={styles.heroTitle}>L'innovation citoyenne{'\n'}au service du territoire</Text>
          <Text style={styles.heroSubtitle}>
            Concevoir des outils numériques utiles, accessibles et souverains — par la Guyane, pour la Guyane.
          </Text>
        </View>

        {/* ── À propos de l'association ─────────────── */}
        <View style={styles.card}>
          <Text style={styles.sectionEyebrow}>L'ASSOCIATION</Text>
          <Text style={styles.sectionTitle}>Système D 3.0 × COSMOLAN</Text>
          <Text style={styles.paragraph}>
            Née des premières LAN parties guyanaises organisées par COSMOLAN, l'association Système D 3.0 est le fruit d'une communauté de passionnés de technologie qui a grandi en Guyane.
          </Text>
          <Text style={styles.paragraph}>
            Aujourd'hui, nous revenons avec une nouvelle ambition : exploiter la puissance des agents IA pour concevoir nos propres écosystèmes numériques, adaptés aux réalités de notre territoire.
          </Text>
          <TouchableOpacity
            style={styles.linkBtn}
            onPress={() => Linking.openURL('https://systemd.cosmolan.fr/')}
          >
            <Text style={styles.linkBtnText}>🌐 systemd.cosmolan.fr</Text>
          </TouchableOpacity>
        </View>

        {/* ── Le développeur ──────────────────────── */}
        <View style={styles.card}>
          <View style={styles.devHeader}>
            <View style={styles.devAvatar}>
              <Text style={styles.devAvatarText}>WM</Text>
            </View>
            <View style={styles.devInfo}>
              <Text style={styles.devName}>William MERI</Text>
              <Text style={styles.devTitle}>Ingénieur Informatique · Kourou</Text>
            </View>
          </View>
          <View style={styles.devTagRow}>
            <View style={styles.devTag}><Text style={styles.devTagText}>🚀 Pionnier COSMOLAN</Text></View>
            <View style={styles.devTag}><Text style={styles.devTagText}>🤖 Agents IA</Text></View>
            <View style={styles.devTag}><Text style={styles.devTagText}>🌴 Made in Guyane</Text></View>
          </View>
          <Text style={styles.paragraph}>
            Avant-gardiste des premières LAN parties en Guyane, William MERI a fédéré une communauté numérique là où tout restait à construire. Kouroucien de souche et ingénieur informatique, il revient aujourd'hui avec une maîtrise poussée des agents IA capables de développer des écosystèmes complets — de l'architecture serveur à l'interface mobile.
          </Text>
          <Text style={[styles.paragraph, styles.quoteText]}>
            « Ma Commune est la preuve qu'un développeur guyanais, armé d'IA et de conviction, peut livrer un outil de service public qui rivalise avec les meilleures solutions du marché. »
          </Text>
        </View>

        {/* ── L'application ───────────────────────── */}
        <View style={styles.card}>
          <Text style={styles.sectionEyebrow}>L'APPLICATION</Text>
          <Text style={styles.sectionTitle}>Ma Commune</Text>
          <Text style={styles.paragraph}>
            Ma Commune connecte citoyens, agents communaux et élus autour d'un objectif simple : rendre le suivi des signalements transparent, traçable et humain. Chaque dossier ouvert est une conversation entre le territoire et ses habitants.
          </Text>
          <View style={styles.statsRow}>
            <View style={styles.statChip}>
              <Text style={styles.statValue}>v{APP_VERSION}</Text>
              <Text style={styles.statLabel}>Version</Text>
            </View>
            <View style={styles.statChip}>
              <Text style={styles.statValue}>{Platform.OS === 'ios' ? 'iOS' : 'Android'}</Text>
              <Text style={styles.statLabel}>Plateforme</Text>
            </View>
            <View style={styles.statChip}>
              <Text style={styles.statValue}>Kourou</Text>
              <Text style={styles.statLabel}>Territoire</Text>
            </View>
          </View>
        </View>

        {/* ── Feedback ────────────────────────────── */}
        <View style={[styles.card, styles.feedbackCard]}>
          <Text style={styles.sectionEyebrow}>VOTRE AVIS COMPTE</Text>
          <Text style={styles.sectionTitle}>Envoyer un retour</Text>
          <Text style={[styles.paragraph, { marginBottom: 16 }]}>
            Bug, idée, remarque ou encouragement — chaque retour nous aide à progresser.
          </Text>

          <Text style={styles.fieldLabel}>Sujet</Text>
          <View style={styles.subjectRow}>
            {FEEDBACK_SUBJECTS.map((s) => (
              <TouchableOpacity
                key={s.key}
                style={[styles.subjectChip, subject === s.key && styles.subjectChipActive]}
                onPress={() => setSubject(s.key)}
              >
                <Text style={[styles.subjectChipText, subject === s.key && styles.subjectChipTextActive]}>
                  {s.label}
                </Text>
              </TouchableOpacity>
            ))}
          </View>

          <Text style={styles.fieldLabel}>Message</Text>
          <TextInput
            style={styles.textArea}
            placeholder="Décrivez votre retour ici..."
            placeholderTextColor={BRAND.colors.slate}
            value={message}
            onChangeText={setMessage}
            multiline
            maxLength={2000}
            textAlignVertical="top"
          />

          <TouchableOpacity
            style={[styles.sendBtn, (!message.trim() || sending) && { opacity: 0.5 }]}
            onPress={sendFeedback}
            disabled={!message.trim() || sending}
          >
            {sending
              ? <ActivityIndicator color="#fff" size="small" />
              : <Text style={styles.sendBtnText}>Envoyer le feedback</Text>
            }
          </TouchableOpacity>
        </View>

        {/* ── Footer ──────────────────────────────── */}
        <View style={styles.footer}>
          <Text style={styles.footerText}>Système D 3.0 × COSMOLAN</Text>
          <Text style={styles.footerText}>Ma Commune v{APP_VERSION} — Kourou, Guyane</Text>
          <Text style={styles.footerText}>© {new Date().getFullYear()} Tous droits réservés</Text>
        </View>

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  scroll: { flex: 1, backgroundColor: BRAND.colors.mist },
  content: { paddingBottom: 50 },

  // Hero
  hero: {
    backgroundColor: BRAND.colors.canopyDeep,
    paddingHorizontal: 24,
    paddingTop: 28,
    paddingBottom: 36,
    alignItems: 'center',
  },
  heroBadge: {
    backgroundColor: 'rgba(210,161,58,0.15)',
    borderRadius: 20,
    paddingHorizontal: 16,
    paddingVertical: 6,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: 'rgba(210,161,58,0.3)',
  },
  heroBadgeText: {
    color: BRAND.colors.awara,
    fontSize: 11,
    fontWeight: '900',
    letterSpacing: 1.5,
  },
  heroTitle: {
    color: '#fff',
    fontSize: 24,
    fontWeight: '800',
    textAlign: 'center',
    lineHeight: 32,
    fontFamily: BRAND.displayFont,
    marginBottom: 12,
  },
  heroSubtitle: {
    color: '#D7E7DF',
    fontSize: 14,
    textAlign: 'center',
    lineHeight: 21,
    maxWidth: 340,
  },

  // Cards
  card: {
    backgroundColor: BRAND.surfaces.card,
    marginHorizontal: 16,
    marginTop: 16,
    borderRadius: 16,
    padding: 20,
    ...BRAND_SHADOW,
  },
  sectionEyebrow: {
    color: BRAND.colors.awara,
    fontSize: 10,
    fontWeight: '800',
    letterSpacing: 1.2,
    marginBottom: 6,
  },
  sectionTitle: {
    color: BRAND.colors.ink,
    fontSize: 20,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
    marginBottom: 12,
  },
  paragraph: {
    color: BRAND.colors.slate,
    fontSize: 14,
    lineHeight: 22,
    marginBottom: 10,
  },
  quoteText: {
    fontStyle: 'italic',
    borderLeftWidth: 3,
    borderLeftColor: BRAND.colors.awara,
    paddingLeft: 14,
    marginTop: 6,
    color: BRAND.colors.ink,
  },

  // Developer
  devHeader: { flexDirection: 'row', alignItems: 'center', marginBottom: 14 },
  devAvatar: {
    width: 56, height: 56, borderRadius: 28,
    backgroundColor: BRAND.colors.canopy,
    alignItems: 'center', justifyContent: 'center',
    marginRight: 14,
  },
  devAvatarText: { color: '#fff', fontSize: 20, fontWeight: '800' },
  devInfo: { flex: 1 },
  devName: { color: BRAND.colors.ink, fontSize: 18, fontWeight: '800', fontFamily: BRAND.displayFont },
  devTitle: { color: BRAND.colors.slate, fontSize: 13, marginTop: 2 },
  devTagRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 },
  devTag: {
    backgroundColor: BRAND.surfaces.mutedCard,
    borderRadius: 12,
    paddingHorizontal: 10,
    paddingVertical: 5,
  },
  devTagText: { color: BRAND.colors.ink, fontSize: 12, fontWeight: '600' },

  // Stats
  statsRow: { flexDirection: 'row', gap: 10, marginTop: 8 },
  statChip: {
    flex: 1, alignItems: 'center',
    backgroundColor: BRAND.surfaces.mutedCard,
    borderRadius: 12, paddingVertical: 12,
  },
  statValue: { color: BRAND.colors.canopy, fontSize: 16, fontWeight: '800' },
  statLabel: { color: BRAND.colors.slate, fontSize: 11, marginTop: 2 },

  // Feedback
  feedbackCard: { borderTopWidth: 3, borderTopColor: BRAND.colors.awara },
  fieldLabel: {
    color: BRAND.colors.ink, fontSize: 13, fontWeight: '700',
    marginBottom: 8,
  },
  subjectRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 16 },
  subjectChip: {
    backgroundColor: BRAND.surfaces.mutedCard,
    borderRadius: 20,
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderWidth: 1.5,
    borderColor: 'transparent',
  },
  subjectChipActive: {
    backgroundColor: 'rgba(23,75,58,0.08)',
    borderColor: BRAND.colors.canopy,
  },
  subjectChipText: { color: BRAND.colors.slate, fontSize: 13, fontWeight: '600' },
  subjectChipTextActive: { color: BRAND.colors.canopy },

  textArea: {
    backgroundColor: BRAND.surfaces.mutedCard,
    borderRadius: 12,
    padding: 14,
    fontSize: 14,
    color: BRAND.colors.ink,
    minHeight: 120,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: BRAND.colors.border,
  },
  sendBtn: {
    backgroundColor: BRAND.colors.canopy,
    borderRadius: 12,
    paddingVertical: 14,
    alignItems: 'center',
  },
  sendBtnText: { color: '#fff', fontSize: 15, fontWeight: '700' },

  // Link button
  linkBtn: {
    backgroundColor: BRAND.surfaces.mutedCard,
    borderRadius: 10,
    paddingVertical: 10,
    paddingHorizontal: 16,
    alignSelf: 'flex-start',
    marginTop: 6,
  },
  linkBtnText: { color: BRAND.colors.canopy, fontSize: 13, fontWeight: '700' },

  // Footer
  footer: { alignItems: 'center', paddingVertical: 28, paddingHorizontal: 24 },
  footerText: { color: BRAND.colors.slate, fontSize: 12, lineHeight: 18 },
});
