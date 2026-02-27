# Feuille de Route (Roadmap) - Projet CCDS

Ce document est la source de vérité unique pour le développement de l'application citoyenne de signalement (CCDS). Il sert de guide pour l'agent IA et de suivi pour le commanditaire du projet.

## 🎯 Objectif Global (Goal)

Développer une solution complète (application mobile iOS/Android et back-office web) permettant aux citoyens de signaler des anomalies dans l'espace public de leur commune, et aux services municipaux de recevoir, gérer et traiter ces signalements de manière efficace.

## 🗺️ Phases du Projet

Le projet est découpé en 6 phases principales, chacune avec des objectifs clairs.

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

### Phase 6 : Documentation et Finalisation
*   [ ] **Documentation API** : Générer une documentation claire pour tous les endpoints de l'API (ex: avec Swagger/OpenAPI).
*   [ ] **Commit Final** : S'assurer que tout le code est propre, commenté et poussé sur GitHub.
*   [ ] **Nettoyage** : Supprimer les fichiers et logs inutiles.

---

##  trạng thái hiện tại (Current State)

*   **ID de la Phase Actuelle** : `6`
*   **Statut** : 🟢 En cours
*   **Prochaine Étape** : Documentation API Swagger/OpenAPI, nettoyage du code, commit final et publication.

## 📓 Journal des Décisions (Decision Log)

*   **2026-02-26**: Décision d'utiliser **React Native avec Expo** pour le développement mobile afin de mutualiser le code pour iOS et Android, réduisant ainsi le temps et les coûts de développement.
*   **2026-02-26**: Décision d'utiliser l'authentification par **JWT (JSON Web Tokens)** pour sécuriser l'API REST, une méthode standard et robuste pour les applications mobiles.
*   **2026-02-26**: Le dépôt GitHub est créé en mode **privé** pour protéger la propriété intellectuelle du projet durant sa phase de développement.
