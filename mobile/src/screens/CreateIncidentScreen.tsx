/**
 * CCDS — Écran Création de Signalement
 * Permet de photographier un problème, le géolocaliser et le soumettre.
 */

import React, { useState, useEffect } from 'react';
import {
  View, Text, StyleSheet, ScrollView, TouchableOpacity,
  Image, Alert, ActivityIndicator, KeyboardAvoidingView, Platform,
} from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { useNavigation } from '@react-navigation/native';

import { categoriesApi, incidentsApi, Category } from '../services/api';
import { Button, Input, COLORS } from '../components/ui';

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
  const [categories,  setCategories]  = useState<Category[]>([]);
  const [loading,     setLoading]     = useState(false);
  const [locLoading,  setLocLoading]  = useState(false);
  const [errors,      setErrors]      = useState<Record<string, string>>({});

  // Charger les catégories au montage
  useEffect(() => {
    (async () => {
      try {
        const res = await categoriesApi.list();
        if (res.data) setCategories(res.data);
      } catch {
        Alert.alert('Erreur', 'Impossible de charger les catégories.');
      }
    })();
    // Obtenir la position immédiatement
    getLocation();
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

      // Géocodage inverse pour obtenir l'adresse
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
        quality: 0.7,
        allowsEditing: true,
        aspect: [4, 3],
      });
    } else {
      result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ImagePicker.MediaTypeOptions.Images,
        quality: 0.7,
        allowsEditing: true,
        aspect: [4, 3],
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
      { text: '📷 Prendre une photo', onPress: () => pickPhoto('camera') },
      { text: '🖼️ Choisir dans la galerie', onPress: () => pickPhoto('library') },
      { text: 'Annuler', style: 'cancel' },
    ]);
  };

  // ----------------------------------------------------------------
  // Validation et soumission
  // ----------------------------------------------------------------
  const validate = () => {
    const e: Record<string, string> = {};
    if (!categoryId)                    e.category    = 'Veuillez choisir une catégorie.';
    if (!description || description.length < 10)
                                        e.description = 'La description doit contenir au moins 10 caractères.';
    if (!coords)                        e.location    = 'La localisation est obligatoire.';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate()) return;
    setLoading(true);
    try {
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
      Alert.alert('Erreur', err?.message ?? 'Impossible d\'envoyer le signalement.');
    } finally {
      setLoading(false);
    }
  };

  // ----------------------------------------------------------------
  // Rendu
  // ----------------------------------------------------------------
  return (
    <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={styles.header}>
        <TouchableOpacity onPress={() => navigation.goBack()} style={styles.closeBtn}>
          <Text style={styles.closeText}>✕</Text>
        </TouchableOpacity>
        <Text style={styles.headerTitle}>Nouveau signalement</Text>
        <View style={{ width: 40 }} />
      </View>

      <ScrollView style={styles.scroll} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">

        {/* Photo */}
        <Text style={styles.sectionTitle}>Photo du problème</Text>
        <TouchableOpacity style={[styles.photoBox, photo ? styles.photoBoxFilled : {}]} onPress={showPhotoPicker}>
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
        <Text style={styles.sectionTitle}>Catégorie <Text style={styles.required}>*</Text></Text>
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
        <Text style={styles.sectionTitle}>Localisation <Text style={styles.required}>*</Text></Text>
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
          title="Envoyer le signalement"
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

  scroll:   { flex: 1, backgroundColor: '#f8fafc' },
  content:  { padding: 20 },

  sectionTitle: { fontSize: 15, fontWeight: '700', color: COLORS.dark, marginBottom: 10, marginTop: 8 },
  required:     { color: COLORS.danger },
  errorText:    { color: COLORS.danger, fontSize: 12, marginBottom: 8 },

  photoBox: {
    height: 200,
    borderRadius: 14,
    borderWidth: 2,
    borderColor: COLORS.border,
    borderStyle: 'dashed',
    overflow: 'hidden',
    backgroundColor: COLORS.lightGray,
    marginBottom: 8,
  },
  photoBoxFilled:   { borderStyle: 'solid', borderColor: COLORS.primary },
  photoPreview:     { width: '100%', height: '100%', resizeMode: 'cover' },
  photoPlaceholder: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 6 },
  photoIcon:        { fontSize: 40 },
  photoHint:        { fontSize: 14, fontWeight: '600', color: COLORS.gray },
  photoSubHint:     { fontSize: 12, color: COLORS.gray },
  removePhoto:      { alignSelf: 'flex-end', marginBottom: 12 },
  removePhotoText:  { fontSize: 13, color: COLORS.danger },

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
  catChipText: { fontSize: 13, fontWeight: '500', color: COLORS.dark },

  locationBox: {
    backgroundColor: COLORS.white,
    borderRadius: 12,
    borderWidth: 1.5,
    borderColor: COLORS.border,
    padding: 14,
    marginBottom: 8,
    minHeight: 56,
    justifyContent: 'center',
  },
  locationInfo:    { flexDirection: 'row', alignItems: 'center', gap: 10 },
  locationIcon:    { fontSize: 22 },
  locationAddress: { fontSize: 14, fontWeight: '600', color: COLORS.dark, marginBottom: 2 },
  locationCoords:  { fontSize: 12, color: COLORS.gray, fontFamily: 'monospace' },
  refreshLocation: { fontSize: 20 },
  locationBtn:     { alignItems: 'center' },
  locationBtnText: { fontSize: 14, color: COLORS.primary, fontWeight: '600' },
});
