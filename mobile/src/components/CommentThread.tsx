import React, { useState } from 'react';
import {
  View, Text, TextInput, TouchableOpacity,
  StyleSheet, Alert, ActivityIndicator,
} from 'react-native';
import { commentsApi, Comment } from '../services/api';

/**
 * CommentThread — Fil de commentaires avec édition, suppression et réponses (UX-05)
 *
 * Props :
 *   incidentId  : id de l'incident
 *   comments    : liste des commentaires (avec replies imbriquées)
 *   currentUser : { id, role }
 *   onRefresh   : callback pour recharger les commentaires
 */

interface CurrentUser {
  id: number;
  role: 'citizen' | 'agent' | 'admin';
}

interface Props {
  incidentId: number;
  comments: Comment[];
  currentUser: CurrentUser;
  onRefresh: () => void;
}

export default function CommentThread({ incidentId, comments, currentUser, onRefresh }: Props) {
  const [newComment, setNewComment]     = useState('');
  const [submitting, setSubmitting]     = useState(false);
  const [editingId, setEditingId]       = useState<number | null>(null);
  const [editText, setEditText]         = useState('');
  const [replyingTo, setReplyingTo]     = useState<number | null>(null);
  const [replyText, setReplyText]       = useState('');
  const [replySubmitting, setReplySubmitting] = useState(false);

  // ── Poster un nouveau commentaire ────────────────────────────
  const submitComment = async () => {
    if (!newComment.trim()) return;
    setSubmitting(true);
    try {
      await commentsApi.create(incidentId, newComment.trim());
      setNewComment('');
      onRefresh();
    } catch {
      Alert.alert('Erreur', 'Impossible d\'envoyer le commentaire.');
    } finally {
      setSubmitting(false);
    }
  };

  // ── Poster une réponse ───────────────────────────────────────
  const submitReply = async (parentId: number) => {
    if (!replyText.trim()) return;
    setReplySubmitting(true);
    try {
      await commentsApi.reply(incidentId, parentId, replyText.trim());
      setReplyText('');
      setReplyingTo(null);
      onRefresh();
    } catch {
      Alert.alert('Erreur', 'Impossible d\'envoyer la réponse.');
    } finally {
      setReplySubmitting(false);
    }
  };

  // ── Éditer un commentaire ────────────────────────────────────
  const startEdit = (comment: Comment) => {
    setEditingId(comment.id);
    setEditText(comment.comment);
  };

  const saveEdit = async (commentId: number) => {
    if (!editText.trim()) return;
    try {
      await commentsApi.update(incidentId, commentId, editText.trim());
      setEditingId(null);
      setEditText('');
      onRefresh();
    } catch {
      Alert.alert('Erreur', 'Impossible de modifier le commentaire.');
    }
  };

  const cancelEdit = () => {
    setEditingId(null);
    setEditText('');
  };

  // ── Supprimer un commentaire ─────────────────────────────────
  const deleteComment = (commentId: number) => {
    Alert.alert(
      'Supprimer le commentaire',
      'Cette action est irréversible.',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Supprimer',
          style: 'destructive',
          onPress: async () => {
            try {
              await commentsApi.delete(incidentId, commentId);
              onRefresh();
            } catch {
              Alert.alert('Erreur', 'Impossible de supprimer le commentaire.');
            }
          },
        },
      ]
    );
  };

  const canEdit   = (c: Comment) => c.user_id === currentUser.id;
  const canDelete = (c: Comment) => c.user_id === currentUser.id || currentUser.role !== 'citizen';
  const canReply  = (c: Comment) => !c.parent_id; // Réponses niveau 1 uniquement

  const formatDate = (iso: string) => {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60)   return 'À l\'instant';
    if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)} h`;
    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
  };

  const renderComment = (comment: Comment, isReply = false) => (
    <View key={comment.id} style={[styles.commentCard, isReply && styles.replyCard]}>
      {/* En-tête */}
      <View style={styles.commentHeader}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>
            {(comment.author_name ?? 'U').charAt(0).toUpperCase()}
          </Text>
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.authorName}>{comment.author_name ?? 'Utilisateur'}</Text>
          <Text style={styles.commentDate}>{formatDate(comment.created_at)}</Text>
        </View>
        {comment.is_edited && (
          <Text style={styles.editedBadge}>modifié</Text>
        )}
      </View>

      {/* Corps */}
      {editingId === comment.id ? (
        <View style={styles.editBox}>
          <TextInput
            style={styles.editInput}
            value={editText}
            onChangeText={setEditText}
            multiline
            autoFocus
            maxLength={1000}
          />
          <View style={styles.editActions}>
            <TouchableOpacity onPress={cancelEdit} style={styles.btnCancel}>
              <Text style={styles.btnCancelText}>Annuler</Text>
            </TouchableOpacity>
            <TouchableOpacity onPress={() => saveEdit(comment.id)} style={styles.btnSave}>
              <Text style={styles.btnSaveText}>Enregistrer</Text>
            </TouchableOpacity>
          </View>
        </View>
      ) : (
        <Text style={styles.commentText}>{comment.comment}</Text>
      )}

      {/* Actions */}
      {editingId !== comment.id && (
        <View style={styles.commentActions}>
          {canReply(comment) && (
            <TouchableOpacity
              onPress={() => setReplyingTo(replyingTo === comment.id ? null : comment.id)}
              style={styles.actionBtn}
            >
              <Text style={styles.actionBtnText}>↩ Répondre</Text>
            </TouchableOpacity>
          )}
          {canEdit(comment) && (
            <TouchableOpacity onPress={() => startEdit(comment)} style={styles.actionBtn}>
              <Text style={styles.actionBtnText}>✏️ Modifier</Text>
            </TouchableOpacity>
          )}
          {canDelete(comment) && (
            <TouchableOpacity onPress={() => deleteComment(comment.id)} style={styles.actionBtn}>
              <Text style={[styles.actionBtnText, { color: '#ef4444' }]}>🗑 Supprimer</Text>
            </TouchableOpacity>
          )}
        </View>
      )}

      {/* Formulaire de réponse */}
      {replyingTo === comment.id && (
        <View style={styles.replyForm}>
          <TextInput
            style={styles.replyInput}
            placeholder={`Répondre à ${comment.author_name}…`}
            value={replyText}
            onChangeText={setReplyText}
            multiline
            maxLength={500}
            autoFocus
          />
          <View style={styles.replyActions}>
            <TouchableOpacity onPress={() => { setReplyingTo(null); setReplyText(''); }} style={styles.btnCancel}>
              <Text style={styles.btnCancelText}>Annuler</Text>
            </TouchableOpacity>
            <TouchableOpacity
              onPress={() => submitReply(comment.id)}
              style={[styles.btnSave, (!replyText.trim() || replySubmitting) && styles.btnDisabled]}
              disabled={!replyText.trim() || replySubmitting}
            >
              {replySubmitting ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.btnSaveText}>Répondre</Text>}
            </TouchableOpacity>
          </View>
        </View>
      )}

      {/* Réponses imbriquées */}
      {comment.replies && comment.replies.length > 0 && (
        <View style={styles.repliesContainer}>
          {comment.replies.map(reply => renderComment(reply, true))}
        </View>
      )}
    </View>
  );

  // Séparer les commentaires racine des réponses
  const rootComments = comments.filter(c => !c.parent_id);

  return (
    <View style={styles.container}>
      <Text style={styles.sectionTitle}>
        💬 Commentaires ({comments.length})
      </Text>

      {/* Liste des commentaires */}
      {rootComments.length === 0 ? (
        <Text style={styles.emptyText}>Aucun commentaire pour l'instant. Soyez le premier !</Text>
      ) : (
        rootComments.map(c => renderComment(c))
      )}

      {/* Formulaire d'ajout */}
      <View style={styles.newCommentForm}>
        <TextInput
          style={styles.newCommentInput}
          placeholder="Ajouter un commentaire…"
          value={newComment}
          onChangeText={setNewComment}
          multiline
          maxLength={1000}
        />
        <TouchableOpacity
          style={[styles.submitBtn, (!newComment.trim() || submitting) && styles.btnDisabled]}
          onPress={submitComment}
          disabled={!newComment.trim() || submitting}
        >
          {submitting
            ? <ActivityIndicator size="small" color="#fff" />
            : <Text style={styles.submitBtnText}>Envoyer</Text>}
        </TouchableOpacity>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container:        { marginTop: 16 },
  sectionTitle:     { fontSize: 16, fontWeight: '700', color: '#111827', marginBottom: 12 },
  emptyText:        { color: '#9ca3af', fontSize: 14, textAlign: 'center', paddingVertical: 20 },
  commentCard:      { backgroundColor: '#fff', borderRadius: 10, padding: 12, marginBottom: 10, borderWidth: 1, borderColor: '#f3f4f6' },
  replyCard:        { backgroundColor: '#f9fafb', borderLeftWidth: 3, borderLeftColor: '#3b82f6', marginTop: 8 },
  commentHeader:    { flexDirection: 'row', alignItems: 'center', marginBottom: 8, gap: 8 },
  avatar:           { width: 32, height: 32, borderRadius: 16, backgroundColor: '#dbeafe', alignItems: 'center', justifyContent: 'center' },
  avatarText:       { fontSize: 13, fontWeight: '700', color: '#1d4ed8' },
  authorName:       { fontSize: 13, fontWeight: '600', color: '#111827' },
  commentDate:      { fontSize: 11, color: '#9ca3af' },
  editedBadge:      { fontSize: 10, color: '#9ca3af', fontStyle: 'italic' },
  commentText:      { fontSize: 14, color: '#374151', lineHeight: 20 },
  commentActions:   { flexDirection: 'row', gap: 12, marginTop: 8 },
  actionBtn:        { paddingVertical: 2 },
  actionBtnText:    { fontSize: 12, color: '#6b7280' },
  editBox:          { marginTop: 4 },
  editInput:        { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 8, padding: 10, fontSize: 14, minHeight: 60, textAlignVertical: 'top' },
  editActions:      { flexDirection: 'row', justifyContent: 'flex-end', gap: 8, marginTop: 8 },
  replyForm:        { marginTop: 10, backgroundColor: '#eff6ff', borderRadius: 8, padding: 10 },
  replyInput:       { borderWidth: 1, borderColor: '#bfdbfe', borderRadius: 6, padding: 8, fontSize: 13, minHeight: 50, textAlignVertical: 'top', backgroundColor: '#fff' },
  replyActions:     { flexDirection: 'row', justifyContent: 'flex-end', gap: 8, marginTop: 8 },
  repliesContainer: { marginTop: 8 },
  btnCancel:        { paddingHorizontal: 12, paddingVertical: 6, borderRadius: 6, borderWidth: 1, borderColor: '#d1d5db' },
  btnCancelText:    { fontSize: 13, color: '#374151' },
  btnSave:          { paddingHorizontal: 14, paddingVertical: 6, borderRadius: 6, backgroundColor: '#2563eb' },
  btnSaveText:      { fontSize: 13, color: '#fff', fontWeight: '600' },
  btnDisabled:      { opacity: 0.5 },
  newCommentForm:   { marginTop: 16, backgroundColor: '#f9fafb', borderRadius: 10, padding: 12, borderWidth: 1, borderColor: '#e5e7eb' },
  newCommentInput:  { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 8, padding: 10, fontSize: 14, minHeight: 70, textAlignVertical: 'top', backgroundColor: '#fff', marginBottom: 10 },
  submitBtn:        { backgroundColor: '#2563eb', borderRadius: 8, padding: 12, alignItems: 'center' },
  submitBtnText:    { color: '#fff', fontWeight: '600', fontSize: 14 },
});
