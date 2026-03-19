# Planches Generation Personnages Batch 01 Ma Commune

## But

Preparer le premier lot de generation personnages avec des fiches directement actionnables.

Ce batch couvre :

- 3 pistes mascotte
- 2 pistes agent
- 1 duo de reference

## Regles Globales

Contraintes communes :

- rendu premium
- semi-realiste
- lumiere tropicale douce
- silhouette lisible
- fond simple ou detachable
- pas de texte dans l image
- pas de style emoji
- pas de clipart
- pas de rendu enfantin

Bloc style commun :

```text
semi-realistic premium civic illustration, warm tropical light, cinematic composition, strong silhouette,
French Guiana setting, lush vegetation, humid atmosphere, natural textures, polished details,
mobile-friendly readability, emotionally engaging, not childish, not flat clipart, no text in image
```

Negative prompt commun :

```text
text, watermark, logo, flat emoji style, childish cartoon, low detail, clipart, distorted hands,
extra limbs, blurry face, over-saturated neon colors, generic corporate background, photorealistic photo look
```

## CHAR-01

### Intent

Mascotte `M1 - Oiseau vigie tropical` en portrait hero.

### Cadrage

- buste / portrait trois quarts
- regard vers le spectateur
- fond simple detachable

### Emotion

- accueil
- intelligence
- chaleur

### Ratio

- `4:5`

### Nom de sortie

- `char-01-mascot-m1-portrait-4x5.png`

### Prompt

```text
Premium civic mascot for a local public service mobile app in French Guiana,
a small tropical sentinel bird, semi-realistic, expressive and intelligent eyes,
warm and trustworthy, elegant silhouette, cinematic tropical light, deep green and laterite palette,
friendly but not childish, premium polished rendering, simple detachable background, bust portrait three-quarter view,
subtle hints of humid tropical atmosphere, no text
```

## CHAR-02

### Intent

Mascotte `M2 - Gardienne du territoire` en portrait premium plus emblematique.

### Cadrage

- portrait trois quarts
- posture noble mais douce

### Emotion

- dignite
- calme
- bienveillance

### Ratio

- `4:5`

### Nom de sortie

- `char-02-mascot-m2-portrait-4x5.png`

### Prompt

```text
Premium territorial mascot for a civic mobile app in French Guiana,
symbolic guardian creature, elegant and memorable, semi-realistic, warm but dignified,
cinematic portrait composition, humid tropical atmosphere, refined palette inspired by forest, river and laterite,
premium rendering, noble but approachable posture, no text
```

## CHAR-03

### Intent

Mascotte `M3 - Compagnon urbain tropical` en portrait plus expressif.

### Cadrage

- portrait ou buste
- visage tres lisible

### Emotion

- energie douce
- empathie
- proximite

### Ratio

- `4:5`

### Nom de sortie

- `char-03-mascot-m3-portrait-4x5.png`

### Prompt

```text
Premium tropical companion mascot for a civic mobile app in French Guiana,
stylized tropical urban animal, semi-realistic, highly expressive, warm and memorable,
strong silhouette, cinematic soft tropical light, polished premium rendering, detachable background,
gentle energetic emotion, very readable face, no text
```

## CHAR-04

### Intent

Agent `A1 - Agent voirie de proximite` en buste.

### Cadrage

- buste
- tenue municipale sobre
- attitude stable

### Emotion

- confiance
- competence
- attention

### Ratio

- `4:5`

### Nom de sortie

- `char-04-agent-a1-bust-4x5.png`

### Prompt

```text
Premium municipal field agent for a civic mobile app in French Guiana,
semi-realistic human character, calm and trustworthy, municipal workwear kept elegant and simple,
warm tropical light, natural textures, cinematic bust framing, service-oriented attitude,
credible public service presence, premium rendering, no text
```

## CHAR-05

### Intent

Agent `A2 - Agente relation usager` en buste.

### Cadrage

- buste
- posture d explication ou d accueil

### Emotion

- reassurance
- clarte
- proximite

### Ratio

- `4:5`

### Nom de sortie

- `char-05-agent-a2-bust-4x5.png`

### Prompt

```text
Premium municipal citizen-relations agent for a civic mobile app in French Guiana,
semi-realistic human character, warm, pedagogical and reassuring,
simple municipal attire, expressive face, cinematic tropical light, polished premium rendering,
credible public service presence, welcoming explanatory posture, no text
```

## CHAR-06

### Intent

Duo recommande `M1 + A2` en scene hero de reference.

### Cadrage

- plan moyen
- duo lisible
- fond epure ou scene territoriale legere

### Emotion

- accueil
- confiance
- engagement civique

### Ratio

- `16:9`

### Nom de sortie

- `char-06-duo-m1-a2-hero-16x9.png`

### Prompt

```text
Premium civic duo for a mobile public service app in French Guiana,
a small tropical sentinel bird mascot and a warm municipal citizen-relations agent,
semi-realistic, cinematic, emotionally engaging, trustworthy, elegant, tropical humid atmosphere,
lush vegetation hints, polished premium rendering, strong readable silhouettes,
medium shot hero composition, no text
```

## Variantes A Prevoir Si La Premiere Passe Est Prometteuse

- version fond transparent pour `CHAR-01`, `CHAR-05`, `CHAR-06`
- version verticale hero pour landing
- version horizon court pour cartes de guidance

## Critères De Relecture

Pour chaque sortie, verifier :

- lisibilite visage
- lisibilite silhouette
- niveau de premium
- absence d effet gadget
- compatibilite avec une interface mobile claire
