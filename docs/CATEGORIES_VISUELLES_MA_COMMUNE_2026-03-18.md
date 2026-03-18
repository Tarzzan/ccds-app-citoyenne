# Catégories Visuelles Ma Commune

Ce document formalise la collection d'icônes catégories inspirées du terrain guyanais.

## Intention

L'ancien système reposait sur des emojis et des chaînes libres. Il produisait trois défauts:

- rendu peu crédible sur mobile
- incohérence entre back-office et application
- dérive éditoriale dès qu'une catégorie était modifiée manuellement

La nouvelle approche repose sur une famille fermée de repères visuels premium:

- `Voirie & Chaussée`
- `Éclairage Public`
- `Espaces Verts`
- `Propreté & Déchets`
- `Mobilier Urbain`
- `Réseaux & Inondations`
- `Signalisation`
- `Bâtiments Communaux`

Chaque catégorie dispose désormais:

- d'un pictogramme PNG 512x512
- d'une couleur accent
- d'un fond et d'une ambiance visuelle cohérents
- d'un mapping stable côté mobile et admin

## Sources

- catalogue: [assets/category-visuals/category-visuals.json](/home/tarzzan/codex/ccds-app-citoyenne/assets/category-visuals/category-visuals.json)
- générateur: [scripts/generate_category_icons.py](/home/tarzzan/codex/ccds-app-citoyenne/scripts/generate_category_icons.py)
- aperçu PNG: [category-visuals-preview.png](/home/tarzzan/codex/ccds-app-citoyenne/assets/category-visuals/generated/category-visuals-preview.png)
- aperçu HTML: [index.html](/home/tarzzan/codex/ccds-app-citoyenne/assets/category-visuals/index.html)

## Régénération

Commande:

```bash
python3 scripts/generate_category_icons.py
```

Cette commande alimente automatiquement:

- [assets/category-visuals/generated](/home/tarzzan/codex/ccds-app-citoyenne/assets/category-visuals/generated)
- [mobile/assets/category-visuals](/home/tarzzan/codex/ccds-app-citoyenne/mobile/assets/category-visuals)
- [mobile/assets/category-icons](/home/tarzzan/codex/ccds-app-citoyenne/mobile/assets/category-icons)
- [admin/assets/img/category-icons](/home/tarzzan/codex/ccds-app-citoyenne/admin/assets/img/category-icons)

## Contrôle

Commande:

```bash
bash scripts/check_category_visuals.sh
```

Ce contrôle échoue si:

- une entrée du catalogue JSON est incomplète
- une icône PNG manque côté master, mobile ou admin
- la copie du catalogue généré n'est plus synchronisée
- le mapping mobile ne couvre plus exactement les catégories du catalogue
- les aperçus de collection ont disparu

## Intégration

### Mobile

- registre visuel: [categoryVisuals.ts](/home/tarzzan/codex/ccds-app-citoyenne/mobile/src/theme/categoryVisuals.ts)
- composant partagé: [CategoryMark.tsx](/home/tarzzan/codex/ccds-app-citoyenne/mobile/src/components/CategoryMark.tsx)

Surfaces raccordées:

- création de signalement
- tableau de bord
- bilan citoyen
- carte
- détail signalement
- listes de signalements

### Admin

- helper partagé: [category_visuals.php](/home/tarzzan/codex/ccds-app-citoyenne/admin/includes/category_visuals.php)

Surfaces raccordées:

- gestion des catégories
- recherche globale
- utilisateurs
- liste signalements
- détail signalement
- carte signalements
- tableau de bord admin
- statistiques admin

## Règle de gouvernance

Ne pas réintroduire:

- d'emoji saisis librement comme source principale
- de pictogrammes isolés sans entrée dans le catalogue JSON
- de couleur de catégorie non alignée avec le catalogue

Si une nouvelle catégorie est ajoutée, elle doit être ajoutée d'abord dans le catalogue puis régénérée.
