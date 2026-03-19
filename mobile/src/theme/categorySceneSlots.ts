import { ImageSourcePropType } from 'react-native';
import { resolveCategoryVisual } from './categoryVisuals';

export type CategorySceneAssetId =
  | 'ILL-01'
  | 'ILL-02'
  | 'ILL-03'
  | 'ILL-04'
  | 'ILL-05'
  | 'ILL-06'
  | 'ILL-07'
  | 'ILL-08';

export type CategorySceneSlot = {
  assetId: CategorySceneAssetId;
  label: string;
  source?: ImageSourcePropType;
};

// Les illustrations terrain resteront optionnelles tant qu'aucun drop valide
// n'aura ete installe dans mobile/assets/generated-visuals.
export const CATEGORY_SCENE_SLOTS: Record<string, CategorySceneSlot> = {
  road: { assetId: 'ILL-01', label: 'Nid de poule apres pluie tropicale' },
  lightbulb: { assetId: 'ILL-02', label: 'Lampadaire en panne au crepuscule' },
  trash: { assetId: 'ILL-03', label: 'Depot sauvage en bord de voirie' },
  droplets: { assetId: 'ILL-04', label: 'Bouche d egout bouchee' },
  tree: { assetId: 'ILL-05', label: 'Vegetation envahissante sur passage' },
  bench: { assetId: 'ILL-06', label: 'Mobilier urbain degrade' },
  'triangle-alert': { assetId: 'ILL-07', label: 'Signalisation endommagee' },
  'building-2': { assetId: 'ILL-08', label: 'Batiment communal degrade' },
};

export function resolveCategorySceneSlot(icon?: string | null, name?: string | null): CategorySceneSlot | null {
  const visual = resolveCategoryVisual(icon, name);
  return CATEGORY_SCENE_SLOTS[visual.key] ?? null;
}
