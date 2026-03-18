# Checklist De Reprise Fonctionnelle

Date : 18 mars 2026

## Objectif

Valider rapidement qu'une version du projet est réellement exploitable avant d'ajouter de nouvelles fonctionnalités.

## Règle De Travail

Ne pas déclarer une version "bonne" tant que les points ci-dessous ne sont pas validés sur build réel, backend réel et back-office réel.

## Parcours P0

- Mobile : l'application s'installe et s'ouvre sans crash.
- Mobile : la configuration serveur accepte `http://127.0.0.1:8080/api` en mode local USB.
- Mobile : inscription d'un citoyen.
- Mobile : connexion du citoyen.
- Mobile : création d'un signalement avec catégorie, description et position.
- Mobile : ouverture du détail d'un signalement.
- Mobile : ajout d'un commentaire public.
- Mobile : affichage du menu bas sans conflit avec les gestes Android.
- Admin : connexion agent/admin.
- Admin : ouverture du tableau de bord.
- Admin : ouverture de la liste des signalements.
- Admin : ouverture d'un détail de signalement.
- Admin : changement de statut d'un signalement.

## Parcours P1

- Mobile : liste "Mes signalements" avec filtres.
- Mobile : notifications listées sans erreur.
- Mobile : navigation depuis une notification vers le détail quand `incident_id` est fourni.
- Mobile : tableau de bord citoyen.
- Mobile : surfaces visibles alignées sur le nom `Ma Commune`.
- Mobile : interface visible 100 % en français.
- Mobile : écran `Carte` centré sur Kourou au démarrage.
- Admin : gestion des catégories.
- Admin : gestion des utilisateurs.
- Admin : carte admin.
- Admin : carte admin centrée sur Kourou au chargement.
- Admin : recherche globale.

## Contrôles Techniques

- `pnpm run typecheck` dans `mobile`
- `./gradlew assembleRelease` dans `mobile/android`
- endpoint `GET /api/categories`
- endpoint `POST /api/register`
- endpoint `POST /api/login`
- endpoint `POST /api/incidents`
- endpoint `GET /api/incidents/{id}`
- page `/admin/?page=login`
- page `/admin/?page=dashboard`

## Points De Vigilance Déjà Observés

- incohérences de schéma entre code PHP et base réelle
- champs renommés : `password_hash`, `status_history.user_id`
- divergences entre build `debug` et `release`
- navigation mobile supposant des données incomplètes
- interface admin plus avancée en apparence que réellement vérifiée

## Définition D'une Base Saine

Une baseline de reprise est acceptable quand :

- le build mobile `release` est reproductible localement
- la tablette Android peut joindre le backend local
- un citoyen peut créer et suivre un signalement
- un agent peut se connecter et consulter le dossier dans l'admin
- aucun fatal PHP ni crash React Native n'apparaît sur ces parcours

## Chantiers De Marque A Suivre

- Le nom produit visible doit converger vers `Ma Commune`.
- Le logo final doit représenter la Guyane avec une qualité exploitable en production.
- La reprise UX/graphique doit renforcer l'idée de devoir citoyen et de service public local.
- Voir la TODO dédiée : `docs/TODO_REPRISE_MA_COMMUNE_2026-03-18.md`
