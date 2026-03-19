/**
 * Ma Commune — Écran carte territoriale
 * Centré sur Kourou au démarrage pour rester cohérent avec le territoire cible.
 */
import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  View,
  Text,
  StyleSheet,
  TouchableOpacity,
  ActivityIndicator,
  ScrollView,
  RefreshControl,
  Platform,
} from 'react-native';
import MapView, { Marker, Region } from 'react-native-maps';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';
import { incidentsApi, Incident } from '../services/api';
import { CategoryMark } from '../components/CategoryMark';
import { CivicCompanionStage } from '../components/CivicCompanionStage';
import { ScreenLoadingState } from '../components/ScreenStatePanel';
import { AppStackParamList } from '../navigation/RootNavigator';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';
import { GENERATED_VISUAL_SOURCES } from '../theme/generatedVisualSources';

type NavProp = NativeStackNavigationProp<AppStackParamList>;

const STATUS_COLORS: Record<string, string> = {
  submitted: '#f59e0b',
  acknowledged: '#3b82f6',
  in_progress: '#2563eb',
  resolved: '#10b981',
  rejected: '#ef4444',
};

const STATUS_LABELS: Record<string, string> = {
  submitted: 'Soumis',
  acknowledged: 'Pris en charge',
  in_progress: 'En cours',
  resolved: 'Résolu',
  rejected: 'Rejeté',
};

const KOUROU_REGION: Region = {
  latitude: 5.1597,
  longitude: -52.6498,
  latitudeDelta: 0.12,
  longitudeDelta: 0.12,
};

