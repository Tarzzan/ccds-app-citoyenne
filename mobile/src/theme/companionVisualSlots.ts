import { ImageSourcePropType } from 'react-native';
import { GENERATED_VISUAL_SOURCES } from './generatedVisualSources';
import { getCompanionSkin } from '../services/CompanionService';

export type CompanionAssetId =
  | 'CHAR-01'
  | 'CHAR-02'
  | 'CHAR-03'
  | 'CHAR-04'
  | 'CHAR-05'
  | 'CHAR-06'
  | 'MOM-01'
  | 'MOM-02'
  | 'MOM-03'
  | 'MOM-04'
  | 'MOM-05'
  | 'MOM-06';

export type CompanionVisualSlot = {
  assetId: CompanionAssetId;
  label: string;
  source?: ImageSourcePropType;
};

/**
 * Retourne la source du companion : image chargée depuis le back-office
 * (via CompanionService) ou fallback local si absente.
 */
function companionSource(): ImageSourcePropType {
  const skin = getCompanionSkin();
  return skin.source;
}

// Les MOM-* sont toujours locaux (scènes de moments, pas de skin)
function momSource(key: string): ImageSourcePropType | undefined {
  return GENERATED_VISUAL_SOURCES[key];
}

export const COMPANION_VISUAL_SLOTS: Record<string, CompanionVisualSlot> = {
  login:          { assetId: 'CHAR-05', label: 'Agent relation citoyenne',  source: companionSource() },
  register:       { assetId: 'CHAR-05', label: 'Agente relation usager',    source: companionSource() },
  serverConfig:   { assetId: 'CHAR-05', label: 'Agente relation usager',    source: companionSource() },
  onboarding:     { assetId: 'CHAR-05', label: 'Agent relation citoyenne',  source: companionSource() },
  dashboard:      { assetId: 'CHAR-04', label: 'Agent terrain',             source: companionSource() },
  createIncident: { assetId: 'CHAR-05', label: 'Agent relation citoyenne',  source: companionSource() },
  incidentDetail: { assetId: 'CHAR-04', label: 'Agent terrain',             source: companionSource() },
  notifications:  { assetId: 'CHAR-05', label: 'Agent relation citoyenne',  source: companionSource() },
  impact:         { assetId: 'CHAR-04', label: 'Agent terrain',             source: companionSource() },
  profile:        { assetId: 'CHAR-05', label: 'Agente relation usager',    source: companionSource() },
  map:            { assetId: 'CHAR-04', label: 'Agent terrain',             source: companionSource() },
  events:         { assetId: 'CHAR-05', label: 'Agent relation citoyenne',  source: companionSource() },
  polls:          { assetId: 'CHAR-05', label: 'Agent relation citoyenne',  source: companionSource() },
  twoFactor:      { assetId: 'CHAR-05', label: 'Agente relation usager',    source: companionSource() },
  // Moments (scènes fixes)
  welcome:        { assetId: 'MOM-01', label: 'Accueil',                    source: momSource('MOM-01') },
  afterReport:    { assetId: 'MOM-02', label: 'Après signalement',          source: momSource('MOM-02') },
  inProgress:     { assetId: 'MOM-03', label: 'En cours',                   source: momSource('MOM-03') },
  resolved:       { assetId: 'MOM-04', label: 'Résolu',                     source: momSource('MOM-04') },
  noIncidents:    { assetId: 'MOM-05', label: 'Aucun signalement',          source: momSource('MOM-05') },
  noNotifs:       { assetId: 'MOM-06', label: 'Aucune notification',        source: momSource('MOM-06') },
};

