import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  RefreshControl, Alert,
} from 'react-native';
import { useTheme } from '../theme/ThemeContext';
import { pollsApi, Poll } from '../services/api';
import { BRAND } from '../theme/brand';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { CivicCompanionStage } from '../components/CivicCompanionStage';
import { ScreenFeedbackState, ScreenLoadingState } from '../components/ScreenStatePanel';
import { GENERATED_VISUAL_SOURCES } from '../theme/generatedVisualSources';

export default function PollsScreen() {
  const { theme } = useTheme();
  const [polls, setPolls]           = useState<Poll[]>([]);
  const [loading, setLoading]       = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadPolls = useCallback(async () => {
    try {
      const res = await pollsApi.list();
      setPolls((res.data as any) ?? []);
    } catch (e) {
      Alert.alert(
        'Consultations indisponibles',
        'Impossible de charger les consultations pour le moment.'
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { loadPolls(); }, [loadPolls]);

  const handleVote = async (pollId: number, optionId: number) => {
    try {
      await pollsApi.vote(pollId, optionId);
      loadPolls();
      Alert.alert(
        'Vote enregistre',
        `${BRAND.companion.name} a bien note votre choix. Merci pour votre participation.`
      );
    } catch (e: any) {
      Alert.alert('Vote impossible', e.message ?? 'Impossible d enregistrer votre vote pour le moment.');
    }
  };

  const activePolls = polls.filter((poll) => !poll.ends_at || new Date(poll.ends_at) >= new Date()).length;
  const totalVotes = polls.reduce((sum, poll) => sum + (poll.total_votes ?? 0), 0);

  const renderPoll = ({ item }: { item: Poll }) => {
    const isExpired  = item.ends_at ? new Date(item.ends_at) < new Date() : false;
    const hasVoted   = item.user_vote_id != null;
    const totalVotes = item.total_votes ?? 0;

    return (
      <View style={[styles.card, { backgroundColor: theme.surface }]}>
        {/* En-tête */}
        <View style={styles.cardHeader}>
          <Text style={[styles.pollTitle, { color: theme.textPrimary }]}>{item.title}</Text>
          {isExpired ? (
            <View style={[styles.badge, { backgroundColor: '#EF444422' }]}>
              <Text style={[styles.badgeText, { color: '#EF4444' }]}>Terminé</Text>
            </View>
          ) : (
            <View style={[styles.badge, { backgroundColor: '#10B98122' }]}>
              <Text style={[styles.badgeText, { color: '#10B981' }]}>En cours</Text>
            </View>
          )}
        </View>

        {/* Description */}
        {item.description ? (
          <Text style={[styles.description, { color: theme.textSecondary }]}>
            {item.description}
          </Text>
        ) : null}

        {/* Méta */}
        <Text style={[styles.meta, { color: theme.textSecondary }]}>
          Consultation a choix unique · {' '}
          {totalVotes} vote{totalVotes !== 1 ? 's' : ''}
          {item.ends_at ? ` · Jusqu'au ${new Date(item.ends_at).toLocaleDateString('fr-FR')}` : ''}
        </Text>

        {/* Options */}
        <View style={styles.options}>
          {item.options?.map((option) => {
            const isSelected = item.user_vote_id === option.id;
            const percentage = totalVotes > 0
              ? Math.round((option.votes_count / totalVotes) * 100)
              : 0;

            return (
              <TouchableOpacity
                key={option.id}
                style={[
                  styles.option,
                  { borderColor: isSelected ? theme.primary : theme.border },
                  isSelected && { backgroundColor: theme.primary + '15' },
                ]}
                onPress={() => {
                  if (!hasVoted && !isExpired) {
                    Alert.alert(
                      'Confirmer ce choix',
                      `Souhaitez-vous voter pour "${option.text}" ?`,
                      [
                        { text: 'Annuler', style: 'cancel' },
                        { text: 'Valider', onPress: () => handleVote(item.id, option.id) },
                      ]
                    );
                  }
                }}
                disabled={hasVoted || isExpired}
                accessibilityRole="radio"
                accessibilityState={{ checked: isSelected }}
                accessibilityLabel={`${option.text}, ${percentage}% des votes`}
              >
                <View style={styles.optionRow}>
                  <Text style={[styles.optionText, { color: theme.textPrimary }]}>
                    {isSelected ? '✅ ' : ''}{option.text}
                  </Text>
                  {(hasVoted || isExpired) && (
                    <Text style={[styles.percentage, { color: theme.primary }]}>
                      {percentage}%
                    </Text>
                  )}
                </View>

                {/* Barre de progression */}
                {(hasVoted || isExpired) && (
                  <View style={[styles.progressBar, { backgroundColor: theme.border }]}>
                    <View
                      style={[
                        styles.progressFill,
                        { width: `${percentage}%` as any, backgroundColor: theme.primary },
                      ]}
                    />
                  </View>
                )}
              </TouchableOpacity>
            );
          })}
        </View>

        {/* Invitation à voter */}
        {!hasVoted && !isExpired && (
          <Text style={[styles.votePrompt, { color: theme.textSecondary }]}>
            Appuyez sur une option pour voter
          </Text>
        )}
      </View>
    );
  };

  if (loading) {
    return (
      <ScreenLoadingState
        title="Les consultations se remettent en contexte"
        body="Le relais communal rassemble les sujets ouverts pour vous laisser lire d abord les choix utiles a la decision locale."
        visualSource={GENERATED_VISUAL_SOURCES['MOM-05']}
        visualBadgeLabel="Concertation"
      />
    );
  }

  return (
    <View style={[styles.container, { backgroundColor: theme.background }]}>
      <FlatList
        data={polls}
        keyExtractor={(item) => String(item.id)}
        renderItem={renderPoll}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => { setRefreshing(true); loadPolls(); }}
            colors={[theme.primary]}
          />
        }
        ListEmptyComponent={
          <ScreenFeedbackState
            icon="🗳️"
            title="Aucune consultation ouverte pour le moment"
            body={`${BRAND.companion.name} vous retrouvera ici les prochaines questions ouvertes par la commune.`}
            visualSource={GENERATED_VISUAL_SOURCES['MOM-06']}
            visualBadgeLabel="Concertation"
          />
        }
        ListHeaderComponent={
          <View style={styles.headerWrap}>
            <Text style={[styles.headerTitle, { color: theme.textPrimary }]}>
              Consultations citoyennes
            </Text>
            <Text style={[styles.headerIntro, { color: theme.textSecondary }]}>
              Donnez un avis simple, lisible et utile a la decision locale.
            </Text>

            <View style={styles.stageWrap}>
              <CivicCompanionStage
                eyebrow="Relais communal · Concertation locale"
                title="Une consultation doit mener a une decision comprenable."
                body="Retrouvez ici les sujets ouverts par la commune, votez une fois, puis revenez relire la tendance generale sans perdre le fil."
                aside="Chaque consultation reste volontairement simple : un choix clair, un resultat lisible."
                visualSource={GENERATED_VISUAL_SOURCES['HERO-02']}
                visualBadgeLabel="Concertation"
              />
            </View>

            <View style={styles.statsRow}>
              <View style={[styles.statCard, { backgroundColor: theme.surface }]}>
                <Text style={[styles.statValue, { color: theme.textPrimary }]}>{activePolls}</Text>
                <Text style={[styles.statLabel, { color: theme.textSecondary }]}>ouvertes</Text>
              </View>
              <View style={[styles.statCard, { backgroundColor: theme.surface }]}>
                <Text style={[styles.statValue, { color: theme.textPrimary }]}>{totalVotes}</Text>
                <Text style={[styles.statLabel, { color: theme.textSecondary }]}>votes cumules</Text>
              </View>
            </View>

            <CivicCompanionCard
              compact
              tone="guide"
              title={`${BRAND.companion.name} vous conseille de voter d abord sur un seul sujet utile`}
              body="L objectif n est pas de multiplier les clics, mais de rendre visible une preference citoyenne claire."
              visualSource={GENERATED_VISUAL_SOURCES['MOM-02']}
              visualBadgeLabel="Concertation"
              bullets={[
                'une consultation = un choix unique',
                'les resultats restent relisibles apres votre vote',
              ]}
            />
          </View>
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container:    { flex: 1 },
  list:         { padding: 16 },
  headerWrap:   { marginBottom: 18 },
  headerTitle:  { fontSize: 22, fontWeight: '800', marginBottom: 16 },
  headerIntro:  { fontSize: 14, lineHeight: 21, marginTop: -6, marginBottom: 16 },
  stageWrap:    { marginBottom: 14 },
  statsRow:     { flexDirection: 'row', gap: 10, marginBottom: 14 },
  statCard:     { flex: 1, borderRadius: 14, padding: 14, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 8, elevation: 2 },
  statValue:    { fontSize: 22, fontWeight: '800', marginBottom: 4 },
  statLabel:    { fontSize: 12, fontWeight: '600' },
  card:         { borderRadius: 14, padding: 16, marginBottom: 14, shadowColor: '#000', shadowOpacity: 0.06, shadowRadius: 8, elevation: 3 },
  cardHeader:   { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 8 },
  pollTitle:    { fontSize: 16, fontWeight: '700', flex: 1, marginRight: 8 },
  badge:        { paddingHorizontal: 8, paddingVertical: 3, borderRadius: 20 },
  badgeText:    { fontSize: 11, fontWeight: '700' },
  description:  { fontSize: 13, lineHeight: 18, marginBottom: 8 },
  meta:         { fontSize: 11, marginBottom: 12 },
  options:      { gap: 8 },
  option:       { borderWidth: 1.5, borderRadius: 10, padding: 12 },
  optionRow:    { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  optionText:   { fontSize: 14, fontWeight: '600', flex: 1 },
  percentage:   { fontSize: 13, fontWeight: '700', marginLeft: 8 },
  progressBar:  { height: 4, borderRadius: 2, marginTop: 8, overflow: 'hidden' },
  progressFill: { height: '100%', borderRadius: 2 },
  votePrompt:   { fontSize: 11, textAlign: 'center', marginTop: 10, fontStyle: 'italic' },
});
