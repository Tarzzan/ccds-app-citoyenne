# Présentation : Application Mobile CCDS Citoyen
## Contenu des slides

---

## Slide 1 — Couverture (Title Slide)
**Titre :** CCDS Citoyen — Application Mobile
**Sous-titre :** Signalez, suivez, améliorez votre commune
**Détails :**
- Icône 🏛️ et nom de l'application en grand
- Tagline : "Votre commune, votre voix"
- Version 1.0.0 — iOS & Android
- Fond dégradé bleu profond (#1e3a5f → #3b82f6)
- Style moderne, épuré, institutionnel

---

## Slide 2 — Vue d'ensemble de l'application
**Titre :** Une application citoyenne complète en 5 écrans clés
**Contenu :**
L'application CCDS Citoyen offre un parcours utilisateur fluide et intuitif, de l'authentification jusqu'au suivi en temps réel des signalements. Développée en React Native (Expo), elle fonctionne nativement sur iOS et Android avec une seule base de code.

**Points clés :**
- Authentification sécurisée avec tokens JWT
- Carte interactive des signalements en temps réel
- Création de signalement en moins de 60 secondes
- Suivi personnalisé de chaque signalement
- Notifications de mise à jour de statut

**Design :** Afficher les 5 maquettes en rangée horizontale miniature pour donner un aperçu global du flux de l'application.

---

## Slide 3 — Écran de Connexion
**Titre :** Connexion sécurisée et onboarding simplifié
**Image principale :** mockup_01_login.png (centré, grande taille)
**Contenu :**
L'écran d'accueil combine identité visuelle forte et formulaire épuré. Le dégradé bleu institutionnel renforce la crédibilité de l'application auprès des citoyens.

**Fonctionnalités :**
- Authentification par email + mot de passe (bcrypt + JWT)
- Lien "Mot de passe oublié" avec réinitialisation par email
- Accès direct à la création de compte citoyen
- Session persistante via SecureStore (pas de reconnexion à chaque ouverture)

---

## Slide 4 — Carte Interactive
**Titre :** Visualiser tous les signalements de la commune en un coup d'œil
**Image principale :** mockup_02_carte.png (centré, grande taille)
**Contenu :**
La carte est le cœur de l'application. Elle affiche en temps réel l'ensemble des signalements de la commune avec un code couleur intuitif par statut, permettant aux citoyens de voir l'état d'avancement des problèmes signalés.

**Fonctionnalités :**
- Carte OpenStreetMap interactive (zoom, déplacement)
- Marqueurs colorés : 🟡 En attente · 🔵 En cours · 🟢 Résolu
- Popups avec titre et catégorie du signalement
- Bouton FAB "+" pour créer un signalement depuis la carte
- Barre de navigation inférieure persistante

---

## Slide 5 — Création d'un Signalement
**Titre :** Signaler un problème en moins de 60 secondes
**Image principale :** mockup_03_creation.png (centré, grande taille)
**Contenu :**
Le formulaire de création est conçu pour être le plus rapide possible. La géolocalisation est automatique, la catégorie est sélectionnable en un tap, et la photo peut être prise directement depuis l'application. Le citoyen n'a qu'à décrire le problème.

**Fonctionnalités :**
- Photo depuis la caméra ou la galerie (upload multipart sécurisé)
- Sélecteur de catégorie (Voirie, Éclairage, Espaces verts, Propreté, Mobilier...)
- Description libre du problème
- GPS automatique avec géocodage inverse (adresse lisible)
- Envoi en un tap avec confirmation

---

## Slide 6 — Mes Signalements
**Titre :** Suivre l'avancement de chaque signalement en temps réel
**Image principale :** mockup_04_mes_signalements.png (centré, grande taille)
**Contenu :**
L'écran "Mes signalements" offre une vue personnalisée de tous les signalements du citoyen. Les filtres rapides permettent de retrouver instantanément les signalements par statut, et le pull-to-refresh assure des données toujours à jour.

**Fonctionnalités :**
- Liste paginée de tous les signalements du citoyen
- Filtres rapides par statut (chips horizontaux)
- Carte de signalement avec icône de catégorie, titre, adresse et badge de statut
- Pull-to-refresh et infinite scroll
- Accès au détail par appui sur une carte

---

## Slide 7 — Détail d'un Signalement
**Titre :** Transparence totale sur le traitement de chaque signalement
**Image principale :** mockup_05_detail.png (centré, grande taille)
**Contenu :**
L'écran de détail est la vitrine de la transparence municipale. Le citoyen peut voir exactement où en est son signalement, qui l'a pris en charge, et lire les réponses officielles des agents. Cette transparence renforce la confiance entre la mairie et les citoyens.

**Fonctionnalités :**
- Galerie de photos soumises (défilement horizontal)
- Informations complètes (catégorie, adresse, date, description)
- Timeline chronologique de l'historique des statuts
- Commentaires publics des agents municipaux
- Possibilité d'ajouter un commentaire ou une précision

---

## Slide 8 — Architecture Technique
**Titre :** Une architecture robuste, sécurisée et évolutive
**Contenu :**
L'application repose sur un stack technologique éprouvé et moderne, garantissant performance, sécurité et maintenabilité à long terme.

**Stack technique :**

| Couche | Technologie | Rôle |
|---|---|---|
| Application Mobile | React Native + Expo | iOS & Android natif |
| Langage | TypeScript | Typage strict et fiabilité |
| Navigation | React Navigation v6 | Gestion des écrans |
| Authentification | JWT + SecureStore | Sécurité des sessions |
| Carte | React Native Maps | Carte interactive |
| Caméra | Expo Camera | Prise de photo |
| GPS | Expo Location | Géolocalisation |
| API | REST PHP/MySQL | Backend serveur |

---

## Slide 9 — Roadmap & Prochaines Étapes
**Titre :** Des évolutions planifiées pour enrichir l'expérience citoyenne
**Contenu :**
La version 1.0 pose les bases solides de l'application. La roadmap prévoit des fonctionnalités enrichissant l'engagement citoyen et l'efficacité opérationnelle des services municipaux.

**Prochaines fonctionnalités (v1.1 — v2.0) :**
- Notifications push en temps réel (statut mis à jour)
- Mode hors-ligne avec synchronisation différée
- Système de vote citoyen (signaler un problème déjà signalé)
- Tableau de bord citoyen avec statistiques personnelles
- Intégration avec les systèmes de gestion municipale existants
- Module de satisfaction post-résolution (note 1 à 5 étoiles)

---

## Slide 10 — Conclusion
**Titre :** CCDS Citoyen — La commune à portée de main
**Contenu :**
L'application CCDS Citoyen transforme le rapport entre les habitants et leur commune. En donnant aux citoyens un outil simple, rapide et transparent pour signaler les problèmes, elle renforce la démocratie participative locale et améliore la qualité de vie de tous.

**Chiffres clés du projet :**
- 5 écrans mobiles développés
- 2 317 lignes de TypeScript
- 14 dépendances Expo/React Native
- Compatible iOS 13+ et Android 8+
- Déploiement via Expo EAS (App Store + Google Play)

**Call to action :** Rejoignez le projet sur GitHub : github.com/Tarzzan/ccds-app-citoyenne
