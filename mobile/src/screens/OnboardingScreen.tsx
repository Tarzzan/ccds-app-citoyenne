/**
 * OnboardingScreen — Présentation de l'application aux nouveaux utilisateurs (UX-08)
 * 4 étapes avec animations, skip possible, et mémorisation via AsyncStorage.
 */

import React, { useState, useRef } from 'react';
import {
  View, Text, StyleSheet, TouchableOpacity, FlatList,
  Dimensions, Animated, StatusBar,
} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

const { width, height } = Dimensions.get('window');
const ONBOARDING_KEY = 'ccds_onboarding_done';

interface Slide {
  id: string;
  icon: string;
  title: string;
  description: string;
  color: string;
  accentColor: string;
}

const slides: Slide[] = [
  {
    id: '1',
    icon: '🌿',
    title: 'Bienvenue sur CCDS Citoyen',
    description: 'L\'application officielle pour signaler les problèmes de votre quartier en Guyane. Ensemble, améliorons notre cadre de vie.',
    color: '#1B5E20',
    accentColor: '#4CAF50',
  },
  {
    id: '2',
    icon: '📍',
    title: 'Signalez en quelques secondes',
    description: 'Prenez une photo, localisez le problème sur la carte et décrivez-le. Votre signalement est transmis immédiatement aux services compétents.',
    color: '#1565C0',
    accentColor: '#42A5F5',
  },
  {
    id: '3',
    icon: '🔔',
    title: 'Suivez vos signalements',
    description: 'Recevez des notifications à chaque avancement. Votez pour les signalements de vos voisins et montrez que vous n\'êtes pas seul.',
    color: '#4A148C',
    accentColor: '#AB47BC',
  },
  {
    id: '4',
    icon: '🏆',
    title: 'Devenez un Citoyen Actif',
    description: 'Gagnez des points et des badges pour chaque contribution. Rejoignez la communauté de citoyens engagés qui font avancer les choses.',
    color: '#E65100',
    accentColor: '#FFA726',
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
    onComplete();
  };

  const renderSlide = ({ item }: { item: Slide }) => (
    <View style={[styles.slide, { backgroundColor: item.color, width }]}>
      <View style={styles.slideContent}>
        <View style={[styles.iconCircle, { backgroundColor: item.accentColor + '33' }]}>
          <Text style={styles.slideIcon}>{item.icon}</Text>
        </View>
        <Text style={styles.slideTitle}>{item.title}</Text>
        <Text style={styles.slideDescription}>{item.description}</Text>
      </View>
    </View>
  );

  const currentSlide = slides[currentIndex];

  return (
    <View style={[styles.container, { backgroundColor: currentSlide.color }]}>
      <StatusBar barStyle="light-content" backgroundColor={currentSlide.color} />

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
          style={[styles.nextBtn, { backgroundColor: currentSlide.accentColor }]}
          onPress={handleNext}
        >
          <Text style={styles.nextBtnText}>
            {currentIndex === slides.length - 1 ? 'Commencer 🚀' : 'Suivant →'}
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
  const val = await AsyncStorage.getItem(ONBOARDING_KEY);
  return val === 'true';
};

const styles = StyleSheet.create({
  container:       { flex: 1 },
  skipBtn:         { position: 'absolute', top: 52, right: 24, zIndex: 10, padding: 8 },
  skipText:        { color: 'rgba(255,255,255,.7)', fontSize: 15, fontWeight: '600' },
  slide:           { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 32 },
  slideContent:    { alignItems: 'center', maxWidth: 340 },
  iconCircle:      { width: 140, height: 140, borderRadius: 70, alignItems: 'center', justifyContent: 'center', marginBottom: 40 },
  slideIcon:       { fontSize: 64 },
  slideTitle:      { fontSize: 26, fontWeight: '800', color: '#FFF', textAlign: 'center', marginBottom: 16, lineHeight: 34 },
  slideDescription:{ fontSize: 16, color: 'rgba(255,255,255,.8)', textAlign: 'center', lineHeight: 24 },
  footer:          { paddingHorizontal: 32, paddingBottom: 48, alignItems: 'center' },
  dots:            { flexDirection: 'row', gap: 6, marginBottom: 32 },
  dot:             { height: 8, borderRadius: 4, backgroundColor: 'rgba(255,255,255,.8)' },
  nextBtn:         { width: '100%', padding: 18, borderRadius: 16, alignItems: 'center', marginBottom: 16 },
  nextBtnText:     { color: '#FFF', fontSize: 17, fontWeight: '800' },
  authRow:         { flexDirection: 'row', alignItems: 'center' },
  authText:        { color: 'rgba(255,255,255,.7)', fontSize: 14 },
  authLink:        { color: '#FFF', fontSize: 14, fontWeight: '700', textDecorationLine: 'underline' },
});
