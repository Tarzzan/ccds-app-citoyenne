import React, { useState } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  Image,
  ScrollView,
  StyleSheet,
  Alert,
  ActivityIndicator,
} from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import * as ImageManipulator from 'expo-image-manipulator';

/**
 * PhotoPicker — Sélection et prévisualisation de photos multiples (UX-04)
 *
 * Props :
 *   photos       : URI[] — photos actuellement sélectionnées
 *   onPhotosChange : (photos: PhotoItem[]) => void
 *   maxPhotos    : nombre maximum de photos (défaut : 5)
 *   disabled     : désactiver le composant
 */

export interface PhotoItem {
  uri: string;
  fileName?: string;
  mimeType?: string;
  size?: number;
  isExisting?: boolean; // true si la photo vient du serveur
  serverId?: number;    // id côté serveur pour les photos existantes
}

interface Props {
  photos: PhotoItem[];
  onPhotosChange: (photos: PhotoItem[]) => void;
  maxPhotos?: number;
  disabled?: boolean;
}

const MAX_DIMENSION = 1920;
const JPEG_QUALITY  = 0.82;

export default function PhotoPicker({
  photos,
  onPhotosChange,
  maxPhotos = 5,
  disabled = false,
}: Props) {
  const [compressing, setCompressing] = useState(false);

  const canAdd = photos.length < maxPhotos && !disabled;

  // ── Sélectionner depuis la galerie ──────────────────────────
  const pickFromGallery = async () => {
    const { status } = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (status !== 'granted') {
      Alert.alert('Permission refusée', 'L\'accès à la galerie est nécessaire pour ajouter des photos.');
      return;
    }

    const remaining = maxPhotos - photos.length;
    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ImagePicker.MediaTypeOptions.Images,
      allowsMultipleSelection: true,
      selectionLimit: remaining,
      quality: 1,
    });

    if (!result.canceled && result.assets.length > 0) {
      await addPhotos(result.assets.map(a => ({ uri: a.uri, fileName: a.fileName ?? undefined, mimeType: a.mimeType ?? undefined, size: a.fileSize })));
    }
  };

  // ── Prendre une photo avec l'appareil ───────────────────────
  const takePhoto = async () => {
    const { status } = await ImagePicker.requestCameraPermissionsAsync();
    if (status !== 'granted') {
      Alert.alert('Permission refusée', 'L\'accès à la caméra est nécessaire pour prendre une photo.');
      return;
    }

    const result = await ImagePicker.launchCameraAsync({ quality: 1 });
    if (!result.canceled && result.assets.length > 0) {
      const a = result.assets[0];
      await addPhotos([{ uri: a.uri, fileName: a.fileName ?? undefined, mimeType: a.mimeType ?? undefined, size: a.fileSize }]);
    }
  };

  // ── Compresser et ajouter ────────────────────────────────────
  const addPhotos = async (newPhotos: Partial<PhotoItem>[]) => {
    setCompressing(true);
    try {
      const compressed: PhotoItem[] = [];
      for (const p of newPhotos) {
        if (!p.uri) continue;
        const manipulated = await ImageManipulator.manipulateAsync(
          p.uri,
          [{ resize: { width: MAX_DIMENSION } }],
          { compress: JPEG_QUALITY, format: ImageManipulator.SaveFormat.JPEG }
        );
        compressed.push({
          uri: manipulated.uri,
          fileName: p.fileName ?? `photo_${Date.now()}.jpg`,
          mimeType: 'image/jpeg',
          size: p.size,
        });
      }
      onPhotosChange([...photos, ...compressed].slice(0, maxPhotos));
    } catch (err) {
      Alert.alert('Erreur', 'Impossible de traiter la photo. Réessayez.');
    } finally {
      setCompressing(false);
    }
  };

  // ── Supprimer une photo ──────────────────────────────────────
  const removePhoto = (index: number) => {
    Alert.alert(
      'Supprimer la photo',
      'Voulez-vous retirer cette photo ?',
      [
        { text: 'Annuler', style: 'cancel' },
        {
          text: 'Supprimer',
          style: 'destructive',
          onPress: () => {
            const updated = photos.filter((_, i) => i !== index);
            onPhotosChange(updated);
          },
        },
      ]
    );
  };

  // ── Afficher les options ─────────────────────────────────────
  const showOptions = () => {
    Alert.alert('Ajouter une photo', '', [
      { text: '📷 Prendre une photo', onPress: takePhoto },
      { text: '🖼️ Choisir dans la galerie', onPress: pickFromGallery },
      { text: 'Annuler', style: 'cancel' },
    ]);
  };

  return (
    <View style={styles.container}>
      {/* Label */}
      <View style={styles.labelRow}>
        <Text style={styles.label}>Photos</Text>
        <Text style={styles.counter}>{photos.length}/{maxPhotos}</Text>
      </View>

      {/* Grille de photos */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.scroll}>
        <View style={styles.grid}>
          {/* Miniatures existantes */}
          {photos.map((photo, index) => (
            <View key={index} style={styles.thumbContainer}>
              <Image source={{ uri: photo.uri }} style={styles.thumb} resizeMode="cover" />
              {!disabled && (
                <TouchableOpacity
                  style={styles.removeBtn}
                  onPress={() => removePhoto(index)}
                  accessibilityLabel="Supprimer la photo"
                >
                  <Text style={styles.removeBtnText}>✕</Text>
                </TouchableOpacity>
              )}
              {photo.isExisting && (
                <View style={styles.existingBadge}>
                  <Text style={styles.existingBadgeText}>✓</Text>
                </View>
              )}
            </View>
          ))}

          {/* Bouton d'ajout */}
          {canAdd && (
            <TouchableOpacity
              style={styles.addBtn}
              onPress={showOptions}
              accessibilityLabel="Ajouter une photo"
              accessibilityRole="button"
            >
              {compressing ? (
                <ActivityIndicator color="#2563eb" />
              ) : (
                <>
                  <Text style={styles.addBtnIcon}>+</Text>
                  <Text style={styles.addBtnText}>Photo</Text>
                </>
              )}
            </TouchableOpacity>
          )}
        </View>
      </ScrollView>

      {/* Aide */}
      <Text style={styles.hint}>
        {photos.length === 0
          ? 'Ajoutez jusqu\'à ' + maxPhotos + ' photos pour illustrer le signalement.'
          : photos.length === maxPhotos
          ? 'Nombre maximum de photos atteint.'
          : 'Appuyez sur + pour ajouter une photo (max ' + maxPhotos + ').'}
      </Text>
    </View>
  );
}

