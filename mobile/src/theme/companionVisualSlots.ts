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
  login: { assetId: 'CHAR-05', label: 'Agent relation citoyenne', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  register: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  serverConfig: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  onboarding: { assetId: 'CHAR-05', label: 'Agent relation citoyenne', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  dashboard: { assetId: 'CHAR-04', label: 'Agent terrain', source: GENERATED_VISUAL_SOURCES['CHAR-04'] },
  createIncident: { assetId: 'CHAR-05', label: 'Agent relation citoyenne', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  incidentDetail: { assetId: 'CHAR-04', label: 'Agent terrain', source: GENERATED_VISUAL_SOURCES['CHAR-04'] },
  notifications: { assetId: 'CHAR-05', label: 'Agent relation citoyenne', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  impact: { assetId: 'CHAR-04', label: 'Agent terrain', source: GENERATED_VISUAL_SOURCES['CHAR-04'] },
  profile: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  map: { assetId: 'CHAR-04', label: 'Agent terrain', source: GENERATED_VISUAL_SOURCES['CHAR-04'] },
  events: { assetId: 'CHAR-05', label: 'Agent relation citoyenne', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  polls: { assetId: 'CHAR-05', label: 'Agent relation citoyenne', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
  twoFactor: { assetId: 'CHAR-05', label: 'Agente relation usager', source: GENERATED_VISUAL_SOURCES['CHAR-05'] },
};
