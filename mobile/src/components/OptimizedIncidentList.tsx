/**
 * CCDS v1.3 — OptimizedIncidentList (PERF-01)
 * FlatList optimisé avec :
 * - getItemLayout pour éviter les mesures dynamiques
 * - keyExtractor stable
 * - windowSize réduit pour économiser la mémoire
 * - initialNumToRender limité
 * - removeClippedSubviews activé
 * - Image lazy loading avec cache
 */
import React, { useCallback, memo } from 'react';
import {
  FlatList,
  View,
  Text,
  TouchableOpacity,
  Image,
  StyleSheet,
  ActivityIndicator,
  ListRenderItemInfo,
} from 'react-native';

const ITEM_HEIGHT = 100; // Hauteur fixe de chaque carte d'incident

export interface IncidentListItem {
  id: number;
  reference: string;
  title: string;
  status: string;
  category_name: string;
  category_icon?: string;
  votes_count: number;
  created_at: string;
  photo_url?: string;
  address?: string;
}

interface Props {
  incidents: IncidentListItem[];
  loading: boolean;
  onEndReached?: () => void;
  onRefresh?: () => void;
  refreshing?: boolean;
  onPress?: (incident: IncidentListItem) => void;
  ListHeaderComponent?: React.ReactElement;
  ListEmptyComponent?: React.ReactElement;
}

// Carte d'incident mémoïsée pour éviter les re-rendus inutiles
const IncidentCard = memo(({ item, onPress }: {
  item: IncidentListItem;
  onPress?: (item: IncidentListItem) => void;
}) => {
  const statusColors: Record<string, string> = {
    submitted:   '#F59E0B',
    in_progress: '#3B82F6',
    resolved:    '#10B981',
    rejected:    '#EF4444',
  };
  const statusLabels: Record<string, string> = {
    submitted:   'Soumis',
    in_progress: 'En cours',
    resolved:    'Résolu',
    rejected:    'Rejeté',
  };

  return (
    <TouchableOpacity
      style={styles.card}
      onPress={() => onPress?.(item)}
      activeOpacity={0.7}
      accessibilityLabel={`Signalement ${item.reference} : ${item.title}`}
      accessibilityRole="button"
    >
      {/* Miniature photo (lazy) */}
      {item.photo_url ? (
        <Image
          source={{ uri: item.photo_url }}
          style={styles.thumbnail}
          resizeMode="cover"
          accessibilityLabel="Photo du signalement"
          // Cache natif activé par défaut sur React Native
        />
      ) : (
        <View style={[styles.thumbnail, styles.thumbnailPlaceholder]}>
          <Text style={styles.categoryIcon}>{item.category_icon ?? '📍'}</Text>
        </View>
      )}

      {/* Contenu */}
      <View style={styles.content}>
        <View style={styles.row}>
          <Text style={styles.reference}>{item.reference}</Text>
          <View style={[styles.badge, { backgroundColor: statusColors[item.status] ?? '#6B7280' }]}>
            <Text style={styles.badgeText}>{statusLabels[item.status] ?? item.status}</Text>
          </View>
        </View>
        <Text style={styles.title} numberOfLines={2}>{item.title}</Text>
        <View style={styles.row}>
          <Text style={styles.meta}>{item.category_name}</Text>
          <Text style={styles.votes}>👍 {item.votes_count}</Text>
        </View>
      </View>
    </TouchableOpacity>
  );
});

IncidentCard.displayName = 'IncidentCard';

// Séparateur mémoïsé
const Separator = memo(() => <View style={styles.separator} />);
Separator.displayName = 'Separator';

// Footer de chargement
const LoadingFooter = memo(({ loading }: { loading: boolean }) =>
  loading ? (
    <View style={styles.footer}>
      <ActivityIndicator size="small" color="#2563EB" />
      <Text style={styles.footerText}>Chargement…</Text>
    </View>
  ) : null
);
LoadingFooter.displayName = 'LoadingFooter';

export const OptimizedIncidentList: React.FC<Props> = ({
  incidents,
  loading,
  onEndReached,
  onRefresh,
  refreshing = false,
  onPress,
  ListHeaderComponent,
  ListEmptyComponent,
}) => {
  // Hauteur fixe pour éviter les mesures dynamiques (boost de performance majeur)
  const getItemLayout = useCallback(
    (_: unknown, index: number) => ({
      length: ITEM_HEIGHT + 1, // +1 pour le séparateur
      offset: (ITEM_HEIGHT + 1) * index,
      index,
    }),
    []
  );

  const keyExtractor = useCallback(
    (item: IncidentListItem) => `incident-${item.id}`,
    []
  );

  const renderItem = useCallback(
    ({ item }: ListRenderItemInfo<IncidentListItem>) => (
      <IncidentCard item={item} onPress={onPress} />
    ),
    [onPress]
  );

  const renderSeparator = useCallback(() => <Separator />, []);
  const renderFooter    = useCallback(() => <LoadingFooter loading={loading && incidents.length > 0} />, [loading, incidents.length]);

  return (
    <FlatList
      data={incidents}
      keyExtractor={keyExtractor}
      renderItem={renderItem}
      getItemLayout={getItemLayout}
      ItemSeparatorComponent={renderSeparator}
      ListFooterComponent={renderFooter}
      ListHeaderComponent={ListHeaderComponent}
      ListEmptyComponent={ListEmptyComponent}
      onEndReached={onEndReached}
      onEndReachedThreshold={0.3}
      onRefresh={onRefresh}
      refreshing={refreshing}
      // Optimisations mémoire
      removeClippedSubviews={true}
      windowSize={5}
      maxToRenderPerBatch={10}
      initialNumToRender={8}
      updateCellsBatchingPeriod={50}
      // Accessibilité
      accessibilityLabel="Liste des signalements"
    />
  );
};

const styles = StyleSheet.create({
  card: {
    flexDirection: 'row',
    height: ITEM_HEIGHT,
    backgroundColor: '#FFFFFF',
    paddingHorizontal: 16,
    paddingVertical: 10,
    alignItems: 'center',
  },
  thumbnail: {
    width: 72,
    height: 72,
    borderRadius: 8,
    marginRight: 12,
  },
  thumbnailPlaceholder: {
    backgroundColor: '#F3F4F6',
    justifyContent: 'center',
    alignItems: 'center',
  },
  categoryIcon: {
    fontSize: 28,
  },
  content: {
    flex: 1,
    justifyContent: 'space-between',
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  reference: {
    fontSize: 11,
    color: '#6B7280',
    fontFamily: 'monospace',
  },
  badge: {
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 12,
  },
  badgeText: {
    fontSize: 10,
    color: '#FFFFFF',
    fontWeight: '600',
  },
  title: {
    fontSize: 14,
    fontWeight: '600',
    color: '#111827',
    marginVertical: 2,
  },
  meta: {
    fontSize: 12,
    color: '#9CA3AF',
  },
  votes: {
    fontSize: 12,
    color: '#6B7280',
  },
  separator: {
    height: 1,
    backgroundColor: '#F3F4F6',
    marginLeft: 100,
  },
  footer: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    paddingVertical: 16,
    gap: 8,
  },
  footerText: {
    fontSize: 13,
    color: '#6B7280',
  },
});
