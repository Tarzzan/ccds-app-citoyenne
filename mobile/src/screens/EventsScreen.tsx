import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  ActivityIndicator, RefreshControl, Alert,
} from 'react-native';
import { useTheme } from '../theme/ThemeContext';
import { eventsApi, Event } from '../services/api';

export default function EventsScreen() {
  const { theme }                     = useTheme();
  const [events, setEvents]           = useState<Event[]>([]);
  const [loading, setLoading]         = useState(true);
  const [refreshing, setRefreshing]   = useState(false);
  const [rsvpLoading, setRsvpLoading] = useState<number | null>(null);

  const loadEvents = useCallback(async () => {
    try {
      const data = await eventsApi.list();
      setEvents(data);
    } catch {
      Alert.alert('Erreur', 'Impossible de charger les événements.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { loadEvents(); }, [loadEvents]);

  const handleRsvp = async (eventId: number, status: 'attending' | 'interested' | 'not_attending') => {
    setRsvpLoading(eventId);
    try {
      await eventsApi.rsvp(eventId, status);
      loadEvents();
      const messages: Record<string, string> = {
        attending:     '✅ Vous participez à cet événement !',
        interested:    '👀 Vous êtes intéressé(e) par cet événement.',
        not_attending: '❌ Inscription annulée.',
      };
      Alert.alert('Inscription', messages[status]);
    } catch (e: any) {
      Alert.alert('Erreur', e.message ?? 'Impossible de mettre à jour votre inscription.');
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
    return `Dans ${diff} jours`;
  };

  const renderEvent = ({ item }: { item: Event }) => {
    const isLoading   = rsvpLoading === item.id;
    const userRsvp    = item.user_rsvp;
    const daysUntil   = getDaysUntil(item.event_date);
    const isUrgent    = new Date(item.event_date).getTime() - Date.now() < 86400000 * 3;

    return (
      <View style={[styles.card, { backgroundColor: theme.card }]}>
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
              {new Date(item.event_date).getDate()}
            </Text>
            <Text style={styles.dateMonth}>
              {new Date(item.event_date).toLocaleDateString('fr-FR', { month: 'short' }).toUpperCase()}
            </Text>
          </View>
          <View style={styles.dateInfo}>
            <Text style={[styles.eventTitle, { color: theme.text }]}>{item.title}</Text>
            <Text style={[styles.eventMeta, { color: theme.textSecondary }]}>
              🕐 {formatTime(item.event_date)} · 📍 {item.location}
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
            👤 {item.created_by_name}
          </Text>
        </View>

        {/* Boutons RSVP */}
        <View style={styles.rsvpButtons}>
          {userRsvp === 'attending' ? (
            <TouchableOpacity
              style={[styles.rsvpBtn, styles.rsvpBtnActive, { backgroundColor: theme.primary }]}
              onPress={() => handleRsvp(item.id, 'not_attending')}
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
                onPress={() => handleRsvp(item.id, 'not_attending')}
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
      <View style={[styles.center, { backgroundColor: theme.background }]}>
        <ActivityIndicator size="large" color={theme.primary} />
      </View>
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
          <Text style={[styles.headerTitle, { color: theme.text }]}>
            Événements communautaires
          </Text>
        }
        ListEmptyComponent={
          <View style={styles.center}>
            <Text style={{ fontSize: 48, marginBottom: 12 }}>📅</Text>
            <Text style={[styles.emptyText, { color: theme.textSecondary }]}>
              Aucun événement à venir
            </Text>
          </View>
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
  urgentBadge:  { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20, marginBottom: 10 },
  urgentText:   { fontSize: 12, fontWeight: '700' },
  dateRow:      { flexDirection: 'row', alignItems: 'flex-start', gap: 12, marginBottom: 10 },
  dateBox:      { width: 52, height: 52, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  dateDay:      { color: '#fff', fontSize: 20, fontWeight: '800', lineHeight: 22 },
  dateMonth:    { color: '#fff', fontSize: 10, fontWeight: '700' },
  dateInfo:     { flex: 1 },
  eventTitle:   { fontSize: 16, fontWeight: '700', marginBottom: 4 },
  eventMeta:    { fontSize: 12, lineHeight: 18 },
  daysUntil:    { fontSize: 11, marginTop: 2 },
  description:  { fontSize: 13, lineHeight: 18, marginBottom: 10 },
  counters:     { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 12 },
  counter:      { fontSize: 11 },
  rsvpButtons:  { marginTop: 4 },
  rsvpRow:      { flexDirection: 'row', gap: 8 },
  rsvpBtn:      { flex: 1, paddingVertical: 10, borderRadius: 10, alignItems: 'center' },
  rsvpBtnActive:    {},
  rsvpBtnActiveText:{ color: '#fff', fontWeight: '700', fontSize: 13 },
  rsvpBtnText:  { fontWeight: '600', fontSize: 13 },
  emptyText:    { fontSize: 15, fontWeight: '600' },
});
