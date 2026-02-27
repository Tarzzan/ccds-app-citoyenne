# Présentation : Application Mobile CCDS Citoyen — Guyane
## Contenu des slides

---

## Slide 1 — Couverture (Title Slide)
**Titre :** CCDS Citoyen — Application Mobile
**Sous-titre :** Signalez, suivez, améliorez votre commune — Kourou · Sinnamary · Iracoubo
**Détails :**
- Icône 🏛️ et nom de l'application en grand
- Tagline : "Votre commune, votre voix"
- Version 1.0.0 — iOS & Android — Guyane Française
- Fond dégradé vert forêt amazonienne (#0f4c2a → #1a7a42)
- Style moderne, épuré, institutionnel avec une touche tropicale

---

## Slide 2 — Vue d'ensemble de l'application
**Titre :** Une application citoyenne pour le territoire des Savanes
**Contenu :**
L'application CCDS Citoyen offre un parcours utilisateur fluide et intuitif, adapté aux spécificités de la Guyane. Elle couvre l'ensemble des communes de la Communauté de Communes des Savanes (Kourou, Sinnamary, Iracoubo, Saint-Élie).

**Points clés :**
- Cartographie complète du territoire (OpenStreetMap)
- Signalement géolocalisé précis (même en zone rurale)
- Suivi en temps réel par les services techniques communaux
- Interface optimisée pour une utilisation en extérieur (contraste élevé)
- Notifications de prise en charge et de résolution

**Design :** Afficher les 5 maquettes en rangée horizontale miniature pour donner un aperçu global du flux de l'application.

---

## Slide 3 — Écran de Connexion
**Titre :** Connexion sécurisée et identité territoriale
**Image principale :** mockup_01_login.png (centré, grande taille)
**Contenu :**
L'écran d'accueil ancre immédiatement l'application dans son territoire avec une identité visuelle "CCDS Guyane". La connexion est simplifiée pour encourager l'adoption par tous les citoyens.

**Fonctionnalités :**
- Authentification sécurisée (email + mot de passe)
- Création de compte rapide pour les résidents
- Reconnaissance automatique de la commune de résidence
- Session persistante pour un accès immédiat en cas d'urgence

---

## Slide 4 — Carte Interactive
**Titre :** Visualiser les signalements de Kourou à Sinnamary
**Image principale :** mockup_02_carte.png (centré, grande taille)
**Contenu :**
La carte interactive permet de visualiser l'état des infrastructures sur tout le territoire. Les citoyens peuvent voir les signalements autour de chez eux, que ce soit au quartier Savane à Kourou ou sur l'Avenue Élie Castor à Sinnamary.

**Fonctionnalités :**
- Carte fluide centrée sur la position GPS de l'utilisateur
- Marqueurs colorés par statut (🟡 En attente · 🔵 En cours · 🟢 Résolu)
- Filtrage possible par commune ou par type d'incident
- Bouton d'action rapide pour signaler un problème sur place

---

## Slide 5 — Création d'un Signalement
**Titre :** Signaler un problème en moins de 60 secondes
**Image principale :** mockup_03_creation.png (centré, grande taille)
**Contenu :**
Le formulaire est conçu pour être ultra-rapide. Exemple concret : un nid-de-poule sur l'Avenue Gaston Monnerville. La photo et la géolocalisation suffisent souvent à décrire le problème.

**Fonctionnalités :**
- Prise de photo directe (preuve visuelle indispensable)
- Catégorisation simple (Voirie, Éclairage, Espaces verts...)
- Géolocalisation automatique précise (lat/long)
- Détection automatique de l'adresse (Reverse Geocoding)
- Envoi optimisé même avec une connexion 4G faible

---

## Slide 6 — Mes Signalements
**Titre :** Suivre l'avancement de vos demandes
**Image principale :** mockup_04_mes_signalements.png (centré, grande taille)
**Contenu :**
Chaque citoyen dispose d'un tableau de bord personnel. Il peut suivre l'évolution de ses signalements, de la prise en compte par la mairie jusqu'à la résolution finale sur le terrain.

**Fonctionnalités :**
- Liste chronologique des signalements effectués
- Badges de statut clairs et colorés
- Détail de l'adresse et de la catégorie
- Mise à jour en temps réel (Pull-to-refresh)
- Historique conservé pour référence

---

## Slide 7 — Détail d'un Signalement
**Titre :** Transparence et dialogue avec les services techniques
**Image principale :** mockup_05_detail.png (centré, grande taille)
**Contenu :**
L'écran de détail favorise la transparence. Le citoyen voit les réponses des agents (ex: "Intervention planifiée semaine prochaine") et comprend que sa demande est traitée.

**Fonctionnalités :**
- Galerie photos avant/après
- Fil d'actualité du traitement (Timeline)
- Commentaires officiels des services techniques (CCDS ou Mairie)
- Possibilité de relancer ou de remercier
- Clôture du signalement avec notification

---

## Slide 8 — Back-Office Administration
**Titre :** Un outil de pilotage puissant pour les mairies
**Image principale :** admin_01_dashboard.png (centré, grande taille)
**Contenu :**
Côté mairie, un tableau de bord complet permet de piloter les interventions. Les services techniques de Kourou et Sinnamary disposent d'une vue d'ensemble et d'outils de gestion fine.

**Fonctionnalités :**
- Vue globale des signalements par commune
- Statistiques de performance (délai de résolution)
- Carte de chaleur des incidents (zones prioritaires)
- Gestion des équipes d'intervention
- Export des données pour rapports mensuels

---

## Slide 9 — Architecture & Sécurité
**Titre :** Une solution robuste et souveraine
**Contenu :**
L'application repose sur des technologies standards et sécurisées, garantissant la protection des données des citoyens guyanais.

**Stack technique :**
- **Mobile :** React Native + Expo (iOS/Android)
- **Backend :** PHP 8.1 + API REST
- **Base de données :** MySQL (hébergement local possible)
- **Cartographie :** OpenStreetMap (Souveraineté des données)
- **Sécurité :** Chiffrement SSL + Hachage des mots de passe

---

## Slide 10 — Conclusion
**Titre :** CCDS Citoyen — L'innovation au service du territoire
**Contenu :**
Cette application rapproche les citoyens de leurs élus et améliore concrètement le cadre de vie en Guyane. Elle modernise l'action publique et renforce le lien social.

**Prochaines étapes :**
- Déploiement pilote sur Kourou (Q2 2026)
- Extension à Sinnamary et Iracoubo (Q3 2026)
- Campagne de communication "Ma ville, j'en prends soin"

**Call to action :** Découvrez le projet sur GitHub : github.com/Tarzzan/ccds-app-citoyenne
