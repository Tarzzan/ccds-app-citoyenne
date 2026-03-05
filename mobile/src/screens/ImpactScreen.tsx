/**
 * CCDS v1.3 — ImpactScreen (GAMIF-01)
 * Écran "Mon Impact" : points, rang, badges, statistiques de contribution.
 */
import React, { useEffect, useState, useCallback } from 'react';
import {
  View, Text, ScrollView, TouchableOpacity,
  StyleSheet, ActivityIndicator, RefreshControl,
  Animated,
} from 'react-native';
import { useTheme } from '../theme/ThemeContext';
import { useTranslation } from '../i18n/i18n';
import { apiRequest } from '../services/api';

// ----------------------------------------------------------------
// Types
// ----------------------------------------------------------------

interface Badge {
  key: string;
  label: string;
  icon: string;
  description: string;
  awarded_at?: string;
  earned?: boolean;
}

interface NextBadge {
  key: string;
  label: string;
  icon: string;
  progress: number;
  required: number;
}

interface GamificationStats {
  points: number;
  rank: number;
  total_users: number;
  percentile: number;
  incidents_count: number;
  votes_count: number;
  comments_count: number;
  resolved_count: number;
  badges: Badge[];
  next_badge: NextBadge | null;
}

// ----------------------------------------------------------------
// Composants
// ----------------------------------------------------------------

const StatCard = ({ icon, value, label, color }: {
  icon: string; value: number; label: string; color: string;
}) => {
  const { theme } = useTheme();
  return (
    <View style={[styles.statCard, { backgroundColor: theme.surface, borderColor: theme.border }]}>
      <Text style={styles.statIcon}>{icon}</Text>
      <Text style={[styles.statValue, { color }]}>{value}</Text>
      <Text style={[styles.statLabel, { color: theme.textSecondary }]}>{label}</Text>
    </View>
  );
};

const BadgeItem = ({ badge, earned }: { badge: Badge; earned: boolean }) => {
  const { theme } = useTheme();
  return (
    <View style={[
      styles.badgeItem,
      { backgroundColor: earned ? theme.primaryLight : theme.surfaceVariant, borderColor: earned ? theme.primary : theme.border },
    ]}>
      <Text style={[styles.badgeIcon, { opacity: earned ? 1 : 0.35 }]}>{badge.icon}</Text>
      <Text style={[styles.badgeLabel, { color: earned ? theme.primary : theme.textTertiary }]} numberOfLines={2}>
        {badge.label}
      </Text>
      {earned && <Text style={styles.badgeCheck}>✓</Text>}
    </View>
  );
};

const ProgressBar = ({ progress, total, color }: { progress: number; total: number; color: string }) => {
  const { theme } = useTheme();
  const pct = Math.min((progress / total) * 100, 100);
  return (
    <View style={[styles.progressTrack, { backgroundColor: theme.surfaceVariant }]}>
      <View style={[styles.progressFill, { width: `${pct}%`, backgroundColor: color }]} />
    </View>
  );
};

// ----------------------------------------------------------------
// Écran principal
// ----------------------------------------------------------------

