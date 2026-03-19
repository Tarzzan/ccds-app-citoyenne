/**
 * Ma Commune v1.2 — Écran Mes signalements (UX-01)
 * Recherche textuelle, filtres par statut, tri (date / votes).
 */

import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  View, Text, StyleSheet, FlatList, TouchableOpacity,
  ActivityIndicator, RefreshControl, Alert, TextInput,
} from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';

import { incidentsApi, Incident } from '../services/api';
import { useAuth } from '../services/AuthContext';
import { IncidentCard, COLORS, STATUS_LABELS, PRIORITY_LABELS } from '../components/ui';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { ScreenLoadingState } from '../components/ScreenStatePanel';
import { AppStackParamList } from '../navigation/RootNavigator';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';
import { GENERATED_VISUAL_SOURCES } from '../theme/generatedVisualSources';

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
  { key: 'priority',   label: 'Priorité' },
  { key: 'votes',      label: 'Plus votés' },
  { key: 'updated_at', label: 'Mis à jour' },
];

const QUEUE_OPTIONS = [
  { key: 'open',   label: 'Ouverts' },
  { key: '',       label: 'Tous' },
  { key: 'closed', label: 'Terminés' },
];

const PRIORITY_FILTERS = [
  { key: '', label: 'Toutes' },
  { key: 'critical', label: 'Critique' },
  { key: 'high', label: 'Haute' },
  { key: 'medium', label: 'Normale' },
  { key: 'low', label: 'Faible' },
];

function buildIncidentPlanSummary(incident: Incident): string | null {
  const plan = incident.current_plan;
  if (!plan) {
    return incident.service_name && ['submitted', 'acknowledged', 'in_progress'].includes(incident.status)
      ? 'Aucune intervention n est encore programmee pour ce dossier.'
      : null;
  }

  const executorLabel = plan.source_type === 'provider' && plan.provider_name
    ? `Prestataire ${plan.provider_name}`
    : plan.source_type === 'internal'
      ? 'Equipe interne'
      : 'La commune';

  if (plan.status === 'in_progress') {
    return `${executorLabel} en cours sur le terrain.`;
  }

  if (plan.status === 'completed') {
    return `${executorLabel} a marque cette intervention terminee.`;
  }

  if (plan.status === 'cancelled') {
    return `${executorLabel} a annule cette intervention. Une nouvelle planification pourra etre proposee.`;
  }

  if (!plan.scheduled_date) {
    return 'Plan d intervention en attente de date confirmee.';
  }

  const dateLabel = new Date(plan.scheduled_date).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: 'long',
  });
  const timeWindow = [plan.time_window_start, plan.time_window_end].filter(Boolean).join(' - ');
  const providerLabel = plan.source_type === 'provider' && plan.provider_name
    ? ` · Prestataire ${plan.provider_name}`
    : plan.source_type === 'internal'
      ? ' · Equipe interne'
      : '';

  return timeWindow
    ? `Intervention prévue le ${dateLabel} · ${timeWindow}${providerLabel}`
    : `Intervention prévue le ${dateLabel}${providerLabel}`;
}

function buildIncidentPlanState(incident: Incident): { label: string; variant: 'blue' | 'green' | 'yellow' | 'gray' } | null {
  const plan = incident.current_plan;
  if (!plan) {
    return incident.service_name && ['submitted', 'acknowledged', 'in_progress'].includes(incident.status)
      ? { label: 'A planifier', variant: 'yellow' }
      : null;
  }

  switch (plan.status) {
    case 'scheduled':
    case 'rescheduled':
      return { label: 'Prevue', variant: 'green' };
    case 'in_progress':
      return { label: 'En cours', variant: 'blue' };
    case 'completed':
      return { label: 'Terminee', variant: 'green' };
    case 'cancelled':
      return { label: 'Annulee', variant: 'gray' };
    default:
      return { label: 'A confirmer', variant: 'yellow' };
  }
}

