/**
 * Ma Commune — Écran de création de signalement
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
import { CategoryMark }                           from '../components/CategoryMark';
import { CategoryScenePanel }                     from '../components/CategoryScenePanel';
import { CivicCompanionCard }                     from '../components/CivicCompanionCard';
import { OfflineQueue }                           from '../services/OfflineQueue';
import { BRAND, BRAND_SHADOW }                    from '../theme/brand';
import { GENERATED_VISUAL_SOURCES }               from '../theme/generatedVisualSources';
import { resolveCategoryVisual }                  from '../theme/categoryVisuals';

function StepSection({
  step,
  title,
  hint,
  children,
}: {
  step: string;
  title: string;
  hint: string;
  children: React.ReactNode;
}) {
  return (
    <View style={styles.stepSection}>
      <View style={styles.stepHeader}>
        <View style={styles.stepBadge}>
          <Text style={styles.stepBadgeText}>{step}</Text>
        </View>
        <View style={styles.stepHeaderCopy}>
          <Text style={styles.stepTitle}>{title}</Text>
          <Text style={styles.stepHint}>{hint}</Text>
        </View>
      </View>
      {children}
    </View>
  );
}

function getCreateIncidentMomentVisual(readinessCount: number, hasCategory: boolean, hasPhoto: boolean) {
  if (readinessCount >= 4) {
    return GENERATED_VISUAL_SOURCES['MOM-02'];
  }

  if (hasCategory || hasPhoto) {
    return GENERATED_VISUAL_SOURCES['MOM-03'] ?? GENERATED_VISUAL_SOURCES['MOM-02'];
  }

  return GENERATED_VISUAL_SOURCES['MOM-05'];
}

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
  const readinessCount = [Boolean(photo), Boolean(categoryId), Boolean(description.trim()), Boolean(coords)].filter(Boolean).length;
  const selectedCategory = categories.find((cat) => cat.id === categoryId) ?? null;
  const momentVisual = getCreateIncidentMomentVisual(readinessCount, Boolean(categoryId), Boolean(photo));

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
        Alert.alert('Localisation refusee', 'La localisation aide la commune a situer le signalement avec precision.');
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
      Alert.alert('Position indisponible', 'Impossible d obtenir votre position pour le moment.');
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
        Alert.alert('Camera refusee', 'L acces a la camera est necessaire pour prendre une photo sur le terrain.');
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
    Alert.alert('Ajouter une photo', 'Choisissez comment documenter la situation.', [
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

    if (Object.keys(e).length > 0) {
      const firstError = e.category ?? e.description ?? e.location ?? 'Veuillez corriger le formulaire.';
      Alert.alert('Signalement a completer', firstError);
    }

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
          'Signalement garde pour envoi ulterieur',
          `${BRAND.companion.name} a bien conserve votre signalement. Il sera envoye automatiquement des que votre connexion reviendra.`,
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
        'Merci, votre signalement est parti.',
        `${BRAND.companion.name} vous confirme que le dossier a bien ete enregistre.${res.data?.reference ? `\nReference : ${res.data.reference}` : ''}`,
        [{ text: 'OK', onPress: () => navigation.goBack() }]
      );
    } catch (err: any) {
      // En cas d'erreur réseau inattendue, proposer la mise en queue
      Alert.alert(
        'Envoi interrompu',
        `${BRAND.companion.name} n'a pas pu envoyer le signalement maintenant. Voulez-vous le garder pour un envoi des le retour de la connexion ?`,
        [
          { text: 'Annuler', style: 'cancel' },
          {
            text: 'Garder hors ligne',
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
        <View style={styles.introCard}>
          <Text style={styles.introEyebrow}>Action citoyenne</Text>
          <Text style={styles.introTitle}>{BRAND.copy.incidentTitle}</Text>
          <Text style={styles.introText}>
            Décrivez un problème utile à traiter par la commune. Une bonne fiche aide les agents à intervenir plus vite et plus justement.
          </Text>
        </View>

        <View style={styles.companionCardWrap}>
          <CivicCompanionCard
            tone="guide"
            title={`${BRAND.companion.name} vous aide a faire un signalement utile`}
            body="Quelques details simples changent tout: une photo lisible, un lieu exact et une description courte mais concrete."
            visualSource={momentVisual}
            visualBadgeLabel="Depot"
            bullets={[
              'montrer clairement le probleme sur la photo',
              'verifier la position avant envoi',
              'decrire ce qui gene le plus le terrain',
            ]}
          />
        </View>

        {/* Indicateur mode hors-ligne dans le formulaire */}
        {!isConnected && (
          <View style={styles.offlineNotice}>
            <Text style={styles.offlineNoticeText}>
              📴 Mode hors-ligne — Le signalement sera envoyé dès la reconnexion
            </Text>
          </View>
        )}

        <View style={styles.readinessCard}>
          <Text style={styles.readinessEyebrow}>Avant envoi</Text>
          <Text style={styles.readinessTitle}>{readinessCount}/4 reperes utiles deja prets</Text>
          <Text style={styles.readinessText}>
            Une photo claire, une categorie juste, une description concrete et une position fiable rendent le traitement beaucoup plus rapide.
          </Text>
          <View style={styles.readinessRow}>
            <View style={[styles.readinessPill, photo && styles.readinessPillDone]}><Text style={styles.readinessPillText}>Photo</Text></View>
            <View style={[styles.readinessPill, Boolean(categoryId) && styles.readinessPillDone]}><Text style={styles.readinessPillText}>Categorie</Text></View>
            <View style={[styles.readinessPill, Boolean(description.trim()) && styles.readinessPillDone]}><Text style={styles.readinessPillText}>Description</Text></View>
            <View style={[styles.readinessPill, Boolean(coords) && styles.readinessPillDone]}><Text style={styles.readinessPillText}>Position</Text></View>
          </View>
        </View>

        <StepSection step="1" title="Montrer le probleme" hint="Une image lisible aide les agents a comprendre plus vite.">
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
        </StepSection>

        <StepSection step="2" title="Qualifier la situation" hint="Choisissez la famille qui aidera le mieux la commune a orienter le dossier.">
          <Text style={styles.sectionTitle}>
            Catégorie <Text style={styles.required}>*</Text>
          </Text>
          {errors.category && <Text style={styles.errorText}>{errors.category}</Text>}
          <View style={styles.categoriesGrid}>
            {categories.map(cat => {
              const visual = resolveCategoryVisual(cat.icon, cat.name);
              const isSelected = categoryId === cat.id;

              return (
                <TouchableOpacity
                  key={cat.id}
                  style={[
                    styles.categoryCard,
                    isSelected && { borderColor: visual.accent, backgroundColor: `${visual.accent}14` },
                  ]}
                  onPress={() => setCategoryId(cat.id)}
                  activeOpacity={0.88}
                >
                  <View style={styles.categoryCardTop}>
                    <CategoryMark icon={cat.icon} name={cat.name} color={visual.accent} size={62} />
                    <View style={[styles.categoryPulse, { backgroundColor: `${visual.accent}1F` }]} />
                  </View>
                  <Text style={[styles.categoryCardTitle, isSelected && { color: BRAND.colors.canopyDeep }]}>
                    {cat.name}
                  </Text>
                  <Text style={styles.categoryCardDescription}>
                    {visual.description}
                  </Text>
                </TouchableOpacity>
              );
            })}
          </View>

          <CategoryScenePanel
            icon={selectedCategory?.icon}
            name={selectedCategory?.name}
            title={selectedCategory ? `${selectedCategory.name} sur le terrain` : undefined}
            body={selectedCategory ? "Cette scene aide a replacer la categorie dans une situation concrete, pour distinguer le contexte reel du simple badge fonctionnel." : undefined}
          />

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

          <Input
            label="Titre (optionnel)"
            placeholder="Ex: Nid-de-poule dangereux rue de la Paix"
            value={title}
            onChangeText={setTitle}
          />
        </StepSection>

        <StepSection step="3" title="Confirmer le lieu" hint="Une localisation fiable augmente la qualite de prise en charge.">
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
        </StepSection>

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
    backgroundColor: BRAND.colors.canopyDeep,
    borderBottomWidth: 1,
    borderBottomColor: '#22473D',
  },
  closeBtn:    { width: 40, height: 40, justifyContent: 'center', alignItems: 'center' },
  closeText:   { fontSize: 18, color: '#F4F1E7' },
  headerTitle: { fontSize: 17, fontWeight: '800', color: '#F4F1E7' },

  scroll:  { flex: 1, backgroundColor: BRAND.colors.mist },
  content: { padding: 16, paddingBottom: 40 },
  introCard: {
    backgroundColor: '#FFF9F0',
    borderRadius: 22,
    padding: 18,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#E6DCC8',
    ...BRAND_SHADOW,
  },
  introEyebrow: {
    fontSize: 12,
    fontWeight: '800',
    color: BRAND.colors.laterite,
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    marginBottom: 6,
  },
  introTitle: {
    fontSize: 24,
    fontWeight: '800',
    color: BRAND.colors.ink,
    marginBottom: 8,
    fontFamily: BRAND.displayFont,
  },
  introText: {
    fontSize: 14,
    color: '#355248',
    lineHeight: 21,
  },
  companionCardWrap: {
    marginBottom: 16,
  },
  readinessCard: {
    backgroundColor: '#FFF7E8',
    borderRadius: 20,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#E7D0A2',
    ...BRAND_SHADOW,
  },
  readinessEyebrow: {
    fontSize: 11,
    fontWeight: '800',
    color: BRAND.colors.awara,
    textTransform: 'uppercase',
    letterSpacing: 1,
  },
  readinessTitle: {
    fontSize: 18,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
    marginTop: 6,
    fontFamily: BRAND.displayFont,
  },
  readinessText: {
    fontSize: 13,
    color: BRAND.colors.slate,
    lineHeight: 19,
    marginTop: 8,
  },
  readinessRow: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 14,
  },
  readinessPill: {
    borderRadius: 999,
    paddingHorizontal: 10,
    paddingVertical: 7,
    backgroundColor: '#F2E7CF',
  },
  readinessPillDone: {
    backgroundColor: '#DCEBDD',
  },
  readinessPillText: {
    fontSize: 11,
    fontWeight: '800',
    color: BRAND.colors.canopyDeep,
  },
  stepSection: {
    backgroundColor: '#FFFDF8',
    borderRadius: 22,
    padding: 16,
    marginBottom: 16,
    borderWidth: 1,
    borderColor: '#ECE4D5',
    ...BRAND_SHADOW,
  },
  stepHeader: {
    flexDirection: 'row',
    gap: 12,
    alignItems: 'flex-start',
    marginBottom: 14,
  },
  stepBadge: {
    width: 34,
    height: 34,
    borderRadius: 17,
    backgroundColor: BRAND.colors.canopyDeep,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepBadgeText: {
    color: BRAND.colors.white,
    fontSize: 13,
    fontWeight: '800',
  },
  stepHeaderCopy: {
    flex: 1,
  },
  stepTitle: {
    fontSize: 17,
    fontWeight: '800',
    color: BRAND.colors.ink,
  },
  stepHint: {
    fontSize: 12.5,
    color: BRAND.colors.slate,
    marginTop: 4,
    lineHeight: 18,
  },

  // Mode hors-ligne
  offlineNotice: {
    backgroundColor: '#F8E8C8',
    borderRadius: 10,
    padding: 12,
    marginBottom: 16,
    borderLeftWidth: 4,
    borderLeftColor: BRAND.colors.warning,
  },
  offlineNoticeText: { fontSize: 13, color: '#83541B', fontWeight: '600' },

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
    backgroundColor: '#FFFCF6',
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
    gap: 12,
    marginBottom: 16,
  },
  categoryCard: {
    width: '48%',
    minWidth: 150,
    borderRadius: 22,
    borderWidth: 1.5,
    borderColor: '#E5DECF',
    backgroundColor: '#FFFDF8',
    padding: 14,
    minHeight: 184,
    ...BRAND_SHADOW,
  },
  categoryCardTop: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 12,
  },
  categoryPulse: {
    width: 14,
    height: 14,
    borderRadius: 999,
  },
  categoryCardTitle: {
    fontSize: 14,
    fontWeight: '800',
    color: BRAND.colors.ink,
    marginBottom: 8,
  },
  categoryCardDescription: {
    fontSize: 12,
    lineHeight: 18,
    color: '#52655D',
  },

  locationBox: {
    borderWidth: 1.5,
    borderColor: COLORS.border,
    borderRadius: 10,
    padding: 14,
    backgroundColor: '#FFFDF8',
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
