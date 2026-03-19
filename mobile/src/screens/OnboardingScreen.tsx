/**
 * OnboardingScreen — Présentation de l'application aux nouveaux utilisateurs (UX-08)
 * 4 étapes avec animations, skip possible, et mémorisation via AsyncStorage.
 */

import React, { useState, useRef } from 'react';
import {
  View, Text, StyleSheet, TouchableOpacity, FlatList, Image,
  Dimensions, Animated, StatusBar,
} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { CivicCompanionCard } from '../components/CivicCompanionCard';
import { BRAND, BRAND_SHADOW } from '../theme/brand';
import { COMPANION_VISUAL_SLOTS } from '../theme/companionVisualSlots';
import { GENERATED_VISUAL_SOURCES } from '../theme/generatedVisualSources';

const { width, height } = Dimensions.get('window');
const ONBOARDING_KEY = 'ma_commune_onboarding_done';
const LEGACY_ONBOARDING_KEY = 'ccds_onboarding_done';

interface Slide {
  id: string;
  eyebrow: string;
  marker: string;
  title: string;
  description: string;
  cardTint: string;
}

const slides: Slide[] = [
  {
    id: '1',
    eyebrow: 'Territoire',
    marker: '01',
    title: 'Un outil civique pensé pour la Guyane.',
    description: 'Ma Commune aide les habitants à signaler ce qui abime le cadre de vie, tout en rendant visible la reponse des services publics.',
    cardTint: '#F1E7D5',
  },
  {
    id: '2',
    eyebrow: 'Action',
    marker: '02',
    title: 'Signaler vite, même sur le terrain.',
    description: 'Photo, position, description claire: en quelques gestes, le bon problème arrive à la bonne équipe avec assez d\'informations pour agir.',
    cardTint: '#DDE8E8',
  },
  {
    id: '3',
    eyebrow: 'Confiance',
    marker: '03',
    title: 'Suivre le traitement, pas seulement déposer une alerte.',
    description: 'Chaque statut, chaque commentaire et chaque étape de traitement rendent la réponse publique plus lisible pour les habitants comme pour les services.',
    cardTint: '#EADFD1',
  },
  {
    id: '4',
    eyebrow: 'Engagement',
    marker: '04',
    title: 'Participer, c\'est protéger le commun.',
    description: 'L\'ambition de Ma Commune est simple : faire du signalement un geste utile, visible et respectueux du devoir citoyen.',
    cardTint: '#E7D8C6',
  },
];

interface Props {
  onComplete: () => void;
}

