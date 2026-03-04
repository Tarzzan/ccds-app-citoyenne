# Feuille de Route (Roadmap) - Projet CCDS

Ce document est la source de vérité unique pour le développement de l'application citoyenne de signalement (CCDS). Il sert de guide pour l'agent IA et de suivi pour le commanditaire du projet.

## 🎯 Objectif Global (Goal)

Développer une solution complète (application mobile iOS/Android et back-office web) permettant aux citoyens de signaler des anomalies dans l'espace public de leur commune, et aux services municipaux de recevoir, gérer et traiter ces signalements de manière efficace.

---

## 🗺️ Phases du Projet v1.0

### Phase 1 : Initialisation et Architecture
*   [x] Créer le dépôt GitHub privé (`Tarzzan/ccds-app-citoyenne`).
*   [x] Définir l'architecture technique (Stack PHP/MySQL/Apache, React Native).
*   [x] Mettre en place la structure de dossiers du projet.
*   [x] Rédiger ce document `ROADMAP.md`.
*   [x] Rédiger le fichier `README.md` avec un design HTML/CSS et un tableau de bord de statut.

### Phase 2 : Développement du Backend (API REST en PHP/MySQL)
*   [x] **Base de Données** : Implémenter le schéma SQL complet (tables `users`, `categories`, `incidents`, `photos`, `comments`, `status_history`).
*   [x] **Authentification** : Développer les endpoints `POST /api/register` et `POST /api/login` avec un système de tokens JWT.
*   [x] **API Incidents** : Créer les endpoints CRUD pour les signalements (`GET /incidents`, `POST /incidents`, `GET /incidents/{id}`, `PUT /incidents/{id}`).
*   [x] **Gestion des Photos** : Implémenter la logique d'upload d'images lors de la création d'un signalement.
*   [x] **API Commentaires** : Créer les endpoints pour lire et ajouter des commentaires sur un signalement.
*   [x] **API Catégories** : Créer l'endpoint pour lister les catégories de signalement.
*   [x] **Sécurité** : Mettre en place la validation des données et la protection des endpoints par rôle (citoyen, agent, admin).

### Phase 3 : Développement de l'Application Mobile (React Native)
*   [x] **Initialisation** : Mettre en place le projet avec Expo, `react-navigation` et les dépendances (maps, camera, location).
*   [x] **Navigation** : Configurer les stacks de navigation pour les utilisateurs authentifiés et non-authentifiés.
*   [x] **Écrans d'Authentification** : Créer les composants pour les écrans de connexion et d'inscription.
*   [x] **Écran Carte** : Développer la carte interactive affichant les signalements géolocalisés.
*   [x] **Écran Création Signalement** : Développer le formulaire complet avec accès à la caméra, géolocalisation, et envoi des données à l'API.
*   [x] **Écran Mes Signalements** : Créer la liste des signalements de l'utilisateur avec leur statut.
*   [x] **Écran Détail Signalement** : Afficher les informations complètes d'un signalement et la section des commentaires.
*   [x] **Gestion d'état** : Implémenté via React Context (AuthContext) et hooks locaux — architecture légère et suffisante pour la v1.

### Phase 4 : Développement du Back-Office (Interface Web d'Administration)
*   [x] **Framework** : Routeur PHP natif (`admin/index.php`) avec dispatch par paramètre GET, sans dépendance externe.
*   [x] **Tableau de Bord** : KPIs (total, en attente, en cours, résolus), graphiques Chart.js (courbe 14j, donut catégories), tableau des 10 derniers signalements.
*   [x] **Gestion des Signalements** : Liste paginée avec filtres multi-critères, page détail avec galerie photos, changement de statut + priorité, notes internes, historique timeline.
*   [x] **Gestion des Utilisateurs** : Liste filtrée, création d'agents, activation/désactivation, changement de rôle (citoyen/agent/admin).
*   [x] **Statistiques** : 5 KPIs (total, ouverts, résolus, taux résolution, délai moyen), 4 graphiques Chart.js, top 5 zones. Filtre période (7/30/90/365 jours).
*   [x] **Carte Admin** : Vue cartographique Leaflet.js de tous les signalements avec popups et légende.
*   [x] **Gestion Catégories** : CRUD des catégories avec couleur, service responsable, activation/désactivation.

