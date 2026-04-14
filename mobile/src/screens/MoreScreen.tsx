/**
 * Ma Commune v1.3 — Écran "Plus"
 * Menu principal regroupant les fonctionnalités secondaires.
 * Garde l'interface principale épurée (Carte + Dossiers + Alertes).
 */

import React from 'react';
import {
  View, Text, StyleSheet, ScrollView, TouchableOpacity, Alert,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { useAuth } from '../services/AuthContext';
import { BRAND, BRAND_SHADOW } from '../theme/brand';

type MenuItem = {
  key: string;
  icon: string;
  label: string;
  subtitle: string;
  screen: string;
  badge?: string;
};

const MENU_SECTIONS: { title: string; items: MenuItem[] }[] = [
  {
    title: 'Mon espace',
    items: [
      { key: 'profile',  icon: '👤', label: 'Mon profil',     subtitle: 'Coordonnées et mot de passe', screen: 'Profile' },
      { key: 'impact',   icon: '📊', label: 'Mon bilan',      subtitle: 'Impact de vos signalements',  screen: 'Impact' },
      { key: 'dashboard',icon: '📈', label: 'Tableau de bord', subtitle: 'Statistiques et suivi',       screen: 'Dashboard' },
    ],
  },
  {
    title: 'Vie communale',
    items: [
      { key: 'events', icon: '📅', label: 'Agenda',        subtitle: 'Rendez-vous utiles', screen: 'Events' },
      { key: 'polls',  icon: '🗳️', label: 'Consultations', subtitle: 'Concertations locales', screen: 'Polls' },
    ],
  },
  {
    title: 'Application',
    items: [
      { key: 'about',  icon: '💡', label: 'À propos',  subtitle: 'Système D 3.0 × COSMOLAN', screen: 'About' },
      { key: 'server', icon: '🔧', label: 'Serveur',   subtitle: 'Configuration réseau',      screen: 'ServerConfig' },
    ],
  },
];

export default function MoreScreen() {
  const navigation = useNavigation<any>();
  const { user, logout, isStaff } = useAuth();

  const initials = (user?.full_name ?? '?').split(' ').map((w: string) => w[0]).join('').toUpperCase().slice(0, 2);

  return (
    <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>

      {/* Header compact */}
      <View style={styles.header}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>{initials}</Text>
        </View>
        <View style={styles.headerInfo}>
          <Text style={styles.headerName}>{user?.full_name ?? 'Citoyen'}</Text>
          <Text style={styles.headerRole}>
            {isStaff ? '🛡 Agent municipal' : '🌴 Citoyen de Kourou'}
          </Text>
        </View>
      </View>

      {/* Menu sections */}
      {MENU_SECTIONS.map((section) => (
        <View key={section.title} style={styles.section}>
          <Text style={styles.sectionTitle}>{section.title}</Text>
          <View style={styles.card}>
            {section.items.map((item, idx) => (
              <TouchableOpacity
                key={item.key}
                style={[styles.row, idx < section.items.length - 1 && styles.rowBorder]}
                onPress={() => navigation.navigate(item.screen)}
                activeOpacity={0.6}
              >
                <Text style={styles.rowIcon}>{item.icon}</Text>
                <View style={styles.rowContent}>
                  <Text style={styles.rowLabel}>{item.label}</Text>
                  <Text style={styles.rowSubtitle}>{item.subtitle}</Text>
                </View>
                <Text style={styles.rowChevron}>›</Text>
              </TouchableOpacity>
            ))}
          </View>
        </View>
      ))}

      {/* App version */}
      <View style={styles.versionBlock}>
        <Text style={styles.versionText}>Ma Commune v1.3.0</Text>
        <Text style={styles.versionSub}>Système D 3.0 · Kourou, Guyane</Text>
      </View>

      {/* Déconnexion */}
      <TouchableOpacity
        style={styles.logoutBtn}
        onPress={() => Alert.alert(
          'Se déconnecter',
          'Fermer cette session ?',
          [
            { text: 'Annuler', style: 'cancel' },
            { text: 'Confirmer', style: 'destructive', onPress: logout },
          ]
        )}
      >
        <Text style={styles.logoutText}>🚪 Se déconnecter</Text>
      </TouchableOpacity>

    </ScrollView>
  );
}

const styles = StyleSheet.create({
  scroll: { flex: 1, backgroundColor: BRAND.colors.mist },
  content: { paddingBottom: 40 },

  // Header
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: BRAND.colors.canopyDeep,
    paddingHorizontal: 20,
    paddingTop: 16,
    paddingBottom: 20,
  },
  avatar: {
    width: 52, height: 52, borderRadius: 26,
    backgroundColor: BRAND.colors.canopy,
    alignItems: 'center', justifyContent: 'center',
    marginRight: 14,
    borderWidth: 2,
    borderColor: 'rgba(210,161,58,0.4)',
  },
  avatarText: { color: '#fff', fontSize: 18, fontWeight: '800' },
  headerInfo: { flex: 1 },
  headerName: { color: '#fff', fontSize: 18, fontWeight: '800', fontFamily: BRAND.displayFont },
  headerRole: { color: '#D7E7DF', fontSize: 13, marginTop: 2 },

  // Sections
  section: { marginTop: 20, paddingHorizontal: 16 },
  sectionTitle: {
    color: BRAND.colors.slate,
    fontSize: 11,
    fontWeight: '800',
    letterSpacing: 1,
    textTransform: 'uppercase',
    marginBottom: 8,
    marginLeft: 4,
  },
  card: {
    backgroundColor: BRAND.surfaces.card,
    borderRadius: 14,
    overflow: 'hidden',
    ...BRAND_SHADOW,
  },

  // Rows
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 14,
    paddingHorizontal: 16,
  },
  rowBorder: {
    borderBottomWidth: 1,
    borderBottomColor: BRAND.colors.border,
  },
  rowIcon: { fontSize: 22, width: 36, textAlign: 'center' },
  rowContent: { flex: 1, marginLeft: 8 },
  rowLabel: { color: BRAND.colors.ink, fontSize: 15, fontWeight: '700' },
  rowSubtitle: { color: BRAND.colors.slate, fontSize: 12, marginTop: 1 },
  rowChevron: { color: BRAND.colors.slate, fontSize: 22, fontWeight: '300' },

  // Version
  versionBlock: { alignItems: 'center', marginTop: 28, paddingHorizontal: 16 },
  versionText: { color: BRAND.colors.slate, fontSize: 13, fontWeight: '600' },
  versionSub: { color: BRAND.colors.slate, fontSize: 11, marginTop: 2, opacity: 0.7 },

  // Logout
  logoutBtn: {
    marginHorizontal: 16,
    marginTop: 16,
    borderRadius: 14,
    paddingVertical: 14,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: '#fca5a5',
    backgroundColor: '#fff5f5',
  },
  logoutText: { color: '#ef4444', fontSize: 15, fontWeight: '700' },
});
