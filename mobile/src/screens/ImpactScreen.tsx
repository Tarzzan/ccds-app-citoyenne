import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  View,
  Text,
  ScrollView,
  TouchableOpacity,
  StyleSheet,
  RefreshControl,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { authApi, Incident, UserStats } from '../services/api';
import { CategoryMark } from '../components/CategoryMark';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { ScreenFeedbackState, ScreenLoadingState } from '../components/ScreenStatePanel';
import { useTheme } from '../theme/ThemeContext';
import { BRAND } from '../theme/brand';
import { AppStackParamList } from '../navigation/RootNavigator';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';

type NavProp = NativeStackNavigationProp<AppStackParamList>;

const STATUS_LABELS: Record<string, string> = {
  submitted: 'Soumis',
  acknowledged: 'Pris en compte',
  in_progress: 'En cours',
  resolved: 'Résolu',
  rejected: 'Rejeté',
};

const STATUS_COLORS: Record<string, string> = {
  submitted: '#D97706',
  acknowledged: '#2563EB',
  in_progress: '#7C3AED',
  resolved: '#15803D',
  rejected: '#B91C1C',
};

function getBilanNarrative(stats: UserStats): { title: string; body: string } {
  if (stats.pending_count > 0) {
    return {
      title: 'Des demandes attendent encore une reponse visible',
      body: `${stats.pending_count} dossier(s) sont toujours en attente. Le bon signal pour la commune est maintenant de rendre la prise en charge plus lisible.`,
    };
  }

  if (stats.in_progress_count > 0) {
    return {
      title: 'Votre vigilance produit deja un mouvement concret',
      body: `${stats.in_progress_count} dossier(s) sont en cours de traitement. Le territoire voit donc deja une reponse en train de se construire.`,
    };
  }

  if (stats.resolved_count > 0) {
    return {
      title: 'La boucle de service public local fonctionne',
      body: `${stats.resolved_count} dossier(s) ont deja ete resolus. Votre bilan montre ici la preuve de suivi, pas seulement l acte de signaler.`,
    };
  }

  return {
    title: 'Votre bilan citoyen va documenter la reponse locale',
    body: 'Des vos premiers signalements, cet espace montrera ce qui a ete pris en charge, ce qui reste ouvert et comment la commune repond.',
  };
}

function formatResolutionDelay(avgResolutionHours: number | null): string {
  if (avgResolutionHours === null) {
    return 'Pas encore assez de dossiers resolus pour calculer un delai moyen.';
  }

  if (avgResolutionHours < 24) {
    return `Delai moyen observe : ${Math.round(avgResolutionHours)} h`;
  }

  const days = Math.round((avgResolutionHours / 24) * 10) / 10;
  return `Delai moyen observe : ${days} j`;
}

function buildMonthlySummary(stats: UserStats): string {
  if (!stats.monthly_activity.length) {
    return 'Aucune activite recente n a encore ete consolidee.';
  }

  const total = stats.monthly_activity.reduce((sum, item) => sum + item.count, 0);
  return `${total} signalement(s) sur les 6 derniers mois, avec ${stats.monthly_activity.length} mois d activite visible.`;
}

function MetricCard({
  icon,
  value,
  label,
  backgroundColor,
}: {
  icon: string;
  value: string | number;
  label: string;
  backgroundColor: string;
}) {
  return (
    <View style={[styles.metricCard, { backgroundColor }]}>
      <Text style={styles.metricIcon}>{icon}</Text>
      <Text style={styles.metricValue}>{value}</Text>
      <Text style={styles.metricLabel}>{label}</Text>
    </View>
  );
}

function EngagementCard({
  icon,
  value,
  label,
}: {
  icon: string;
  value: string | number;
  label: string;
}) {
  const { theme } = useTheme();

  return (
    <View style={[styles.engagementCard, { backgroundColor: theme.surface, borderColor: theme.border }]}>
      <Text style={styles.engagementIcon}>{icon}</Text>
      <Text style={[styles.engagementValue, { color: theme.textPrimary }]}>{value}</Text>
      <Text style={[styles.engagementLabel, { color: theme.textSecondary }]}>{label}</Text>
    </View>
  );
}

