import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, FlatList, TouchableOpacity,
  StyleSheet, RefreshControl, Alert,
} from 'react-native';
import { notificationsApi, Notification } from '../services/api';
import { useNavigation } from '@react-navigation/native';
import { clearBadge } from '../services/NotificationService';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { ScreenFeedbackState, ScreenLoadingState } from '../components/ScreenStatePanel';
import { COLORS } from '../components/ui';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';

const TYPE_ICONS: Record<string, string> = {
  status_change:   '🔄',
  new_comment:     '💬',
  vote_milestone:  '🎉',
  system:          '📢',
  event:           '📅',
  intervention_plan: '🛠️',
  intervention_update: '🚧',
};

function buildNotificationContextLabel(notification: Notification): string | null {
  const context = notification.intervention_context;
  if (!context) {
    return null;
  }

  const parts: string[] = [];

  if (context.service_name) {
    parts.push(`Service ${context.service_name}`);
  }

  if (context.source_type === 'provider' && context.provider_name) {
    parts.push(`Prestataire ${context.provider_name}`);
  } else if (context.source_type === 'internal') {
    parts.push('Equipe interne');
  }

  if (context.scheduled_date) {
    const dateLabel = new Date(context.scheduled_date).toLocaleDateString('fr-FR', {
      day: '2-digit',
      month: 'long',
    });
    const timeWindow = [context.time_window_start, context.time_window_end].filter(Boolean).join(' - ');
    parts.push(timeWindow ? `${dateLabel} · ${timeWindow}` : dateLabel);
  }

  return parts.length > 0 ? parts.join(' · ') : null;
}

function getNotificationsCompanion(
  notifications: Notification[],
  unreadCount: number
): { tone: 'guide' | 'thanks' | 'status'; title: string; body: string; bullets: string[] } {
  const latest = notifications[0];
  const unreadNotifications = notifications.filter((notification) => !notification.is_read);
  const latestUnread = unreadNotifications[0];
  const latestPlan = unreadNotifications.find((notification) => notification.type === 'intervention_plan');
  const latestInterventionUpdate = unreadNotifications.find((notification) => notification.type === 'intervention_update');

  if (!latest) {
    return {
      tone: 'guide',
      title: `${BRAND.companion.name} vous previendra au bon moment`,
      body: 'Cette boite sert a rendre le suivi moins opaque. Vous y verrez les changements de statut, les commentaires utiles et les informations communales.',
      bullets: [
        'suivre vos changements de statut ici',
        'ouvrir un dossier des qu une notification importante arrive',
      ],
    };
  }

  if (latestPlan && unreadCount > 0) {
    return {
      tone: 'status',
      title: `${BRAND.companion.name} a une intervention a vous signaler`,
      body: 'Quand une equipe ou un prestataire est programme, cette vue doit rendre la prochaine etape tout de suite compréhensible.',
      bullets: [
        latestPlan.incident_reference ? `ouvrir ${latestPlan.incident_reference} pour relire le passage prevu` : 'ouvrir la planification la plus recente',
        'verifier la fenetre annoncee et le message transmis par la commune',
      ],
    };
  }

  if (latestInterventionUpdate && unreadCount > 0) {
    return {
      tone: 'status',
      title: `${BRAND.companion.name} suit l avancement de l intervention`,
      body: 'Quand l equipe passe, termine ou reprogramme, cette vue doit vous dire clairement ce qui a change sur le terrain.',
      bullets: [
        latestInterventionUpdate.incident_reference
          ? `ouvrir ${latestInterventionUpdate.incident_reference} pour relire l etape`
          : 'ouvrir la derniere mise a jour intervention',
        'verifier si une action ou une replanification est encore attendue',
      ],
    };
  }

  if (unreadCount > 0) {
    return {
      tone: 'status',
      title: `${BRAND.companion.name} a repere ${unreadCount} mise(s) a jour utile(s)`,
      body: 'Votre pile de notifications doit vous dire quoi ouvrir tout de suite, pas seulement empiler des alertes.',
      bullets: [
        'commencer par les notifications non lues',
        latestUnread?.incident_reference ? `ouvrir ${latestUnread.incident_reference} si besoin` : 'ouvrir la mise a jour la plus recente',
      ],
    };
  }

  return {
    tone: 'thanks',
    title: `${BRAND.companion.name} garde votre suivi au clair`,
    body: 'Toutes les notifications visibles ont deja ete lues. Vous pouvez revenir ici pour relire une etape, un commentaire ou un evenement communal.',
    bullets: [
      'utiliser cette vue comme journal de suivi',
      'ouvrir un dossier si un doute revient',
    ],
  };
}

