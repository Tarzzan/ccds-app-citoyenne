# Mode Operatoire De Demonstration - Ma Commune

Date : 18 mars 2026

## Statut

Ce mode operatoire prepare la demonstration fonctionnelle locale.

Il precede encore la phase finale de push tablette.

## Objectif De La Demo

Montrer une boucle courte, credible et vendable :

1. un habitant signale un besoin utile
2. un agent le prend en charge
3. la commune rend son action visible
4. l'habitant retrouve la preuve de suivi

## Pre-Vol Obligatoire

Avant toute demonstration :

```bash
bash scripts/audit_local_predeploy.sh
bash scripts/seed_demo_local.sh
bash scripts/build_release_tablette.sh
```

Le pre-vol doit finir par :

`Toutes les verifications critiques sont passees.`

Le seed doit ensuite produire un resume `DEMO READY` avec :

- les comptes a utiliser
- trois signalements dans des statuts differents
- une consultation active
- un rendez-vous communal
- un fichier JSON de synthese dans `/tmp/`

Le build tablette doit produire un APK cible correspondant a l'ABI reelle de la tablette dans :

- `mobile/android/app/build/outputs/apk/tablette/`

## Ordre Recommande De Demonstration

### 1. Cadrage produit

Expliquer en une phrase :

`Ma Commune rend visible la prise en charge locale des signalements entre habitants, agents et commune.`

### 2. Mobile citoyen

1. ouvrir l'application
2. verifier l'URL serveur :
   `http://127.0.0.1:8080/api`
3. se connecter avec l'un des comptes citoyens seedes
   mot de passe commun actuel : `Citoyen@MaCommune2026!`
4. montrer `Mes signalements`
5. ouvrir le dossier `en cours`
6. ouvrir `Mon bilan`
7. ouvrir `Consultations`
8. ouvrir `Agenda communal`

### 3. Agent terrain

1. se connecter avec le compte agent
2. ouvrir la file territoriale
3. ouvrir le dossier `en cours`
4. passer le statut en `En cours`
5. ajouter un commentaire public
6. si utile, terminer en `Resolu`

### 4. Admin web

1. ouvrir `http://127.0.0.1:8080/admin/?page=login`
2. se connecter avec le compte admin
3. ouvrir le dashboard
4. montrer les blocs `Concertation citoyenne` et `Rendez-vous communaux`
5. ouvrir `Sondages`
6. ouvrir `Événements`
7. revenir sur la liste des signalements
8. ouvrir le dossier concerne
9. montrer l'historique, la priorite et les commentaires

### 5. Retour citoyen

1. revenir sur le compte citoyen
2. ouvrir les notifications
3. montrer les notifications de statut et de commentaire
4. rouvrir le detail du dossier
5. montrer la coherence du suivi
6. ouvrir la consultation seedee
7. ouvrir l'evenement seede

## Points A Verbaliser Pendant La Demo

- l'application est centree sur Kourou pour la premiere livraison
- le produit est 100 % en francais dans cette phase
- la valeur cle n'est pas le depot d'un signalement mais la preuve de traitement
- le back-office web reste la surface de supervision principale
- la couche communaute montre que `Ma Commune` peut aussi rendre visible l'animation locale, pas seulement traiter des incidents
- l'architecture est extensible ensuite a d'autres communes

## Signaux De Reussite

La demonstration est bonne si :

- l'habitant comprend ce qui a ete fait
- l'agent agit sans friction technique
- l'admin retrouve la meme information que le mobile
- les notifications relient bien les etapes du dossier

## A Faire Avant La Livraison Tablette

- revalider les parcours sur la tablette cible
- reinstaller l'APK cible tablette correspondant a l'ABI de l'appareil
- verifier logo, splash et ecran `Mon bilan` sur appareil
- confirmer les credentials de demonstration definitifs
