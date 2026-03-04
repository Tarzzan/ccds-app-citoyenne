import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  ActivityIndicator, RefreshControl, Alert,
} from 'react-native';
import { useTheme } from '../theme/ThemeContext';
import { pollsApi, Poll } from '../services/api';

export default function PollsScreen() {
  const { theme } = useTheme();
  const [polls, setPolls]       = useState<Poll[]>([]);
  const [loading, setLoading]   = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadPolls = useCallback(async () => {
    try {
      const data = await pollsApi.list();
      setPolls(data);
    } catch (e) {
      Alert.alert('Erreur', 'Impossible de charger les sondages.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { loadPolls(); }, [loadPolls]);

  const handleVote = async (pollId: number, optionId: number) => {
    try {
      await pollsApi.vote(pollId, optionId);
      // Recharger pour afficher les résultats mis à jour
      loadPolls();
      Alert.alert('✅ Vote enregistré', 'Merci pour votre participation !');
    } catch (e: any) {
      Alert.alert('Erreur', e.message ?? 'Impossible de voter.');
    }
  };

  const renderPoll = ({ item }: { item: Poll }) => {
    const isExpired  = item.end_date ? new Date(item.end_date) < new Date() : false;
    const hasVoted   = item.user_vote_option_id != null;
    const totalVotes = item.total_votes ?? 0;

    return (
      <View style={[styles.card, { backgroundColor: theme.card }]}>
        {/* En-tête */}
        <View style={styles.cardHeader}>
          <Text style={[styles.pollTitle, { color: theme.text }]}>{item.title}</Text>
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
          {totalVotes} vote{totalVotes !== 1 ? 's' : ''}
          {item.end_date ? ` · Jusqu'au ${new Date(item.end_date).toLocaleDateString('fr-FR')}` : ''}
        </Text>

        {/* Options */}
        <View style={styles.options}>
          {item.options?.map((option) => {
            const isSelected = item.user_vote_option_id === option.id;
            const percentage = totalVotes > 0
              ? Math.round((option.vote_count / totalVotes) * 100)
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
                      'Confirmer votre vote',
                      `Voter pour "${option.option_text}" ?`,
                      [
                        { text: 'Annuler', style: 'cancel' },
                        { text: 'Confirmer', onPress: () => handleVote(item.id, option.id) },
                      ]
                    );
                  }
                }}
                disabled={hasVoted || isExpired}
                accessibilityRole="radio"
                accessibilityState={{ checked: isSelected }}
                accessibilityLabel={`${option.option_text}, ${percentage}% des votes`}
              >
                <View style={styles.optionRow}>
                  <Text style={[styles.optionText, { color: theme.text }]}>
                    {isSelected ? '✅ ' : ''}{option.option_text}
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
                        { width: `${percentage}%`, backgroundColor: theme.primary },
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
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <ActivityIndicator size="large" color={theme.primary} />
      </View>
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
          <View style={styles.center}>
            <Text style={{ fontSize: 48, marginBottom: 12 }}>🗳️</Text>
            <Text style={[styles.emptyText, { color: theme.textSecondary }]}>
              Aucun sondage en cours
            </Text>
          </View>
        }
        ListHeaderComponent={
          <Text style={[styles.headerTitle, { color: theme.text }]}>
            Sondages & Consultations
          </Text>
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container:    { flex: 1 },
  center:       { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: 60 },
  list:         { padding: 16 },
  headerTitle:  { fontSize: 22, fontWeight: '800', marginBottom: 16 },
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
  emptyText:    { fontSize: 15, fontWeight: '600' },
});