export default function MapScreen() {
  const navigation = useNavigation<NavProp>();
  const mapRef = useRef<MapView | null>(null);
  // Android nécessite une clé Google Maps native absente du projet pour l'instant.
  // On garde donc une vue de repli stable afin d'éviter le crash au démarrage.
  const canRenderNativeMap = Platform.OS !== 'android';

  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [selectedIncidentId, setSelectedIncidentId] = useState<number | null>(null);

  useEffect(() => {
    loadIncidents();
  }, []);

  const loadIncidents = async () => {
    try {
      const res = await incidentsApi.list({ page: 1, limit: 100 });
      const payload = res.data as any;
      const nextIncidents: Incident[] = payload?.incidents ?? payload?.items ?? [];
      setIncidents(nextIncidents);

      if (nextIncidents.length > 0) {
        setSelectedIncidentId((current) => current ?? nextIncidents[0].id);
      }
    } catch (error) {
      console.log('[Carte] Erreur:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const geolocatedIncidents = useMemo(
    () =>
      incidents.filter(
        (incident) =>
          Number.isFinite(Number(incident.latitude)) &&
          Number.isFinite(Number(incident.longitude))
      ),
    [incidents]
  );
  const openIncidents = useMemo(
    () => geolocatedIncidents.filter((incident) => !['resolved', 'rejected'].includes(incident.status)),
    [geolocatedIncidents]
  );
  const resolvedIncidents = useMemo(
    () => geolocatedIncidents.filter((incident) => incident.status === 'resolved'),
    [geolocatedIncidents]
  );

  const selectedIncident =
    geolocatedIncidents.find((incident) => incident.id === selectedIncidentId) ??
    geolocatedIncidents[0] ??
    null;

  const centerOnKourou = () => {
    if (!canRenderNativeMap) {
      return;
    }
    mapRef.current?.animateToRegion(KOUROU_REGION, 500);
  };

  const focusIncident = (incident: Incident) => {
    setSelectedIncidentId(incident.id);
    if (!canRenderNativeMap) {
      navigation.navigate('IncidentDetail', { id: incident.id });
      return;
    }
    mapRef.current?.animateToRegion(
      {
        latitude: Number(incident.latitude),
        longitude: Number(incident.longitude),
        latitudeDelta: 0.025,
        longitudeDelta: 0.025,
      },
      500
    );
  };

  if (loading) {
    return (
      <ScreenLoadingState
        title="La carte citoyenne se prepare"
        body="Le relais communal rassemble d abord les reperes utiles du territoire pour que la lecture reste simple des l ouverture."
        visualSource={GENERATED_VISUAL_SOURCES['MOM-05'] ?? COMPANION_VISUAL_SLOTS.map.source}
        visualBadgeLabel="Carte"
      />
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.heroBackdrop} />
      <ScrollView
        contentContainerStyle={styles.scrollContent}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => {
              setRefreshing(true);
              loadIncidents();
            }}
          />
        }
      >
        <View style={styles.header}>
          <Text style={styles.headerEyebrow}>Territoire observé</Text>
          <Text style={styles.headerTitle}>Carte citoyenne de Kourou</Text>
          <Text style={styles.headerSub}>
            La carte s’ouvre sur Kourou puis laisse apparaître les points signalés sur le territoire.
          </Text>
        </View>

        <View style={styles.stageWrap}>
          <CivicCompanionStage
            eyebrow="Relais communal · Lecture territoire"
            title="Voir ou la vigilance doit produire une reponse."
            body="La carte ne sert pas a collectionner des points. Elle sert a rendre visibles les zones a surveiller, les dossiers encore ouverts et les signaux deja traites."
            aside="Commencez par le point mis en avant, puis descendez vers les autres reperes."
            visualSource={COMPANION_VISUAL_SLOTS.map.source}
          />
        </View>

        <View style={styles.missionCard}>
          <Text style={styles.missionTitle}>Voir, situer, agir</Text>
          <Text style={styles.missionText}>
            La lecture géographique aide à repérer les zones où la vigilance citoyenne doit produire une réponse publique.
          </Text>
          <View style={styles.missionStats}>
            <View style={styles.missionStat}>
              <Text style={styles.missionStatValue}>{geolocatedIncidents.length}</Text>
              <Text style={styles.missionStatLabel}>reperes visibles</Text>
            </View>
            <View style={styles.missionStat}>
              <Text style={styles.missionStatValue}>{openIncidents.length}</Text>
              <Text style={styles.missionStatLabel}>encore ouverts</Text>
            </View>
            <View style={styles.missionStat}>
              <Text style={styles.missionStatValue}>{resolvedIncidents.length}</Text>
              <Text style={styles.missionStatLabel}>deja resolus</Text>
            </View>
          </View>
        </View>

        <View style={styles.mapCard}>
          {canRenderNativeMap ? (
            <>
              <MapView
                ref={mapRef}
                style={styles.map}
                initialRegion={KOUROU_REGION}
                showsCompass
                showsScale
                toolbarEnabled={false}
              >
                {geolocatedIncidents.map((incident) => {
                  const color = STATUS_COLORS[incident.status] || '#6b7280';

                  return (
                    <Marker
                      key={incident.id}
                      coordinate={{
                        latitude: Number(incident.latitude),
                        longitude: Number(incident.longitude),
                      }}
                      title={incident.title || 'Signalement citoyen'}
                      description={incident.address || incident.description}
                      pinColor={color}
                      onPress={() => setSelectedIncidentId(incident.id)}
                    />
                  );
                })}
              </MapView>

              <View style={styles.mapOverlay}>
                <View style={styles.mapBadge}>
                  <Text style={styles.mapBadgeText}>
                    {geolocatedIncidents.length} repère(s) géolocalisé(s)
                  </Text>
                </View>
                <TouchableOpacity style={styles.kourouButton} onPress={centerOnKourou}>
                  <Text style={styles.kourouButtonText}>Revenir sur Kourou</Text>
                </TouchableOpacity>
              </View>
            </>
          ) : (
            <View style={styles.mapFallback}>
              <View style={styles.mapFallbackBadge}>
                <Text style={styles.mapFallbackBadgeText}>Kourou, Guyane</Text>
              </View>
              <Text style={styles.mapFallbackTitle}>Vue carte temporairement indisponible</Text>
              <Text style={styles.mapFallbackText}>
                Cette version continue de fonctionner sans planter. Les signalements géolocalisés
                restent consultables ci-dessous pendant la préparation de la clé cartographique.
              </Text>
              <View style={styles.mapFallbackStats}>
                <View style={styles.mapFallbackStat}>
                  <Text style={styles.mapFallbackStatValue}>{geolocatedIncidents.length}</Text>
                  <Text style={styles.mapFallbackStatLabel}>points suivis</Text>
                </View>
                <View style={styles.mapFallbackDivider} />
                <View style={styles.mapFallbackStat}>
                  <Text style={styles.mapFallbackStatValue}>5.1597</Text>
                  <Text style={styles.mapFallbackStatLabel}>latitude Kourou</Text>
                </View>
              </View>
              <TouchableOpacity
                style={styles.mapFallbackButton}
                onPress={() => {
                  if (selectedIncident) {
                    navigation.navigate('IncidentDetail', { id: selectedIncident.id });
                  } else {
                    navigation.navigate('CreateIncident');
                  }
                }}
              >
                <Text style={styles.mapFallbackButtonText}>
                  {selectedIncident ? 'Ouvrir le dossier mis en avant' : 'Créer un signalement'}
                </Text>
              </TouchableOpacity>
            </View>
          )}
        </View>

        {selectedIncident ? (
          <TouchableOpacity
            style={styles.featuredCard}
            activeOpacity={0.9}
            onPress={() => navigation.navigate('IncidentDetail', { id: selectedIncident.id })}
          >
            <View style={styles.featuredHeader}>
              <View style={styles.featuredTitleWrap}>
                <Text style={styles.featuredEyebrow}>Point suivi</Text>
                <Text style={styles.featuredTitle} numberOfLines={2}>
                  {selectedIncident.title || 'Signalement citoyen'}
                </Text>
              </View>
              <View
                style={[
                  styles.statusBadge,
                  {
                    backgroundColor:
                      (STATUS_COLORS[selectedIncident.status] || '#6b7280') + '22',
                  },
                ]}
              >
                <Text
                  style={[
                    styles.statusBadgeText,
                    { color: STATUS_COLORS[selectedIncident.status] || '#6b7280' },
                  ]}
                >
                  {STATUS_LABELS[selectedIncident.status] || selectedIncident.status}
                </Text>
              </View>
            </View>

            <Text style={styles.featuredAddress}>
              📍 {selectedIncident.address || 'Adresse non renseignée'}
            </Text>
            <Text style={styles.featuredDescription} numberOfLines={3}>
              {selectedIncident.description}
            </Text>

            <View style={styles.featuredFooter}>
              <View style={styles.categoryMeta}>
                <CategoryMark
                  icon={selectedIncident.category_icon}
                  name={selectedIncident.category_name}
                  color={selectedIncident.category_color}
                  size={40}
                />
                <Text style={styles.featuredMeta}>{selectedIncident.category_name}</Text>
              </View>
              <Text style={styles.featuredMeta}>
                👍 {selectedIncident.votes_count ?? 0}
              </Text>
            </View>
          </TouchableOpacity>
        ) : (
          <View style={styles.emptyCard}>
            <Text style={styles.emptyIcon}>🗺️</Text>
            <Text style={styles.emptyTitle}>Aucun point géolocalisé pour l’instant</Text>
            <Text style={styles.emptyText}>
              Les futurs signalements situés sur le terrain apparaîtront ici, avec Kourou comme point de départ.
            </Text>
          </View>
        )}

        <View style={styles.sectionHeader}>
          <Text style={styles.sectionTitle}>Points récents à examiner</Text>
          <Text style={styles.sectionHint}>Touchez une carte pour recentrer puis ouvrir le dossier.</Text>
        </View>

        {geolocatedIncidents.length === 0 ? (
          <View style={styles.emptyList}>
            <Text style={styles.emptyListText}>Aucun signalement géolocalisé disponible.</Text>
          </View>
        ) : (
          geolocatedIncidents.map((incident) => (
            <TouchableOpacity
              key={incident.id}
              style={[
                styles.card,
                selectedIncidentId === incident.id && styles.cardActive,
              ]}
              onPress={() => focusIncident(incident)}
            >
              <View style={styles.cardRow}>
                <View
                  style={[
                    styles.statusDot,
                    { backgroundColor: STATUS_COLORS[incident.status] || '#6b7280' },
                  ]}
                />
                <Text style={styles.cardTitle} numberOfLines={1}>
                  {incident.title || 'Signalement citoyen'}
                </Text>
              </View>
              <Text style={styles.cardMeta} numberOfLines={1}>
                📍 {incident.address || 'Adresse non renseignée'}
              </Text>
              <View style={styles.cardFooter}>
                <View style={styles.categoryMeta}>
                  <CategoryMark
                    icon={incident.category_icon}
                    name={incident.category_name}
                    color={incident.category_color}
                    size={34}
                  />
                  <Text style={styles.votes}>{incident.category_name}</Text>
                </View>
                <View
                  style={[
                    styles.badge,
                    {
                      backgroundColor:
                        (STATUS_COLORS[incident.status] || '#6b7280') + '22',
                    },
                  ]}
                >
                  <Text
                    style={[
                      styles.badgeText,
                      { color: STATUS_COLORS[incident.status] || '#6b7280' },
                    ]}
                  >
                    {STATUS_LABELS[incident.status] || incident.status}
                  </Text>
                </View>
              </View>
            </TouchableOpacity>
          ))
        )}

        <TouchableOpacity
          style={styles.fab}
          onPress={() => navigation.navigate('CreateIncident')}
        >
          <Text style={styles.fabText}>+ Nouveau signalement</Text>
        </TouchableOpacity>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: BRAND.colors.mist },
  scrollContent: { paddingBottom: 120 },
  heroBackdrop: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    height: 240,
    backgroundColor: BRAND.colors.canopyDeep,
  },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  loadingText: { marginTop: 12, color: BRAND.colors.slate, fontSize: 14 },
  header: { paddingHorizontal: 20, paddingTop: 56, paddingBottom: 12 },
  headerEyebrow: {
    color: '#D2A13A',
    fontSize: 12,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1.2,
  },
  headerTitle: {
    color: '#fff',
    fontSize: 28,
    fontWeight: '800',
    marginTop: 10,
    fontFamily: BRAND.displayFont,
  },
  headerSub: {
    color: '#dbe8df',
    fontSize: 14,
    marginTop: 8,
    lineHeight: 20,
    maxWidth: 310,
  },
  stageWrap: {
    marginHorizontal: 16,
    marginBottom: 14,
  },
  missionCard: {
    marginHorizontal: 16,
    marginTop: 6,
    marginBottom: 14,
    backgroundColor: '#FFF9F0',
    borderRadius: 22,
    padding: 18,
    borderWidth: 1,
    borderColor: '#E6DCC8',
    ...BRAND_SHADOW,
  },
  missionTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: BRAND.colors.ink,
    marginBottom: 6,
  },
  missionText: {
    fontSize: 14,
    color: '#355248',
    lineHeight: 21,
  },
  missionStats: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 16,
  },
  missionStat: {
    flex: 1,
    borderRadius: 16,
    backgroundColor: '#FFFDF8',
    borderWidth: 1,
    borderColor: '#E7DDCD',
    padding: 12,
  },
  missionStatValue: {
    color: BRAND.colors.canopyDeep,
    fontSize: 20,
    fontWeight: '800',
  },
  missionStatLabel: {
    marginTop: 4,
    color: BRAND.colors.slate,
    fontSize: 11,
    lineHeight: 15,
  },
  mapCard: {
    marginHorizontal: 16,
    borderRadius: 24,
    overflow: 'hidden',
    borderWidth: 1,
    borderColor: '#D9E5DB',
    backgroundColor: '#F4F1E7',
    ...BRAND_SHADOW,
  },
  map: {
    height: 320,
    width: '100%',
  },
  mapFallback: {
    minHeight: 320,
    padding: 22,
    justifyContent: 'center',
    backgroundColor: '#F4F1E7',
  },
  mapFallbackBadge: {
    alignSelf: 'flex-start',
    backgroundColor: '#E9F3EC',
    borderWidth: 1,
    borderColor: '#C8DBCF',
    paddingHorizontal: 12,
    paddingVertical: 7,
    borderRadius: 999,
    marginBottom: 14,
  },
  mapFallbackBadgeText: {
    color: BRAND.colors.canopyDeep,
    fontSize: 12,
    fontWeight: '800',
  },
  mapFallbackTitle: {
    color: BRAND.colors.ink,
    fontSize: 21,
    fontWeight: '800',
    lineHeight: 28,
  },
  mapFallbackText: {
    marginTop: 10,
    color: '#40554C',
    fontSize: 14,
    lineHeight: 21,
  },
  mapFallbackStats: {
    marginTop: 18,
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#D8D1C1',
    borderRadius: 18,
    backgroundColor: '#FFF9F0',
    paddingVertical: 14,
    paddingHorizontal: 12,
  },
  mapFallbackStat: {
    flex: 1,
    alignItems: 'center',
  },
  mapFallbackDivider: {
    width: 1,
    alignSelf: 'stretch',
    backgroundColor: '#DDD4C3',
  },
  mapFallbackStatValue: {
    color: BRAND.colors.canopyDeep,
    fontSize: 20,
    fontWeight: '800',
  },
  mapFallbackStatLabel: {
    marginTop: 4,
    color: BRAND.colors.slate,
    fontSize: 12,
    textAlign: 'center',
  },
  mapFallbackButton: {
    marginTop: 18,
    backgroundColor: BRAND.colors.awara,
    borderRadius: 16,
    paddingVertical: 14,
    paddingHorizontal: 16,
    alignItems: 'center',
  },
  mapFallbackButtonText: {
    color: BRAND.colors.canopyDeep,
    fontSize: 14,
    fontWeight: '800',
    textAlign: 'center',
  },
  mapOverlay: {
    position: 'absolute',
    top: 14,
    left: 14,
    right: 14,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  mapBadge: {
    backgroundColor: 'rgba(15, 23, 42, 0.78)',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 999,
  },
  mapBadgeText: {
    color: '#fff',
    fontSize: 12,
    fontWeight: '700',
  },
  kourouButton: {
    backgroundColor: 'rgba(255, 249, 240, 0.95)',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: '#E6DCC8',
  },
  kourouButtonText: {
    color: BRAND.colors.canopyDeep,
    fontSize: 12,
    fontWeight: '800',
  },
  featuredCard: {
    marginHorizontal: 16,
    marginTop: 16,
    backgroundColor: '#FFFDF8',
    borderRadius: 24,
    padding: 18,
    borderWidth: 1,
    borderColor: '#E8E1D4',
    ...BRAND_SHADOW,
  },
  featuredHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    gap: 12,
  },
  featuredTitleWrap: { flex: 1 },
  featuredEyebrow: {
    fontSize: 11,
    fontWeight: '800',
    textTransform: 'uppercase',
    letterSpacing: 1,
    color: BRAND.colors.canopy,
    marginBottom: 6,
  },
  featuredTitle: {
    fontSize: 18,
    fontWeight: '800',
    color: BRAND.colors.ink,
    lineHeight: 24,
  },
  statusBadge: {
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 999,
    alignSelf: 'flex-start',
  },
  statusBadgeText: {
    fontSize: 12,
    fontWeight: '700',
  },
  featuredAddress: {
    marginTop: 12,
    color: '#51655C',
    fontSize: 13,
  },
  featuredDescription: {
    marginTop: 10,
    color: '#32433C',
    fontSize: 14,
    lineHeight: 21,
  },
  featuredFooter: {
    marginTop: 14,
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: 12,
  },
  categoryMeta: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    flexShrink: 1,
  },
  featuredMeta: {
    color: BRAND.colors.slate,
    fontSize: 12,
    fontWeight: '700',
  },
  emptyCard: {
    marginHorizontal: 16,
    marginTop: 16,
    padding: 26,
    borderRadius: 24,
    backgroundColor: '#FFFDF8',
    alignItems: 'center',
    borderWidth: 1,
    borderColor: '#E8E1D4',
    ...BRAND_SHADOW,
  },
  emptyIcon: { fontSize: 42, marginBottom: 10 },
  emptyTitle: {
    color: '#24352F',
    fontSize: 16,
    fontWeight: '800',
    marginBottom: 6,
    textAlign: 'center',
  },
  emptyText: {
    color: BRAND.colors.slate,
    fontSize: 13,
    lineHeight: 20,
    textAlign: 'center',
  },
  sectionHeader: {
    marginTop: 22,
    marginBottom: 10,
    paddingHorizontal: 20,
  },
  sectionTitle: {
    fontSize: 17,
    fontWeight: '800',
    color: BRAND.colors.ink,
  },
  sectionHint: {
    marginTop: 4,
    fontSize: 12,
    color: BRAND.colors.slate,
  },
  emptyList: {
    marginHorizontal: 16,
    padding: 20,
    borderRadius: 18,
    backgroundColor: '#FFFDF8',
    borderWidth: 1,
    borderColor: '#E8E1D4',
  },
  emptyListText: {
    color: BRAND.colors.slate,
    textAlign: 'center',
  },
  card: {
    backgroundColor: '#FFFDF8',
    borderRadius: 18,
    padding: 16,
    marginHorizontal: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  cardActive: {
    borderColor: '#B78725',
    backgroundColor: '#FFF8EB',
  },
  cardRow: {
    flexDirection: 'row',
    alignItems: 'center',
    marginBottom: 4,
  },
  statusDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    marginRight: 8,
  },
  cardTitle: {
    flex: 1,
    fontSize: 15,
    fontWeight: '700',
    color: '#111827',
  },
  cardMeta: {
    fontSize: 12,
    color: BRAND.colors.slate,
    marginBottom: 8,
  },
  cardFooter: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 10,
  },
  badge: {
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 10,
  },
  badgeText: {
    fontSize: 12,
    fontWeight: '600',
  },
  votes: {
    fontSize: 12,
    color: BRAND.colors.slate,
    fontWeight: '700',
    flexShrink: 1,
  },
  fab: {
    marginTop: 8,
    marginHorizontal: 16,
    backgroundColor: BRAND.colors.awara,
    paddingHorizontal: 22,
    paddingVertical: 15,
    borderRadius: 30,
    alignItems: 'center',
    ...BRAND_SHADOW,
  },
  fabText: {
    color: BRAND.colors.canopyDeep,
    fontWeight: '800',
    fontSize: 15,
  },
});
