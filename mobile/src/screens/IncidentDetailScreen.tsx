/**
 * CCDS — Écran Détail d'un Signalement
 * v1.1 : ajout du bouton "Moi aussi" (VoteButton)
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, StyleSheet, ScrollView, Image,
  ActivityIndicator, Alert, TextInput, TouchableOpacity,
  KeyboardAvoidingView, Platform,
} from 'react-native';
import { RouteProp, useRoute } from '@react-navigation/native';

import { incidentsApi, commentsApi, Incident, Comment } from '../services/api';
import { StatusBadge, Button, COLORS } from '../components/ui';
import { VoteButton } from '../components/VoteButton';
import { AppStackParamList } from '../navigation/RootNavigator';

type RouteType = RouteProp<AppStackParamList, 'IncidentDetail'>;

export default function IncidentDetailScreen() {
  const route = useRoute<RouteType>();
  const { id } = route.params;

  const [incident,    setIncident]    = useState<Incident | null>(null);
  const [comments,    setComments]    = useState<Comment[]>([]);
  const [loading,     setLoading]     = useState(true);
  const [newComment,  setNewComment]  = useState('');
  const [sending,     setSending]     = useState(false);
  const [activePhoto, setActivePhoto] = useState(0);

  const load = useCallback(async () => {
    try {
      setLoading(true);
      const [incRes, comRes] = await Promise.all([
        incidentsApi.get(id),
        commentsApi.list(id),
      ]);
      if (incRes.data) setIncident(incRes.data);
      if (comRes.data) setComments(comRes.data);
    } catch {
      Alert.alert('Erreur', 'Impossible de charger le signalement.');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, []);

  const sendComment = async () => {
    if (!newComment.trim()) return;
    setSending(true);
    try {
      await commentsApi.add(id, { comment: newComment.trim() });
      setNewComment('');
      const res = await commentsApi.list(id);
      if (res.data) setComments(res.data);
    } catch {
      Alert.alert('Erreur', 'Impossible d\'envoyer le commentaire.');
    } finally {
      setSending(false);
    }
  };

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

  if (!incident) {
    return (
      <View style={styles.centered}>
        <Text style={styles.errorMsg}>Signalement introuvable.</Text>
      </View>
    );
  }

  const photos  = incident.photos ?? [];
  const history = incident.status_history ?? [];

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>

        {/* Photos */}
        {photos.length > 0 && (
          <View style={styles.photoSection}>
            <Image source={{ uri: photos[activePhoto].url }} style={styles.mainPhoto} />
            {photos.length > 1 && (
              <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.thumbRow}>
                {photos.map((p, i) => (
                  <TouchableOpacity key={p.id} onPress={() => setActivePhoto(i)}>
                    <Image
                      source={{ uri: p.url }}
                      style={[styles.thumb, i === activePhoto && styles.thumbActive]}
                    />
                  </TouchableOpacity>
                ))}
              </ScrollView>
            )}
          </View>
        )}

        {/* En-tête du signalement */}
        <View style={styles.card}>
          <View style={styles.refRow}>
            <Text style={styles.ref}>{incident.reference}</Text>
            <StatusBadge status={incident.status} />
          </View>

          <View style={[styles.categoryTag, { backgroundColor: incident.category_color + '22' }]}>
            <Text style={[styles.categoryTagText, { color: incident.category_color }]}>
              {incident.category_name}
            </Text>
          </View>

          {incident.title ? <Text style={styles.title}>{incident.title}</Text> : null}
          <Text style={styles.description}>{incident.description}</Text>

          {incident.address && (
            <View style={styles.infoRow}>
              <Text style={styles.infoIcon}>📍</Text>
              <Text style={styles.infoText}>{incident.address}</Text>
            </View>
          )}

          <View style={styles.infoRow}>
            <Text style={styles.infoIcon}>👤</Text>
            <Text style={styles.infoText}>Signalé par {incident.reporter_name}</Text>
          </View>

          <View style={styles.infoRow}>
            <Text style={styles.infoIcon}>📅</Text>
            <Text style={styles.infoText}>
              {new Date(incident.created_at).toLocaleDateString('fr-FR', {
                day: '2-digit', month: 'long', year: 'numeric',
                hour: '2-digit', minute: '2-digit',
              })}
            </Text>
          </View>

          {/* ── Bouton "Moi aussi" (v1.1) ── */}
          {incident.status !== 'resolved' && incident.status !== 'rejected' && (
            <View style={styles.voteSection}>
              <View style={styles.voteDivider} />
              <Text style={styles.voteHint}>
                Ce problème vous affecte aussi ?
              </Text>
              <VoteButton
                incidentId={incident.id}
                initialVotesCount={incident.votes_count ?? 0}
                initialHasVoted={incident.user_has_voted ?? false}
              />
            </View>
          )}
        </View>

        {/* Historique des statuts */}
        {history.length > 0 && (
          <View style={styles.card}>
            <Text style={styles.sectionTitle}>Historique</Text>
            {history.map((h, i) => (
              <View key={i} style={styles.historyItem}>
                <View style={styles.historyDot} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.historyStatus}>
                    {h.old_status ? `${h.old_status} → ` : ''}{h.new_status}
                  </Text>
                  {h.note && <Text style={styles.historyNote}>{h.note}</Text>}
                  <Text style={styles.historyMeta}>
                    {h.changed_by} · {new Date(h.changed_at).toLocaleDateString('fr-FR')}
                  </Text>
                </View>
              </View>
            ))}
          </View>
        )}

        {/* Commentaires */}
        <View style={styles.card}>
          <Text style={styles.sectionTitle}>
            Commentaires ({comments.filter(c => !c.is_internal).length})
          </Text>

          {comments.filter(c => !c.is_internal).length === 0 && (
            <Text style={styles.noComments}>Aucun commentaire pour l'instant.</Text>
          )}

          {comments.filter(c => !c.is_internal).map(c => (
            <View key={c.id} style={styles.commentItem}>
              <View style={styles.commentHeader}>
                <Text style={styles.commentAuthor}>{c.user_name}</Text>
                <Text style={styles.commentRole}>
                  {c.user_role === 'agent' ? '🛠️ Agent' : c.user_role === 'admin' ? '⚙️ Admin' : '👤 Citoyen'}
                </Text>
              </View>
              <Text style={styles.commentText}>{c.comment}</Text>
              <Text style={styles.commentDate}>
                {new Date(c.created_at).toLocaleDateString('fr-FR', {
                  day: '2-digit', month: 'short', year: 'numeric',
                })}
              </Text>
            </View>
          ))}

          {/* Formulaire d'ajout de commentaire */}
          <View style={styles.commentForm}>
            <TextInput
              style={styles.commentInput}
              placeholder="Ajouter un commentaire…"
              placeholderTextColor={COLORS.gray}
              value={newComment}
              onChangeText={setNewComment}
              multiline
              maxLength={2000}
            />
            <TouchableOpacity
              style={[styles.sendBtn, (!newComment.trim() || sending) && { opacity: 0.5 }]}
              onPress={sendComment}
              disabled={!newComment.trim() || sending}
            >
              {sending
                ? <ActivityIndicator color={COLORS.white} size="small" />
                : <Text style={styles.sendBtnText}>Envoyer</Text>
              }
            </TouchableOpacity>
          </View>
        </View>

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  scroll:   { flex: 1, backgroundColor: '#f8fafc' },
  content:  { paddingBottom: 40 },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  errorMsg: { fontSize: 16, color: COLORS.gray },

  photoSection: { backgroundColor: COLORS.dark },
  mainPhoto:    { width: '100%', height: 260, resizeMode: 'cover' },
  thumbRow:     { paddingHorizontal: 12, paddingVertical: 8 },
  thumb: {
    width: 60, height: 60, borderRadius: 8, marginRight: 8,
    borderWidth: 2, borderColor: 'transparent',
  },
  thumbActive: { borderColor: COLORS.primary },

  card: {
    backgroundColor: COLORS.white,
    margin: 16,
    marginBottom: 0,
    borderRadius: 16,
    padding: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.06,
    shadowRadius: 8,
    elevation: 3,
  },

  refRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 10,
  },
  ref: { fontSize: 12, color: COLORS.gray, fontFamily: 'monospace' },

  categoryTag: {
    alignSelf: 'flex-start',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 10,
    marginBottom: 12,
  },
  categoryTagText: { fontSize: 13, fontWeight: '600' },

  title:       { fontSize: 18, fontWeight: '800', color: COLORS.dark, marginBottom: 8 },
  description: { fontSize: 15, color: '#374151', lineHeight: 22, marginBottom: 14 },

  infoRow: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 6, gap: 8 },
  infoIcon:{ fontSize: 16 },
  infoText:{ fontSize: 14, color: COLORS.gray, flex: 1 },

  // Vote section (v1.1)
  voteSection: { marginTop: 8 },
  voteDivider: {
    height: 1,
    backgroundColor: '#e2e8f0',
    marginBottom: 12,
  },
  voteHint: {
    fontSize: 13,
    color: COLORS.gray,
    marginBottom: 4,
    textAlign: 'center',
  },

  sectionTitle: { fontSize: 16, fontWeight: '700', color: COLORS.dark, marginBottom: 14 },

  historyItem: { flexDirection: 'row', gap: 12, marginBottom: 12 },
  historyDot: {
    width: 10, height: 10, borderRadius: 5,
    backgroundColor: COLORS.primary, marginTop: 4,
  },
  historyStatus: { fontSize: 14, fontWeight: '600', color: COLORS.dark, marginBottom: 2 },
  historyNote:   { fontSize: 13, color: '#374151', marginBottom: 2 },
  historyMeta:   { fontSize: 12, color: COLORS.gray },

  noComments: { fontSize: 14, color: COLORS.gray, marginBottom: 16 },

  commentItem: {
    backgroundColor: COLORS.lightGray,
    borderRadius: 10,
    padding: 12,
    marginBottom: 10,
  },
  commentHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 6,
  },
  commentAuthor: { fontSize: 13, fontWeight: '700', color: COLORS.dark },
  commentRole:   { fontSize: 12, color: COLORS.gray },
  commentText:   { fontSize: 14, color: '#374151', lineHeight: 20, marginBottom: 4 },
  commentDate:   { fontSize: 12, color: COLORS.gray },

  commentForm: { marginTop: 12, gap: 8 },
  commentInput: {
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 10,
    fontSize: 14,
    color: COLORS.dark,
    backgroundColor: COLORS.white,
    minHeight: 80,
    textAlignVertical: 'top',
  },
  sendBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  sendBtnText: { color: COLORS.white, fontWeight: '700', fontSize: 15 },
});