const THUMB_SIZE = 88;

const styles = StyleSheet.create({
  container:       { marginBottom: 16 },
  labelRow:        { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 },
  label:           { fontSize: 14, fontWeight: '600', color: '#374151' },
  counter:         { fontSize: 12, color: '#9ca3af' },
  scroll:          { flexGrow: 0 },
  grid:            { flexDirection: 'row', gap: 8, paddingVertical: 4 },
  thumbContainer:  { width: THUMB_SIZE, height: THUMB_SIZE, borderRadius: 8, overflow: 'hidden', position: 'relative' },
  thumb:           { width: THUMB_SIZE, height: THUMB_SIZE },
  removeBtn:       { position: 'absolute', top: 4, right: 4, width: 20, height: 20, borderRadius: 10, backgroundColor: 'rgba(0,0,0,.6)', alignItems: 'center', justifyContent: 'center' },
  removeBtnText:   { color: '#fff', fontSize: 10, fontWeight: '700' },
  existingBadge:   { position: 'absolute', bottom: 4, left: 4, width: 16, height: 16, borderRadius: 8, backgroundColor: '#22c55e', alignItems: 'center', justifyContent: 'center' },
  existingBadgeText: { color: '#fff', fontSize: 9, fontWeight: '700' },
  addBtn:          { width: THUMB_SIZE, height: THUMB_SIZE, borderRadius: 8, borderWidth: 2, borderColor: '#2563eb', borderStyle: 'dashed', alignItems: 'center', justifyContent: 'center', backgroundColor: '#eff6ff' },
  addBtnIcon:      { fontSize: 24, color: '#2563eb', lineHeight: 28 },
  addBtnText:      { fontSize: 11, color: '#2563eb', marginTop: 2 },
  hint:            { fontSize: 11, color: '#9ca3af', marginTop: 6 },
});