const formatDate = (iso: string): string => {
  const d = new Date(iso);
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMins = Math.floor(diffMs / 60000);

  if (diffMins < 1)  return 'À l\'instant';
  if (diffMins < 60) return `Il y a ${diffMins} min`;
  const diffH = Math.floor(diffMins / 60);
  if (diffH < 24)    return `Il y a ${diffH}h`;
  const diffD = Math.floor(diffH / 24);
  if (diffD < 7)     return `Il y a ${diffD}j`;
  return d.toLocaleDateString('fr-FR');
};

export const NotificationsScreen: React.FC = () => {
  const navigation = useNavigation<any>();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount]     = useState(0);
  const [loading, setLoading]             = useState(true);
  const [refreshing, setRefreshing]       = useState(false);

  const fetchNotifications = useCallback(async () => {
    try {
      const res = await notificationsApi.list();
      if (res.data) {
        setNotifications(res.data.notifications);
        setUnreadCount(res.data.unread_count);
      }
    } catch (error) {
      console.error('Erreur chargement notifications', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => {
    fetchNotifications();
    clearBadge();
  }, []);

  const handleMarkAllRead = async () => {
    await notificationsApi.markAllRead();
    setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true })));
    setUnreadCount(0);
  };

  const handlePress = async (notif: Notification) => {
    if (!notif.is_read) {
      await notificationsApi.markRead(notif.id);
      setNotifications((prev) =>
        prev.map((n) => n.id === notif.id ? { ...n, is_read: true } : n)
      );
      setUnreadCount((c) => Math.max(0, c - 1));
    }
    if (notif.incident_id) {
      navigation.navigate('IncidentDetail', { id: notif.incident_id, reference: notif.incident_reference });
      return;
    }

    if (notif.type === 'event') {
      navigation.navigate('Events');
      return;
    }

    if (notif.incident_reference) {
      Alert.alert(
        'Reference disponible',
        `${BRAND.companion.name} vous transmet la reference ${notif.incident_reference}, mais aucun lien direct n'a encore ete fourni pour ouvrir ce dossier.`
      );
      return;
    }

    Alert.alert('Notification sans ecran dedie', 'Cette notification a bien ete lue, mais elle ne dispose pas encore d un ecran detaille dans l application.');
  };

  const renderNotificationContext = (notification: Notification) => {
    const contextLabel = buildNotificationContextLabel(notification);
    if (!contextLabel) {
      return null;
    }

    return (
      <Text style={styles.contextLabel} numberOfLines={2}>
        {contextLabel}
      </Text>
    );
  };

  const renderItem = ({ item }: { item: Notification }) => (
    <TouchableOpacity
      style={[styles.item, !item.is_read && styles.itemUnread]}
      onPress={() => handlePress(item)}
      activeOpacity={0.75}
    >
      <Text style={styles.icon}>{TYPE_ICONS[item.type] ?? '🔔'}</Text>
      <View style={styles.content}>
        <Text style={[styles.title, !item.is_read && styles.titleUnread]} numberOfLines={1}>
          {item.title}
        </Text>
        <Text style={styles.body} numberOfLines={2}>{item.body}</Text>
        {renderNotificationContext(item)}
        {item.incident_reference && (
          <Text style={styles.ref}>Réf. {item.incident_reference}</Text>
        )}
        <Text style={styles.date}>{formatDate(item.sent_at)}</Text>
      </View>
      {!item.is_read && <View style={styles.dot} />}
    </TouchableOpacity>
  );

  if (loading) {
    return (
      <ScreenLoadingState
        title="Vos alertes se remettent en ordre"
        body="L'agent trie d abord les mises a jour utiles pour vous laisser relire ce qui compte vraiment."
      />
    );
  }

  const companion = getNotificationsCompanion(notifications, unreadCount);

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerEyebrow}>Suivi d'information</Text>
        <Text style={styles.headerTitle}>Actualités de vos démarches</Text>
        <Text style={styles.headerText}>
          Suivez les évolutions de vos signalements et gardez un lien clair avec l'action communale.
        </Text>
        <View style={styles.headerActions}>
          <View style={styles.badgePill}>
            <Text style={styles.badgePillValue}>{unreadCount}</Text>
            <Text style={styles.badgePillLabel}>non lues</Text>
          </View>
          {unreadCount > 0 && (
            <TouchableOpacity onPress={handleMarkAllRead} style={styles.markAllBtn}>
              <Text style={styles.markAll}>Tout lire</Text>
            </TouchableOpacity>
          )}
        </View>
      </View>



      <FlatList
        data={notifications}
        keyExtractor={(item) => String(item.id)}
        renderItem={renderItem}
        contentContainerStyle={styles.listContent}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => { setRefreshing(true); fetchNotifications(); }}
            colors={[COLORS.primary]}
          />
        }
        ListEmptyComponent={
          <ScreenFeedbackState
            icon="🔔"
            title="Aucune notification pour l instant"
            body="Vous retrouverez ici les mises a jour, commentaires utiles et etapes de traitement de vos signalements."
          />
        }
      />
    </View>
  );
};

