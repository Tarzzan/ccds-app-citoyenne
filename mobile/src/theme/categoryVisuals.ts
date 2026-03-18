import { ImageSourcePropType } from 'react-native';

type CategoryVisualJson = {
  key: string;
  label: string;
  short_label: string;
  description: string;
  accent: string;
  glow: string;
  aliases: string[];
};

export interface CategoryVisual {
  key: string;
  label: string;
  shortLabel: string;
  description: string;
  accent: string;
  glow: string;
  aliases: string[];
  source: ImageSourcePropType;
}

const CATEGORY_VISUAL_DATA = require('../../../assets/category-visuals/category-visuals.json') as CategoryVisualJson[];

const CATEGORY_ICON_SOURCES: Record<string, ImageSourcePropType> = {
  road: require('../../assets/category-icons/road.png'),
  lightbulb: require('../../assets/category-icons/lightbulb.png'),
  tree: require('../../assets/category-icons/tree.png'),
  trash: require('../../assets/category-icons/trash.png'),
  bench: require('../../assets/category-icons/bench.png'),
  droplets: require('../../assets/category-icons/droplets.png'),
  'triangle-alert': require('../../assets/category-icons/triangle-alert.png'),
  'building-2': require('../../assets/category-icons/building-2.png'),
};

export const CATEGORY_VISUALS: CategoryVisual[] = CATEGORY_VISUAL_DATA.map((entry) => ({
  key: entry.key,
  label: entry.label,
  shortLabel: entry.short_label,
  description: entry.description,
  accent: entry.accent,
  glow: entry.glow,
  aliases: entry.aliases,
  source: CATEGORY_ICON_SOURCES[entry.key],
}));

const FALLBACK_VISUAL = CATEGORY_VISUALS[0];

function normalizeLookup(value?: string | null): string {
  return (value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '');
}

function findCategoryVisual(icon?: string | null, name?: string | null): CategoryVisual | undefined {
  const iconLookup = normalizeLookup(icon);
  const nameLookup = normalizeLookup(name);

  return CATEGORY_VISUALS.find((visual) => {
    const aliases = visual.aliases.map(normalizeLookup);
    return aliases.includes(iconLookup) || aliases.includes(nameLookup) || normalizeLookup(visual.key) === iconLookup;
  });
}

export function resolveCategoryVisual(icon?: string | null, name?: string | null): CategoryVisual {
  return findCategoryVisual(icon, name) ?? FALLBACK_VISUAL;
}

export function getCategoryAccentColor(icon?: string | null, name?: string | null, fallback?: string | null): string {
  return fallback || resolveCategoryVisual(icon, name).accent;
}
