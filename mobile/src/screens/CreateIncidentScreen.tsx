/**
 * CCDS — Écran Création de Signalement
 * v1.1 : mode hors-ligne via OfflineQueue + OfflineBanner
 */

import React, { useState, useEffect } from 'react';
import {
  View, Text, StyleSheet, ScrollView, TouchableOpacity,
  Image, Alert, ActivityIndicator, KeyboardAvoidingView, Platform,
} from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as Location    from 'expo-location';
import { useNavigation } from '@react-navigation/native';

import { categoriesApi, incidentsApi, Category } from '../services/api';
import { Button, Input, COLORS }                 from '../components/ui';
import { OfflineBanner }                          from '../components/OfflineBanner';
import { OfflineQueue }                           from '../services/OfflineQueue';

export default function CreateIncidentScreen() {
  const navigation = useNavigation();

  // Formulaire
  const [title,       setTitle]       = useState('');
  const [description, setDescription] = useState('');
  const [categoryId,  setCategoryId]  = useState<number | null>(null);
  const [photo,       setPhoto]       = useState<{ uri: string; type: string; name: string } | null>(null);
  const [coords,      setCoords]      = useState<{ lat: number; lng: number } | null>(null);
  const [address,     setAddress]     = useState('');

  // UI State
  const [categories,   setCategories]   = useState<Category[]>([]);
  const [loading,      setLoading]      = useState(false);
  const [locLoading,   setLocLoading]   = useState(false);
  const [errors,       setErrors]       = useState<Record<string, string>>({});
  const [isConnected,  setIsConnected]  = useState(true);
  const [pendingCount, setPendingCount] = useState(0);

  // Charger les catégories et surveiller la connectivité
  useEffect(() => {
    (async () => {
      try {
        const res = await categoriesApi.list();
        if (res.data) setCategories(res.data);
      } catch {
        // Pas de connexion — les catégories peuvent être en cache
      }
    })();
    getLocation();

    // Écouter les changements de connectivité via OfflineQueue
    const unsubscribe = OfflineQueue.onConnectivityChange((connected) => {
      setIsConnected(connected);
    });

    // Écouter les changements de la queue
    const unsubQueue = OfflineQueue.onQueueChange((count) => {
      setPendingCount(count);
    });

    // Initialiser le compteur
    OfflineQueue.getPendingCount().then(setPendingCount);

    return () => {
      unsubscribe();
      unsubQueue();
    };
  }, []);

  // ----------------------------------------------------------------
  // Géolocalisation
  // ----------------------------------------------------------------
  const getLocation = async () => {
    setLocLoading(true);
    try {
      const { status } = await Location.requestForegroundPermissionsAsync();
      if (status !== 'granted') {
        Alert.alert('Permission refusée', 'La localisation est nécessaire pour situer le signalement.');
        return;
      }
      const loc = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
      setCoords({ lat: loc.coords.latitude, lng: loc.coords.longitude });

      const [place] = await Location.reverseGeocodeAsync({
        latitude:  loc.coords.latitude,
        longitude: loc.coords.longitude,
      });
      if (place) {
        const parts = [place.streetNumber, place.street, place.city].filter(Boolean);
        setAddress(parts.join(', '));
      }
    } catch {
      Alert.alert('Erreur', 'Impossible d\'obtenir votre position.');
    } finally {
      setLocLoading(false);
    }
  };

  // ----------------------------------------------------------------
  // Prise de photo
  // ----------------------------------------------------------------
  const pickPhoto = async (source: 'camera' | 'library') => {
    let result;
    if (source === 'camera') {
      const { status } = await ImagePicker.requestCameraPermissionsAsync();
      if (status !== 'granted') {
        Alert.alert('Permission refusée', 'L\'accès à la caméra est requis.');
        return;
      }
      result = await ImagePicker.launchCameraAsync({
        mediaTypes: ImagePicker.MediaTypeOptions.Images,
        quality: 0.7, allowsEditing: true, aspect: [4, 3],
      });
    } else {
      result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ImagePicker.MediaTypeOptions.Images,
        quality: 0.7, allowsEditing: true, aspect: [4, 3],
      });
    }

    if (!result.canceled && result.assets[0]) {
      const asset = result.assets[0];
      const ext   = asset.uri.split('.').pop() ?? 'jpg';
      setPhoto({
        uri:  asset.uri,
        type: asset.mimeType ?? `image/${ext}`,
        name: `photo_${Date.now()}.${ext}`,
      });
    }
  };

  const showPhotoPicker = () => {
    Alert.alert('Ajouter une photo', 'Choisissez la source', [
      { text: '📷 Prendre une photo',      onPress: () => pickPhoto('camera') },
      { text: '🖼️ Choisir dans la galerie', onPress: () => pickPhoto('library') },
      { text: 'Annuler', style: 'cancel' },
    ]);
  };

  // ----------------------------------------------------------------
  // Validation
  // ----------------------------------------------------------------
  const validate = () => {
    const e: Record<string, string> = {};
    if (!categoryId)
      e.category    = 'Veuillez choisir une catégorie.';
    if (!description || description.length < 10)
      e.description = 'La description doit contenir au moins 10 caractères.';
    if (!coords)
      e.location    = 'La localisation est obligatoire.';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  // ----------------------------------------------------------------
  // Soumission (en ligne ou hors-ligne)
  // ----------------------------------------------------------------
  const handleSubmit = async () => {
    if (!validate()) return;
    setLoading(true);

    try {
      // ── Mode hors-ligne : mise en queue ──────────────────────────
      if (!isConnected) {
        await OfflineQueue.addToQueue({
          category_id: categoryId!,
          description,
          latitude:    coords!.lat,
          longitude:   coords!.lng,
          title:       title || undefined,
          address:     address || undefined,
          photoUri:    photo?.uri,
          photoType:   photo?.type,
          photoName:   photo?.name,
        });

        Alert.alert(
          '📥 Signalement enregistré hors-ligne',
          'Votre signalement sera envoyé automatiquement dès que vous retrouverez une connexion internet.',
          [{ text: 'OK', onPress: () => navigation.goBack() }]
        );
        return;
      }

      // ── Mode en ligne : envoi direct ─────────────────────────────
      const formData = new FormData();
      formData.append('category_id',  String(categoryId));
      formData.append('description',  description);
      formData.append('latitude',     String(coords!.lat));
      formData.append('longitude',    String(coords!.lng));
      if (title)   formData.append('title',   title);
      if (address) formData.append('address', address);
      if (photo) {
        formData.append('photo', {
          uri:  photo.uri,
          type: photo.type,
          name: photo.name,
        } as any);
      }

      const res = await incidentsApi.create(formData);
      Alert.alert(
        '✅ Signalement envoyé !',
        `Votre signalement a bien été enregistré.\nRéférence : ${res.data?.reference ?? ''}`,
        [{ text: 'OK', onPress: () => navigation.goBack() }]
      );
    } catch (err: any) {
      // En cas d'erreur réseau inattendue, proposer la mise en queue
      Alert.alert(
        'Erreur d\'envoi',
        'Impossible d\'envoyer le signalement. Voulez-vous le sauvegarder pour envoi ultérieur ?',
        [
          { text: 'Annuler', style: 'cancel' },
          {
            text: 'Sauvegarder',
            onPress: async () => {
              await OfflineQueue.addToQueue({
                category_id: categoryId!,
                description,
                latitude:    coords!.lat,
                longitude:   coords!.lng,
                title:       title || undefined,
                address:     address || undefined,
                photoUri:    photo?.uri,
                photoType:   photo?.type,
                photoName:   photo?.name,
              });
              navigation.goBack();
            },
          },
        ]
      );
    } finally {
      setLoading(false);
    }
  };

  // ----------------------------------------------------------------
  // Rendu
  // ----------------------------------------------------------------
  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>

      {/* Bannière hors-ligne (v1.1) */}
      <OfflineBanner />

      <View style={styles.header}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.closeBtn}>
          <Text style={styles.closeText}>✕</Text>
        </TouchableOpacity>
        <Text style={styles.headerTitle}>Nouveau signalement</Text>
        <View style={{ width: 40 }} />
      </View>

      <ScrollView style={styles.scroll} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">

        {/* Indicateur mode hors-ligne dans le formulaire */}
        {!isConnected && (
          <View style={styles.offlineNotice}>
            <Text style={styles.offlineNoticeText}>
              📴 Mode hors-ligne — Le signalement sera envoyé dès la reconnexion
            </Text>
          </View>
        )}

        {/* Photo */}
        <Text style={styles.sectionTitle}>Photo du problème</Text>
        <TouchableOpacity
          style={[styles.photoBox, photo ? styles.photoBoxFilled : {}]}
          onPress={showPhotoPicker}
        >
          {photo
            ? <Image source={{ uri: photo.uri }} style={styles.photoPreview} />
            : (
              <View style={styles.photoPlaceholder}>
                <Text style={styles.photoIcon}>📷</Text>
                <Text style={styles.photoHint}>Appuyer pour photographier</Text>
                <Text style={styles.photoSubHint}>ou choisir dans la galerie</Text>
              </View>
            )
          }
        </TouchableOpacity>
        {photo && (
          <TouchableOpacity onPress={() => setPhoto(null)} style={styles.removePhoto}>
            <Text style={styles.removePhotoText}>Supprimer la photo</Text>
          </TouchableOpacity>
        )}

        {/* Catégorie */}
        <Text style={styles.sectionTitle}>
          Catégorie <Text style={styles.required}>*</Text>
        </Text>
        {errors.category && <Text style={styles.errorText}>{errors.category}</Text>}
        <View style={styles.categoriesGrid}>
          {categories.map(cat => (
            <TouchableOpacity
              key={cat.id}
              style={[
                styles.catChip,
                categoryId === cat.id && { backgroundColor: cat.color, borderColor: cat.color },
              ]}
              onPress={() => setCategoryId(cat.id)}
            >
              <Text style={[styles.catChipText, categoryId === cat.id && { color: COLORS.white }]}>
                {cat.name}
              </Text>
            </TouchableOpacity>
          ))}
        </View>

        {/* Description */}
        <Input
          label={<>Description <Text style={styles.required}>*</Text></> as any}
          placeholder="Décrivez précisément le problème observé…"
          value={description}
          onChangeText={setDescription}
          multiline
          numberOfLines={4}
          style={{ minHeight: 100, textAlignVertical: 'top' }}
          error={errors.description}
        />

        {/* Titre optionnel */}
        <Input
          label="Titre (optionnel)"
          placeholder="Ex: Nid-de-poule dangereux rue de la Paix"
          value={title}
          onChangeText={setTitle}
        />

        {/* Localisation */}
        <Text style={styles.sectionTitle}>
          Localisation <Text style={styles.required}>*</Text>
        </Text>
        {errors.location && <Text style={styles.errorText}>{errors.location}</Text>}
        <View style={styles.locationBox}>
          {locLoading
            ? <ActivityIndicator color={COLORS.primary} />
            : coords
              ? (
                <View style={styles.locationInfo}>
                  <Text style={styles.locationIcon}>📍</Text>
                  <View style={{ flex: 1 }}>
                    {address ? <Text style={styles.locationAddress}>{address}</Text> : null}
                    <Text style={styles.locationCoords}>
                      {coords.lat.toFixed(6)}, {coords.lng.toFixed(6)}
                    </Text>
                  </View>
                  <TouchableOpacity onPress={getLocation}>
                    <Text style={styles.refreshLocation}>🔄</Text>
                  </TouchableOpacity>
                </View>
              )
              : (
                <TouchableOpacity style={styles.locationBtn} onPress={getLocation}>
                  <Text style={styles.locationBtnText}>📍 Obtenir ma position</Text>
                </TouchableOpacity>
              )
          }
        </View>

        {/* Bouton de soumission */}
        <Button
          title={isConnected ? 'Envoyer le signalement' : '📥 Sauvegarder hors-ligne'}
          onPress={handleSubmit}
          loading={loading}
          style={{ marginTop: 24, marginBottom: 40 }}
        />

      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingTop: Platform.OS === 'ios' ? 56 : 16,
    paddingBottom: 12,
    backgroundColor: COLORS.white,
    borderBottomWidth: 1,
    borderBottomColor: COLORS.border,
  },
  closeBtn:    { width: 40, height: 40, justifyContent: 'center', alignItems: 'center' },
  closeText:   { fontSize: 18, color: COLORS.gray },
  headerTitle: { fontSize: 17, fontWeight: '700', color: COLORS.dark },

  scroll:  { flex: 1, backgroundColor: '#f8fafc' },
  content: { padding: 16, paddingBottom: 40 },

  // Mode hors-ligne
  offlineNotice: {
    backgroundColor: '#fef3c7',
    borderRadius: 10,
    padding: 12,
    marginBottom: 16,
    borderLeftWidth: 4,
    borderLeftColor: '#f59e0b',
  },
  offlineNoticeText: { fontSize: 13, color: '#92400e', fontWeight: '500' },

  sectionTitle: { fontSize: 15, fontWeight: '700', color: COLORS.dark, marginBottom: 10, marginTop: 8 },
  required:     { color: COLORS.danger },
  errorText:    { fontSize: 12, color: COLORS.danger, marginBottom: 6 },

  photoBox: {
    borderWidth: 2,
    borderColor: COLORS.border,
    borderStyle: 'dashed',
    borderRadius: 12,
    height: 180,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f8fafc',
    overflow: 'hidden',
  },
  photoBoxFilled:  { borderStyle: 'solid', borderColor: COLORS.primary },
  photoPreview:    { width: '100%', height: '100%', resizeMode: 'cover' },
  photoPlaceholder:{ alignItems: 'center', gap: 8 },
  photoIcon:       { fontSize: 36 },
  photoHint:       { fontSize: 15, color: COLORS.gray, fontWeight: '600' },
  photoSubHint:    { fontSize: 13, color: COLORS.gray },
  removePhoto:     { alignSelf: 'center', marginTop: 8 },
  removePhotoText: { fontSize: 13, color: COLORS.danger },

  categoriesGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginBottom: 16,
  },
  catChip: {
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 20,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    backgroundColor: COLORS.white,
  },
  catChipText: { fontSize: 13, fontWeight: '600', color: COLORS.dark },

  locationBox: {
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 10,
    padding: 14,
    backgroundColor: COLORS.white,
    marginBottom: 8,
  },
  locationInfo:    { flexDirection: 'row', alignItems: 'center', gap: 10 },
  locationIcon:    { fontSize: 20 },
  locationAddress: { fontSize: 14, fontWeight: '600', color: COLORS.dark, marginBottom: 2 },
  locationCoords:  { fontSize: 12, color: COLORS.gray, fontFamily: 'monospace' },
  refreshLocation: { fontSize: 20 },
  locationBtn:     { alignItems: 'center', paddingVertical: 8 },
  locationBtnText: { fontSize: 15, color: COLORS.primary, fontWeight: '600' },
});