export default function ImpactScreen() {
  const navigation = useNavigation<NavProp>();
  const { theme } = useTheme();
  const [stats, setStats] = useState<UserStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');

  const loadStats = useCallback(async (isRefresh = false) => {
    if (isRefresh) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }

    try {
      const response = await authApi.getStats();
      if (response.data) {
        setStats(response.data);
      }
      setError('');
    } catch (err: any) {
      setError(err?.message ?? 'Impossible de charger votre bilan citoyen.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    loadStats();
  }, [loadStats]);

  const resolutionRate = useMemo(() => {
    if (!stats || stats.incidents_count === 0) {
      return 0;
    }
    return Math.round((stats.resolved_count / stats.incidents_count) * 100);
  }, [stats]);

  if (loading) {
    return (
      <ScreenLoadingState
        title="Votre bilan citoyen se construit"
        body="Le relais communal relit vos dossiers, vos statuts et vos reperes d engagement pour rendre votre impact plus concret."
      />
    );
  }

  if (error || !stats) {
    return (
      <ScreenFeedbackState
        icon="⚠️"
        tone="warning"
        title="Le bilan citoyen n a pas encore pu se charger"
        body={error || 'Impossible de charger votre bilan citoyen.'}
        actionLabel="Relancer le bilan"
        onPress={() => loadStats()}
      />
    );
  }

  const narrative = getBilanNarrative(stats);
  const monthlySummary = buildMonthlySummary(stats);
  const companionBody = stats.pending_count > 0
    ? 'Des dossiers attendent encore une reponse visible. Le bon reflexe maintenant est de relire ce qui reste ouvert et de pousser la boucle de suivi jusqu au bout.'
    : stats.resolved_count > 0
      ? 'Votre bilan montre deja une preuve de service rendu. Gardez cette dynamique en surveillant les nouveaux dossiers et en documentant ce qui reste a traiter.'
      : 'Votre bilan est encore en construction. Des vos premiers signalements, cet espace rendra la reponse locale plus concrete et plus lisible.';

  return (
    <ScrollView
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={styles.container}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => loadStats(true)}
          tintColor={theme.primary}
        />
      }
    >
      <View style={[styles.hero, { backgroundColor: theme.primary }]}>
        <Text style={styles.heroEyebrow}>Mon bilan citoyen</Text>
        <Text style={styles.heroTitle}>{narrative.title}</Text>
        <Text style={styles.heroText}>{narrative.body}</Text>
        <View style={styles.heroStats}>
          <View style={styles.heroStat}>
            <Text style={styles.heroStatValue}>{stats.incidents_count}</Text>
            <Text style={styles.heroStatLabel}>dossiers suivis</Text>
          </View>
          <View style={styles.heroStat}>
            <Text style={styles.heroStatValue}>{resolutionRate}%</Text>
            <Text style={styles.heroStatLabel}>resolus</Text>
          </View>
          <View style={styles.heroStat}>
            <Text style={styles.heroStatValue}>#{stats.rank}</Text>
            <Text style={styles.heroStatLabel}>rang local</Text>
          </View>
        </View>
      </View>

      <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>Preuve de suivi</Text>
      <View style={styles.metricsGrid}>
        <MetricCard icon="🕐" value={stats.pending_count} label="En attente" backgroundColor="#FFF4D6" />
        <MetricCard icon="🛠️" value={stats.in_progress_count} label="En cours" backgroundColor="#EEE6FF" />
        <MetricCard icon="✅" value={stats.resolved_count} label="Resolus" backgroundColor="#DCFCE7" />
        <MetricCard
          icon="⏱️"
          value={stats.avg_resolution_hours === null ? 'N/D' : stats.avg_resolution_hours < 24 ? `${Math.round(stats.avg_resolution_hours)} h` : `${Math.round(stats.avg_resolution_hours / 24)} j`}
          label="Delai moyen"
          backgroundColor="#E0F2FE"
        />
      </View>

      <View style={[styles.proofCard, { backgroundColor: theme.surface, borderColor: theme.border }]}>
        <Text style={[styles.proofTitle, { color: theme.textPrimary }]}>Lecture rapide du service rendu</Text>
        <Text style={[styles.proofText, { color: theme.textSecondary }]}>{formatResolutionDelay(stats.avg_resolution_hours)}</Text>
        <Text style={[styles.proofHint, { color: theme.textSecondary }]}>{monthlySummary}</Text>
      </View>

      <View style={styles.companionWrap}>
        <CivicCompanionCard
          tone="status"
          title={`${BRAND.companion.name} lit votre impact avec vous`}
          body={companionBody}
          visualSource={COMPANION_VISUAL_SLOTS.impact.source}
          bullets={[
            'prioriser les dossiers encore ouverts',
            'verifier les derniers commentaires et statuts',
          ]}
          ctaLabel="Ouvrir mes signalements"
          onPress={() => navigation.navigate('Tabs')}
        />
      </View>

      <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>Derniers dossiers</Text>
      {stats.recent_incidents.length === 0 ? (
        <ScreenFeedbackState
          icon="🗂️"
          title="Aucun dossier pour le moment"
          body="Vos prochains signalements apparaitront ici avec leur statut, leur reference et une lecture plus concrete du suivi."
        />
      ) : (
        <View style={styles.incidentList}>
          {stats.recent_incidents.map((incident) => {
            const statusColor = STATUS_COLORS[incident.status] ?? theme.primary;
            const statusLabel = STATUS_LABELS[incident.status] ?? incident.status;

            return (
              <TouchableOpacity
                key={incident.id}
                style={[styles.incidentCard, { backgroundColor: theme.surface, borderColor: theme.border }]}
                onPress={() => navigation.navigate('IncidentDetail', { id: incident.id })}
                activeOpacity={0.85}
              >
                <View style={styles.incidentTopRow}>
                  <CategoryMark
                    icon={incident.category_icon}
                    name={incident.category_name}
                    color={incident.category_color}
                    size={42}
                  />
                  <View style={styles.incidentMain}>
                    <Text style={[styles.incidentTitle, { color: theme.textPrimary }]} numberOfLines={2}>
                      {incident.title}
                    </Text>
                    <Text style={[styles.incidentRef, { color: theme.textSecondary }]}>
                      {incident.reference}
                    </Text>
                  </View>
                  <View style={[styles.statusPill, { backgroundColor: `${statusColor}22` }]}>
                    <Text style={[styles.statusPillText, { color: statusColor }]}>{statusLabel}</Text>
                  </View>
                </View>
              </TouchableOpacity>
            );
          })}
        </View>
      )}

      <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>Repères d engagement</Text>
      <View style={styles.engagementGrid}>
        <EngagementCard icon="🏅" value={stats.points} label="points de contribution" />
        <EngagementCard icon="👍" value={stats.votes_cast} label="votes exprimes" />
        <EngagementCard icon="💬" value={stats.comments_count} label="commentaires" />
        <EngagementCard icon="🎖️" value={stats.badges.length} label="badges recents" />
      </View>

      {stats.badges.length > 0 && (
        <>
          <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>Badges recents</Text>
          <View style={styles.badgesList}>
            {stats.badges.map((badge) => (
              <View key={`${badge.key}-${badge.awarded_at || badge.label}`} style={[styles.badgeItem, { backgroundColor: theme.surface, borderColor: theme.border }]}>
                <Text style={styles.badgeIcon}>{badge.icon}</Text>
                <View style={{ flex: 1 }}>
                  <Text style={[styles.badgeLabel, { color: theme.textPrimary }]}>{badge.label}</Text>
                  <Text style={[styles.badgeMeta, { color: theme.textSecondary }]}>
                    {badge.awarded_at ? `Attribue le ${new Date(badge.awarded_at).toLocaleDateString('fr-FR')}` : 'Badge enregistre'}
                  </Text>
                </View>
              </View>
            ))}
          </View>
        </>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingBottom: 32,
  },
  hero: {
    paddingHorizontal: 20,
    paddingTop: 28,
    paddingBottom: 24,
  },
  heroEyebrow: {
    color: '#E8F6ED',
    fontSize: 12,
    fontWeight: '800',
    letterSpacing: 0.8,
    textTransform: 'uppercase',
    marginBottom: 8,
  },
  heroTitle: {
    color: '#FFFFFF',
    fontSize: 26,
    fontWeight: '800',
    lineHeight: 32,
  },
  heroText: {
    color: '#E8F6ED',
    fontSize: 14,
    lineHeight: 22,
    marginTop: 10,
  },
  heroStats: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 18,
  },
  heroStat: {
    flex: 1,
    backgroundColor: '#FFFFFF14',
    borderWidth: 1,
    borderColor: '#FFFFFF1F',
    borderRadius: 14,
    padding: 12,
  },
  heroStatValue: {
    color: '#FFFFFF',
    fontSize: 22,
    fontWeight: '800',
  },
  heroStatLabel: {
    color: '#E8F6ED',
    fontSize: 11,
    marginTop: 4,
  },
  sectionTitle: {
    fontSize: 17,
    fontWeight: '800',
    marginHorizontal: 16,
    marginTop: 22,
    marginBottom: 12,
  },
  metricsGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    paddingHorizontal: 16,
  },
  metricCard: {
    width: '47%',
    borderRadius: 16,
    padding: 14,
  },
  metricIcon: {
    fontSize: 22,
    marginBottom: 8,
  },
  metricValue: {
    fontSize: 24,
    fontWeight: '800',
    color: '#14213D',
  },
  metricLabel: {
    fontSize: 12,
    color: '#334155',
    marginTop: 4,
  },
  proofCard: {
    borderWidth: 1,
    borderRadius: 18,
    marginHorizontal: 16,
    marginTop: 14,
    padding: 16,
  },
  companionWrap: {
    marginHorizontal: 16,
    marginTop: 14,
  },
  proofTitle: {
    fontSize: 16,
    fontWeight: '800',
    marginBottom: 8,
  },
  proofText: {
    fontSize: 14,
    lineHeight: 22,
  },
  proofHint: {
    fontSize: 12,
    lineHeight: 19,
    marginTop: 8,
  },
  incidentList: {
    gap: 10,
    paddingHorizontal: 16,
  },
  incidentCard: {
    borderWidth: 1,
    borderRadius: 18,
    padding: 14,
  },
  incidentTopRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
  },
  incidentMain: {
    flex: 1,
  },
  incidentTitle: {
    fontSize: 15,
    fontWeight: '700',
    lineHeight: 21,
  },
  incidentRef: {
    fontSize: 12,
    marginTop: 4,
  },
  statusPill: {
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 999,
  },
  statusPillText: {
    fontSize: 11,
    fontWeight: '800',
  },
  engagementGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    paddingHorizontal: 16,
  },
  engagementCard: {
    width: '47%',
    borderWidth: 1,
    borderRadius: 16,
    padding: 14,
  },
  engagementIcon: {
    fontSize: 20,
    marginBottom: 8,
  },
  engagementValue: {
    fontSize: 22,
    fontWeight: '800',
  },
  engagementLabel: {
    fontSize: 12,
    marginTop: 4,
  },
  badgesList: {
    gap: 10,
    paddingHorizontal: 16,
  },
  badgeItem: {
    borderWidth: 1,
    borderRadius: 16,
    padding: 14,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  badgeIcon: {
    fontSize: 24,
  },
  badgeLabel: {
    fontSize: 14,
    fontWeight: '700',
  },
  badgeMeta: {
    fontSize: 12,
    marginTop: 4,
  },
});