export default function ImpactScreen() {
  const { theme }   = useTheme();
  const { t }       = useTranslation();
  const [stats, setStats]         = useState<GamificationStats | null>(null);
  const [loading, setLoading]     = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError]         = useState<string | null>(null);
  const pointsAnim = React.useRef(new Animated.Value(0)).current;

  const loadStats = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    try {
      const res = await apiRequest<GamificationStats>('gamification');
      if (res.data) setStats(res.data);
      setError(null);

      // Animation des points
      Animated.timing(pointsAnim, {
        toValue: res.data?.points ?? 0,
        duration: 1200,
        useNativeDriver: false,
      }).start();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('errors.unknown'));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [t, pointsAnim]);

  useEffect(() => { loadStats(); }, [loadStats]);

  if (loading) {
    return (
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <ActivityIndicator size="large" color={theme.primary} />
      </View>
    );
  }

  if (error || !stats) {
    return (
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <Text style={[styles.errorText, { color: theme.danger }]}>{error ?? t('errors.unknown')}</Text>
        <TouchableOpacity onPress={() => loadStats()} style={[styles.retryBtn, { backgroundColor: theme.primary }]}>
          <Text style={{ color: theme.textInverse, fontWeight: '600' }}>{t('common.retry')}</Text>
        </TouchableOpacity>
      </View>
    );
  }

  const earnedKeys = stats.badges.map(b => b.key);

  return (
    <ScrollView
      style={{ backgroundColor: theme.background }}
      contentContainerStyle={styles.container}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => loadStats(true)} tintColor={theme.primary} />}
    >
      {/* En-tête : Points et Rang */}
      <View style={[styles.header, { backgroundColor: theme.primary }]}>
        <Text style={styles.headerTitle}>{t('gamification.my_impact')}</Text>
        <Animated.Text style={styles.headerPoints}>
          {pointsAnim.interpolate({ inputRange: [0, stats.points], outputRange: ['0', String(stats.points)] })}
        </Animated.Text>
        <Text style={styles.headerPointsLabel}>{t('gamification.points', { count: stats.points })}</Text>
        <View style={styles.rankRow}>
          <Text style={styles.rankText}>
            {t('gamification.rank', { rank: stats.rank })} / {stats.total_users}
          </Text>
          <View style={styles.percentileBadge}>
            <Text style={styles.percentileText}>Top {stats.percentile}%</Text>
          </View>
        </View>
      </View>

      {/* Statistiques */}
      <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>Mes contributions</Text>
      <View style={styles.statsGrid}>
        <StatCard icon="📍" value={stats.incidents_count} label="Signalements"  color={theme.primary} />
        <StatCard icon="👍" value={stats.votes_count}     label="Votes donnés"  color={theme.success} />
        <StatCard icon="💬" value={stats.comments_count}  label="Commentaires"  color={theme.warning} />
        <StatCard icon="✅" value={stats.resolved_count}  label="Résolus"       color={theme.success} />
      </View>

      {/* Prochain badge */}
      {stats.next_badge && (
        <>
          <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>Prochain badge</Text>
          <View style={[styles.nextBadgeCard, { backgroundColor: theme.surface, borderColor: theme.border }]}>
            <Text style={styles.nextBadgeIcon}>{stats.next_badge.icon}</Text>
            <View style={{ flex: 1 }}>
              <Text style={[styles.nextBadgeLabel, { color: theme.textPrimary }]}>{stats.next_badge.label}</Text>
              <Text style={[styles.nextBadgeProgress, { color: theme.textSecondary }]}>
                {t('gamification.progress', {
                  current:  stats.next_badge.progress,
                  required: stats.next_badge.required,
                  badge:    stats.next_badge.label,
                })}
              </Text>
              <ProgressBar
                progress={stats.next_badge.progress}
                total={stats.next_badge.required}
                color={theme.primary}
              />
            </View>
          </View>
        </>
      )}

      {/* Badges */}
      <Text style={[styles.sectionTitle, { color: theme.textPrimary }]}>
        {t('gamification.badges')} ({earnedKeys.length}/7)
      </Text>
      <View style={styles.badgesGrid}>
        {[
          { key: 'explorer',  label: 'Explorateur',        icon: '🗺️',  description: 'Premier signalement' },
          { key: 'active',    label: 'Contributeur actif', icon: '⭐',  description: '10 signalements' },
          { key: 'voter',     label: 'Votant engagé',      icon: '👍',  description: '20 votes' },
          { key: 'commenter', label: 'Commentateur',       icon: '💬',  description: '10 commentaires' },
          { key: 'resolved',  label: 'Problème résolu',    icon: '✅',  description: '5 résolus' },
          { key: 'popular',   label: 'Populaire',          icon: '🔥',  description: '50 votes sur un signalement' },
          { key: 'top',       label: 'Top contributeur',   icon: '🏆',  description: 'Top 10%' },
        ].map(badge => (
          <BadgeItem key={badge.key} badge={badge} earned={earnedKeys.includes(badge.key)} />
        ))}
      </View>
    </ScrollView>
  );
}

// ----------------------------------------------------------------
// Styles
// ----------------------------------------------------------------

const styles = StyleSheet.create({
  container:      { paddingBottom: 32 },
  center:         { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 16 },
  header:         { padding: 24, alignItems: 'center', paddingTop: 40 },
  headerTitle:    { color: '#FFFFFF', fontSize: 14, fontWeight: '500', opacity: 0.85, marginBottom: 4 },
  headerPoints:   { color: '#FFFFFF', fontSize: 56, fontWeight: '800', letterSpacing: -2 },
  headerPointsLabel: { color: '#FFFFFF', fontSize: 14, opacity: 0.85, marginTop: -4 },
  rankRow:        { flexDirection: 'row', alignItems: 'center', marginTop: 12, gap: 10 },
  rankText:       { color: '#FFFFFF', fontSize: 14, opacity: 0.9 },
  percentileBadge:{ backgroundColor: 'rgba(255,255,255,0.25)', paddingHorizontal: 10, paddingVertical: 3, borderRadius: 12 },
  percentileText: { color: '#FFFFFF', fontSize: 12, fontWeight: '700' },
  sectionTitle:   { fontSize: 16, fontWeight: '700', marginHorizontal: 16, marginTop: 24, marginBottom: 12 },
  statsGrid:      { flexDirection: 'row', flexWrap: 'wrap', paddingHorizontal: 12, gap: 8 },
  statCard:       { width: '47%', borderRadius: 12, padding: 16, alignItems: 'center', borderWidth: 1 },
  statIcon:       { fontSize: 24, marginBottom: 4 },
  statValue:      { fontSize: 28, fontWeight: '800' },
  statLabel:      { fontSize: 12, marginTop: 2 },
  nextBadgeCard:  { marginHorizontal: 16, borderRadius: 12, padding: 16, flexDirection: 'row', alignItems: 'center', gap: 14, borderWidth: 1 },
  nextBadgeIcon:  { fontSize: 36 },
  nextBadgeLabel: { fontSize: 15, fontWeight: '700', marginBottom: 2 },
  nextBadgeProgress: { fontSize: 12, marginBottom: 8 },
  progressTrack:  { height: 6, borderRadius: 3, overflow: 'hidden' },
  progressFill:   { height: 6, borderRadius: 3 },
  badgesGrid:     { flexDirection: 'row', flexWrap: 'wrap', paddingHorizontal: 12, gap: 8, paddingBottom: 8 },
  badgeItem:      { width: '30%', borderRadius: 12, padding: 12, alignItems: 'center', borderWidth: 1.5, position: 'relative' },
  badgeIcon:      { fontSize: 28, marginBottom: 4 },
  badgeLabel:     { fontSize: 11, fontWeight: '600', textAlign: 'center' },
  badgeCheck:     { position: 'absolute', top: 6, right: 8, fontSize: 11, color: '#2563EB', fontWeight: '800' },
  errorText:      { fontSize: 14, textAlign: 'center', marginHorizontal: 32 },
  retryBtn:       { paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8, marginTop: 8 },
});
