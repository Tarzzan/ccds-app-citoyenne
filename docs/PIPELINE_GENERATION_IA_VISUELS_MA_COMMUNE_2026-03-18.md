# Pipeline Generation IA Visuels Ma Commune

## But

Structurer la production des visuels premium `Ma Commune` pour eviter :

- des images de qualite inegale
- des personnages qui changent de visage
- des scenes categories sans coherence
- un melange confus entre icones, badges et illustrations

## Familles D Assets

### 1. Personnages

- mascotte territoriale
- agent communal
- scenes duo

### 2. Categories

- badges categories
- illustrations terrain

### 3. Produit

- hero landing
- onboarding
- etats vides
- cartes de remerciement
- stores mobile

## Workflow Recommande

### Etape 1. Bible

Utiliser :

- `docs/DIRECTION_ARTISTIQUE_COMPAGNON_ET_IMAGERIE_TERRAIN_MA_COMMUNE_2026-03-18.md`
- `docs/BIBLE_PERSONNAGES_MA_COMMUNE_2026-03-18.md`

Ne rien produire avant validation du style.

### Etape 2. Exploration

Produire un nombre limite de pistes :

- 3 pistes mascotte
- 2 pistes agent
- 2 palettes
- 2 directions de lumiere

Objectif :

- choisir vite
- ne pas disperser la production

### Etape 3. Canon

Une fois la piste retenue :

- figer les references visage
- figer la palette
- figer l eclairage
- figer le niveau de stylisation

### Etape 4. Production Par Lots

Ordre recommande :

1. personnages hero
2. categories prioritaires
3. etats vides et remerciements
4. landing et stores

## Prompts A Structurer

Chaque prompt doit toujours comporter :

- sujet
- emotion
- contexte guyanais
- type de cadrage
- lumiere
- niveau de stylisation
- contraintes UI

## Gabarit Prompt Personnage

```text
Personnage civique premium pour application mobile de service public local en Guyane francaise,
semi-realiste, chaleureux, cinematographique, tres lisible, silhouette forte, palette foret-fleuve-laterite,
lumiere tropicale douce, expression bienveillante, rendu propre et premium, fond simple ou detachable,
pas de texte dans l image, pas de style emoji, pas de clipart, pas de rendu enfantin.
```

## Gabarit Prompt Scene Terrain

```text
Illustration semi-realiste premium d un probleme de cadre de vie en Guyane francaise,
scene mobile-first lisible, contexte urbain tropical plausible, vegetation dense, humidite, textures reelles,
emotion de vigilance civique, composition claire, sujet principal immediatement lisible,
pas de texte, pas de style emoji, pas de surcharge, pas de photo brute.
```

## Gabarit Prompt Badge Categorie

```text
Badge categorie premium pour application mobile, symbole central tres lisible, fond simple, contraste fort,
palette coherente avec le territoire guyanais, style coherent avec une illustration semi-realiste stylisee,
aucun emoji, aucun decor inutile, lecture immediate a petite taille.
```

## Lots Prioritaires

### Lot A. Characters

- mascotte piste 1
- mascotte piste 2
- mascotte piste 3
- agent piste 1
- agent piste 2

### Lot B. Terrain

- voirie / nid de poule
- eclairage public
- depot sauvage
- inondation / bouche bouchee
- vegetation envahissante
- mobilier degrade
- signalisation abimee
- batiment communal degrade

### Lot C. Product Moments

- accueil
- remerciement apres signalement
- dossier en cours
- dossier resolu
- aucun signalement
- aucune notification utile

## Contrôle Qualite

Verifier chaque image sur :

- lisibilite a 320 px
- lisibilite a 64 px si badge
- coherence palette
- coherence personnage
- lisibilite du sujet principal
- absence de confusion icone / illustration

## Règles D Intégration

### Icônes

- SVG ou PNG simple
- usage UI pur

### Badges

- PNG premium lisible
- usage categorie

### Illustrations

- PNG ou WebP
- usage hero, onboarding, etats vides, suivi

## Règles De Nommage

Format recommande :

- `mascot-awa-pitch-a-hero.png`
- `agent-communal-pitch-b-bust.png`
- `terrain-road-pothole-rain-v1.png`
- `badge-road-v2.png`

## Gouvernance

Une idee visuelle n entre pas dans la roadmap ni dans la production sans validation.

Avant integration d un nouveau lot, valider :

- utilite produit
- coherence marque
- cout de production
- surface d usage reelle

## Definition De Fini

Un lot est considere fini si :

- les references sont valides
- les exports finaux existent
- les usages produits sont identifies
- les assets peuvent etre branches sans reinterpretation