function getIncidentListCompanionVisual(incidents: Incident[], isStaff: boolean, query: string) {
  const defaultVisual = isStaff ? COMPANION_VISUAL_SLOTS.dashboard.source : COMPANION_VISUAL_SLOTS.profile.source;

  if (!incidents.length && !query.trim()) {
    return GENERATED_VISUAL_SOURCES['MOM-05'] ?? defaultVisual;
  }

  if (incidents.some((incident) => incident.current_plan?.status === 'in_progress')) {
    return GENERATED_VISUAL_SOURCES['MOM-03'] ?? defaultVisual;
  }

  if (incidents.length > 0 && incidents.every((incident) => incident.status === 'resolved')) {
    return GENERATED_VISUAL_SOURCES['MOM-04'] ?? defaultVisual;
  }

  return defaultVisual;
}

export default function MyIncidentsScreen() {
  const navigation = useNavigation<NavProp>();
  const { user, logout, isStaff } = useAuth();

  const [incidents,    setIncidents]    = useState<Incident[]>([]);
  const [loading,      setLoading]      = useState(true);
  const [refreshing,   setRefreshing]   = useState(false);
  const [loadingMore,  setLoadingMore]  = useState(false);
  const [page,         setPage]         = useState(1);
  const [totalPages,   setTotalPages]   = useState(1);
  const [filter,       setFilter]       = useState('');
  const [searchQuery,  setSearchQuery]  = useState('');
  const [sortBy,       setSortBy]       = useState('created_at');
  const [priorityFilter, setPriorityFilter] = useState('');
  const [showFilters,  setShowFilters]  = useState(false);
  const [scope,        setScope]        = useState<'mine' | 'territory'>(isStaff ? 'territory' : 'mine');
  const [queue,        setQueue]        = useState(isStaff ? 'open' : '');

  // Debounce pour la recherche
  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [debouncedQuery, setDebouncedQuery] = useState('');

  useEffect(() => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => setDebouncedQuery(searchQuery), 400);
    return () => { if (searchTimer.current) clearTimeout(searchTimer.current); };
  }, [searchQuery]);

  useEffect(() => {
    setScope(isStaff ? 'territory' : 'mine');
    setQueue(isStaff ? 'open' : '');
    setSortBy(isStaff ? 'priority' : 'created_at');
  }, [isStaff]);

  useEffect(() => {
    if (scope === 'territory' && isStaff) {
      setQueue((current) => current || 'open');
      setSortBy((current) => current === 'created_at' ? 'priority' : current);
    } else if (scope === 'mine') {
      setQueue('');
      setPriorityFilter('');
    }
  }, [scope, isStaff]);

  const loadIncidents = useCallback(async (p = 1, reset = false) => {
    if (p === 1) setLoading(true);
    else         setLoadingMore(true);
    try {
      const params: Record<string, any> = {
        page: p,
        limit: 15,
        sort: sortBy,
        dir: sortBy === 'priority' ? 'ASC' : 'DESC',
        scope,
      };
      if (filter)        params.status = filter;
      if (debouncedQuery) params.q    = debouncedQuery;
      if (queue)         params.queue = queue;
      if (priorityFilter) params.priority = priorityFilter;

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
  }, [filter, debouncedQuery, sortBy, scope, queue, priorityFilter]);

  useEffect(() => { loadIncidents(1, true); }, [filter, debouncedQuery, sortBy, scope, queue, priorityFilter]);

  const onRefresh = () => {
    setRefreshing(true);
    loadIncidents(1, true);
  };

  const onEndReached = () => {
    if (!loadingMore && page < totalPages) {
      loadIncidents(page + 1);
    }
  };

  const heroEyebrow = isStaff ? 'Pilotage terrain' : 'Suivi citoyen';
  const heroTitle = isStaff
    ? (scope === 'territory' ? 'File territoriale à traiter' : 'Mes interventions suivies')
    : 'Mes preuves de terrain';
  const heroText = isStaff
    ? (scope === 'territory'
      ? 'Retrouvez les signalements ouverts sur le territoire pour qualifier, traiter et valider l’exécution depuis le terrain.'
      : 'Visualisez les dossiers qui vous sont assignés depuis votre compte métier, avec les mêmes filtres de terrain.')
    : 'Retrouvez vos signalements, filtrez les situations en cours et gardez une vue claire sur ce qui avance dans votre quartier.';
  const listLabel = isStaff
    ? (scope === 'territory' ? 'dossiers à traiter' : 'interventions suivies')
    : 'éléments affichés';
  const stateLabel = isStaff ? (scope === 'territory' ? 'Territoire' : 'Mes interventions') : 'Tous';
  const userRoleLabel = isStaff ? 'Pilotage opérationnel' : 'Veille locale en cours';
  const queueLabel = QUEUE_OPTIONS.find((item) => item.key === queue)?.label ?? 'Tous';
  const priorityLabel = PRIORITY_FILTERS.find((item) => item.key === priorityFilter)?.label ?? 'Toutes';
  const companionMessage = isStaff
    ? {
      tone: 'status' as const,
      title: `${BRAND.companion.name} vous aide a lire la file`,
      body: scope === 'territory'
        ? 'Gardez cette vue concentree sur ce qui demande une action terrain immediate. Le bon usage est de filtrer, ouvrir, qualifier puis valider.'
        : 'Cette vue sert a suivre vos propres interventions sans perdre le fil des priorites ni des assignations.',
      bullets: scope === 'territory'
        ? ['ouvrir d abord les urgences visibles', 'resserrer ensuite par file et priorite']
        : ['revenir sur vos dossiers assignes', 'relire les derniers changements utiles'],
    }
    : {
      tone: 'guide' as const,
      title: `${BRAND.companion.name} garde votre suivi lisible`,
      body: 'Cette vue ne sert pas seulement a stocker des dossiers. Elle doit vous aider a voir ce qui attend, ce qui avance et ce qui est deja resolu.',
      bullets: ['utiliser les filtres pour reduire le bruit', 'ouvrir d abord les dossiers encore actifs'],
    };
  const companionVisualSource = getIncidentListCompanionVisual(incidents, isStaff, debouncedQuery);

  const renderHeader = () => (
    <View>
      <View style={styles.heroCard}>
        <Text style={styles.heroEyebrow}>{heroEyebrow}</Text>
        <Text style={styles.heroTitle}>{heroTitle}</Text>
        <Text style={styles.heroText}>{heroText}</Text>
        <View style={styles.heroMetrics}>
          <View style={styles.heroMetric}>
            <Text style={styles.heroMetricValue}>{incidents.length}</Text>
            <Text style={styles.heroMetricLabel}>{listLabel}</Text>
          </View>
          <View style={styles.heroMetric}>
            <Text style={styles.heroMetricValue}>
              {filter ? (STATUS_LABELS[filter] ?? filter) : stateLabel}
            </Text>
            <Text style={styles.heroMetricLabel}>état suivi</Text>
          </View>
        </View>
      </View>

      <View style={styles.companionWrap}>
        <CivicCompanionCard
          compact
          tone={companionMessage.tone}
          title={companionMessage.title}
          body={companionMessage.body}
          bullets={companionMessage.bullets}
          visualSource={companionVisualSource}
          visualBadgeLabel={isStaff ? 'Terrain' : 'Suivi'}
        />
      </View>

      <View style={styles.userHeader}>
        <View>
          <Text style={styles.greeting}>Bonjour, {user?.full_name?.split(' ')[0] ?? 'Citoyen'}</Text>
          <Text style={styles.userRole}>{userRoleLabel}</Text>
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
        <Text style={styles.newBtnText}>
          {isStaff ? 'Créer un signalement terrain' : 'Signaler un besoin du territoire'}
        </Text>
      </TouchableOpacity>

      {isStaff && (
        <View style={styles.scopeRow}>
          <TouchableOpacity
            style={[styles.scopeChip, scope === 'territory' && styles.scopeChipActive]}
            onPress={() => setScope('territory')}
          >
            <Text style={[styles.scopeChipText, scope === 'territory' && styles.scopeChipTextActive]}>
              File territoriale
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.scopeChip, scope === 'mine' && styles.scopeChipActive]}
            onPress={() => setScope('mine')}
          >
            <Text style={[styles.scopeChipText, scope === 'mine' && styles.scopeChipTextActive]}>
              Mes interventions
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {isStaff && scope === 'territory' && (
        <View style={styles.queueRow}>
          {QUEUE_OPTIONS.map((item) => (
            <TouchableOpacity
              key={item.key || 'all'}
              style={[styles.queueChip, queue === item.key && styles.queueChipActive]}
              onPress={() => setQueue(item.key)}
            >
              <Text style={[styles.queueChipText, queue === item.key && styles.queueChipTextActive]}>
                {item.label}
              </Text>
            </TouchableOpacity>
          ))}
        </View>
      )}

      <View style={styles.searchRow}>
        <View style={styles.searchBox}>
          <Text style={styles.searchIcon}>🔍</Text>
          <TextInput
            style={styles.searchInput}
            placeholder="Rechercher une référence, un lieu ou une situation..."
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
          <Text style={[styles.filterToggleText, showFilters && styles.filterToggleTextActive]}>
            Filtres
          </Text>
        </TouchableOpacity>
      </View>

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

          {isStaff && scope === 'territory' && (
            <>
              <Text style={styles.filterLabel}>Priorité terrain</Text>
              <View style={styles.filtersRow}>
                {PRIORITY_FILTERS.map((item) => (
                  <TouchableOpacity
                    key={item.key || 'all-priority'}
                    style={[
                      styles.filterChip,
                      priorityFilter === item.key && styles.filterChipActive,
                    ]}
                    onPress={() => setPriorityFilter(item.key)}
                  >
                    <Text
                      style={[
                        styles.filterText,
                        priorityFilter === item.key && styles.filterTextActive,
                      ]}
                    >
                      {item.label}
                    </Text>
                  </TouchableOpacity>
                ))}
              </View>
            </>
          )}
        </View>
      )}

      <Text style={styles.sectionTitle}>
        {isStaff && scope === 'territory' ? 'File d’intervention' : 'Journal de suivi'}
        {debouncedQuery ? ` · "${debouncedQuery}"` : ''}
        {isStaff && scope === 'territory' && queue ? ` · ${queueLabel}` : ''}
        {isStaff && scope === 'territory' && priorityFilter ? ` · priorité ${priorityLabel.toLowerCase()}` : ''}
        {filter ? ` · ${STATUS_LABELS[filter] ?? filter}` : ''}
      </Text>
    </View>
  );

  const renderEmpty = () => (
    <View style={styles.emptyBox}>
      <Text style={styles.emptyIcon}>{debouncedQuery ? '🔍' : '📋'}</Text>
      <Text style={styles.emptyTitle}>
        {debouncedQuery ? 'Aucun résultat utile' : isStaff && scope === 'territory' ? 'Aucun dossier à traiter' : 'Aucun signalement enregistré'}
      </Text>
      <Text style={styles.emptyText}>
        {debouncedQuery
          ? `Aucun signalement ne correspond à "${debouncedQuery}".`
          : filter
            ? `Aucun signalement avec le statut "${STATUS_LABELS[filter] ?? filter}".`
            : priorityFilter
              ? `Aucun signalement avec la priorité "${PRIORITY_LABELS[priorityFilter] ?? priorityFilter}".`
            : isStaff && scope === 'territory'
              ? "Aucun signalement n'est actuellement visible dans la file territoriale.\nRevenez plus tard ou créez un dossier terrain."
              : "Votre historique est encore vide.\nCommencez par documenter un premier besoin sur le territoire."
        }
      </Text>
      {debouncedQuery ? (
        <TouchableOpacity style={styles.emptyActionBtn} onPress={() => setSearchQuery('')}>
          <Text style={styles.emptyActionBtnText}>Effacer la recherche</Text>
        </TouchableOpacity>
      ) : (
        <TouchableOpacity style={styles.emptyActionBtn} onPress={() => navigation.navigate('CreateIncident')}>
          <Text style={styles.emptyActionBtnText}>
            {isStaff ? 'Creer un dossier terrain' : 'Creer mon premier signalement'}
          </Text>
        </TouchableOpacity>
      )}
    </View>
  );

  const renderFooter = () =>
    loadingMore ? <ActivityIndicator color={COLORS.primary} style={{ marginVertical: 16 }} /> : null;

  if (loading) {
    return (
      <ScreenLoadingState
        title={isStaff ? 'La file terrain se met en place' : 'Vos dossiers se remettent en place'}
        body={isStaff
          ? 'Le relais communal rassemble les dossiers utiles pour vous laisser commencer par les priorites du terrain.'
          : 'Le relais communal regroupe vos signalements pour vous rendre la suite plus lisible dossier par dossier.'}
      />
    );
  }

  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={styles.listContent}
      data={incidents}
      keyExtractor={item => String(item.id)}
      renderItem={({ item }) => {
        const planState = buildIncidentPlanState(item);
        return (
        <IncidentCard
          reference={item.reference}
          title={item.title}
          description={item.description}
          status={item.status}
          categoryName={item.category_name}
          categoryIcon={item.category_icon}
          categoryColor={item.category_color}
          date={item.created_at}
          priority={isStaff ? item.priority : undefined}
          assignedToName={isStaff ? item.assigned_to_name : undefined}
          serviceName={item.service_name ?? undefined}
          planStateLabel={planState?.label}
          planStateVariant={planState?.variant}
          planSummary={buildIncidentPlanSummary(item) ?? undefined}
          planCitizenMessage={item.current_plan?.citizen_message ?? undefined}
          address={item.address}
          onPress={() => navigation.navigate('IncidentDetail', { id: item.id })}
        />
        );
      }}
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
  list:        { flex: 1, backgroundColor: BRAND.colors.mist },
  listContent: { padding: 16, paddingTop: 28, paddingBottom: 120 },
  centered:    { flex: 1, justifyContent: 'center', alignItems: 'center' },

  heroCard: {
    backgroundColor: BRAND.colors.canopyDeep,
    borderRadius: 24,
    padding: 20,
    marginBottom: 16,
    ...BRAND_SHADOW,
  },
  heroEyebrow: {
    color: BRAND.colors.awara,
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    marginBottom: 10,
  },
  heroTitle: {
    color: BRAND.colors.white,
    fontSize: 28,
    fontWeight: '800',
    fontFamily: BRAND.displayFont,
    marginBottom: 10,
  },
  heroText: {
    color: '#D7E7DF',
    fontSize: 14,
    lineHeight: 21,
  },
  heroMetrics: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 16,
  },
  heroMetric: {
    flex: 1,
    backgroundColor: 'rgba(255,255,255,0.12)',
    borderRadius: 16,
    padding: 12,
  },
  heroMetricValue: {
    color: BRAND.colors.white,
    fontSize: 18,
    fontWeight: '800',
  },
  heroMetricLabel: {
    color: '#D7E7DF',
    fontSize: 11,
    marginTop: 4,
  },
  companionWrap: {
    marginBottom: 16,
  },
  userHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 16,
  },
  greeting:   { fontSize: 20, fontWeight: '800', color: COLORS.dark, fontFamily: BRAND.displayFont },
  userRole:   { fontSize: 12, color: BRAND.colors.canopy, fontWeight: '700', marginTop: 2, textTransform: 'uppercase', letterSpacing: 0.9 },
  userEmail:  { fontSize: 13, color: COLORS.gray, marginTop: 4 },
  headerActions: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  profileBtn: {
    width: 40, height: 40, borderRadius: 20,
    backgroundColor: BRAND.colors.sand, justifyContent: 'center', alignItems: 'center',
  },
  profileBtnText: { fontSize: 18 },
  logoutIcon: { fontSize: 20 },

  newBtn: {
    backgroundColor: BRAND.colors.awara,
    borderRadius: 18,
    paddingVertical: 16,
    alignItems: 'center',
    marginBottom: 16,
    ...BRAND_SHADOW,
  },
  newBtnText: { color: BRAND.colors.canopyDeep, fontSize: 16, fontWeight: '800' },
  scopeRow: {
    flexDirection: 'row',
    gap: 10,
    marginBottom: 16,
  },
  scopeChip: {
    flex: 1,
    minHeight: 48,
    borderRadius: 16,
    backgroundColor: '#FFFDF8',
    borderWidth: 1.5,
    borderColor: '#E7DECF',
    alignItems: 'center',
    justifyContent: 'center',
  },
  scopeChipActive: {
    backgroundColor: BRAND.colors.canopy,
    borderColor: BRAND.colors.canopy,
  },
  scopeChipText: {
    fontSize: 13,
    fontWeight: '800',
    color: BRAND.colors.canopy,
  },
  scopeChipTextActive: {
    color: BRAND.colors.white,
  },
  queueRow: {
    flexDirection: 'row',
    gap: 8,
    marginBottom: 14,
  },
  queueChip: {
    flex: 1,
    minHeight: 42,
    borderRadius: 14,
    backgroundColor: '#F3EEE2',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 10,
  },
  queueChipActive: {
    backgroundColor: BRAND.colors.awara,
  },
  queueChipText: {
    fontSize: 12,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
  },
  queueChipTextActive: {
    color: BRAND.colors.canopyDeep,
  },

  searchRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 12 },
  searchBox: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#FFFDF8',
    borderRadius: 16,
    borderWidth: 1.5,
    borderColor: '#E7DECF',
    paddingHorizontal: 12,
    height: 52,
  },
  searchIcon:  { fontSize: 16, marginRight: 8 },
  searchInput: { flex: 1, fontSize: 14, color: COLORS.dark, height: 52 },
  filterToggle: {
    minWidth: 88, height: 52, borderRadius: 16,
    paddingHorizontal: 14,
    backgroundColor: '#FFFDF8',
    borderWidth: 1.5, borderColor: '#E7DECF',
    justifyContent: 'center', alignItems: 'center',
  },
  filterToggleActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  filterToggleText: { fontSize: 13, fontWeight: '800', color: BRAND.colors.canopy },
  filterToggleTextActive: { color: COLORS.white },

  advancedFilters: {
    backgroundColor: '#FFFDF8',
    borderRadius: 18,
    padding: 14,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  filterLabel: { fontSize: 12, fontWeight: '800', color: COLORS.gray, marginBottom: 8, textTransform: 'uppercase', letterSpacing: 0.5 },
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
    backgroundColor: BRAND.surfaces.mutedCard,
    borderWidth: 1.5,
    borderColor: '#E4D9C6',
  },
  filterChipActive: { backgroundColor: COLORS.primary, borderColor: COLORS.primary },
  filterText:       { fontSize: 13, color: COLORS.gray, fontWeight: '500' },
  filterTextActive: { color: COLORS.white },

  sectionTitle: { fontSize: 17, fontWeight: '800', color: COLORS.dark, marginBottom: 12 },

  emptyBox:  { alignItems: 'center', paddingVertical: 48, paddingHorizontal: 24 },
  emptyIcon: { fontSize: 48, marginBottom: 12 },
  emptyTitle:{ fontSize: 18, fontWeight: '800', color: COLORS.dark, marginBottom: 8, textAlign: 'center' },
  emptyText: { fontSize: 14, color: COLORS.gray, textAlign: 'center', lineHeight: 22 },
  emptyActionBtn: {
    marginTop: 18,
    minHeight: 46,
    paddingHorizontal: 18,
    borderRadius: 16,
    backgroundColor: BRAND.colors.awara,
    alignItems: 'center',
    justifyContent: 'center',
    ...BRAND_SHADOW,
  },
  emptyActionBtnText: {
    fontSize: 13,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
  },
});
