# Galerie Visuelle — CCDS App Citoyenne

> **Générée le :** 26 Février 2026 | **Version :** 1.0.0 | **Outil :** Playwright + Python/Pillow

Cette galerie présente l'ensemble des interfaces du projet CCDS, organisées en deux sections : le **Back-Office Web d'Administration** et l'**Application Mobile Citoyenne** (iOS & Android).

---

## 🖥️ Back-Office Web d'Administration

Le back-office est une interface web PHP accessible aux agents municipaux et aux administrateurs. Il permet de gérer l'intégralité du cycle de vie des signalements citoyens.

---

### 1. Tableau de Bord Principal

> Vue d'ensemble en temps réel : KPIs, courbe d'activité sur 14 jours, répartition par catégorie et derniers signalements.

![Tableau de bord](screenshots/admin/admin_01_dashboard.png)

**Fonctionnalités visibles :**
- 4 cartes KPI : Total (247), En attente (12), En cours (34), Résolus (189)
- Graphique linéaire Chart.js des signalements des 14 derniers jours
- Graphique donut de répartition par catégorie
- Tableau des 10 derniers signalements avec statuts et priorités

---

### 2. Liste des Signalements

> Interface de gestion avec filtres multi-critères, pagination et accès rapide à chaque signalement.

![Liste des signalements](screenshots/admin/admin_02_incidents.png)

**Fonctionnalités visibles :**
- Filtres combinables : statut, catégorie, priorité, recherche textuelle
- Tableau paginé (20 signalements par page) avec tri par colonnes
- Badges de statut colorés et indicateurs de priorité
- Bouton d'accès rapide au détail de chaque signalement

---

### 3. Détail d'un Signalement

> Interface de traitement complète : informations, photos, changement de statut, notes internes et historique.

![Détail signalement](screenshots/admin/admin_03_incident_detail.png)

**Fonctionnalités visibles :**
- Informations complètes du signalement (référence, catégorie, priorité, description)
- Galerie de photos soumises par le citoyen
- Panel d'actions : changement de statut et de priorité
- Ajout de commentaires publics et notes internes (agents uniquement)
- Timeline chronologique de l'historique des statuts

---

### 4. Statistiques

> Tableaux de bord analytiques avec 5 KPIs, 4 graphiques Chart.js et classements par zone et catégorie.

![Statistiques](screenshots/admin/admin_04_statistiques.png)

**Fonctionnalités visibles :**
- KPIs : Total, taux de résolution (76.5%), délai moyen (4.2j), citoyens inscrits, satisfaction
- Graphique barres : signalements par mois
- Graphique camembert : répartition par statut
- Top 5 zones les plus signalées avec barres de progression
- Performance par catégorie (taux de résolution et délai moyen)

---

### 5. Carte Interactive

> Vue cartographique Leaflet.js de tous les signalements avec marqueurs colorés par statut et popups.

![Carte interactive](screenshots/admin/admin_05_carte.png)

**Fonctionnalités visibles :**
- Carte OpenStreetMap interactive avec zoom et déplacement
- Marqueurs colorés selon le statut (jaune=attente, bleu=cours, vert=résolu)
- Popups avec titre, catégorie et lien "Traiter"
- Compteurs en temps réel par statut
- Légende des couleurs

---

## 📱 Application Mobile Citoyenne

L'application mobile est développée en React Native (Expo) et fonctionne nativement sur iOS et Android. Les captures ci-dessous sont présentées dans une maquette iPhone pour un rendu réaliste.

---

### 6. Écran de Connexion

> Interface d'authentification avec design dégradé, formulaire sécurisé et accès à l'inscription.

![Connexion](screenshots/mockups/mockup_01_login.png)

**Fonctionnalités visibles :**
- Logo et identité visuelle de l'application
- Formulaire email + mot de passe avec validation
- Lien "Mot de passe oublié"
- Bouton de connexion avec dégradé bleu
- Accès à la création de compte

---

### 7. Carte Interactive Mobile

> Vue cartographique des signalements avec marqueurs colorés, bouton FAB de création et barre de navigation.

![Carte mobile](screenshots/mockups/mockup_02_carte.png)

**Fonctionnalités visibles :**
- Carte OpenStreetMap plein écran
- Marqueurs colorés par statut avec popups
- Légende compacte en superposition
- Bouton FAB "+" pour créer un signalement
- Barre de navigation inférieure (Carte / Mes signalements / Profil)

---

### 8. Création d'un Signalement

> Formulaire complet avec prise de photo, sélection de catégorie, description et localisation GPS automatique.

![Création signalement](screenshots/mockups/mockup_03_creation.png)

**Fonctionnalités visibles :**
- Aperçu de la photo prise (caméra ou galerie)
- Boutons "Caméra" et "Galerie" pour joindre une photo
- Sélecteur de catégorie (Voirie, Éclairage, Espaces verts...)
- Zone de description libre
- Localisation GPS automatique avec adresse géocodée
- Bouton d'envoi avec dégradé bleu

---

### 9. Mes Signalements

> Liste paginée des signalements du citoyen avec filtres par statut, pull-to-refresh et accès au détail.

![Mes signalements](screenshots/mockups/mockup_04_mes_signalements.png)

**Fonctionnalités visibles :**
- En-tête avec compteur total de signalements
- Filtres rapides par statut (chips horizontaux)
- Cartes de signalement avec icône de catégorie, titre, adresse et badge de statut
- Indicateur de date de signalement
- Navigation vers le détail par appui sur une carte

---

### 10. Détail d'un Signalement

> Vue complète d'un signalement : photos, informations, historique des statuts et commentaires publics.

![Détail signalement mobile](screenshots/mockups/mockup_05_detail.png)

**Fonctionnalités visibles :**
- Galerie de photos horizontale défilante
- Informations détaillées (catégorie, adresse, date, description)
- Timeline de l'historique des statuts
- Section commentaires publics avec réponses des agents
- Bouton d'ajout de commentaire

---

## 📊 Récapitulatif des Interfaces

| # | Interface | Type | Plateforme |
|---|---|---|---|
| 1 | Tableau de bord | Back-Office | Web |
| 2 | Liste des signalements | Back-Office | Web |
| 3 | Détail signalement | Back-Office | Web |
| 4 | Statistiques | Back-Office | Web |
| 5 | Carte interactive | Back-Office | Web |
| 6 | Connexion | Application | iOS & Android |
| 7 | Carte interactive | Application | iOS & Android |
| 8 | Création signalement | Application | iOS & Android |
| 9 | Mes signalements | Application | iOS & Android |
| 10 | Détail signalement | Application | iOS & Android |

---

*Galerie générée automatiquement par le pipeline de documentation visuelle CCDS.*
*Outil : Playwright (captures headless) + Python/Pillow (maquettes smartphone)*