### Phase 5 : Tests et Déploiement
*   [x] **Tests API** : PHPUnit 10.5 installé. 12 tests unitaires (JWT, validation, coordonnées, statuts) — 28 assertions, 100% passés. 2 suites d'intégration (auth + incidents) avec 13 scénarios couvrant les cas nominaux et d'erreur.
*   [x] **Tests Mobiles** : Guide complet rédigé (`docs/GUIDE_TESTS_MOBILES.md`) — 45 scénarios couvrant authentification, création de signalement, carte, liste et détail. Checklist iOS + Android, tests de performance et d'accessibilité.
*   [x] **Déploiement Backend** : Guide complet rédigé (`docs/GUIDE_DEPLOIEMENT_SERVEUR.md`) — installation LAMP, configuration Apache VirtualHost SSL, script `deploy.sh` automatisé avec sauvegarde BDD, Fail2Ban, cron backup.
*   [x] **Build Mobile** : Guide complet rédigé (`docs/GUIDE_BUILD_MOBILE_EAS.md`) — configuration `eas.json` (dev/preview/production), builds Android (APK + AAB) et iOS (IPA), soumission automatisée, mises à jour OTA.

### Phase 6 : Documentation et Finalisation v1.0
*   [x] **Documentation API** : Spécification OpenAPI 3.0 complète (`docs/api/openapi.yaml`) + page HTML interactive Redoc (`docs/api/index.html`) couvrant tous les endpoints (Auth, Catégories, Incidents, Commentaires) avec schémas, exemples et sécurité JWT.
*   [x] **Nettoyage** : Suppression des `.gitkeep` inutiles, des logs et fichiers temporaires. Dépôt propre et cohérent.
*   [x] **Commit Final** : Tag `v1.0.0` créé et poussé sur GitHub. Projet officiellement livré en version stable.

---

## 🚀 Version 1.1 — Améliorations Prioritaires

> Branche : `feature/v1.1-ameliorations` | Démarrage : 04 Mars 2026

### Amélioration 1 : Système de Vote "Moi aussi" ✅
*   [x] **Migration SQL** : Table `votes` (user_id, incident_id, UNIQUE) + colonne `votes_count` dans `incidents`.
*   [x] **Backend PHP** : `backend/api/votes.php` — POST (voter), DELETE (retirer vote), GET (état du vote).
*   [x] **Composant Mobile** : `mobile/src/components/VoteButton.tsx` — bouton animé avec compteur, état persisté.
*   [x] **Intégration API** : `api_additions.ts` — `voteForIncident()`, `removeVote()`, `getVotes()`.

### Amélioration 2 : Mode Hors-Ligne ✅
*   [x] **Migration SQL** : Table `offline_sync_log` pour traçabilité des synchronisations.
*   [x] **Service Queue** : `mobile/src/services/OfflineQueue.ts` — AsyncStorage, détection réseau, retry auto (3 tentatives).
*   [x] **Composant Bannière** : `mobile/src/components/OfflineBanner.tsx` — indicateur visuel + bouton sync manuelle.
*   [x] **Intégration** : Formulaire de création signalement redirige vers queue si hors-ligne.

### Amélioration 3 : Notifications Push ✅
*   [x] **Migration SQL** : Tables `push_tokens` et `notifications`.
*   [x] **Backend PHP** : `backend/api/notifications.php` — enregistrement token, liste, marquer lu/tous lus.
*   [x] **Service Push** : `backend/config/PushNotificationService.php` — envoi via Expo Push API, gestion des erreurs.
*   [x] **Service Mobile** : `mobile/src/services/NotificationService.ts` — permissions, enregistrement token, listeners.
*   [x] **Écran Notifications** : `mobile/src/screens/NotificationsScreen.tsx` — liste, badge, navigation vers signalement.

---

## 📊 État Actuel

| Version | Statut | Date |
|---------|--------|------|
| v1.0.0  | ✅ Stable | 27 Fév 2026 |
| v1.1.0  | ✅ Stable | 04 Mars 2026 |
| v1.2.0  | ✅ Stable | 04 Mars 2026 |

---

## ✅ Version 1.2 — Livrée

> Branche : `main` | Livraison : 04 Mars 2026 | Tag : `v1.2.0`

### Thème 1 : Modernisation Technique et Sécurité ✅

| Ticket | Titre | Fichiers créés / modifiés | Statut |
|---|---|---|---|
| **TECH-01** | Refactorisation Backend PHP OO | `backend/core/BaseController.php`, `backend/controllers/AuthController.php`, `backend/controllers/IncidentController.php`, `backend/controllers/CategoryController.php`, `backend/index.php` (routeur OO) | ✅ |
| **SEC-01** | Sécurité renforcée (CSRF, XSS, rate limiting) | `backend/core/Security.php` — sanitisation, headers HTTP, rate limiting, validation centralisée | ✅ |
| **SEC-02** | RBAC avec permissions fines | `backend/core/Permissions.php` — matrice de permissions par rôle (citizen/agent/admin), intégré dans tous les contrôleurs | ✅ |

