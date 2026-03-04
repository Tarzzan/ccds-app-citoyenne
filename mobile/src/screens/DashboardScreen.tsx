/**
 * DashboardScreen — Tableau de bord citoyen (UX-07)
 * Statistiques personnelles, badges récents, signalements actifs.
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, ScrollView, StyleSheet, TouchableOpacity,
  ActivityIndicator, RefreshControl, Animated,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { authApi, UserStats } from '../services/api';

const STATUS_COLORS: Record<string, string> = {
  submitted:    '#F59E0B',
  acknowledged: '#3B82F6',
  in_progress:  '#8B5CF6',
  resolved:     '#10B981',
  rejected:     '#EF4444',
};

const STATUS_LABELS: Record<string, string> = {
  submitted:    'Soumis',
  acknowledged: 'Pris en compte',
  in_progress:  'En cours',
  resolved:     'Résolu',
  rejected:     'Rejeté',
};

export default function DashboardScreen() {
  const navigation                = useNavigation<any>();
  const [stats, setStats]         = useState<UserStats | null>(null);
  const [loading, setLoading]     = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError]         = useState('');
  const fadeAnim                  = useState(new Animated.Value(0))[0];

  const loadStats = useCallback(async (isRefresh = false) => {
    try {
      if (!isRefresh) setLoading(true);
      setError('');
      const res = await authApi.getStats();
      if (res.data) {
        setStats(res.data);
        Animated.timing(fadeAnim, { toValue: 1, duration: 400, useNativeDriver: true }).start();
      }
    } catch {
      setError('Impossible de charger vos statistiques.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { loadStats(); }, []);

  const onRefresh = () => { setRefreshing(true); loadStats(true); };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#2E7D32" />
        <Text style={styles.loadingText}>Chargement de votre tableau de bord…</Text>
      </View>
    );
  }

  if (error) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorIcon}>⚠️</Text>
        <Text style={styles.errorText}>{error}</Text>
        <TouchableOpacity style={styles.retryBtn} onPress={() => loadStats()}>
          <Text style={styles.retryBtnText}>Réessayer</Text>
        </TouchableOpacity>
      </View>
    );
  }

  if (!stats) return null;

  const resolutionRate = stats.incidents_count > 0
    ? Math.round((stats.resolved_count / stats.incidents_count) * 100)
    : 0;

  return (
    <ScrollView
      style={styles.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#2E7D32" />}
    >
      <Animated.View style={{ opacity: fadeAnim }}>

        {/* En-tête */}
        <View style={styles.header}>
          <Text style={styles.greeting}>Mon Impact Citoyen</Text>
          <View style={styles.rankBadge}>
            <Text style={styles.rankText}>🏆 Rang #{stats.rank}</Text>
            <Text style={styles.rankSub}>sur {stats.total_users} citoyens</Text>
          </View>
        </View>

        {/* KPIs principaux */}
        <View style={styles.kpiGrid}>
          <View style={[styles.kpiCard, styles.kpiPrimary]}>
            <Text style={styles.kpiNumber}>{stats.incidents_count}</Text>
            <Text style={styles.kpiLabel}>Signalements</Text>
          </View>
          <View style={[styles.kpiCard, styles.kpiSuccess]}>
            <Text style={styles.kpiNumber}>{stats.resolved_count}</Text>
            <Text style={styles.kpiLabel}>Résolus</Text>
          </View>
          <View style={[styles.kpiCard, styles.kpiInfo]}>
            <Text style={styles.kpiNumber}>{stats.votes_cast}</Text>
            <Text style={styles.kpiLabel}>Votes</Text>
          </View>
          <View style={[styles.kpiCard, styles.kpiWarn]}>
            <Text style={styles.kpiNumber}>{stats.comments_count}</Text>
            <Text style={styles.kpiLabel}>Commentaires</Text>
          </View>
        </View>

        {/* Taux de résolution */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Taux de résolution</Text>
          <View style={styles.progressBar}>
            <View style={[styles.progressFill, { width: `${resolutionRate}%` as any }]} />
          </View>
          <Text style={styles.progressLabel}>{resolutionRate}% de vos signalements ont été résolus</Text>
        </View>

        {/* Points et gamification */}
        <View style={styles.pointsCard}>
          <View style={styles.pointsLeft}>
            <Text style={styles.pointsNumber}>{stats.points.toLocaleString()}</Text>
            <Text style={styles.pointsLabel}>points accumulés</Text>
          </View>
          <TouchableOpacity
            style={styles.impactBtn}
            onPress={() => navigation.navigate('Impact')}
          >
            <Text style={styles.impactBtnText}>Voir mes badges →</Text>
          </TouchableOpacity>
        </View>

        {/* Badges récents */}
        {stats.badges.length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Badges récents</Text>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.badgesRow}>
              {stats.badges.slice(0, 5).map((badge, i) => (
                <View key={i} style={styles.badgeChip}>
                  <Text style={styles.badgeIcon}>{badge.icon}</Text>
                  <Text style={styles.badgeLabel}>{badge.label}</Text>
                </View>
              ))}
            </ScrollView>
          </View>
        )}

        {/* Signalements récents */}
        {stats.recent_incidents.length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Mes signalements récents</Text>
            {stats.recent_incidents.map((incident) => (
              <TouchableOpacity
                key={incident.id}
                style={styles.incidentRow}
                onPress={() => navigation.navigate('IncidentDetail', { id: incident.id })}
              >
                <View style={styles.incidentLeft}>
                  <Text style={styles.incidentIcon}>{incident.category_icon || '📌'}</Text>
                  <View style={styles.incidentInfo}>
                    <Text style={styles.incidentTitle} numberOfLines={1}>{incident.title}</Text>
                    <Text style={styles.incidentRef}>{incident.reference}</Text>
                  </View>
                </View>
                <View style={[styles.statusDot, { backgroundColor: STATUS_COLORS[incident.status] || '#999' }]}>
                  <Text style={styles.statusDotText}>{STATUS_LABELS[incident.status] || incident.status}</Text>
                </View>
              </TouchableOpacity>
            ))}
            <TouchableOpacity
              style={styles.viewAllBtn}
              onPress={() => navigation.navigate('MyIncidents')}
            >
              <Text style={styles.viewAllText}>Voir tous mes signalements →</Text>
            </TouchableOpacity>
          </View>
        )}

        <View style={{ height: 32 }} />
      </Animated.View>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container:      { flex: 1, backgroundColor: '#F5F5F5' },
  center:         { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  loadingText:    { marginTop: 12, color: '#666', fontSize: 14 },
  errorIcon:      { fontSize: 40, marginBottom: 12 },
  errorText:      { color: '#C62828', textAlign: 'center', marginBottom: 16 },
  retryBtn:       { backgroundColor: '#2E7D32', padding: 12, borderRadius: 10 },
  retryBtnText:   { color: '#FFF', fontWeight: '700' },
  header:         { backgroundColor: '#1B5E20', padding: 24, paddingTop: 48, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  greeting:       { fontSize: 22, fontWeight: '800', color: '#FFF' },
  rankBadge:      { backgroundColor: 'rgba(255,255,255,.15)', borderRadius: 12, padding: 10, alignItems: 'center' },
  rankText:       { color: '#FFF', fontWeight: '700', fontSize: 14 },
  rankSub:        { color: 'rgba(255,255,255,.7)', fontSize: 11, marginTop: 2 },
  kpiGrid:        { flexDirection: 'row', flexWrap: 'wrap', padding: 16, gap: 12 },
  kpiCard:        { flex: 1, minWidth: '40%', borderRadius: 14, padding: 16, alignItems: 'center' },
  kpiPrimary:     { backgroundColor: '#E8F5E9' },
  kpiSuccess:     { backgroundColor: '#E3F2FD' },
  kpiInfo:        { backgroundColor: '#FFF3E0' },
  kpiWarn:        { backgroundColor: '#F3E5F5' },
  kpiNumber:      { fontSize: 28, fontWeight: '800', color: '#1B5E20' },
  kpiLabel:       { fontSize: 12, color: '#666', marginTop: 4 },
  section:        { backgroundColor: '#FFF', margin: 16, marginTop: 0, borderRadius: 14, padding: 16 },
  sectionTitle:   { fontSize: 16, fontWeight: '700', color: '#1B5E20', marginBottom: 14 },
  progressBar:    { height: 10, backgroundColor: '#E0E0E0', borderRadius: 5, overflow: 'hidden', marginBottom: 8 },
  progressFill:   { height: '100%', backgroundColor: '#2E7D32', borderRadius: 5 },
  progressLabel:  { fontSize: 13, color: '#666' },
  pointsCard:     { backgroundColor: '#1B5E20', margin: 16, marginTop: 0, borderRadius: 14, padding: 20, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  pointsLeft:     {},
  pointsNumber:   { fontSize: 32, fontWeight: '800', color: '#FFF' },
  pointsLabel:    { fontSize: 13, color: 'rgba(255,255,255,.7)', marginTop: 2 },
  impactBtn:      { backgroundColor: 'rgba(255,255,255,.15)', padding: 12, borderRadius: 10 },
  impactBtnText:  { color: '#FFF', fontWeight: '700', fontSize: 13 },
  badgesRow:      { flexDirection: 'row' },
  badgeChip:      { backgroundColor: '#F5F5F5', borderRadius: 12, padding: 12, marginRight: 10, alignItems: 'center', minWidth: 80 },
  badgeIcon:      { fontSize: 24, marginBottom: 6 },
  badgeLabel:     { fontSize: 11, color: '#444', textAlign: 'center', fontWeight: '600' },
  incidentRow:    { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#F5F5F5' },
  incidentLeft:   { flexDirection: 'row', alignItems: 'center', flex: 1 },
  incidentIcon:   { fontSize: 24, marginRight: 12 },
  incidentInfo:   { flex: 1 },
  incidentTitle:  { fontSize: 14, fontWeight: '600', color: '#333' },
  incidentRef:    { fontSize: 11, color: '#999', marginTop: 2 },
  statusDot:      { paddingHorizontal: 8, paddingVertical: 4, borderRadius: 20 },
  statusDotText:  { fontSize: 11, color: '#FFF', fontWeight: '600' },
  viewAllBtn:     { marginTop: 14, alignItems: 'center' },
  viewAllText:    { color: '#2E7D32', fontWeight: '700', fontSize: 14 },
});
