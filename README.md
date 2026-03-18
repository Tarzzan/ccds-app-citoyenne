# Ma Commune

Plateforme civique territoriale de proximite pour signaler, suivre et documenter la reponse publique locale.

## Etat Reel Du Depot

Le projet est en phase de reprise et de recentrage produit au 18 mars 2026.

Il ne doit plus etre presente comme "livre 100 %", mais comme un socle deja large en cours de stabilisation autour d'un MVP commercialisable pour :

- Kourou
- la Guyane
- Android en priorite
- un back-office web responsive

Voir les documents de cadrage actifs :

- `docs/OBJECTIF_LIVRAISON_RAPIDE_MA_COMMUNE_2026-03-18.md`
- `docs/CHECKLIST_REPRISE_FONCTIONNELLE_2026-03-18.md`
- `docs/TODO_REPRISE_MA_COMMUNE_2026-03-18.md`
- `docs/AUDIT_REPRISE_PRODUIT_UX_2026-03-18.md`
- `docs/STRATEGIE_COMMERCIALISATION_MA_COMMUNE_2026-03-18.md`
- `docs/DOSSIER_LIVRAISON_LOCALE_MA_COMMUNE_2026-03-18.md`
- `docs/MATRICE_READINESS_MA_COMMUNE_2026-03-18.md`
- `docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md`
- `docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md`
- `docs/CATEGORIES_VISUELLES_MA_COMMUNE_2026-03-18.md`

## Documentation Active Vs Archive

Documents actifs a privilegier pour comprendre l'etat reel du produit :

- `README.md`
- `docs/AUDIT_PRE_DEPLOIEMENT_LOCAL_MA_COMMUNE_2026-03-18.md`
- `docs/STRATEGIE_COMMERCIALISATION_MA_COMMUNE_2026-03-18.md`
- `docs/DOSSIER_LIVRAISON_LOCALE_MA_COMMUNE_2026-03-18.md`
- `docs/MATRICE_READINESS_MA_COMMUNE_2026-03-18.md`
- `docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md`
- `docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md`
- `docs/RECETTE_MVP_CITOYEN_AGENT_ADMIN_2026-03-18.md`
- `docs/GUIDE_TESTS_MOBILES.md`
- `docs/CATEGORIES_VISUELLES_MA_COMMUNE_2026-03-18.md`

Documents historiques a lire comme archives ou matiere de reprise :

- `docs/planning/`
- `docs/slides_mobile_content.md`
- `docs/slides_ccds_content.md`
- `docs/Rapport_Phase_3_Mobile.md`

## Promesse Produit

La V1 ne cherche pas a etre une plateforme municipale complete.

La bonne promesse actuelle est :

> un habitant signale un probleme utile a la commune, voit sa prise en charge, puis un agent peut documenter et valider l'execution

Cette boucle doit etre fluide sur trois surfaces :

- mobile citoyen
- mobile terrain pour agent
- admin web pour supervision

## Positionnement

`Ma Commune` ne doit pas etre percue comme une simple app de plainte.

Le bon positionnement est :

- interface de vigilance civique de proximite
- outil de preuve de traitement entre habitants et services
- produit territorial ancre en Guyane, extensible ensuite a d'autres communes

## Parcours Critiques A Verrouiller

### Cote citoyen

- configurer le serveur
- creer un compte
- se connecter
- creer un signalement avec photo et position
- suivre le statut et les commentaires
- consulter son bilan de contribution

### Cote agent

- se connecter avec un role metier
- ouvrir la file territoriale
- qualifier une priorite
- changer le statut
- documenter l'execution

### Cote admin

- superviser les dossiers
- gerer categories et utilisateurs
- consulter tableau de bord, liste et detail
- piloter depuis tablette ou poste web
- publier des consultations flash a choix unique
- publier des rendez-vous communaux

## Architecture

- `backend/` : API REST PHP/MySQL, auth, incidents, commentaires, notifications, supervision
- `mobile/` : application React Native / Expo
- `admin/` : back-office web responsive
- `site/` : page de garde publique servie sur le domaine racine
- `docs/` : cadrage produit, reprise, checklists et audit
- `docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md` : comptes locaux de demonstration
- `docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md` : deroule recommande de demonstration
- `scripts/recette_mvp_local.sh` : recette locale rapide de la boucle citoyen -> agent -> admin
- `scripts/audit_local_predeploy.sh` : pre-audit automatisé du noyau fonctionnel local
- `scripts/seed_demo_local.sh` : seed additif d'un terrain de demonstration local coherent
- `scripts/prepare_livraison_locale.sh` : rejoue audit + seed et produit un resume Markdown de preparation dans `/tmp`
- `scripts/build_release_tablette.sh` : construit un APK Android cible tablette par ABI, a privilegier pour la validation appareil
  Sans argument, le script detecte l'ABI de la tablette `adb` connectee. La tablette Samsung `SM-T590` locale remonte `armeabi-v7a`
- `scripts/gate_avant_tablette.sh` : verifie que le projet est autorise a entrer en phase de validation tablette, sans declarer la livraison finale appareil
  Les comptes de demonstration et de recette locale utilisent desormais `@macommune.local` pour une coherence produit plus propre
