import { ImageSourcePropType } from 'react-native';

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
  login: { assetId: 'MOM-01', label: 'Accueil duo' },
  register: { assetId: 'CHAR-05', label: 'Agente relation usager' },
  serverConfig: { assetId: 'CHAR-05', label: 'Agente relation usager' },
  onboarding: { assetId: 'MOM-01', label: 'Accueil duo' },
  dashboard: { assetId: 'MOM-04', label: 'Dossier resolu' },
  createIncident: { assetId: 'MOM-02', label: 'Remerciement apres signalement' },
  incidentDetail: { assetId: 'MOM-03', label: 'Dossier en cours' },
  notifications: { assetId: 'MOM-06', label: 'Aucune notification importante' },
  impact: { assetId: 'MOM-04', label: 'Dossier resolu' },
  profile: { assetId: 'CHAR-05', label: 'Agente relation usager' },
  map: { assetId: 'CHAR-06', label: 'Duo hero' },
  events: { assetId: 'MOM-01', label: 'Accueil duo' },
  polls: { assetId: 'MOM-01', label: 'Accueil duo' },
  twoFactor: { assetId: 'CHAR-05', label: 'Agente relation usager' },
};
