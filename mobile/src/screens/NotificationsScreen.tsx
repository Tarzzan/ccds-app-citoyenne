import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, FlatList, TouchableOpacity,
  StyleSheet, RefreshControl, ActivityIndicator,
} from 'react-native';
import { notificationsApi, Notification } from '../services/api';
import { useNavigation } from '@react-navigation/native';
import { clearBadge } from '../services/NotificationService';
import { COLORS } from '../components/ui';

const TYPE_ICONS: Record<string, string> = {
  status_change:   '🔄',
  new_comment:     '💬',
  vote_milestone:  '🎉',
  system:          '📢',
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
    // Naviguer vers le signalement si disponible
    if (notif.incident_reference) {
      navigation.navigate('IncidentDetail', { reference: notif.incident_reference });
    }
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
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.headerTitle}>
          Notifications {unreadCount > 0 && <Text style={styles.badge}> {unreadCount} </Text>}
        </Text>
        {unreadCount > 0 && (
          <TouchableOpacity onPress={handleMarkAllRead}>
            <Text style={styles.markAll}>Tout lire</Text>
          </TouchableOpacity>
        )}
      </View>

      {/* Liste */}
      <FlatList
        data={notifications}
        keyExtractor={(item) => String(item.id)}
        renderItem={renderItem}
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
              Vous serez notifié des mises à jour de vos signalements
            </Text>
          </View>
        }
      />
    </View>
  );
};

const styles = StyleSheet.create({
  container:    { flex: 1, backgroundColor: '#f8fafc' },
  center:       { flex: 1, justifyContent: 'center', alignItems: 'center' },
  header:       {
    flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center',
    paddingHorizontal: 16, paddingVertical: 12,
    backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#e2e8f0',
  },
  headerTitle:  { fontSize: 18, fontWeight: '700', color: COLORS.dark },
  badge:        {
    backgroundColor: COLORS.primary, color: '#fff',
    fontSize: 12, fontWeight: '700', borderRadius: 10,
    paddingHorizontal: 6, paddingVertical: 2,
  },
  markAll:      { fontSize: 13, color: COLORS.primary, fontWeight: '600' },
  item:         {
    flexDirection: 'row', alignItems: 'flex-start',
    padding: 14, backgroundColor: '#fff',
    borderBottomWidth: 1, borderBottomColor: '#f1f5f9',
    gap: 12,
  },
  itemUnread:   { backgroundColor: '#f0fdf4' },
  icon:         { fontSize: 24, marginTop: 2 },
  content:      { flex: 1 },
  title:        { fontSize: 14, fontWeight: '500', color: COLORS.dark, marginBottom: 3 },
  titleUnread:  { fontWeight: '700' },
  body:         { fontSize: 13, color: COLORS.textSecondary, lineHeight: 18 },
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
