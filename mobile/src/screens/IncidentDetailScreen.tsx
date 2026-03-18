/**
 * Ma Commune — Écran détail d'un signalement
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
import { StatusBadge, COLORS, STATUS_LABELS, STATUS_COLORS } from '../components/ui';
import { CategoryMark } from '../components/CategoryMark';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { VoteButton } from '../components/VoteButton';
import { AppStackParamList } from '../navigation/RootNavigator';
import { useAuth } from '../services/AuthContext';
import { BRAND, BRAND_SHADOW } from '../theme/brand';

type RouteType = RouteProp<AppStackParamList, 'IncidentDetail'>;
type StaffStatus = 'acknowledged' | 'in_progress' | 'resolved' | 'rejected';
const STAFF_STATUSES: StaffStatus[] = ['acknowledged', 'in_progress', 'resolved', 'rejected'];
const PRIORITY_LABELS: Record<'low' | 'medium' | 'high' | 'critical', string> = {
  low: 'Faible',
  medium: 'Normale',
  high: 'Haute',
  critical: 'Critique',
};
const PRIORITY_COLORS: Record<'low' | 'medium' | 'high' | 'critical', string> = {
  low: '#5E6C67',
  medium: '#D48B2C',
  high: '#A64B2A',
  critical: '#C94B3C',
};
const PRIORITIES: Array<'low' | 'medium' | 'high' | 'critical'> = ['low', 'medium', 'high', 'critical'];
const STATUS_FLOW: Array<Incident['status']> = ['submitted', 'acknowledged', 'in_progress', 'resolved'];

function getRecommendedStaffAction(incident: Incident, isAssignedToMe: boolean) {
  const assignmentText = incident.assigned_to_name
    ? isAssignedToMe
      ? 'Vous êtes déjà identifié comme référent sur ce dossier.'
      : `Le dossier est actuellement suivi par ${incident.assigned_to_name}.`
    : 'Le dossier n’a pas encore de référent opérationnel.';

  switch (incident.status) {
    case 'submitted':
      return {
        title: 'Prise en charge initiale',
        description:
          `${assignmentText} Confirmez la prise en charge pour faire entrer le dossier dans la file active.`,
        cta: 'Préparer la prise en charge',
        status: 'acknowledged' as StaffStatus,
        note: 'Prise en charge enregistrée depuis le terrain.',
      };
    case 'acknowledged':
      return {
        title: 'Passer en intervention',
        description:
          `${assignmentText} Le dossier est reconnu. La prochaine étape utile est de signaler le début d’intervention sur le terrain.`,
        cta: 'Préparer le passage en cours',
        status: 'in_progress' as StaffStatus,
        note: 'Intervention engagée sur le terrain.',
      };
    case 'in_progress':
      return {
        title: 'Valider l’exécution',
        description:
          `${assignmentText} Si l’action a été menée, vous pouvez clôturer le dossier et laisser une trace claire d’exécution.`,
        cta: 'Préparer la validation',
        status: 'resolved' as StaffStatus,
        note: 'Exécution validée sur le terrain.',
      };
    case 'resolved':
      return {
        title: 'Dossier déjà clôturé',
        description:
          `${assignmentText} Le signalement est marqué comme résolu. Vous pouvez ajouter une précision ou ajuster la priorité si un contrôle complémentaire est nécessaire.`,
        cta: 'Préparer une note de suivi',
        status: 'resolved' as StaffStatus,
        note: 'Contrôle complémentaire effectué après résolution.',
      };
    case 'rejected':
      return {
        title: 'Dossier classé sans suite',
        description:
          `${assignmentText} Le signalement a été rejeté. Vous pouvez documenter le motif ou ajouter un complément d’information si besoin.`,
        cta: 'Préparer une note de classement',
        status: 'rejected' as StaffStatus,
        note: 'Classement sans suite confirmé après vérification.',
      };
    default:
      return {
        title: 'Analyse terrain',
        description: assignmentText,
        cta: 'Préparer une mise à jour',
        status: 'acknowledged' as StaffStatus,
        note: 'Analyse terrain en cours.',
      };
  }
}

function getCitizenStatusCompanion(incident: Incident) {
  switch (incident.status) {
    case 'submitted':
      return {
        tone: 'thanks' as const,
        title: `${BRAND.companion.name} a bien transmis votre signalement`,
        body: 'Le dossier est maintenant depose. La prochaine etape utile est que la commune accuse reception ou qualifie la prise en charge.',
        bullets: [
          'surveiller le passage en prise en compte',
          'ajouter une precision si le terrain evolue',
        ],
      };
    case 'acknowledged':
      return {
        tone: 'status' as const,
        title: `${BRAND.companion.name} vous confirme la prise en compte`,
        body: 'La commune a reconnu le dossier. Il entre maintenant dans une phase ou le suivi doit devenir plus concret et plus visible.',
        bullets: [
          'verifier si un commentaire agent apparait',
          'surveiller le passage en cours',
        ],
      };
    case 'in_progress':
      return {
        tone: 'status' as const,
        title: `${BRAND.companion.name} voit un traitement en cours`,
        body: 'Une action est en train de se construire. Le plus important ici est de garder la trace de ce qui a deja ete fait et de ce qui reste a valider.',
        bullets: [
          'lire les derniers commentaires',
          'revenir verifier la validation finale',
        ],
      };
    case 'resolved':
      return {
        tone: 'uplift' as const,
        title: `${BRAND.companion.name} vous remercie pour cette vigilance utile`,
        body: 'Le dossier est marque comme resolu. Ce suivi sert maintenant de preuve de reponse locale, pas seulement d archive.',
        bullets: [
          'verifier que le resultat correspond bien au terrain',
          'signaler a nouveau si le probleme reapparait',
        ],
      };
    case 'rejected':
      return {
        tone: 'guide' as const,
        title: `${BRAND.companion.name} vous aide a lire ce classement`,
        body: 'Le dossier a ete classe sans suite. Si un element manque ou si la situation change, vous pouvez ajouter une precision ou refaire un signalement plus documente.',
        bullets: [
          'relire le motif dans l historique',
          'ajouter une precision utile si besoin',
        ],
      };
    default:
      return {
        tone: 'guide' as const,
        title: `${BRAND.companion.name} suit ce dossier avec vous`,
        body: 'Cet espace sert a lire clairement l etat du dossier et la prochaine etape utile.',
        bullets: [],
      };
  }
}

export default function IncidentDetailScreen() {
  const route = useRoute<RouteType>();
  const { id } = route.params;
  const { isStaff, user } = useAuth();

  const [incident,    setIncident]    = useState<Incident | null>(null);
  const [comments,    setComments]    = useState<Comment[]>([]);
  const [loading,     setLoading]     = useState(true);
  const [newComment,  setNewComment]  = useState('');
  const [sending,     setSending]     = useState(false);
  const [activePhoto, setActivePhoto] = useState(0);
  const [staffStatus, setStaffStatus] = useState<StaffStatus>('acknowledged');
  const [staffNote, setStaffNote] = useState('');
  const [staffPriority, setStaffPriority] = useState<'low' | 'medium' | 'high' | 'critical'>('medium');
  const [assignToMe, setAssignToMe] = useState(true);
  const [updatingStatus, setUpdatingStatus] = useState(false);

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
      Alert.alert('Dossier indisponible', 'Impossible de charger ce signalement pour le moment.');
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, []);
  useEffect(() => {
    if (!incident) {
      return;
    }

    if (STAFF_STATUSES.includes(incident.status as StaffStatus)) {
      setStaffStatus(incident.status as StaffStatus);
    } else {
      setStaffStatus('acknowledged');
    }

    setStaffPriority(incident.priority ?? 'medium');
    setAssignToMe(!incident.assigned_to || incident.assigned_to === user?.id);
  }, [incident, user?.id]);

  const sendComment = async () => {
    if (!newComment.trim()) return;
    setSending(true);
    try {
      await commentsApi.add(id, { comment: newComment.trim() });
      setNewComment('');
      const res = await commentsApi.list(id);
      if (res.data) setComments(res.data);
    } catch {
      Alert.alert('Message non envoye', `${BRAND.companion.name} n'a pas pu transmettre ce commentaire pour le moment.`);
    } finally {
      setSending(false);
    }
  };

  const handleStaffStatusUpdate = async () => {
    if (!incident) {
      return;
    }

    setUpdatingStatus(true);
    try {
      await incidentsApi.updateStatus(incident.id, {
        status: staffStatus,
        note: staffNote.trim() || undefined,
        priority: staffPriority,
        assigned_to: assignToMe ? user?.id : undefined,
      });
      setStaffNote('');
      await load();
      Alert.alert(
        'Traitement mis a jour',
        staffStatus === 'resolved'
          ? `${BRAND.companion.name} confirme que l execution a ete validee et que l historique du dossier est a jour.`
          : 'Le statut du signalement a bien ete mis a jour.'
      );
    } catch (error: any) {
      Alert.alert('Mise a jour impossible', error?.message ?? 'Impossible de mettre a jour le traitement pour le moment.');
    } finally {
      setUpdatingStatus(false);
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
  const publicComments = comments.filter(c => !c.is_internal);
  const staffRoleLabel = user?.role === 'admin' ? 'Administrateur' : 'Agent municipal';
  const recommendedAction = getRecommendedStaffAction(incident, Boolean(assignToMe));
  const citizenCompanion = getCitizenStatusCompanion(incident);
  const statusIndex = STATUS_FLOW.indexOf(incident.status);

  const applyRecommendedAction = () => {
    setStaffStatus(recommendedAction.status);
    if (recommendedAction.status !== 'rejected') {
      setAssignToMe(true);
    }
    setStaffNote((current) => current.trim() ? current : recommendedAction.note);
  };

  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>

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

        <View style={styles.heroCard}>
          <Text style={styles.heroEyebrow}>Suivi communal</Text>
          <Text style={styles.heroTitle}>
            {incident.title || 'Signalement citoyen en cours de traitement'}
          </Text>
          <Text style={styles.heroText}>
            Ce dossier documente un besoin concret du territoire, son état d’avancement et les échanges utiles entre habitants et commune.
          </Text>
        </View>

        {!isStaff && (
          <View style={styles.companionWrap}>
            <CivicCompanionCard
              tone={citizenCompanion.tone}
              title={citizenCompanion.title}
              body={citizenCompanion.body}
              bullets={citizenCompanion.bullets}
              compact
            />
          </View>
        )}

        <View style={styles.progressCard}>
          <Text style={styles.progressEyebrow}>Lecture rapide du dossier</Text>
          <View style={styles.progressRow}>
            {STATUS_FLOW.map((status, index) => {
              const active = incident.status === status;
              const done = statusIndex >= index && incident.status !== 'rejected';
              return (
                <View key={status} style={styles.progressStep}>
                  <View
                    style={[
                      styles.progressDot,
                      done && styles.progressDotDone,
                      active && styles.progressDotActive,
                    ]}
                  />
                  <Text
                    style={[
                      styles.progressLabel,
                      done && styles.progressLabelDone,
                    ]}
                  >
                    {STATUS_LABELS[status]}
                  </Text>
                </View>
              );
            })}
          </View>
          {incident.status === 'rejected' ? (
            <Text style={styles.progressRejected}>
              Ce dossier est actuellement classe sans suite. Relisez l historique ou ajoutez une precision utile si la situation a change.
            </Text>
          ) : null}
        </View>

        <View style={styles.card}>
          <View style={styles.refRow}>
            <Text style={styles.ref}>{incident.reference}</Text>
            <StatusBadge status={incident.status} />
          </View>

          <View style={[styles.categoryTag, { backgroundColor: incident.category_color + '22' }]}>
            <CategoryMark
              icon={incident.category_icon}
              name={incident.category_name}
              color={incident.category_color}
              size={38}
            />
            <Text style={[styles.categoryTagText, { color: incident.category_color }]}>
              {incident.category_name}
            </Text>
          </View>

          <View style={styles.metaChipsRow}>
            <View style={[styles.metaChip, { backgroundColor: PRIORITY_COLORS[incident.priority] + '18' }]}>
              <Text style={[styles.metaChipText, { color: PRIORITY_COLORS[incident.priority] }]}>
                Priorité {PRIORITY_LABELS[incident.priority]}
              </Text>
            </View>
            {incident.assigned_to_name ? (
              <View style={styles.metaChip}>
                <Text style={styles.metaChipText}>
                  Assigné à {incident.assigned_to_name}
                </Text>
              </View>
            ) : null}
          </View>

          {incident.title ? <Text style={styles.title}>{incident.title}</Text> : null}
          <Text style={styles.description}>{incident.description}</Text>
          <View style={styles.civicNote}>
            <Text style={styles.civicNoteTitle}>Pourquoi ce signalement compte</Text>
            <Text style={styles.civicNoteText}>
              Chaque mise à jour renforce la transparence entre citoyens, agents et décision publique locale.
            </Text>
          </View>

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

        {isStaff && (
          <View style={styles.card}>
            <Text style={styles.sectionTitle}>Traitement terrain</Text>
            <Text style={styles.staffIntro}>
              Connecté comme {staffRoleLabel}. Depuis l’application, vous pouvez qualifier ce dossier, faire progresser le traitement et valider l’exécution. Le back-office web reste la surface complète de supervision.
            </Text>

            <View style={styles.nextActionCard}>
              <Text style={styles.nextActionEyebrow}>Prochaine action recommandée</Text>
              <Text style={styles.nextActionTitle}>{recommendedAction.title}</Text>
              <Text style={styles.nextActionText}>{recommendedAction.description}</Text>
              <TouchableOpacity style={styles.nextActionBtn} onPress={applyRecommendedAction}>
                <Text style={styles.nextActionBtnText}>{recommendedAction.cta}</Text>
              </TouchableOpacity>
            </View>

            <View style={styles.staffStatusRow}>
              {STAFF_STATUSES.map((status) => {
                const selected = staffStatus === status;
                const color = STATUS_COLORS[status];

                return (
                  <TouchableOpacity
                    key={status}
                    style={[
                      styles.staffStatusChip,
                      selected && { backgroundColor: color + '22', borderColor: color },
                    ]}
                    onPress={() => setStaffStatus(status)}
                  >
                    <Text
                      style={[
                        styles.staffStatusChipText,
                        selected && { color },
                      ]}
                    >
                      {STATUS_LABELS[status]}
                    </Text>
                  </TouchableOpacity>
                );
              })}
            </View>

            <Text style={styles.staffFieldLabel}>Priorité de traitement</Text>
            <View style={styles.staffPriorityRow}>
              {PRIORITIES.map((priority) => {
                const selected = staffPriority === priority;
                const color = PRIORITY_COLORS[priority];

                return (
                  <TouchableOpacity
                    key={priority}
                    style={[
                      styles.staffPriorityChip,
                      selected && { backgroundColor: color + '22', borderColor: color },
                    ]}
                    onPress={() => setStaffPriority(priority)}
                  >
                    <Text
                      style={[
                        styles.staffPriorityChipText,
                        selected && { color },
                      ]}
                    >
                      {PRIORITY_LABELS[priority]}
                    </Text>
                  </TouchableOpacity>
                );
              })}
            </View>

            <TouchableOpacity
              style={[styles.assignRow, assignToMe && styles.assignRowActive]}
              onPress={() => setAssignToMe((value) => !value)}
            >
              <Text style={styles.assignIcon}>{assignToMe ? '☑️' : '⬜️'}</Text>
              <View style={{ flex: 1 }}>
                <Text style={styles.assignTitle}>Me l’assigner</Text>
                <Text style={styles.assignText}>
                  {assignToMe
                    ? 'Le dossier sera attribué à votre compte métier.'
                    : 'Le dossier restera sans agent assigné.'}
                </Text>
              </View>
            </TouchableOpacity>

            <Text style={styles.staffFieldLabel}>Note de traitement</Text>
            <TextInput
              style={styles.staffNoteInput}
              placeholder="Décrivez l'action menée, l'avancement ou la validation sur le terrain..."
              placeholderTextColor={COLORS.gray}
              value={staffNote}
              onChangeText={setStaffNote}
              multiline
              textAlignVertical="top"
              maxLength={1000}
            />

            <TouchableOpacity
              style={[styles.staffActionBtn, updatingStatus && styles.staffActionBtnDisabled]}
              onPress={handleStaffStatusUpdate}
              disabled={updatingStatus}
            >
              {updatingStatus ? (
                <ActivityIndicator color={COLORS.white} size="small" />
              ) : (
                <Text style={styles.staffActionBtnText}>
                  {staffStatus === 'resolved' ? 'Valider l’exécution' : 'Enregistrer le traitement'}
                </Text>
              )}
            </TouchableOpacity>
          </View>
        )}

        {history.length > 0 && (
          <View style={styles.card}>
            <Text style={styles.sectionTitle}>Historique de traitement</Text>
            {history.map((h, i) => (
              <View key={i} style={styles.historyItem}>
                <View style={styles.historyDot} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.historyStatus}>
                    {h.old_status ? `${STATUS_LABELS[h.old_status] ?? h.old_status} → ` : ''}
                    {STATUS_LABELS[h.new_status] ?? h.new_status}
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

        <View style={styles.card}>
          <Text style={styles.sectionTitle}>
            Commentaires publics ({publicComments.length})
          </Text>

          {publicComments.length === 0 && (
            <Text style={styles.noComments}>
              Aucun commentaire pour l'instant. Le suivi commencera ici dès qu’un citoyen ou un agent ajoutera une précision utile.
            </Text>
          )}

          {publicComments.map(c => (
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

          <View style={styles.commentForm}>
            <Text style={styles.commentFormTitle}>Ajouter une précision utile</Text>
            <TextInput
              style={styles.commentInput}
              placeholder="Décrivez une évolution, une précision de lieu ou un élément observé..."
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
  scroll:   { flex: 1, backgroundColor: BRAND.colors.mist },
  content:  { paddingBottom: 40, paddingTop: 18 },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  errorMsg: { fontSize: 16, color: COLORS.gray },

  heroCard: {
    marginHorizontal: 16,
    marginBottom: 0,
    backgroundColor: BRAND.colors.canopyDeep,
    borderRadius: 24,
    padding: 20,
    ...BRAND_SHADOW,
  },
  heroEyebrow: {
    color: BRAND.colors.awara,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.1,
    marginBottom: 10,
  },
  heroTitle: {
    color: BRAND.colors.white,
    fontSize: 26,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
  },
  heroText: {
    color: '#D7E7DF',
    fontSize: 14,
    lineHeight: 21,
    marginTop: 10,
  },
  companionWrap: {
    marginHorizontal: 16,
    marginTop: 16,
  },
  progressCard: {
    marginHorizontal: 16,
    marginTop: 16,
    marginBottom: 0,
    backgroundColor: '#FFFDF8',
    borderRadius: 20,
    padding: 16,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  progressEyebrow: {
    color: BRAND.colors.canopy,
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: 12,
  },
  progressRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 10,
  },
  progressStep: {
    flex: 1,
    alignItems: 'center',
    gap: 8,
  },
  progressDot: {
    width: 14,
    height: 14,
    borderRadius: 999,
    backgroundColor: '#E2D8C8',
  },
  progressDotDone: {
    backgroundColor: BRAND.colors.leaf,
  },
  progressDotActive: {
    backgroundColor: BRAND.colors.awara,
    borderWidth: 2,
    borderColor: BRAND.colors.canopyDeep,
  },
  progressLabel: {
    fontSize: 11,
    color: BRAND.colors.slate,
    textAlign: 'center',
    lineHeight: 15,
  },
  progressLabelDone: {
    color: BRAND.colors.canopyDeep,
    fontWeight: '700',
  },
  progressRejected: {
    marginTop: 12,
    fontSize: 12.5,
    lineHeight: 18,
    color: BRAND.colors.slate,
  },

  photoSection: { backgroundColor: COLORS.primaryDark, marginTop: 16 },
  mainPhoto:    { width: '100%', height: 260, resizeMode: 'cover' },
  thumbRow:     { paddingHorizontal: 12, paddingVertical: 8 },
  thumb: {
    width: 60, height: 60, borderRadius: 8, marginRight: 8,
    borderWidth: 2, borderColor: 'transparent',
  },
  thumbActive: { borderColor: COLORS.primary },

  card: {
    backgroundColor: '#FFFDF8',
    margin: 16,
    marginBottom: 0,
    borderRadius: 20,
    padding: 18,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
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
    paddingVertical: 8,
    borderRadius: 12,
    marginBottom: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  categoryTagText: { fontSize: 13, fontWeight: '600' },
  metaChipsRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginBottom: 12,
  },
  metaChip: {
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 999,
    backgroundColor: '#F3EEE2',
  },
  metaChipText: {
    fontSize: 12,
    fontWeight: '700',
    color: BRAND.colors.slate,
  },

  title:       { fontSize: 20, fontWeight: '800', color: COLORS.dark, marginBottom: 8, fontFamily: BRAND.displayFont },
  description: { fontSize: 15, color: '#355248', lineHeight: 23, marginBottom: 14 },
  civicNote: {
    backgroundColor: BRAND.surfaces.mutedCard,
    borderRadius: 16,
    padding: 14,
    marginBottom: 14,
  },
  civicNoteTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
    marginBottom: 4,
  },
  civicNoteText: {
    fontSize: 13,
    lineHeight: 20,
    color: BRAND.colors.slate,
  },

  infoRow: { flexDirection: 'row', alignItems: 'flex-start', marginBottom: 6, gap: 8 },
  infoIcon:{ fontSize: 16 },
  infoText:{ fontSize: 14, color: COLORS.gray, flex: 1 },

  // Vote section (v1.1)
  voteSection: { marginTop: 8 },
  voteDivider: {
    height: 1,
    backgroundColor: '#E6DCC8',
    marginBottom: 12,
  },
  voteHint: {
    fontSize: 13,
    color: COLORS.gray,
    marginBottom: 4,
    textAlign: 'center',
  },

  sectionTitle: { fontSize: 17, fontWeight: '800', color: COLORS.dark, marginBottom: 14 },
  staffIntro: {
    fontSize: 14,
    lineHeight: 21,
    color: BRAND.colors.slate,
    marginBottom: 16,
  },
  nextActionCard: {
    backgroundColor: '#F2F8F2',
    borderRadius: 18,
    padding: 16,
    borderWidth: 1,
    borderColor: '#C7DDCA',
    marginBottom: 16,
  },
  nextActionEyebrow: {
    fontSize: 11,
    fontWeight: '800',
    color: BRAND.colors.canopy,
    textTransform: 'uppercase',
    letterSpacing: 1,
    marginBottom: 6,
  },
  nextActionTitle: {
    fontSize: 18,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
    marginBottom: 6,
  },
  nextActionText: {
    fontSize: 13,
    lineHeight: 20,
    color: BRAND.colors.slate,
  },
  nextActionBtn: {
    marginTop: 14,
    minHeight: 46,
    borderRadius: 14,
    backgroundColor: BRAND.colors.canopyDeep,
    alignItems: 'center',
    justifyContent: 'center',
  },
  nextActionBtnText: {
    color: BRAND.colors.white,
    fontSize: 14,
    fontWeight: '800',
  },
  staffStatusRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    marginBottom: 16,
  },
  staffStatusChip: {
    borderWidth: 1,
    borderColor: '#E6DCC8',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 9,
    backgroundColor: '#FFF9EF',
  },
  staffStatusChipText: {
    fontSize: 13,
    fontWeight: '700',
    color: BRAND.colors.ink,
  },
  staffPriorityRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 10,
    marginBottom: 14,
  },
  staffPriorityChip: {
    borderWidth: 1,
    borderColor: '#E6DCC8',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 9,
    backgroundColor: '#FFF9EF',
  },
  staffPriorityChipText: {
    fontSize: 13,
    fontWeight: '700',
    color: BRAND.colors.ink,
  },
  assignRow: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 10,
    borderWidth: 1,
    borderColor: '#E6DCC8',
    borderRadius: 16,
    backgroundColor: '#FFF9EF',
    padding: 14,
    marginBottom: 14,
  },
  assignRowActive: {
    borderColor: BRAND.colors.canopy,
    backgroundColor: '#F2F8F2',
  },
  assignIcon: {
    fontSize: 16,
    marginTop: 1,
  },
  assignTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
    marginBottom: 4,
  },
  assignText: {
    fontSize: 13,
    lineHeight: 19,
    color: BRAND.colors.slate,
  },
  staffFieldLabel: {
    fontSize: 13,
    fontWeight: '700',
    color: BRAND.colors.canopyDeep,
    marginBottom: 8,
  },
  staffNoteInput: {
    minHeight: 110,
    borderWidth: 1.5,
    borderColor: '#E6DCC8',
    borderRadius: 16,
    backgroundColor: '#FFF9EF',
    color: BRAND.colors.ink,
    paddingHorizontal: 14,
    paddingVertical: 14,
    marginBottom: 14,
    textAlignVertical: 'top',
  },
  staffActionBtn: {
    minHeight: 52,
    borderRadius: 16,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: BRAND.colors.canopy,
    ...BRAND_SHADOW,
  },
  staffActionBtnDisabled: {
    opacity: 0.7,
  },
  staffActionBtnText: {
    color: BRAND.colors.white,
    fontSize: 15,
    fontWeight: '800',
    letterSpacing: 0.2,
  },

  historyItem: { flexDirection: 'row', gap: 12, marginBottom: 12 },
  historyDot: {
    width: 10, height: 10, borderRadius: 5,
    backgroundColor: COLORS.primary, marginTop: 4,
  },
  historyStatus: { fontSize: 14, fontWeight: '600', color: COLORS.dark, marginBottom: 2 },
  historyNote:   { fontSize: 13, color: '#355248', marginBottom: 2 },
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
  commentText:   { fontSize: 14, color: '#355248', lineHeight: 20, marginBottom: 4 },
  commentDate:   { fontSize: 12, color: COLORS.gray },

  commentForm: { marginTop: 12, gap: 8 },
  commentFormTitle: {
    fontSize: 13,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
  },
  commentInput: {
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 14,
    paddingHorizontal: 14,
    paddingVertical: 10,
    fontSize: 14,
    color: COLORS.dark,
    backgroundColor: '#FFF9F0',
    minHeight: 80,
    textAlignVertical: 'top',
  },
  sendBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: 14,
    paddingVertical: 12,
    alignItems: 'center',
  },
  sendBtnText: { color: COLORS.white, fontWeight: '700', fontSize: 15 },
});
