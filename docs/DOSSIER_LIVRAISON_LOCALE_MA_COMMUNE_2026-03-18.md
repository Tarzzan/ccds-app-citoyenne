# Dossier De Livraison Locale - Ma Commune

Date : 18 mars 2026

## Statut Global

Etat actuel :

- noyau local fonctionnel : `OK`
- demonstration locale : `OK`
- build Android release : `OK`
- build Android cible tablette par ABI reelle : `OK`
- validation finale tablette : `NON REALISEE`

Decision a ce stade :

- `GO` pour une demonstration locale sur poste
- `GO` pour preparation de la phase tablette
- `NO GO` pour declarer une livraison finale appareil tant que la revalidation tablette n'a pas ete rejouee

## Ce Qui Est Pret

### Parcours verifies localement

- configuration serveur mobile
- inscription et connexion citoyenne
- creation de signalement avec photo
- suivi du dossier, commentaires et notifications
- `Mon bilan citoyen`
- agenda communal
- consultations flash a choix unique
- traitement agent
- supervision admin web
- publication admin de consultations et rendez-vous
- modération commentaires
- export RGPD
- audit logs CSV
- webhooks
- surfaces publiques `status` et `api-docs`

### Assets et marque

- icone mobile et assets Expo remplaces
- surfaces visibles prioritaires renommees `Ma Commune`
- documentation active separee des archives dans `README.md`

### Demonstration locale

- seed de demonstration coherent disponible via `bash scripts/seed_demo_local.sh`
- credentials de demonstration documentes
- mode operatoire de demonstration local documente

## Preuves Disponibles

### Audit automatise

Commande de reference :

```bash
bash scripts/audit_local_predeploy.sh
bash scripts/export_manifest_livraison_locale.sh
bash scripts/check_branding_residuals.sh
bash scripts/generate_audit_final_local.sh
bash scripts/package_livraison_locale.sh
bash scripts/verify_livraison_bundle.sh /tmp/ma-commune-livraison-bundle-<stamp>.zip
bash scripts/publish_latest_livraison_aliases.sh
bash scripts/verify_latest_livraison_aliases.sh
bash scripts/show_latest_livraison_status.sh
bash scripts/show_tablette_validation_brief.sh
bash scripts/publish_latest_handoff.sh
```

Resultat attendu :

`Toutes les verifications critiques sont passees.`

Couverture actuelle :

- typecheck mobile
- build Android release
- build Android cible tablette par ABI reelle
- categories API
- surfaces publiques
- recette citoyen -> agent -> admin
- votes, commentaires, notifications
- photos incident
- admin web -> notifications citoyennes
- moderation
- gamification et RGPD
- audit logs, webhooks, notifications routes
- publications communaute via admin web
- manifeste JSON de contexte local pret pour la future phase tablette
- controle automatique des residus de marque sur la couche active
- rapport final local unique genere a partir du manifeste et du gate
- bundle zip unique pret pour transmission et future validation tablette
- verification d'integrite du bundle disponible via checksums et extraction reelle
- alias stables `/tmp/ma-commune-latest-*` disponibles pour pointer sans ambiguite vers le dernier lot verifie
- verification des alias stables disponible pour confirmer la coherence du dernier lot publie
- checksum public du zip de bundle publie avec le lot latest pour controle de transmission
- commande unique de lecture rapide du dernier lot valide disponible pour la future phase tablette
- brief operateur tablette disponible en une commande de lecture seule alimentee par le manifeste latest
- handoff Markdown stable publie dans `/tmp` pour ouvrir directement le bon lot sans relancer les commandes de lecture

### Seed de demonstration

Commande :

```bash
bash scripts/seed_demo_local.sh
```

Resultat attendu :

- bloc `DEMO READY`
- 3 comptes citoyens `@macommune.local`
- 3 incidents a statuts differents
- 1 consultation active
- 1 rendez-vous communal
- 1 resume JSON dans `/tmp/`

### Build Android cible tablette

Commande :

```bash
bash scripts/build_release_tablette.sh
```

Resultat attendu :

- un APK cible tablette dans `mobile/android/app/build/outputs/apk/tablette/`
- dans l'environnement local actuel, la tablette `SM-T590` remonte `armeabi-v7a`
- une taille inferieure a l'APK universel
- un ordre de grandeur compatible validation appareil, cible actuelle : `< 50 Mo`

## Documents A Utiliser

### References actives

- `README.md`
- `docs/AUDIT_PRE_DEPLOIEMENT_LOCAL_MA_COMMUNE_2026-03-18.md`
- `docs/STRATEGIE_COMMERCIALISATION_MA_COMMUNE_2026-03-18.md`
- `docs/CREDENTIALS_DEMO_MA_COMMUNE_2026-03-18.md`
- `docs/MODE_OPERATOIRE_DEMO_MA_COMMUNE_2026-03-18.md`
- `docs/RECETTE_MVP_CITOYEN_AGENT_ADMIN_2026-03-18.md`
- `docs/GUIDE_TESTS_MOBILES.md`

### Archives a ne pas presenter comme etat reel

- `docs/planning/`
- `docs/slides_mobile_content.md`
- `docs/slides_ccds_content.md`
- `docs/Rapport_Phase_3_Mobile.md`

## Risques Restants

### Bloquants pour la livraison finale tablette

- aucune revalidation finale sur tablette cible dans cette phase
- aucun push final release rejoue apres la consolidation complete
- credentials definitifs sur appareil non figes

### Non bloquants pour la demonstration locale

- quelques documents et jeux de donnees historiques peuvent encore contenir des residus `CCDS`
- plusieurs documents historiques encore presents dans `docs/`, meme s'ils sont maintenant marques comme archives

## Passage Tablette A Preparer

Quand la phase appareil sera autorisee, la sequence recommandee est :

1. rejouer `bash scripts/audit_local_predeploy.sh`
2. rejouer `bash scripts/seed_demo_local.sh`
3. construire l'APK cible tablette via `bash scripts/build_release_tablette.sh`
4. installer uniquement cet APK cible sur la tablette de validation
5. verifier configuration serveur, login citoyen, login agent, notifications, `Mon bilan`, logo, splash
6. rejouer la demonstration complete
7. figer les credentials et le mode operatoire definitifs

Dans l'etat local actuel, l'APK universel reste utile pour la compilation generale, mais l'APK cible tablette est la bonne reference pour la phase appareil car il est nettement plus leger.

## Positionnement Commercial Retenu

La promesse vendable a tenir reste :

`rendre visible la prise en charge locale des signalements entre habitants, agents et commune`

Il faut continuer a vendre :

- une preuve de traitement
- une supervision simple
- une application territoriale sobre et utile

Il ne faut pas encore vendre :

- des fonctions non verifiees sur appareil
- du multi-communes deja pret
- des consultations plus complexes que le choix unique V1

## Conclusion

Le projet est maintenant dans un etat local suffisamment propre pour preparer un audit final de livraison.

La prochaine vraie barriere qualite n'est plus le backend local ni la demonstration poste.
La prochaine barriere qualite est la revalidation complete sur la tablette cible.
