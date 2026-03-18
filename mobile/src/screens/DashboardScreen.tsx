/**
 * DashboardScreen — Tableau de bord citoyen (UX-07)
 * Statistiques personnelles, badges récents, signalements actifs.
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, ScrollView, StyleSheet, TouchableOpacity, Image,
  ActivityIndicator, RefreshControl, Animated,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { authApi, incidentsApi, Incident, UserStats } from '../services/api';
import { CategoryMark } from '../components/CategoryMark';
import { useAuth } from '../services/AuthContext';
import { BRAND, BRAND_SHADOW } from '../theme/brand';

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

function getPriorityPillStyle(priority: Incident['priority']) {
  switch (priority) {
    case 'critical':
      return styles.priorityPill_critical;
    case 'high':
      return styles.priorityPill_high;
    case 'low':
      return styles.priorityPill_low;
    default:
      return styles.priorityPill_medium;
  }
}

function getPriorityLabel(priority: Incident['priority']) {
  switch (priority) {
    case 'critical':
      return 'Critique';
    case 'high':
      return 'Haute';
    case 'low':
      return 'Faible';
    default:
      return 'Normale';
  }
}

function formatResolutionDelay(avgResolutionHours: number | null): string {
  if (avgResolutionHours === null) {
    return 'Pas encore assez de dossiers résolus pour calculer un délai moyen.';
  }

  if (avgResolutionHours < 24) {
    return `Délai moyen observé: ${avgResolutionHours} h`;
  }

  const days = Math.round((avgResolutionHours / 24) * 10) / 10;
  return `Délai moyen observé: ${days} j`;
}

function getCitizenServiceNarrative(stats: UserStats): { title: string; body: string } {
  if (stats.pending_count > 0) {
    return {
      title: 'Des dossiers attendent encore une réponse',
      body: `${stats.pending_count} signalement(s) sont encore en attente de prise en charge ou d’accusé de réception. La commune doit maintenant rendre sa réponse visible.`,
    };
  }

  if (stats.in_progress_count > 0) {
    return {
      title: 'Le suivi est déjà en mouvement',
      body: `${stats.in_progress_count} dossier(s) sont en cours. Votre vigilance produit donc déjà une action visible sur le territoire.`,
    };
  }

  if (stats.resolved_count > 0) {
    return {
      title: 'La boucle citoyenne fonctionne',
      body: `${stats.resolved_count} dossier(s) ont déjà été résolus. L’enjeu maintenant est de garder cette qualité de suivi dans la durée.`,
    };
  }

  return {
    title: 'Votre espace sert à documenter le terrain',
    body: 'Dès vos premiers signalements, ce bilan montrera ce qui a été pris en charge, ce qui reste ouvert et la vitesse de réponse publique.',
  };
}

type StaffQueueSummary = {
  openTotal: number;
  criticalTotal: number;
  highTotal: number;
  myOpenTotal: number;
  topQueue: Incident[];
};

export default function DashboardScreen() {
  const navigation                = useNavigation<any>();
  const { user, isStaff }         = useAuth();
  const [stats, setStats]         = useState<UserStats | null>(null);
  const [staffQueue, setStaffQueue] = useState<StaffQueueSummary | null>(null);
  const [loading, setLoading]     = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError]         = useState('');
  const fadeAnim                  = useState(new Animated.Value(0))[0];
  const staffRoleLabel = user?.role === 'admin' ? 'Administrateur' : 'Agent municipal';

  const loadStats = useCallback(async (isRefresh = false) => {
    try {
      if (!isRefresh) setLoading(true);
      setError('');
      const requests: Promise<any>[] = [authApi.getStats()];

      if (isStaff) {
        requests.push(
          incidentsApi.list({ scope: 'territory', queue: 'open', sort: 'priority', dir: 'ASC', limit: 5 }),
          incidentsApi.list({ scope: 'territory', queue: 'open', priority: 'critical', limit: 1 }),
          incidentsApi.list({ scope: 'territory', queue: 'open', priority: 'high', limit: 1 }),
          incidentsApi.list({ scope: 'mine', queue: 'open', limit: 1 })
        );
      }

      const [
        statsRes,
        queueRes,
        criticalRes,
        highRes,
        myOpenRes,
      ] = await Promise.all(requests);

      if (statsRes?.data) {
        setStats(statsRes.data);
      }

      if (isStaff) {
        setStaffQueue({
          openTotal: queueRes?.data?.pagination?.total ?? 0,
          criticalTotal: criticalRes?.data?.pagination?.total ?? 0,
          highTotal: highRes?.data?.pagination?.total ?? 0,
          myOpenTotal: myOpenRes?.data?.pagination?.total ?? 0,
          topQueue: queueRes?.data?.incidents ?? [],
        });
      } else {
        setStaffQueue(null);
      }

      Animated.timing(fadeAnim, { toValue: 1, duration: 400, useNativeDriver: true }).start();
    } catch {
      setError('Impossible de charger vos statistiques.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [fadeAnim, isStaff]);

  useEffect(() => { loadStats(); }, []);

  const onRefresh = () => { setRefreshing(true); loadStats(true); };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color={BRAND.colors.canopy} />
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
  const heroEyebrow = isStaff ? 'Coordination terrain' : 'Engagement citoyen';
  const heroTitle = isStaff ? 'Tableau de bord opérationnel' : BRAND.copy.impactTitle;
  const heroText = isStaff
    ? 'Suivez la pression terrain sur Kourou, priorisez les urgences et ouvrez immédiatement les dossiers qui attendent une action.'
    : 'Votre activité aide la commune à hiérarchiser, traiter et documenter les besoins du territoire, avec un ancrage immédiat à Kourou.';
  const citizenNarrative = getCitizenServiceNarrative(stats);
  const serviceProofLabel = isStaff
    ? 'Le mobile métier complète le back-office, avec une vue concentrée sur les urgences, les assignations et le terrain.'
    : formatResolutionDelay(stats.avg_resolution_hours);
  const impactButtonLabel = stats.badges.length > 0 || stats.points > 0
    ? 'Voir les repères détaillés →'
    : 'Ouvrir le suivi d’impact →';

  return (
    <ScrollView
      style={styles.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#2E7D32" />}
    >
      <Animated.View style={{ opacity: fadeAnim }}>

        {/* En-tête */}
        <View style={styles.header}>
          <Image source={require('../../assets/icon.png')} style={styles.headerLogo} />
          <Text style={styles.eyebrow}>{heroEyebrow}</Text>
          <Text style={styles.greeting}>{heroTitle}</Text>
          <Text style={styles.headerText}>
            {heroText}
          </Text>
          <View style={styles.rankBadge}>
            <Text style={styles.rankText}>
              {isStaff ? `${staffRoleLabel}` : `🏆 Rang #${stats.rank}`}
            </Text>
            <Text style={styles.rankSub}>
              {isStaff ? 'Accès métier actif sur Ma Commune' : `sur ${stats.total_users} citoyens`}
            </Text>
          </View>
        </View>

        {isStaff && (
          <View style={styles.staffCard}>
            <Text style={styles.staffEyebrow}>Accès professionnel activé</Text>
            <Text style={styles.staffTitle}>{staffRoleLabel}</Text>
            <Text style={styles.staffText}>
              Votre compte dispose déjà de droits métier. L’application mobile sert ici à piloter l’intervention terrain, tandis que l’admin web reste la surface complète de supervision.
            </Text>
            <TouchableOpacity
              style={styles.staffActionLink}
              onPress={() => navigation.navigate('Tabs', { screen: 'MyIncidents' })}
            >
              <Text style={styles.staffActionLinkText}>Ouvrir la file d’intervention</Text>
            </TouchableOpacity>
          </View>
        )}

        {isStaff && staffQueue && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Synthèse terrain</Text>
            <View style={styles.staffMetricGrid}>
              <View style={[styles.staffMetricCard, styles.staffMetricUrgent]}>
                <Text style={styles.staffMetricValue}>{staffQueue.openTotal}</Text>
                <Text style={styles.staffMetricLabel}>dossiers ouverts</Text>
              </View>
              <View style={[styles.staffMetricCard, styles.staffMetricCritical]}>
                <Text style={styles.staffMetricValue}>{staffQueue.criticalTotal}</Text>
                <Text style={styles.staffMetricLabel}>urgences critiques</Text>
              </View>
              <View style={[styles.staffMetricCard, styles.staffMetricHigh]}>
                <Text style={styles.staffMetricValue}>{staffQueue.highTotal}</Text>
                <Text style={styles.staffMetricLabel}>priorités hautes</Text>
              </View>
              <View style={[styles.staffMetricCard, styles.staffMetricMine]}>
                <Text style={styles.staffMetricValue}>{staffQueue.myOpenTotal}</Text>
                <Text style={styles.staffMetricLabel}>dans ma file</Text>
              </View>
            </View>
            <Text style={styles.staffHint}>
              Cette synthèse reflète la file territoriale ouverte, triée par priorité.
            </Text>
          </View>
        )}

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

        <View style={styles.proofCard}>
          <Text style={styles.proofEyebrow}>
            {isStaff ? 'Cadre d’action' : 'Preuve de service'}
          </Text>
          <Text style={styles.proofTitle}>
            {isStaff ? 'Ce que la commune doit garder lisible' : citizenNarrative.title}
          </Text>
          <Text style={styles.proofText}>
            {isStaff
              ? 'Chaque dossier ouvert doit déboucher sur une qualification claire, une prise en charge documentée et une preuve d’exécution consultable.'
              : citizenNarrative.body}
          </Text>
          <View style={styles.proofMetrics}>
            <View style={styles.proofMetric}>
              <Text style={styles.proofMetricValue}>{stats.pending_count}</Text>
              <Text style={styles.proofMetricLabel}>en attente</Text>
            </View>
            <View style={styles.proofMetric}>
              <Text style={styles.proofMetricValue}>{stats.in_progress_count}</Text>
              <Text style={styles.proofMetricLabel}>en cours</Text>
            </View>
            <View style={styles.proofMetric}>
              <Text style={styles.proofMetricValue}>
                {stats.avg_resolution_hours === null
                  ? 'N/D'
                  : stats.avg_resolution_hours < 24
                    ? `${Math.round(stats.avg_resolution_hours)} h`
                    : `${Math.round(stats.avg_resolution_hours / 24)} j`}
              </Text>
              <Text style={styles.proofMetricLabel}>délai moyen</Text>
            </View>
          </View>
          <Text style={styles.proofHint}>{serviceProofLabel}</Text>
        </View>

        {/* Taux de résolution */}
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Taux de résolution</Text>
          <View style={styles.progressBar}>
            <View style={[styles.progressFill, { width: `${resolutionRate}%` as any }]} />
          </View>
          <Text style={styles.progressLabel}>{resolutionRate}% de vos signalements ont été résolus</Text>
        </View>

        {/* Repères d'impact */}
        <View style={styles.pointsCard}>
          <View style={styles.pointsLeft}>
            <Text style={styles.pointsEyebrow}>Repères d’impact</Text>
            <Text style={styles.pointsNumber}>
              {stats.points > 0 ? stats.points.toLocaleString() : `${stats.resolved_count}/${stats.incidents_count || 0}`}
            </Text>
            <Text style={styles.pointsLabel}>
              {stats.points > 0
                ? 'points de contribution enregistrés'
                : 'dossiers résolus sur votre historique'}
            </Text>
          </View>
          <TouchableOpacity
            style={styles.impactBtn}
            onPress={() => navigation.navigate('Impact')}
          >
            <Text style={styles.impactBtnText}>{impactButtonLabel}</Text>
          </TouchableOpacity>
        </View>

        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Vie locale & concertation</Text>
          <Text style={styles.sectionIntro}>
            Ne vous limitez pas au signalement. Retrouvez aussi les rendez-vous communaux et les consultations ouvertes aux habitants.
          </Text>
          <View style={styles.communityGrid}>
            <TouchableOpacity
              style={[styles.communityCard, styles.communityCardAgenda]}
              onPress={() => navigation.navigate('Events')}
            >
              <Text style={styles.communityIcon}>📅</Text>
              <Text style={styles.communityTitle}>Agenda communal</Text>
              <Text style={styles.communityText}>
                Réunions publiques, opérations de quartier et temps forts organisés par la commune.
              </Text>
              <Text style={styles.communityLink}>Ouvrir l’agenda →</Text>
            </TouchableOpacity>

            <TouchableOpacity
              style={[styles.communityCard, styles.communityCardConsultation]}
              onPress={() => navigation.navigate('Polls')}
            >
              <Text style={styles.communityIcon}>🗳️</Text>
              <Text style={styles.communityTitle}>Consultations</Text>
              <Text style={styles.communityText}>
                Donnez un avis sur les sujets du quotidien et participez à la priorisation locale.
              </Text>
              <Text style={styles.communityLink}>Voir les consultations →</Text>
            </TouchableOpacity>
          </View>
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
        {isStaff && staffQueue && staffQueue.topQueue.length > 0 ? (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Interventions à ouvrir en priorité</Text>
            {staffQueue.topQueue.map((incident) => (
              <TouchableOpacity
                key={incident.id}
                style={styles.incidentRow}
                onPress={() => navigation.navigate('IncidentDetail', { id: incident.id })}
              >
                <View style={styles.incidentLeft}>
                  <CategoryMark
                    icon={incident.category_icon}
                    name={incident.category_name}
                    color={incident.category_color}
                    size={42}
                  />
                  <View style={styles.incidentInfo}>
                    <Text style={styles.incidentTitle} numberOfLines={1}>{incident.title || 'Signalement citoyen'}</Text>
                    <Text style={styles.incidentRef}>
                      {incident.reference}
                      {incident.assigned_to_name ? ` · ${incident.assigned_to_name}` : ''}
                    </Text>
                    {incident.address ? (
                      <Text style={styles.incidentMeta} numberOfLines={1}>
                        📍 {incident.address}
                      </Text>
                    ) : null}
                  </View>
                </View>
                <View style={styles.queueStatusWrap}>
                  <View style={[styles.priorityPill, getPriorityPillStyle(incident.priority)]}>
                    <Text style={styles.priorityPillText}>
                      {getPriorityLabel(incident.priority)}
                    </Text>
                  </View>
                  <View style={[styles.statusDot, { backgroundColor: STATUS_COLORS[incident.status] || '#999' }]}>
                    <Text style={styles.statusDotText}>{STATUS_LABELS[incident.status] || incident.status}</Text>
                  </View>
                </View>
              </TouchableOpacity>
            ))}
            <TouchableOpacity
              style={styles.viewAllBtn}
              onPress={() => navigation.navigate('Tabs', { screen: 'MyIncidents' })}
            >
              <Text style={styles.viewAllText}>Ouvrir toute la file territoriale →</Text>
            </TouchableOpacity>
          </View>
        ) : stats.recent_incidents.length > 0 && (
          <View style={styles.section}>
            <Text style={styles.sectionTitle}>Mes signalements récents</Text>
            {stats.recent_incidents.map((incident) => (
              <TouchableOpacity
                key={incident.id}
                style={styles.incidentRow}
                onPress={() => navigation.navigate('IncidentDetail', { id: incident.id })}
              >
                <View style={styles.incidentLeft}>
                  <CategoryMark
                    icon={incident.category_icon}
                    name={incident.category_name}
                    color={incident.category_color}
                    size={42}
                  />
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
  container:      { flex: 1, backgroundColor: BRAND.colors.mist },
  center:         { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  loadingText:    { marginTop: 12, color: BRAND.colors.slate, fontSize: 14 },
  errorIcon:      { fontSize: 40, marginBottom: 12 },
  errorText:      { color: BRAND.colors.danger, textAlign: 'center', marginBottom: 16 },
  retryBtn:       { backgroundColor: BRAND.colors.canopy, padding: 12, borderRadius: 10 },
  retryBtnText:   { color: '#FFF', fontWeight: '700' },
  header:         { backgroundColor: BRAND.colors.canopyDeep, padding: 24, paddingTop: 48 },
  headerLogo: {
    width: 74,
    height: 74,
    borderRadius: 22,
    marginBottom: 14,
  },
  eyebrow:        { color: '#D2A13A', fontSize: 12, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1.2, marginBottom: 8 },
  greeting:       { fontSize: 28, fontWeight: '800', color: '#FFF', fontFamily: BRAND.displayFont },
  headerText:     { color: '#DCE7E0', marginTop: 10, maxWidth: 300, lineHeight: 20 },
  rankBadge:      { backgroundColor: 'rgba(255,255,255,.12)', borderRadius: 14, padding: 12, alignItems: 'center', alignSelf: 'flex-start', marginTop: 18 },
  rankText:       { color: '#FFF', fontWeight: '700', fontSize: 14 },
  rankSub:        { color: 'rgba(255,255,255,.7)', fontSize: 11, marginTop: 2 },
  staffCard:      { backgroundColor: '#FFF7E8', margin: 16, borderRadius: 18, padding: 18, borderWidth: 1, borderColor: '#E7D0A2', ...BRAND_SHADOW },
  staffEyebrow:   { color: BRAND.colors.awara, fontSize: 11, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1 },
  staffTitle:     { color: BRAND.colors.canopyDeep, fontSize: 20, fontWeight: '800', marginTop: 6, marginBottom: 8, fontFamily: BRAND.displayFont },
  staffText:      { color: BRAND.colors.slate, lineHeight: 21 },
  staffActionLink:{ marginTop: 14, minHeight: 46, borderRadius: 14, backgroundColor: BRAND.colors.canopy, alignItems: 'center', justifyContent: 'center' },
  staffActionLinkText: { color: BRAND.colors.white, fontWeight: '800', fontSize: 14 },
  kpiGrid:        { flexDirection: 'row', flexWrap: 'wrap', padding: 16, gap: 12 },
  kpiCard:        { flex: 1, minWidth: '40%', borderRadius: 18, padding: 16, alignItems: 'center', borderWidth: 1, borderColor: '#ECE4D5' },
  kpiPrimary:     { backgroundColor: '#F2E9D6' },
  kpiSuccess:     { backgroundColor: '#DDEBDD' },
  kpiInfo:        { backgroundColor: '#DDE8E8' },
  kpiWarn:        { backgroundColor: '#EADFD1' },
  kpiNumber:      { fontSize: 28, fontWeight: '800', color: BRAND.colors.canopyDeep },
  kpiLabel:       { fontSize: 12, color: BRAND.colors.slate, marginTop: 4 },
  proofCard:      { backgroundColor: '#FFF7E8', margin: 16, marginTop: 0, borderRadius: 20, padding: 20, borderWidth: 1, borderColor: '#E7D0A2', ...BRAND_SHADOW },
  proofEyebrow:   { color: BRAND.colors.awara, fontSize: 11, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1 },
  proofTitle:     { color: BRAND.colors.canopyDeep, fontSize: 20, fontWeight: '800', marginTop: 6, fontFamily: BRAND.displayFont },
  proofText:      { color: BRAND.colors.slate, lineHeight: 21, marginTop: 8 },
  proofMetrics:   { flexDirection: 'row', gap: 10, marginTop: 16 },
  proofMetric:    { flex: 1, borderRadius: 16, padding: 12, backgroundColor: '#FFFDF8', borderWidth: 1, borderColor: '#F0E3C7' },
  proofMetricValue: { color: BRAND.colors.canopyDeep, fontSize: 22, fontWeight: '800' },
  proofMetricLabel: { color: BRAND.colors.slate, fontSize: 11, marginTop: 4 },
  proofHint:      { marginTop: 12, color: BRAND.colors.slate, fontSize: 12, lineHeight: 18 },
  section:        { backgroundColor: '#FFFDF8', margin: 16, marginTop: 0, borderRadius: 18, padding: 18, borderWidth: 1, borderColor: '#ECE4D5', ...BRAND_SHADOW },
  sectionTitle:   { fontSize: 16, fontWeight: '800', color: BRAND.colors.canopyDeep, marginBottom: 14 },
  sectionIntro:   { color: BRAND.colors.slate, fontSize: 13, lineHeight: 20, marginBottom: 14 },
  staffMetricGrid:{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  staffMetricCard:{ flex: 1, minWidth: '44%', borderRadius: 16, padding: 14, borderWidth: 1 },
  staffMetricUrgent: { backgroundColor: '#F3EEE2', borderColor: '#E4D9C6' },
  staffMetricCritical: { backgroundColor: '#F8E1DE', borderColor: '#E3B7AF' },
  staffMetricHigh: { backgroundColor: '#F4E5DB', borderColor: '#DFC1B1' },
  staffMetricMine: { backgroundColor: '#E4EFE7', borderColor: '#C6DCCB' },
  staffMetricValue:{ fontSize: 28, fontWeight: '800', color: BRAND.colors.canopyDeep },
  staffMetricLabel:{ fontSize: 12, color: BRAND.colors.slate, marginTop: 4 },
  staffHint: { marginTop: 12, fontSize: 12, color: BRAND.colors.slate, lineHeight: 18 },
  progressBar:    { height: 10, backgroundColor: '#E6DCC8', borderRadius: 5, overflow: 'hidden', marginBottom: 8 },
  progressFill:   { height: '100%', backgroundColor: BRAND.colors.canopy, borderRadius: 5 },
  progressLabel:  { fontSize: 13, color: BRAND.colors.slate },
  pointsCard:     { backgroundColor: BRAND.colors.canopyDeep, margin: 16, marginTop: 0, borderRadius: 20, padding: 20, flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', ...BRAND_SHADOW },
  pointsLeft:     {},
  pointsEyebrow:  { fontSize: 11, color: '#D2A13A', fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 8 },
  pointsNumber:   { fontSize: 32, fontWeight: '800', color: '#FFF' },
  pointsLabel:    { fontSize: 13, color: 'rgba(255,255,255,.7)', marginTop: 2 },
  impactBtn:      { backgroundColor: BRAND.colors.awara, padding: 12, borderRadius: 12 },
  impactBtnText:  { color: BRAND.colors.canopyDeep, fontWeight: '800', fontSize: 13 },
  communityGrid:  { gap: 12 },
  communityCard:  { borderRadius: 18, padding: 18, borderWidth: 1 },
  communityCardAgenda: { backgroundColor: '#E6F0EA', borderColor: '#BFD6CA' },
  communityCardConsultation: { backgroundColor: '#F5EBDD', borderColor: '#E5CCAB' },
  communityIcon:  { fontSize: 24, marginBottom: 10 },
  communityTitle: { color: BRAND.colors.canopyDeep, fontSize: 17, fontWeight: '800', marginBottom: 6 },
  communityText:  { color: BRAND.colors.slate, fontSize: 13, lineHeight: 19 },
  communityLink:  { color: BRAND.colors.canopyDeep, fontSize: 13, fontWeight: '800', marginTop: 12 },
  badgesRow:      { flexDirection: 'row' },
  badgeChip:      { backgroundColor: '#F1ECE0', borderRadius: 14, padding: 12, marginRight: 10, alignItems: 'center', minWidth: 90 },
  badgeIcon:      { fontSize: 24, marginBottom: 6 },
  badgeLabel:     { fontSize: 11, color: '#444', textAlign: 'center', fontWeight: '700' },
  incidentRow:    { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 12, borderBottomWidth: 1, borderBottomColor: '#F2EBDE' },
  incidentLeft:   { flexDirection: 'row', alignItems: 'center', flex: 1, gap: 12 },
  incidentInfo:   { flex: 1 },
  incidentTitle:  { fontSize: 14, fontWeight: '700', color: '#333' },
  incidentRef:    { fontSize: 11, color: BRAND.colors.slate, marginTop: 2 },
  incidentMeta:   { fontSize: 12, color: BRAND.colors.slate, marginTop: 3 },
  queueStatusWrap:{ alignItems: 'flex-end', gap: 6, marginLeft: 10 },
  priorityPill:   { paddingHorizontal: 8, paddingVertical: 4, borderRadius: 999 },
  priorityPill_low: { backgroundColor: '#E4EAE7' },
  priorityPill_medium: { backgroundColor: '#F2E8D4' },
  priorityPill_high: { backgroundColor: '#F0DED4' },
  priorityPill_critical: { backgroundColor: '#F6DAD6' },
  priorityPillText:{ fontSize: 11, fontWeight: '800', color: BRAND.colors.canopyDeep },
  statusDot:      { paddingHorizontal: 8, paddingVertical: 4, borderRadius: 20 },
  statusDotText:  { fontSize: 11, color: '#FFF', fontWeight: '600' },
  viewAllBtn:     { marginTop: 14, alignItems: 'center' },
  viewAllText:    { color: BRAND.colors.canopy, fontWeight: '800', fontSize: 14 },
});