const styles = StyleSheet.create({
  container:    { flex: 1, backgroundColor: BRAND.colors.mist },
  listContent:  { paddingHorizontal: 16, paddingBottom: 120 },
  header:       {
    paddingHorizontal: 16,
    paddingTop: 28,
    paddingBottom: 16,
  },
  headerEyebrow: {
    color: BRAND.colors.canopy,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.1,
  },
  headerTitle:  {
    fontSize: 28,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
    fontFamily: BRAND.displayFont,
    marginTop: 8,
  },
  headerText: {
    fontSize: 14,
    color: BRAND.colors.slate,
    lineHeight: 21,
    marginTop: 8,
  },
  headerActions: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    marginTop: 16,
  },
  companionWrap: {
    paddingHorizontal: 16,
    paddingBottom: 12,
  },
  badgePill: {
    backgroundColor: BRAND.colors.canopyDeep,
    borderRadius: 18,
    paddingHorizontal: 14,
    paddingVertical: 10,
  },
  badgePillValue: {
    color: BRAND.colors.white,
    fontSize: 16,
    fontWeight: '800',
  },
  badgePillLabel: {
    color: '#D7E7DF',
    fontSize: 11,
    marginTop: 2,
  },
  markAllBtn: {
    backgroundColor: BRAND.colors.awara,
    borderRadius: 16,
    paddingHorizontal: 14,
    paddingVertical: 10,
  },
  markAll:      { fontSize: 13, color: BRAND.colors.canopyDeep, fontWeight: '800' },
  item:         {
    flexDirection: 'row', alignItems: 'flex-start',
    padding: 16, backgroundColor: '#FFFDF8',
    borderRadius: 18,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    marginBottom: 12,
    gap: 12,
    ...BRAND_SHADOW,
  },
  itemUnread:   { backgroundColor: '#F8F3E7' },
  icon:         { fontSize: 24, marginTop: 2 },
  content:      { flex: 1 },
  title:        { fontSize: 15, fontWeight: '600', color: COLORS.dark, marginBottom: 3 },
  titleUnread:  { fontWeight: '700' },
  body:         { fontSize: 13, color: COLORS.textSecondary, lineHeight: 19 },
  contextLabel: { fontSize: 12, color: BRAND.colors.canopyDeep, lineHeight: 18, fontWeight: '700', marginTop: 6 },
  ref:          { fontSize: 11, color: COLORS.primary, marginTop: 4, fontWeight: '600' },
  date:         { fontSize: 11, color: '#94a3b8', marginTop: 4 },
  dot:          {
    width: 10, height: 10, borderRadius: 5,
    backgroundColor: COLORS.primary, marginTop: 6,
  },
});

export default NotificationsScreen;