- `scripts/export_manifest_livraison_locale.sh` : rejoue le gate et exporte un manifeste JSON de livraison locale dans `/tmp`
- `scripts/check_branding_residuals.sh` : echoue si une ancienne nomenclature reapparait dans la couche active hors legacy et archives autorisees
- `scripts/generate_audit_final_local.sh` : produit un rapport Markdown final unique de l'etat local verifie dans `/tmp`
- `scripts/package_livraison_locale.sh` : assemble un zip unique avec audit, manifeste, docs actives et APK tablette courant
- `scripts/verify_livraison_bundle.sh` : verifie l'integrite, la structure et les checksums d'un bundle zip de livraison locale
  Le `README_BUNDLE.md` embarque aussi l'identite Git du lot, l'upstream local et l'etat du worktree, puis le tout est reverifie a l'extraction
- `scripts/publish_latest_livraison_aliases.sh` : publie des alias stables `/tmp/ma-commune-latest-*` vers les derniers artefacts verifies
  L'index latest rappelle aussi le commit embarque par le bundle courant, son upstream local et l'etat du worktree
- `scripts/verify_latest_livraison_aliases.sh` : controle que les alias `/tmp/ma-commune-latest-*` pointent vers un bundle coherent et encore valide
  Le checksum du zip publie est expose via `/tmp/ma-commune-latest-livraison-bundle.zip.sha256`
- `scripts/show_latest_livraison_status.sh` : affiche en une commande le statut, les alias stables, les credentials et l alignement `HEAD local vs bundle latest`
- `scripts/show_latest_access_brief.sh` : affiche directement le brief d acces stable publie dans `/tmp/ma-commune-latest-access-brief.txt` puis rappelle l alignement `HEAD local vs bundle latest`
- `scripts/show_tablette_validation_brief.sh` : affiche le brief operateur complet de la future phase tablette depuis le manifeste latest
- `scripts/publish_latest_handoff.sh` : publie un handoff Markdown stable `/tmp/ma-commune-latest-handoff.md` pour la future phase tablette
  Le brief d acces stable est aussi expose via `/tmp/ma-commune-latest-access-brief.txt`
- `scripts/show_local_network_access.sh` : affiche l IP LAN reelle de la machine, les URLs reseau du projet et la commande SSH a utiliser
- `scripts/check_eas_builds.sh` : affiche l etat des builds EAS Android/iOS de reference a partir des IDs courants
- `scripts/publish_macommune_access_brief.sh` : regenere le fichier `macommune.txt` sur le Bureau et le Desktop avec les acces, credentials et builds EAS courants
  Ce brief est aussi republie automatiquement a la fin de `scripts/package_livraison_locale.sh`
- `scripts/verify_access_brief_consistency.sh` : controle que le brief Bureau, la copie Desktop et le brief latest publie dans `/tmp` sont strictement alignes
- `scripts/generate_category_icons.py` : regenere la collection d icones categories Guyane pour mobile et admin
- `scripts/check_category_visuals.sh` : controle que le catalogue categories, les PNG generes et le mapping mobile restent alignes
- `assets/category-visuals/index.html` : apercu HTML de la collection visuelle des categories

## Commandes Utiles

### Mobile

```bash
cd mobile
pnpm run typecheck
cd android
./gradlew assembleRelease
```

```bash
bash scripts/build_release_tablette.sh
```

Sans tablette `adb` connectee, la detection retombe sur le dernier APK tablette deja construit ou sur `armeabi-v7a`, pour ne pas bloquer la preparation locale hors appareil.

### Backend / recette

```bash
curl -sS http://127.0.0.1:8080/api/categories
bash scripts/recette_mvp_local.sh
bash scripts/audit_local_predeploy.sh
bash scripts/seed_demo_local.sh
```

### Branding categories

```bash
python3 scripts/generate_category_icons.py
bash scripts/check_category_visuals.sh
```

Voir aussi :

- `assets/category-visuals/index.html`
- `docs/CATEGORIES_VISUELLES_MA_COMMUNE_2026-03-18.md`

La regeneration alimente aussi `mobile/assets/category-visuals/category-visuals.json`, afin que le build Metro release n'importe plus un fichier situe hors du projet mobile.

### Admin

```bash
curl -sSI http://127.0.0.1:8080/admin/
curl -sSI http://127.0.0.1:8080/admin/?page=login
```

## Vision Commercialisable

La version vendable de `Ma Commune` doit demontrer trois choses :

1. utilite terrain immediate pour l'habitant
2. capacite de traitement visible pour les services
3. qualite de supervision exploitable pour la commune

Le produit ne sera juge credible que si la preuve de traitement est plus forte que l'effet vitrine.

Sur la couche communaute de la V1, le perimetre doit rester strict :

- consultations a choix unique
- evenements publies par la commune
- pas de promesse de participation complexe tant que le schema et le mobile ne la supportent pas reellement

## Regle De Reprise

Ne plus declarer une fonctionnalite "terminee" sans verification sur :

- backend reel
- admin reel
- build mobile release
- parcours utilisateur reel

Le prochain jalon est l'audit final local, puis seulement ensuite le redeploiement sur tablette avec mode operatoire et credentials de demonstration.

Pour la phase tablette, l'APK cible par ABI doit etre privilegie sur l'APK universel, afin de limiter la taille installee et de coller au materiel reel. Dans l'environnement local actuel, la tablette connectee `SM-T590` attend `armeabi-v7a`.