export default function OnboardingScreen({ onComplete }: Props) {
  const [currentIndex, setCurrentIndex] = useState(0);
  const flatListRef = useRef<FlatList>(null);
  const scrollX     = useRef(new Animated.Value(0)).current;

  const handleNext = () => {
    if (currentIndex < slides.length - 1) {
      flatListRef.current?.scrollToIndex({ index: currentIndex + 1, animated: true });
      setCurrentIndex(currentIndex + 1);
    } else {
      handleComplete();
    }
  };

  const handleComplete = async () => {
    await AsyncStorage.setItem(ONBOARDING_KEY, 'true');
    await AsyncStorage.removeItem(LEGACY_ONBOARDING_KEY);
    onComplete();
  };

  const renderSlide = ({ item }: { item: Slide }) => (
    <View style={[styles.slide, { width }]}>
      <View style={styles.slideContent}>
        <View style={styles.heroTop}>
          <Image source={require('../../assets/icon.png')} style={styles.heroLogo} />
          <Text style={styles.heroBrand}>{BRAND.name}</Text>
          <Text style={styles.heroTerritory}>{BRAND.territory}</Text>
        </View>
        <View style={[styles.storyCard, { backgroundColor: item.cardTint }]}>
          <View style={styles.storyHeader}>
            <Text style={styles.storyEyebrow}>{item.eyebrow}</Text>
            <Text style={styles.storyMarker}>{item.marker}</Text>
          </View>
          <Text style={styles.slideTitle}>{item.title}</Text>
          <Text style={styles.slideDescription}>{item.description}</Text>
          {item.id === '4' ? (
            <View style={styles.companionPreviewWrap}>
              <CivicCompanionCard
                compact
                tone="thanks"
                title={`${BRAND.companion.name} vous accompagne dans la suite.`}
                body={BRAND.copy.companionOnboarding}
                visualSource={GENERATED_VISUAL_SOURCES['MOM-01'] ?? COMPANION_VISUAL_SLOTS.onboarding.source}
                visualBadgeLabel="Accueil"
                bullets={[
                  'remercier apres un signalement utile',
                  'traduire la prochaine etape en langage simple',
                ]}
              />
            </View>
          ) : null}
          <View style={styles.storyFooter}>
            <View style={[styles.storyPill, { backgroundColor: '#0E3127' }]}>
              <Text style={styles.storyPillText}>Service public</Text>
            </View>
            <View style={[styles.storyPill, { backgroundColor: '#2D6F86' }]}>
              <Text style={styles.storyPillText}>Suivi public</Text>
            </View>
          </View>
        </View>
        <View style={styles.heroStatement}>
          <Text style={styles.heroStatementLabel}>{BRAND.missionLabel}</Text>
          <Text style={styles.heroStatementText}>{BRAND.copy.heroBody}</Text>
        </View>
      </View>
    </View>
  );

  const currentSlide = slides[currentIndex];

  return (
    <View style={styles.container}>
      <StatusBar barStyle="light-content" backgroundColor={BRAND.colors.canopyDeep} />
      <View style={styles.backgroundTop} />
      <View style={styles.backgroundRiver} />
      <View style={styles.backgroundEarth} />

      {/* Bouton Skip */}
      {currentIndex < slides.length - 1 && (
        <TouchableOpacity style={styles.skipBtn} onPress={handleComplete}>
          <Text style={styles.skipText}>Passer</Text>
        </TouchableOpacity>
      )}

      {/* Slides */}
      <Animated.FlatList
        ref={flatListRef}
        data={slides}
        renderItem={renderSlide}
        keyExtractor={(item) => item.id}
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        scrollEnabled={false}
        onScroll={Animated.event(
          [{ nativeEvent: { contentOffset: { x: scrollX } } }],
          { useNativeDriver: false }
        )}
      />

      {/* Indicateurs de progression */}
      <View style={styles.footer}>
        <View style={styles.dots}>
          {slides.map((_, index) => {
            const inputRange = [(index - 1) * width, index * width, (index + 1) * width];
            const dotWidth = scrollX.interpolate({
              inputRange,
              outputRange: [8, 24, 8],
              extrapolate: 'clamp',
            });
            const opacity = scrollX.interpolate({
              inputRange,
              outputRange: [0.4, 1, 0.4],
              extrapolate: 'clamp',
            });
            return (
              <Animated.View
                key={index}
                style={[styles.dot, { width: dotWidth, opacity }]}
              />
            );
          })}
        </View>

        {/* Bouton principal */}
        <TouchableOpacity
          style={styles.nextBtn}
          onPress={handleNext}
        >
          <Text style={styles.nextBtnText}>
            {currentIndex === slides.length - 1 ? 'Entrer dans l’espace citoyen' : 'Suivant →'}
          </Text>
        </TouchableOpacity>

        {/* Connexion / Inscription */}
        {currentIndex === slides.length - 1 && (
          <View style={styles.authRow}>
            <Text style={styles.authText}>Déjà un compte ? </Text>
            <TouchableOpacity onPress={handleComplete}>
              <Text style={styles.authLink}>Se connecter</Text>
            </TouchableOpacity>
          </View>
        )}
      </View>
    </View>
  );
}

