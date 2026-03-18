import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, FlatList, TouchableOpacity,
  StyleSheet, RefreshControl, ActivityIndicator, Alert,
} from 'react-native';
import { notificationsApi, Notification } from '../services/api';
import { useNavigation } from '@react-navigation/native';
import { clearBadge } from '../services/NotificationService';
import { COLORS } from '../components/ui';
import { BRAND, BRAND_SHADOW } from '../theme/brand';

const TYPE_ICONS: Record<string, string> = {
  status_change:   '🔄',
  new_comment:     '💬',
  vote_milestone:  '🎉',
  system:          '📢',
  event:           '📅',
};

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
        'Référence disponible',
        `Cette notification mentionne ${notif.incident_reference}, mais aucun lien direct n'a été transmis.`
      );
      return;
    }

    Alert.alert('Information', 'Cette notification ne dispose pas encore d’un écran dédié.');
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
      <View style={styles.center}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

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
          <View style={styles.empty}>
            <Text style={styles.emptyIcon}>🔔</Text>
            <Text style={styles.emptyText}>Aucune notification pour l'instant</Text>
            <Text style={styles.emptySubtext}>
              Vous serez prévenu des mises à jour, commentaires et étapes de traitement de vos signalements.
            </Text>
          </View>
        }
      />
    </View>
  );
};

const styles = StyleSheet.create({
  container:    { flex: 1, backgroundColor: BRAND.colors.mist },
  listContent:  { paddingHorizontal: 16, paddingBottom: 120 },
  center:       { flex: 1, justifyContent: 'center', alignItems: 'center' },
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
  ref:          { fontSize: 11, color: COLORS.primary, marginTop: 4, fontWeight: '600' },
  date:         { fontSize: 11, color: '#94a3b8', marginTop: 4 },
  dot:          {
    width: 10, height: 10, borderRadius: 5,
    backgroundColor: COLORS.primary, marginTop: 6,
  },
  empty:        { alignItems: 'center', paddingTop: 80, paddingHorizontal: 40 },
  emptyIcon:    { fontSize: 48, marginBottom: 16 },
  emptyText:    { fontSize: 16, fontWeight: '600', color: COLORS.dark, textAlign: 'center' },
  emptySubtext: { fontSize: 13, color: COLORS.textSecondary, textAlign: 'center', marginTop: 8 },
});

export default NotificationsScreen;
