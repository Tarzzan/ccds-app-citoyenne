/**
 * CCDS — Écran Carte Interactive
 * Affiche tous les signalements sous forme de marqueurs colorés sur une carte.
 * Bouton flottant pour créer un nouveau signalement.
 */

import React, { useEffect, useState, useRef, useCallback } from 'react';
import {
  View, Text, StyleSheet, TouchableOpacity,
  ActivityIndicator, Alert, Platform,
} from 'react-native';
import MapView, { Marker, Callout, Region } from 'react-native-maps';
import * as Location from 'expo-location';
import { useNavigation } from '@react-navigation/native';
import { NativeStackNavigationProp } from '@react-navigation/native-stack';

import { incidentsApi, Incident } from '../services/api';
import { COLORS, STATUS_COLORS, STATUS_LABELS } from '../components/ui';
import { AppStackParamList } from '../navigation/RootNavigator';

type NavProp = NativeStackNavigationProp<AppStackParamList>;

// Région par défaut : France métropolitaine
const DEFAULT_REGION: Region = {
  latitude:      46.603354,
  longitude:     1.888334,
  latitudeDelta:  8,
  longitudeDelta: 8,
};

export default function MapScreen() {
  const navigation = useNavigation<NavProp>();
  const mapRef     = useRef<MapView>(null);

  const [incidents,       setIncidents]       = useState<Incident[]>([]);
  const [loading,         setLoading]         = useState(true);
  const [locationGranted, setLocationGranted] = useState(false);
  const [userLocation,    setUserLocation]    = useState<{ latitude: number; longitude: number } | null>(null);

  // Charger les signalements
  const loadIncidents = useCallback(async () => {
    try {
      setLoading(true);
      const res = await incidentsApi.list({ limit: 200 } as any);
      if (res.data) setIncidents(res.data.incidents);
    } catch {
      // Silencieux : la carte reste vide
    } finally {
      setLoading(false);
    }
  }, []);

  // Demander la permission de localisation
  const requestLocation = useCallback(async () => {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status === 'granted') {
      setLocationGranted(true);
      const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      const coords = { latitude: loc.coords.latitude, longitude: loc.coords.longitude };
      setUserLocation(coords);
      mapRef.current?.animateToRegion({ ...coords, latitudeDelta: 0.05, longitudeDelta: 0.05 }, 800);
    }
  }, []);

  useEffect(() => {
    loadIncidents();
    requestLocation();
  }, []);

  // Couleur du marqueur selon le statut
  const markerColor = (incident: Incident) =>
    STATUS_COLORS[incident.status] ?? COLORS.gray;

  return (
    <View style={styles.container}>

      {/* Carte */}
      <MapView
        ref={mapRef}
        style={StyleSheet.absoluteFillObject}
        initialRegion={DEFAULT_REGION}
        showsUserLocation={locationGranted}
        showsMyLocationButton={false}
        showsCompass
        loadingEnabled
      >
        {incidents.map(incident => (
          <Marker
            key={incident.id}
            coordinate={{ latitude: incident.latitude, longitude: incident.longitude }}
            pinColor={markerColor(incident)}
          >
            <Callout
              tooltip
              onPress={() => navigation.navigate('IncidentDetail', { id: incident.id })}
            >
              <View style={styles.callout}>
                <View style={[styles.calloutDot, { backgroundColor: incident.category_color }]} />
                <Text style={styles.calloutCategory}>{incident.category_name}</Text>
                <Text style={styles.calloutRef}>{incident.reference}</Text>
                {incident.title ? <Text style={styles.calloutTitle}>{incident.title}</Text> : null}
                <Text style={styles.calloutDesc} numberOfLines={2}>{incident.description}</Text>
                <View style={[styles.calloutBadge, { backgroundColor: markerColor(incident) + '22' }]}>
                  <Text style={[styles.calloutBadgeText, { color: markerColor(incident) }]}>
                    {STATUS_LABELS[incident.status] ?? incident.status}
                  </Text>
                </View>
                <Text style={styles.calloutTap}>Appuyer pour voir le détail →</Text>
              </View>
            </Callout>
          </Marker>
        ))}
      </MapView>

      {/* Indicateur de chargement */}
      {loading && (
        <View style={styles.loadingOverlay}>
          <ActivityIndicator color={COLORS.primary} size="small" />
          <Text style={styles.loadingText}>Chargement des signalements…</Text>
        </View>
      )}

      {/* Compteur de signalements */}
      {!loading && (
        <View style={styles.counter}>
          <Text style={styles.counterText}>
            {incidents.length} signalement{incidents.length > 1 ? 's' : ''}
          </Text>
        </View>
      )}

      {/* Bouton Ma position */}
      <TouchableOpacity
        style={[styles.fab, styles.fabLocation]}
        onPress={locationGranted
          ? () => userLocation && mapRef.current?.animateToRegion({ ...userLocation, latitudeDelta: 0.02, longitudeDelta: 0.02 }, 600)
          : requestLocation
        }
      >
        <Text style={styles.fabIcon}>📍</Text>
      </TouchableOpacity>

      {/* Bouton Actualiser */}
      <TouchableOpacity style={[styles.fab, styles.fabRefresh]} onPress={loadIncidents}>
        <Text style={styles.fabIcon}>🔄</Text>
      </TouchableOpacity>

      {/* Bouton Nouveau signalement */}
      <TouchableOpacity
        style={[styles.fab, styles.fabCreate]}
        onPress={() => navigation.navigate('CreateIncident')}
        activeOpacity={0.85}
      >
        <Text style={styles.fabCreateText}>+ Signaler</Text>
      </TouchableOpacity>

    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },

  loadingOverlay: {
    position: 'absolute',
    top: 60,
    alignSelf: 'center',
    backgroundColor: 'rgba(255,255,255,0.95)',
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 20,
    gap: 8,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.1,
    shadowRadius: 8,
    elevation: 4,
  },
  loadingText: { fontSize: 13, color: COLORS.dark },

  counter: {
    position: 'absolute',
    top: 60,
    alignSelf: 'center',
    backgroundColor: 'rgba(255,255,255,0.95)',
    paddingHorizontal: 14,
    paddingVertical: 6,
    borderRadius: 20,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.08,
    shadowRadius: 6,
    elevation: 3,
  },
  counterText: { fontSize: 13, color: COLORS.dark, fontWeight: '600' },

  fab: {
    position: 'absolute',
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: COLORS.white,
    justifyContent: 'center',
    alignItems: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 3 },
    shadowOpacity: 0.15,
    shadowRadius: 8,
    elevation: 5,
  },
  fabLocation: { bottom: 120, right: 16 },
  fabRefresh:  { bottom: 180, right: 16 },
  fabIcon:     { fontSize: 22 },

  fabCreate: {
    position: 'absolute',
    bottom: 40,
    right: 16,
    width: 'auto',
    paddingHorizontal: 20,
    paddingVertical: 14,
    borderRadius: 28,
    backgroundColor: COLORS.primary,
    height: 'auto',
  },
  fabCreateText: {
    color: COLORS.white,
    fontWeight: '700',
    fontSize: 15,
  },

  // Callout personnalisé
  callout: {
    backgroundColor: COLORS.white,
    borderRadius: 12,
    padding: 14,
    width: 240,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.15,
    shadowRadius: 10,
    elevation: 6,
  },
  calloutDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginBottom: 4,
  },
  calloutCategory: { fontSize: 11, color: COLORS.gray, fontWeight: '500', marginBottom: 2 },
  calloutRef:      { fontSize: 11, color: COLORS.gray, fontFamily: 'monospace', marginBottom: 4 },
  calloutTitle:    { fontSize: 14, fontWeight: '700', color: COLORS.dark, marginBottom: 4 },
  calloutDesc:     { fontSize: 13, color: '#374151', lineHeight: 18, marginBottom: 8 },
  calloutBadge: {
    alignSelf: 'flex-start',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 10,
    marginBottom: 8,
  },
  calloutBadgeText: { fontSize: 11, fontWeight: '600' },
  calloutTap:       { fontSize: 11, color: COLORS.primary, fontWeight: '500' },
});
