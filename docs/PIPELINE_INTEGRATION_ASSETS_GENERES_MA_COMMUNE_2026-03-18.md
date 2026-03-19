# Pipeline Integration Assets Generes Ma Commune

## But

Préparer l étape qui suit la génération IA : l ingestion propre des visuels dans le dépôt et dans les cibles produit.

Ce pipeline évite :

- des copies manuelles fragiles
- des chemins incohérents entre mobile, admin et site
- des assets générés mais jamais réellement installés

## Entrée Attendue

Un dossier source contenant les fichiers générés avec les noms définis dans les batches :

- `char-01-mascot-m1-portrait-4x5.png`
- `ill-01-road-pothole-rain-4x5.png`
- `mom-01-welcome-duo-4x5.png`
- `hero-01-landing-ma-commune-16x9.png`

## Commande D Installation

```bash
python3 scripts/install_visual_asset_drop.py /chemin/vers/le/drop
```

## Cibles Alimentées

### Mobile

- `mobile/assets/generated-visuals/characters`
- `mobile/assets/generated-visuals/terrain`
- `mobile/assets/generated-visuals/moments`
- `mobile/assets/generated-visuals/hero`

### Admin

- `admin/assets/img/generated-visuals/characters`
- `admin/assets/img/generated-visuals/terrain`
- `admin/assets/img/generated-visuals/moments`
- `admin/assets/img/generated-visuals/hero`

### Site

- `site/assets/generated-visuals/characters`
- `site/assets/generated-visuals/terrain`
- `site/assets/generated-visuals/moments`
- `site/assets/generated-visuals/hero`

### Trace De Référence

- `assets/visual-production/installed/current`
- `assets/visual-production/generated/installed-visual-assets.json`
- `assets/visual-production/generated/installed-visual-assets.md`

## Contrôle

```bash
bash scripts/check_installed_visual_assets.sh
```

Ce contrôle vérifie :

- que le manifeste d installation existe
- que les cibles mobile, admin et site existent réellement
- que le nombre d assets installés n est pas vide

## Règle D Usage

Ne pas brancher les futurs visuels directement depuis un dossier externe.

Toujours :

1. générer les visuels
2. les installer via `install_visual_asset_drop.py`
3. vérifier via `check_installed_visual_assets.sh`
4. brancher seulement ensuite dans les écrans

## État Actuel

Le pipeline est prêt, même si aucun drop réel n a encore été installé.
