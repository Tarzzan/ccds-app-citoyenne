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
*   [ ] **Initialisation** : Mettre en place le projet avec Expo, `react-navigation` et les dépendances (maps, camera, location).
*   [ ] **Navigation** : Configurer les stacks de navigation pour les utilisateurs authentifiés et non-authentifiés.
*   [ ] **Écrans d'Authentification** : Créer les composants pour les écrans de connexion et d'inscription.
*   [ ] **Écran Carte** : Développer la carte interactive affichant les signalements géolocalisés.
*   [ ] **Écran Création Signalement** : Développer le formulaire complet avec accès à la caméra, géolocalisation, et envoi des données à l'API.
*   [ ] **Écran Mes Signalements** : Créer la liste des signalements de l'utilisateur avec leur statut.
*   [ ] **Écran Détail Signalement** : Afficher les informations complètes d'un signalement et la section des commentaires.
*   [ ] **Gestion d'état** : Intégrer un gestionnaire d'état (ex: Redux Toolkit) pour les données utilisateur et les signalements.

### Phase 4 : Développement du Back-Office (Interface Web d'Administration)
*   [ ] **Framework** : Choisir et mettre en place un micro-framework PHP ou un simple routeur pour le back-office.
*   [ ] **Tableau de Bord** : Créer une vue listant tous les signalements avec des filtres et des options de tri.
*   [ ] **Gestion des Signalements** : Permettre aux agents de changer le statut d'un signalement et d'ajouter des commentaires internes.
*   [ ] **Gestion des Utilisateurs** : Interface pour gérer les comptes des agents municipaux.
*   [ ] **Statistiques** : Développer une page d'analytiques simples (nombre de signalements par jour, par catégorie, etc.).

### Phase 5 : Tests et Déploiement
*   [ ] **Tests API** : Écrire des tests unitaires et d'intégration pour les endpoints critiques de l'API.
*   [ ] **Tests Mobiles** : Effectuer des tests manuels complets sur les deux plateformes (iOS et Android).
*   [ ] **Déploiement Backend** : Préparer le serveur Apache/PHP et déployer l'API.
*   [ ] **Build Mobile** : Générer les builds de production de l'application mobile via Expo Application Services (EAS).

### Phase 6 : Documentation et Finalisation
*   [ ] **Documentation API** : Générer une documentation claire pour tous les endpoints de l'API (ex: avec Swagger/OpenAPI).
*   [ ] **Commit Final** : S'assurer que tout le code est propre, commenté et poussé sur GitHub.
*   [ ] **Nettoyage** : Supprimer les fichiers et logs inutiles.

---

##  trạng thái hiện tại (Current State)

*   **ID de la Phase Actuelle** : `3`
*   **Statut** : 🟢 En cours
*   **Prochaine Étape** : Initialiser le projet React Native (Expo), configurer la navigation et développer les écrans de l'application mobile.

## 📓 Journal des Décisions (Decision Log)

*   **2026-02-26**: Décision d'utiliser **React Native avec Expo** pour le développement mobile afin de mutualiser le code pour iOS et Android, réduisant ainsi le temps et les coûts de développement.
*   **2026-02-26**: Décision d'utiliser l'authentification par **JWT (JSON Web Tokens)** pour sécuriser l'API REST, une méthode standard et robuste pour les applications mobiles.
*   **2026-02-26**: Le dépôt GitHub est créé en mode **privé** pour protéger la propriété intellectuelle du projet durant sa phase de développement.