### Thème 2 : Expérience Utilisateur (Citoyen) ✅

| Ticket | Titre | Fichiers créés / modifiés | Statut |
|---|---|---|---|
| **UX-01** | Recherche et filtres avancés | `mobile/src/screens/MyIncidentsScreen.tsx` — barre de recherche, filtres dépliables, tri (date/votes/màj), debounce 400ms | ✅ |
| **UX-02** | Édition d'un signalement | `mobile/src/screens/EditIncidentScreen.tsx` (nouveau), `backend/controllers/IncidentController.php` — PATCH `/incidents/{id}` (propriétaire + statut=submitted) | ✅ |
| **UX-03** | Écran Mon Profil | `mobile/src/screens/ProfileScreen.tsx` (nouveau) — 3 onglets : profil, mot de passe, préférences notifs. `authApi.getProfile/updateProfile/changePassword` | ✅ |

### Thème 3 : Outils Admin ✅

| Ticket | Titre | Fichiers créés / modifiés | Statut |
|---|---|---|---|
| **ADMIN-01** | Tableau de bord analytique enrichi | `admin/pages/stats.php` — 7 KPIs (votes, citoyens actifs), carte de chaleur horaire, graphique soumis vs résolus, top 5 votés, export CSV | ✅ |
| **ADMIN-02** | CRUD Catégories complet | `admin/pages/categories.php` — création, édition inline, activation/désactivation, suppression sécurisée, icône emoji, stats votes | ✅ |
| **ADMIN-03** | Filtres avancés liste incidents | `admin/pages/incidents.php` — filtres date (from/to), tri par votes/date, export CSV, colonnes votes et titre | ✅ |

### Thème 4 : Améliorations Générales ✅

| Ticket | Titre | Fichiers créés | Statut |
|---|---|---|---|
| **I18N-01** | Internationalisation | `mobile/src/i18n/fr.json` (100+ clés), `mobile/src/i18n/i18n.ts` (service `t()` avec interpolation) | ✅ |

### Migration SQL v1.2

Exécuter sur le serveur de production :
```sql
-- Colonnes profil utilisateur (préférences notifications)
ALTER TABLE users
  ADD COLUMN notification_status_change  TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN notification_new_comment    TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN notification_vote_milestone TINYINT(1) NOT NULL DEFAULT 0;

-- Colonne icône catégorie
ALTER TABLE categories ADD COLUMN icon VARCHAR(10) NOT NULL DEFAULT '📌' AFTER name;
```

---

## 📓 Journal des Décisions (Decision Log)

*   **2026-02-26**: Décision d'utiliser **React Native avec Expo** pour le développement mobile afin de mutualiser le code pour iOS et Android, réduisant ainsi le temps et les coûts de développement.
*   **2026-02-26**: Décision d'utiliser l'authentification par **JWT (JSON Web Tokens)** pour sécuriser l'API REST, une méthode standard et robuste pour les applications mobiles.
*   **2026-02-26**: Le dépôt GitHub est créé en mode **privé** pour protéger la propriété intellectuelle du projet durant sa phase de développement.
*   **2026-03-04**: Décision d'utiliser **Expo Push Notifications** (via l'API Expo Push) plutôt que Firebase FCM directement, afin de simplifier l'intégration cross-platform et éviter la gestion de certificats APNs/FCM séparément.
*   **2026-03-04**: Décision d'utiliser **AsyncStorage** pour la queue hors-ligne (plutôt que SQLite) pour rester dans l'écosystème Expo sans module natif supplémentaire. Migration possible vers SQLite si le volume de données l'exige.
*   **2026-03-04**: Le système de vote utilise une contrainte **UNIQUE (user_id, incident_id)** en base pour garantir l'idempotence — un citoyen ne peut voter qu'une fois par signalement, même en cas de double-clic ou de retry réseau.
*   **2026-03-04 (v1.2)**: Décision d'adopter une **architecture OO pour le backend PHP** (contrôleurs + `BaseController` + `Security` + `Permissions`) sans framework externe, pour rester déployable sur hébergements mutualisés standard.
*   **2026-03-04 (v1.2)**: Le **RBAC** est implémenté via une matrice statique dans `Permissions.php` plutôt qu'une table BDD, pour éviter une requête supplémentaire à chaque appel API. Migration vers BDD possible en v1.3 si les rôles deviennent configurables.
*   **2026-03-04 (v1.2)**: L'**internationalisation** utilise un simple fichier `fr.json` + service `t()` maison, sans bibliothèque i18n tierce (ex: i18next), pour limiter les dépendances. Extension multi-langue prévue en v1.3 si besoin créole guérillais.
