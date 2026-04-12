import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  RefreshControl, Alert,
} from 'react-native';
import { useTheme } from '../theme/ThemeContext';
import { eventsApi, Event } from '../services/api';
import { BRAND } from '../theme/brand';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { CivicCompanionStage } from '../components/CivicCompanionStage';
import { ScreenFeedbackState, ScreenLoadingState } from '../components/ScreenStatePanel';

export default function EventsScreen() {
  const { theme }                     = useTheme();
  const [events, setEvents]           = useState<Event[]>([]);
  const [loading, setLoading]         = useState(true);
  const [refreshing, setRefreshing]   = useState(false);
  const [rsvpLoading, setRsvpLoading] = useState<number | null>(null);

  const loadEvents = useCallback(async () => {
    try {
      const res = await eventsApi.list();
      setEvents((res.data as any) ?? []);
    } catch {
      Alert.alert(
        'Agenda indisponible',
        'Impossible de charger les rendez-vous communaux pour le moment.'
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { loadEvents(); }, [loadEvents]);

  const handleRsvp = async (eventId: number, status: 'attending' | 'interested' | null) => {
    setRsvpLoading(eventId);
    try {
      await eventsApi.rsvp(eventId, status);
      loadEvents();
      const messages: Record<string, string> = {
        attending:  `${BRAND.companion.name} confirme votre participation a ce rendez-vous communal.`,
        interested: `${BRAND.companion.name} note votre interet et vous laissera retrouver ce rendez-vous plus facilement.`,
      };
      Alert.alert(
        status ? 'Participation mise a jour' : 'Participation retiree',
        status ? messages[status] : 'Votre participation a bien ete retiree de ce rendez-vous.'
      );
    } catch (e: any) {
      Alert.alert(
        'Mise a jour impossible',
        e.message ?? 'Impossible de mettre a jour votre participation pour le moment.'
      );
    } finally {
      setRsvpLoading(null);
    }
  };

  const formatDate = (dateStr: string) => {
    const d = new Date(dateStr);
    return d.toLocaleDateString('fr-FR', {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    });
  };

  const formatTime = (dateStr: string) => {
    return new Date(dateStr).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
  };

  const getDaysUntil = (dateStr: string) => {
    const diff = Math.ceil((new Date(dateStr).getTime() - Date.now()) / 86400000);
    if (diff === 0) return "Aujourd'hui";
    if (diff === 1) return 'Demain';
    if (diff < 0) return 'Passé';
    return `Dans ${diff} jours`;
  };

  const upcomingEvents = events.filter((event) => new Date(event.starts_at).getTime() >= Date.now()).length;
  const totalParticipants = events.reduce((sum, event) => sum + (event.attendees_count ?? 0), 0);

  const renderEvent = ({ item }: { item: Event }) => {
    const isLoading   = rsvpLoading === item.id;
    const userRsvp    = item.user_rsvp;
    const startDate   = item.starts_at;
    const daysUntil   = getDaysUntil(startDate);
    const isUrgent    = new Date(startDate).getTime() - Date.now() < 86400000 * 3 && new Date(startDate).getTime() > Date.now();

    return (
      <View style={[styles.card, { backgroundColor: theme.surface }]}>
        {/* Badge urgence */}
        {isUrgent && (
          <View style={[styles.urgentBadge, { backgroundColor: '#F59E0B22' }]}>
            <Text style={[styles.urgentText, { color: '#F59E0B' }]}>🔥 {daysUntil}</Text>
          </View>
        )}

        {/* Date et heure */}
        <View style={styles.dateRow}>
          <View style={[styles.dateBox, { backgroundColor: theme.primary }]}>
            <Text style={styles.dateDay}>
              {new Date(startDate).getDate()}
            </Text>
            <Text style={styles.dateMonth}>
              {new Date(startDate).toLocaleDateString('fr-FR', { month: 'short' }).toUpperCase()}
            </Text>
          </View>
          <View style={styles.dateInfo}>
            <Text style={[styles.eventTitle, { color: theme.textPrimary }]}>{item.title}</Text>
            <Text style={[styles.eventMeta, { color: theme.textSecondary }]}>
              🕐 {formatTime(startDate)} · 📍 {item.location}
            </Text>
            {!isUrgent && (
              <Text style={[styles.daysUntil, { color: theme.textSecondary }]}>{daysUntil}</Text>
            )}
          </View>
        </View>

        {/* Description */}
        {item.description ? (
          <Text style={[styles.description, { color: theme.textSecondary }]} numberOfLines={3}>
            {item.description}
          </Text>
        ) : null}

        {/* Compteurs */}
        <View style={styles.counters}>
          <Text style={[styles.counter, { color: theme.textSecondary }]}>
            ✅ {item.attendees_count} participant{item.attendees_count !== 1 ? 's' : ''}
          </Text>
          <Text style={[styles.counter, { color: theme.textSecondary }]}>
            👀 {item.interested_count} intéressé{item.interested_count !== 1 ? 's' : ''}
          </Text>
          <Text style={[styles.counter, { color: theme.textSecondary }]}>
            👤 {item.organizer}
          </Text>
        </View>

        {/* Boutons RSVP */}
        <View style={styles.rsvpButtons}>
          {userRsvp === 'attending' ? (
            <TouchableOpacity
              style={[styles.rsvpBtn, styles.rsvpBtnActive, { backgroundColor: theme.primary }]}
              onPress={() => handleRsvp(item.id, null)}
              disabled={isLoading}
              accessibilityRole="button"
              accessibilityLabel="Annuler ma participation"
            >
              <Text style={styles.rsvpBtnActiveText}>✅ Je participe</Text>
            </TouchableOpacity>
          ) : userRsvp === 'interested' ? (
            <View style={styles.rsvpRow}>
              <TouchableOpacity
                style={[styles.rsvpBtn, { borderColor: theme.primary, borderWidth: 1.5 }]}
                onPress={() => handleRsvp(item.id, 'attending')}
                disabled={isLoading}
              >
                <Text style={[styles.rsvpBtnText, { color: theme.primary }]}>✅ Participer</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.rsvpBtn, { borderColor: theme.border, borderWidth: 1.5 }]}
                onPress={() => handleRsvp(item.id, null)}
                disabled={isLoading}
              >
                <Text style={[styles.rsvpBtnText, { color: theme.textSecondary }]}>❌ Annuler</Text>
              </TouchableOpacity>
            </View>
          ) : (
            <View style={styles.rsvpRow}>
              <TouchableOpacity
                style={[styles.rsvpBtn, { backgroundColor: theme.primary }]}
                onPress={() => handleRsvp(item.id, 'attending')}
                disabled={isLoading}
                accessibilityRole="button"
                accessibilityLabel="Participer à cet événement"
              >
                <Text style={styles.rsvpBtnActiveText}>✅ Participer</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.rsvpBtn, { borderColor: theme.border, borderWidth: 1.5 }]}
                onPress={() => handleRsvp(item.id, 'interested')}
                disabled={isLoading}
              >
                <Text style={[styles.rsvpBtnText, { color: theme.textSecondary }]}>👀 Intéressé(e)</Text>
              </TouchableOpacity>
            </View>
          )}
        </View>
      </View>
    );
  };

  if (loading) {
    return (
      <ScreenLoadingState
        title="L agenda communal se met en place"
        body="L'agent rassemble les rendez-vous utiles pour que vous retrouviez d abord les temps les plus proches et les plus concrets."
      />
    );
  }

  return (
    <View style={[styles.container, { backgroundColor: theme.background }]}>
      <FlatList
        data={events}
        keyExtractor={(item) => String(item.id)}
        renderItem={renderEvent}
        contentContainerStyle={styles.list}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => { setRefreshing(true); loadEvents(); }}
            colors={[theme.primary]}
          />
        }
        ListHeaderComponent={
          <View style={styles.headerWrap}>
            <Text style={[styles.headerTitle, { color: theme.textPrimary }]}>
              Rendez-vous communaux
            </Text>
            <Text style={[styles.headerIntro, { color: theme.textSecondary }]}>
              Rejoignez les temps utiles du territoire sans perdre les informations pratiques.
            </Text>

            <View style={styles.stageWrap}>
              <CivicCompanionStage
                eyebrow="Agent · Agenda communal"
                title="Un rendez-vous doit donner envie de venir, pas seulement afficher une date."
                body="Retrouvez ici les rencontres utiles du quartier, confirmez votre presence ou gardez un repere simple pour y revenir plus tard."
                aside="La commune doit rendre ses rendez-vous lisibles, concrets et faciles a rejoindre."
              />
            </View>

            <View style={styles.statsRow}>
              <View style={[styles.statCard, { backgroundColor: theme.surface }]}>
                <Text style={[styles.statValue, { color: theme.textPrimary }]}>{upcomingEvents}</Text>
                <Text style={[styles.statLabel, { color: theme.textSecondary }]}>a venir</Text>
              </View>
              <View style={[styles.statCard, { backgroundColor: theme.surface }]}>
                <Text style={[styles.statValue, { color: theme.textPrimary }]}>{totalParticipants}</Text>
                <Text style={[styles.statLabel, { color: theme.textSecondary }]}>participations annoncees</Text>
              </View>
            </View>

            <CivicCompanionCard
              compact
              tone="thanks"
              title={`${BRAND.companion.name} vous aide a choisir le bon niveau d engagement`}
              body="Confirmez votre presence si vous venez, ou gardez simplement un repere si vous souhaitez suivre ce rendez-vous de plus loin."
              bullets={[
                'participer quand vous etes sur de venir',
                'signaler un interet sans surcharger la suite',
              ]}
            />
          </View>
        }
        ListEmptyComponent={
          <ScreenFeedbackState
            icon="📅"
            title="Aucun rendez-vous a venir"
            body={`${BRAND.companion.name} vous signalera ici les prochains rendez-vous utiles de la commune.`}
          />
        }
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container:        { flex: 1 },
  list:             { padding: 16 },
  headerWrap:       { marginBottom: 18 },
  headerTitle:      { fontSize: 22, fontWeight: '800', marginBottom: 16 },
  headerIntro:      { fontSize: 14, lineHeight: 21, marginTop: -6, marginBottom: 16 },
  stageWrap:        { marginBottom: 14 },
  statsRow:         { flexDirection: 'row', gap: 10, marginBottom: 14 },
  statCard:         { flex: 1, borderRadius: 14, padding: 14, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 8, elevation: 2 },
  statValue:        { fontSize: 22, fontWeight: '800', marginBottom: 4 },
  statLabel:        { fontSize: 12, fontWeight: '600' },
  card:             { borderRadius: 14, padding: 16, marginBottom: 14, shadowColor: '#000', shadowOpacity: 0.06, shadowRadius: 8, elevation: 3 },
  urgentBadge:      { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20, marginBottom: 10 },
  urgentText:       { fontSize: 12, fontWeight: '700' },
  dateRow:          { flexDirection: 'row', alignItems: 'flex-start', gap: 12, marginBottom: 10 },
  dateBox:          { width: 52, height: 52, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  dateDay:          { color: '#fff', fontSize: 20, fontWeight: '800', lineHeight: 22 },
  dateMonth:        { color: '#fff', fontSize: 10, fontWeight: '700' },
  dateInfo:         { flex: 1 },
  eventTitle:       { fontSize: 16, fontWeight: '700', marginBottom: 4 },
  eventMeta:        { fontSize: 12, lineHeight: 18 },
  daysUntil:        { fontSize: 11, marginTop: 2 },
  description:      { fontSize: 13, lineHeight: 18, marginBottom: 10 },
  counters:         { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 },
  counter:          { fontSize: 11 },
  rsvpButtons:      { marginTop: 4 },
  rsvpRow:          { flexDirection: 'row', gap: 8 },
  rsvpBtn:          { flex: 1, paddingVertical: 10, borderRadius: 10, alignItems: 'center' },
  rsvpBtnActive:    {},
  rsvpBtnActiveText:{ color: '#fff', fontWeight: '700', fontSize: 13 },
  rsvpBtnText:      { fontWeight: '600', fontSize: 13 },
});