export const checkOnboardingDone = async (): Promise<boolean> => {
  const val = await AsyncStorage.getItem(ONBOARDING_KEY)
    ?? await AsyncStorage.getItem(LEGACY_ONBOARDING_KEY);

  if (val === 'true') {
    await AsyncStorage.setItem(ONBOARDING_KEY, 'true');
    await AsyncStorage.removeItem(LEGACY_ONBOARDING_KEY);
    return true;
  }

  return false;
};

const styles = StyleSheet.create({
  container:       { flex: 1, backgroundColor: BRAND.colors.mist },
  backgroundTop:   { position: 'absolute', top: 0, left: 0, right: 0, height: height * 0.42, backgroundColor: BRAND.colors.canopyDeep },
  backgroundRiver: { position: 'absolute', top: height * 0.34, right: -60, width: 220, height: 220, borderRadius: 110, backgroundColor: '#2D6F8622' },
  backgroundEarth: { position: 'absolute', top: height * 0.18, left: -40, width: 180, height: 180, borderRadius: 90, backgroundColor: '#A64B2A22' },
  skipBtn:         { position: 'absolute', top: 52, right: 24, zIndex: 10, padding: 8 },
  skipText:        { color: '#E8EFE9', fontSize: 15, fontWeight: '700' },
  slide:           { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 28 },
  slideContent:    { width: '100%', maxWidth: 360 },
  heroTop:         { marginBottom: 22 },
  heroLogo: {
    width: 76,
    height: 76,
    borderRadius: 20,
    marginBottom: 16,
  },
  heroBrand:       { fontSize: 30, fontWeight: '800', color: BRAND.colors.white, fontFamily: BRAND.displayFont },
  heroTerritory:   { marginTop: 6, color: '#D8E7DD', fontSize: 13, letterSpacing: 0.4 },
  storyCard: {
    borderRadius: 28,
    padding: 24,
    borderWidth: 1,
    borderColor: '#FFFFFF55',
    ...BRAND_SHADOW,
  },
  storyHeader:     { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 },
  storyEyebrow:    { fontSize: 12, fontWeight: '800', color: BRAND.colors.canopy, textTransform: 'uppercase', letterSpacing: 1.2 },
  storyMarker:     { fontSize: 18, fontWeight: '800', color: BRAND.colors.laterite, fontFamily: 'monospace' },
  slideTitle:      { fontSize: 29, fontWeight: '800', color: BRAND.colors.ink, marginBottom: 14, lineHeight: 36, fontFamily: BRAND.displayFont },
  slideDescription:{ fontSize: 16, color: '#355248', lineHeight: 25 },
  companionPreviewWrap: { marginTop: 18 },
  storyFooter:     { flexDirection: 'row', gap: 10, marginTop: 20 },
  storyPill:       { borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8 },
  storyPillText:   { color: BRAND.colors.white, fontSize: 12, fontWeight: '700' },
  heroStatement:   { marginTop: 18, paddingHorizontal: 4 },
  heroStatementLabel: { color: '#D2A13A', fontSize: 12, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 1.2, marginBottom: 6 },
  heroStatementText:  { color: '#E8EFE9', fontSize: 14, lineHeight: 22 },
  footer:          { paddingHorizontal: 32, paddingBottom: 48, alignItems: 'center' },
  dots:            { flexDirection: 'row', gap: 6, marginBottom: 32 },
  dot:             { height: 8, borderRadius: 4, backgroundColor: BRAND.colors.canopy },
  nextBtn:         { width: '100%', padding: 18, borderRadius: 18, alignItems: 'center', marginBottom: 16, backgroundColor: BRAND.colors.awara, ...BRAND_SHADOW },
  nextBtnText:     { color: BRAND.colors.canopyDeep, fontSize: 17, fontWeight: '800' },
  authRow:         { flexDirection: 'row', alignItems: 'center' },
  authText:        { color: BRAND.colors.slate, fontSize: 14 },
  authLink:        { color: BRAND.colors.canopy, fontSize: 14, fontWeight: '700', textDecorationLine: 'underline' },
});
