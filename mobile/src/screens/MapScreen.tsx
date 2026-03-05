/**
 * CCDS — Écran Carte Interactive (compatible Expo Go)
 * Note: react-native-maps nécessite un build natif.
 * Cette version affiche la liste des signalements géolocalisés.
 */
import React, { useEffect, useState, useCallback } from 'react';
import {
  View, Text, StyleSheet, TouchableOpacity,
  ActivityIndicator, ScrollView, RefreshControl,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { incidentsApi, Incident } from '../services/api';
import { AppStackParamList } from '../navigation/RootNavigator';

type NavProp = NativeStackNavigationProp<AppStackParamList>;

const STATUS_COLORS: Record<string, string> = {
  submitted:   '#f59e0b',
  in_progress: '#3b82f6',
  resolved:    '#10b981',
  rejected:    '#ef4444',
};
const STATUS_LABELS: Record<string, string> = {
  submitted:   'Soumis',
  in_progress: 'En cours',
  resolved:    'Résolu',
  rejected:    'Rejeté',
};

export default function MapScreen() {
  const navigation = useNavigation<NavProp>();
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [loading, setLoading]     = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadIncidents = useCallback(async () => {
    try {
      const res = await incidentsApi.list({ page: 1, limit: 50 });
      const payload = res.data as any;
      setIncidents(payload?.incidents ?? payload?.items ?? []);
    } catch (e) {
      console.log('[Map] Erreur:', e);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { loadIncidents(); }, []);

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#1a7a42" />
        <Text style={styles.loadingText}>Chargement...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerTitle}>🗺️ Signalements</Text>
        <Text style={styles.headerSub}>{incidents.length} signalement(s) actifs</Text>
      </View>

      <View style={styles.noteBanner}>
        <Text style={styles.noteText}>
          📱 Expo Go — Carte interactive disponible dans le build natif
        </Text>
      </View>

      <ScrollView
        style={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); loadIncidents(); }} />}
      >
        {incidents.length === 0 ? (
          <View style={styles.empty}>
            <Text style={styles.emptyIcon}>📍</Text>
            <Text style={styles.emptyText}>Aucun signalement pour le moment.</Text>
            <Text style={styles.emptyHint}>Soyez le premier à signaler un problème !</Text>
          </View>
        ) : (
          incidents.map((inc) => (
            <TouchableOpacity
              key={inc.id}
              style={styles.card}
              onPress={() => navigation.navigate('IncidentDetail', { id: inc.id })}
            >
              <View style={styles.cardRow}>
                <View style={[styles.statusDot, { backgroundColor: STATUS_COLORS[inc.status] || '#6b7280' }]} />
                <Text style={styles.cardTitle} numberOfLines={1}>{inc.title}</Text>
              </View>
              <Text style={styles.cardMeta} numberOfLines={1}>
                📍 {inc.address || 'Adresse non renseignée'}
              </Text>
              <View style={styles.cardFooter}>
                <View style={[styles.badge, { backgroundColor: (STATUS_COLORS[inc.status] || '#6b7280') + '22' }]}>
                  <Text style={[styles.badgeText, { color: STATUS_COLORS[inc.status] || '#6b7280' }]}>
                    {STATUS_LABELS[inc.status] || inc.status}
                  </Text>
                </View>
                {(inc.votes_count ?? 0) > 0 && (
                  <Text style={styles.votes}>👍 {inc.votes_count}</Text>
                )}
              </View>
            </TouchableOpacity>
          ))
        )}
        <View style={{ height: 100 }} />
      </ScrollView>

      <TouchableOpacity style={styles.fab} onPress={() => navigation.navigate('CreateIncident')}>
        <Text style={styles.fabText}>+ Signaler</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container:   { flex: 1, backgroundColor: '#f9fafb' },
  center:      { flex: 1, justifyContent: 'center', alignItems: 'center' },
  loadingText: { marginTop: 12, color: '#6b7280', fontSize: 14 },
  header:      { backgroundColor: '#0f4c2a', padding: 16, paddingTop: 52 },
  headerTitle: { color: '#fff', fontSize: 20, fontWeight: '700' },
  headerSub:   { color: '#a7f3d0', fontSize: 13, marginTop: 2 },
  noteBanner:  { backgroundColor: '#fef3c7', padding: 8, borderBottomWidth: 1, borderBottomColor: '#fde68a' },
  noteText:    { fontSize: 11, color: '#92400e', textAlign: 'center' },
  list:        { flex: 1, padding: 12 },
  empty:       { padding: 60, alignItems: 'center' },
  emptyIcon:   { fontSize: 48, marginBottom: 12 },
  emptyText:   { color: '#374151', fontSize: 16, fontWeight: '600', marginBottom: 4 },
  emptyHint:   { color: '#9ca3af', fontSize: 13 },
  card: {
    backgroundColor: '#fff', borderRadius: 12, padding: 14,
    marginBottom: 10, shadowColor: '#000', shadowOpacity: 0.06,
    shadowRadius: 4, elevation: 2,
  },
  cardRow:    { flexDirection: 'row', alignItems: 'center', marginBottom: 4 },
  statusDot:  { width: 10, height: 10, borderRadius: 5, marginRight: 8 },
  cardTitle:  { flex: 1, fontSize: 15, fontWeight: '600', color: '#111827' },
  cardMeta:   { fontSize: 12, color: '#6b7280', marginBottom: 8 },
  cardFooter: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  badge:      { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 10 },
  badgeText:  { fontSize: 12, fontWeight: '600' },
  votes:      { fontSize: 12, color: '#6b7280' },
  fab: {
    position: 'absolute', bottom: 24, right: 20,
    backgroundColor: '#1a7a42', paddingHorizontal: 22, paddingVertical: 14,
    borderRadius: 30, elevation: 5, shadowColor: '#000', shadowOpacity: 0.2, shadowRadius: 6,
  },
  fabText: { color: '#fff', fontWeight: '700', fontSize: 15 },
});
