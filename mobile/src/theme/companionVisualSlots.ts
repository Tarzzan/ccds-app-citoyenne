import { ImageSourcePropType } from 'react-native';
import { GENERATED_VISUAL_SOURCES } from './generatedVisualSources';

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

// Les sources restent optionnelles tant qu'aucun drop visuel installe n'a ete branche.
// Quand les assets seront valides et installes, chaque slot pourra recevoir un require(...)
// vers mobile/assets/generated-visuals sans changer les ecrans.
export const COMPANION_VISUAL_SLOTS: Record<string, CompanionVisualSlot> = {
  login: { assetId: 'MOM-01', label: 'Accueil duo', source: GENERATED_VISUAL_SOURCES['MOM-01'] },
  register: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  serverConfig: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  onboarding: { assetId: 'MOM-01', label: 'Accueil duo', source: GENERATED_VISUAL_SOURCES['MOM-01'] },
  dashboard: { assetId: 'MOM-04', label: 'Dossier resolu', source: GENERATED_VISUAL_SOURCES['MOM-04'] },
  createIncident: { assetId: 'MOM-02', label: 'Remerciement apres signalement', source: GENERATED_VISUAL_SOURCES['MOM-02'] },
  incidentDetail: { assetId: 'MOM-03', label: 'Dossier en cours', source: GENERATED_VISUAL_SOURCES['MOM-03'] },
  notifications: { assetId: 'MOM-06', label: 'Aucune notification importante', source: GENERATED_VISUAL_SOURCES['MOM-06'] },
  impact: { assetId: 'MOM-04', label: 'Dossier resolu', source: GENERATED_VISUAL_SOURCES['MOM-04'] },
  profile: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  map: { assetId: 'CHAR-06', label: 'Duo hero', source: GENERATED_VISUAL_SOURCES['CHAR-06'] },
  events: { assetId: 'MOM-01', label: 'Accueil duo', source: GENERATED_VISUAL_SOURCES['MOM-01'] },
  polls: { assetId: 'MOM-01', label: 'Accueil duo', source: GENERATED_VISUAL_SOURCES['MOM-01'] },
  twoFactor: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
};
