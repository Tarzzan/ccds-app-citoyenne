/**
 * CCDS v1.2 — Écran Mes Signalements (UX-01)
 * Recherche textuelle, filtres par statut, tri (date / votes).
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  ActivityIndicator, RefreshControl, Alert, TextInput,
  Animated,
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

const SORT_OPTIONS = [
  { key: 'created_at', label: 'Plus récents' },
  { key: 'votes',      label: 'Plus votés' },
  { key: 'updated_at', label: 'Mis à jour' },
];

export default function MyIncidentsScreen() {
  const navigation = useNavigation<NavProp>();
  const { user, logout } = useAuth();

  const [incidents,    setIncidents]    = useState<Incident[]>([]);
  const [loading,      setLoading]      = useState(true);
  const [refreshing,   setRefreshing]   = useState(false);
  const [loadingMore,  setLoadingMore]  = useState(false);
  const [page,         setPage]         = useState(1);
  const [totalPages,   setTotalPages]   = useState(1);
  const [filter,       setFilter]       = useState('');
  const [searchQuery,  setSearchQuery]  = useState('');
  const [sortBy,       setSortBy]       = useState('created_at');
  const [showFilters,  setShowFilters]  = useState(false);

  // Debounce pour la recherche
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [debouncedQuery, setDebouncedQuery] = useState('');

  useEffect(() => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setDebouncedQuery(searchQuery), 400);
    return () => { if (searchTimer.current) clearTimeout(searchTimer.current); };
  }, [searchQuery]);

  const loadIncidents = useCallback(async (p = 1, reset = false) => {
    if (p === 1) setLoading(true);
    else         setLoadingMore(true);
    try {
      const params: Record<string, any> = { page: p, limit: 15, sort: sortBy, dir: 'DESC' };
      if (filter)        params.status = filter;
      if (debouncedQuery) params.q    = debouncedQuery;

      const res = await incidentsApi.list(params);
      if (res.data) {
        const newItems = (res.data as any).incidents ?? (res.data as any).items ?? [];
        setIncidents(prev => reset || p === 1 ? newItems : [...prev, ...newItems]);
        setTotalPages(res.data.pagination?.total_pages ?? 1);
        setPage(p);
      }
    } catch {
      Alert.alert('Erreur', 'Impossible de charger vos signalements.');
    } finally {
      setLoading(false);
      setRefreshing(false);
      setLoadingMore(false);
    }
  }, [filter, debouncedQuery, sortBy]);

  useEffect(() => { loadIncidents(1, true); }, [filter, debouncedQuery, sortBy]);

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
        <View style={styles.headerActions}>
          <TouchableOpacity
            style={styles.profileBtn}
            onPress={() => (navigation as any).navigate('Profile')}
          >
            <Text style={styles.profileBtnText}>👤</Text>
          </TouchableOpacity>
          <TouchableOpacity onPress={() => Alert.alert('Déconnexion', 'Voulez-vous vous déconnecter ?', [
            { text: 'Annuler', style: 'cancel' },
            { text: 'Déconnecter', style: 'destructive', onPress: logout },
          ])}>
            <Text style={styles.logoutIcon}>🚪</Text>
          </TouchableOpacity>
        </View>
      </View>

      {/* Bouton Nouveau signalement */}
      <TouchableOpacity
        style={styles.newBtn}
        onPress={() => navigation.navigate('CreateIncident')}
        activeOpacity={0.85}
      >
        <Text style={styles.newBtnText}>📸  Signaler un problème</Text>
      </TouchableOpacity>

      {/* Barre de recherche */}
      <View style={styles.searchRow}>
        <View style={styles.searchBox}>
          <Text style={styles.searchIcon}>🔍</Text>
          <TextInput
            style={styles.searchInput}
            placeholder="Rechercher un signalement..."
            placeholderTextColor={COLORS.gray}
            value={searchQuery}
            onChangeText={setSearchQuery}
            returnKeyType="search"
            clearButtonMode="while-editing"
          />
        </View>
        <TouchableOpacity
          style={[styles.filterToggle, showFilters && styles.filterToggleActive]}
          onPress={() => setShowFilters(v => !v)}
        >
          <Text style={styles.filterToggleText}>⚙️</Text>
        </TouchableOpacity>
      </View>

      {/* Filtres avancés (dépliables) */}
      {showFilters && (
        <View style={styles.advancedFilters}>
          <Text style={styles.filterLabel}>Statut</Text>
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

          <Text style={styles.filterLabel}>Trier par</Text>
          <View style={styles.filtersRow}>
            {SORT_OPTIONS.map(s => (
              <TouchableOpacity
                key={s.key}
                style={[styles.filterChip, sortBy === s.key && styles.filterChipActive]}
                onPress={() => setSortBy(s.key)}
              >
                <Text style={[styles.filterText, sortBy === s.key && styles.filterTextActive]}>
                  {s.label}
                </Text>
              </TouchableOpacity>
            ))}
          </View>
        </View>
      )}

      <Text style={styles.sectionTitle}>
        Mes signalements
        {debouncedQuery ? ` · "${debouncedQuery}"` : ''}
        {filter ? ` · ${STATUS_LABELS[filter] ?? filter}` : ''}
      </Text>
    </View>
  );

  const renderEmpty = () => (
    <View style={styles.emptyBox}>
      <Text style={styles.emptyIcon}>{debouncedQuery ? '🔍' : '📋'}</Text>
      <Text style={styles.emptyTitle}>
        {debouncedQuery ? 'Aucun résultat' : 'Aucun signalement'}
      </Text>
      <Text style={styles.emptyText}>
        {debouncedQuery
          ? `Aucun signalement ne correspond à "${debouncedQuery}".`
          : filter
            ? `Aucun signalement avec le statut "${STATUS_LABELS[filter] ?? filter}".`
            : "Vous n'avez pas encore effectué de signalement.\nAppuyez sur le bouton ci-dessus pour commencer."
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
      keyboardShouldPersistTaps="handled"
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
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  profileBtn: {
    width: 36, height: 36, borderRadius: 18,
    backgroundColor: COLORS.light, justifyContent: 'center', alignItems: 'center',
  },
  profileBtnText: { fontSize: 18 },
  logoutIcon: { fontSize: 24 },

  newBtn: {
    backgroundColor: COLORS.primary,
    borderRadius: 14,
    paddingVertical: 16,
    alignItems: 'center',
    marginBottom: 16,
    shadowColor: COLORS.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 5,
  },
  newBtnText: { color: COLORS.white, fontSize: 16, fontWeight: '700' },

  // Recherche
  searchRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 12 },
  searchBox: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: COLORS.white,
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    paddingHorizontal: 12,
    height: 44,
  },
  searchIcon:  { fontSize: 16, marginRight: 8 },
  searchInput: { flex: 1, fontSize: 14, color: COLORS.dark, height: 44 },
  filterToggle: {
    width: 44, height: 44, borderRadius: 12,
    backgroundColor: COLORS.white,
    borderWidth: 1.5, borderColor: COLORS.border,
    justifyContent: 'center', alignItems: 'center',
  },
  filterToggleActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  filterToggleText: { fontSize: 18 },

  // Filtres avancés
  advancedFilters: {
    backgroundColor: COLORS.white,
    borderRadius: 14,
    padding: 14,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: COLORS.border,
  },
  filterLabel: { fontSize: 12, fontWeight: '700', color: COLORS.gray, marginBottom: 8, textTransform: 'uppercase', letterSpacing: 0.5 },
  filtersRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginBottom: 12,
  },
  filterChip: {
    paddingHorizontal: 14,
    paddingVertical: 7,
    borderRadius: 20,
    backgroundColor: '#f1f5f9',
    borderWidth: 1.5,
    borderColor: COLORS.border,
  },
  filterChipActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  filterText:       { fontSize: 13, color: COLORS.gray, fontWeight: '500' },
  filterTextActive: { color: COLORS.white },

  sectionTitle: { fontSize: 16, fontWeight: '700', color: COLORS.dark, marginBottom: 12 },

  emptyBox:  { alignItems: 'center', paddingVertical: 48, paddingHorizontal: 24 },
  emptyIcon: { fontSize: 48, marginBottom: 12 },
  emptyTitle:{ fontSize: 18, fontWeight: '700', color: COLORS.dark, marginBottom: 8 },
  emptyText: { fontSize: 14, color: COLORS.gray, textAlign: 'center', lineHeight: 22 },
});
