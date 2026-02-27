/**
 * CCDS — Écran Mes Signalements
 * Liste paginée des signalements de l'utilisateur connecté avec filtres par statut.
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  ActivityIndicator, RefreshControl, Alert,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';

import { incidentsApi, Incident } from '../services/api';
import { useAuth } from '../services/AuthContext';
import { IncidentCard, COLORS, STATUS_LABELS } from '../components/ui';
import { AppStackParamList } from '../navigation/RootNavigator';

type NavProp = NativeStackNavigationProp<AppStackParamList>;

const STATUS_FILTERS = [
  { key: '',             label: 'Tous' },
  { key: 'submitted',    label: 'Soumis' },
  { key: 'acknowledged', label: 'Pris en charge' },
  { key: 'in_progress',  label: 'En cours' },
  { key: 'resolved',     label: 'Résolus' },
  { key: 'rejected',     label: 'Rejetés' },
];

export default function MyIncidentsScreen() {
  const navigation = useNavigation<NavProp>();
  const { user, logout } = useAuth();

  const [incidents,   setIncidents]   = useState<Incident[]>([]);
  const [loading,     setLoading]     = useState(true);
  const [refreshing,  setRefreshing]  = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [page,        setPage]        = useState(1);
  const [totalPages,  setTotalPages]  = useState(1);
  const [filter,      setFilter]      = useState('');

  const loadIncidents = useCallback(async (p = 1, reset = false) => {
    if (p === 1) setLoading(true);
    else         setLoadingMore(true);
    try {
      const params: any = { page: p, limit: 15 };
      if (filter) params.status = filter;
      const res = await incidentsApi.list(params);
      if (res.data) {
        setIncidents(prev => reset || p === 1 ? res.data!.incidents : [...prev, ...res.data!.incidents]);
        setTotalPages(res.data.pagination.total_pages);
        setPage(p);
      }
    } catch {
      Alert.alert('Erreur', 'Impossible de charger vos signalements.');
    } finally {
      setLoading(false);
      setRefreshing(false);
      setLoadingMore(false);
    }
  }, [filter]);

  useEffect(() => { loadIncidents(1, true); }, [filter]);

  const onRefresh = () => {
    setRefreshing(true);
    loadIncidents(1, true);
  };

  const onEndReached = () => {
    if (!loadingMore && page < totalPages) {
      loadIncidents(page + 1);
    }
  };

  const renderHeader = () => (
    <View>
      {/* En-tête utilisateur */}
      <View style={styles.userHeader}>
        <View>
          <Text style={styles.greeting}>Bonjour, {user?.full_name?.split(' ')[0] ?? 'Citoyen'} 👋</Text>
          <Text style={styles.userEmail}>{user?.email}</Text>
        </View>
        <TouchableOpacity onPress={() => Alert.alert('Déconnexion', 'Voulez-vous vous déconnecter ?', [
          { text: 'Annuler', style: 'cancel' },
          { text: 'Déconnecter', style: 'destructive', onPress: logout },
        ])}>
          <Text style={styles.logoutIcon}>🚪</Text>
        </TouchableOpacity>
      </View>

      {/* Bouton Nouveau signalement */}
      <TouchableOpacity
        style={styles.newBtn}
        onPress={() => navigation.navigate('CreateIncident')}
        activeOpacity={0.85}
      >
        <Text style={styles.newBtnText}>📸  Signaler un problème</Text>
      </TouchableOpacity>

      {/* Filtres par statut */}
      <Text style={styles.sectionTitle}>Mes signalements</Text>
      <View style={styles.filtersRow}>
        {STATUS_FILTERS.map(f => (
          <TouchableOpacity
            key={f.key}
            style={[styles.filterChip, filter === f.key && styles.filterChipActive]}
            onPress={() => setFilter(f.key)}
          >
            <Text style={[styles.filterText, filter === f.key && styles.filterTextActive]}>
              {f.label}
            </Text>
          </TouchableOpacity>
        ))}
      </View>
    </View>
  );

  const renderEmpty = () => (
    <View style={styles.emptyBox}>
      <Text style={styles.emptyIcon}>📋</Text>
      <Text style={styles.emptyTitle}>Aucun signalement</Text>
      <Text style={styles.emptyText}>
        {filter
          ? `Aucun signalement avec le statut "${STATUS_LABELS[filter] ?? filter}".`
          : 'Vous n\'avez pas encore effectué de signalement.\nAppuyez sur le bouton ci-dessus pour commencer.'
        }
      </Text>
    </View>
  );

  const renderFooter = () =>
    loadingMore ? <ActivityIndicator color={COLORS.primary} style={{ marginVertical: 16 }} /> : null;

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" color={COLORS.primary} />
      </View>
    );
  }

  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={styles.listContent}
      data={incidents}
      keyExtractor={item => String(item.id)}
      renderItem={({ item }) => (
        <IncidentCard
          reference={item.reference}
          title={item.title}
          description={item.description}
          status={item.status}
          categoryName={item.category_name}
          categoryColor={item.category_color}
          date={item.created_at}
          onPress={() => navigation.navigate('IncidentDetail', { id: item.id })}
        />
      )}
      ListHeaderComponent={renderHeader}
      ListEmptyComponent={renderEmpty}
      ListFooterComponent={renderFooter}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={COLORS.primary} />}
      onEndReached={onEndReached}
      onEndReachedThreshold={0.3}
    />
  );
}

const styles = StyleSheet.create({
  list:        { flex: 1, backgroundColor: '#f8fafc' },
  listContent: { padding: 16, paddingTop: 56 },
  centered:    { flex: 1, justifyContent: 'center', alignItems: 'center' },

  userHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 16,
  },
  greeting:   { fontSize: 20, fontWeight: '800', color: COLORS.dark },
  userEmail:  { fontSize: 13, color: COLORS.gray, marginTop: 2 },
  logoutIcon: { fontSize: 24 },

  newBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: 14,
    paddingVertical: 16,
    alignItems: 'center',
    marginBottom: 24,
    shadowColor: COLORS.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 5,
  },
  newBtnText: { color: COLORS.white, fontSize: 16, fontWeight: '700' },

  sectionTitle: { fontSize: 18, fontWeight: '700', color: COLORS.dark, marginBottom: 12 },

  filtersRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginBottom: 16,
  },
  filterChip: {
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 20,
    backgroundColor: COLORS.white,
    borderWidth: 1.5,
    borderColor: COLORS.border,
  },
  filterChipActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  filterText:       { fontSize: 13, color: COLORS.gray, fontWeight: '500' },
  filterTextActive: { color: COLORS.white },

  emptyBox:  { alignItems: 'center', paddingVertical: 48, paddingHorizontal: 24 },
  emptyIcon: { fontSize: 48, marginBottom: 12 },
  emptyTitle:{ fontSize: 18, fontWeight: '700', color: COLORS.dark, marginBottom: 8 },
  emptyText: { fontSize: 14, color: COLORS.gray, textAlign: 'center', lineHeight: 22 },
});
