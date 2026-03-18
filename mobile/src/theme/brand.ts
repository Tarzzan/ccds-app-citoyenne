import { Platform } from 'react-native';

export const BRAND = {
  name: 'Ma Commune',
  territory: 'Guyane · Kourou',
  civicPromise: 'Veiller sur nos rues, nos quartiers et nos services communs.',
  missionLabel: 'Service public local',
  companion: {
    name: 'Awa',
    role: 'Relais communal',
    signature: 'Je vous aide a comprendre ce qui se passe, a chaque etape utile.',
  },
  displayFont: Platform.select({
    ios: 'Georgia',
    android: 'serif',
    default: 'serif',
  }),
  colors: {
    canopy: '#174B3A',
    canopyDeep: '#0E3127',
    river: '#2D6F86',
    laterite: '#A64B2A',
    awara: '#D2A13A',
    leaf: '#4D8A5B',
    mist: '#F4F1E7',
    sand: '#E6DCC8',
    cloud: '#FAF8F2',
    ink: '#183229',
    slate: '#5E6C67',
    white: '#FFFFFF',
    border: '#D8D0C2',
    success: '#2F7D50',
    warning: '#D48B2C',
    danger: '#C94B3C',
  },
  status: {
    submitted: '#D48B2C',
    acknowledged: '#2D6F86',
    in_progress: '#A64B2A',
    resolved: '#2F7D50',
    rejected: '#C94B3C',
  },
  surfaces: {
    hero: '#0E3127',
    page: '#F4F1E7',
    card: '#FFFCF6',
    mutedCard: '#EFE7D7',
  },
  copy: {
    heroTitle: 'Le signalement devient un geste de soin du territoire.',
    heroBody:
      'Ma Commune relie habitants, agents et commune dans une meme chaine de suivi, visible et utile, d abord a Kourou puis sur le territoire guyanais.',
    impactTitle: 'Ma part dans la vie communale',
    incidentTitle: 'Je veille sur mon quartier',
    companionOnboarding:
      'Awa vous accompagne pour remercier, expliquer la prochaine etape et rendre le suivi plus humain.',
  },
};

export const BRAND_SHADOW = {
  shadowColor: '#0E3127',
  shadowOffset: { width: 0, height: 10 },
  shadowOpacity: 0.08,
  shadowRadius: 20,
  elevation: 6,
};
