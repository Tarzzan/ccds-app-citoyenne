# Guide de Tests Mobiles — CCDS App Citoyenne

> **Version :** 1.0 | **Date :** Février 2026 | **Plateformes :** iOS 15+ / Android 10+

Ce guide décrit les procédures de tests manuels à effectuer sur l'application mobile avant chaque déploiement en production. Les tests sont organisés par fonctionnalité et doivent être exécutés sur un appareil physique (non simulateur) pour chaque plateforme.

---

## 1. Environnement de Test

### Appareils recommandés

| Plateforme | Appareil minimum | OS minimum | Résolution |
|---|---|---|---|
| iOS | iPhone SE (2e gen) | iOS 15.0 | 375 × 667 pt |
| iOS | iPhone 14 Pro | iOS 16.0 | 393 × 852 pt |
| Android | Samsung Galaxy A32 | Android 10 | 720 × 1600 px |
| Android | Google Pixel 6 | Android 12 | 1080 × 2400 px |

### Configuration préalable

Avant de commencer les tests, s'assurer que :

- L'URL de l'API de test est correctement configurée dans `mobile/src/services/api.ts`
- La base de données de test est initialisée avec les fixtures (`tests/Fixtures/test_database.sql`)
- Les permissions de l'application sont réinitialisées sur chaque appareil
- La connexion réseau est active (Wi-Fi ou 4G)

---

## 2. Checklist de Tests — Authentification

### 2.1 Inscription

| # | Scénario | Étapes | Résultat attendu | iOS | Android |
|---|---|---|---|---|---|
| A01 | Inscription valide | Remplir tous les champs correctement → Appuyer sur "S'inscrire" | Redirection vers l'écran principal, token stocké | ☐ | ☐ |
| A02 | Email déjà utilisé | Tenter de s'inscrire avec un email existant | Message d'erreur "Email déjà utilisé" | ☐ | ☐ |
| A03 | Mot de passe trop court | Saisir un mot de passe < 6 caractères | Validation inline rouge sous le champ | ☐ | ☐ |
| A04 | Champs vides | Appuyer sur "S'inscrire" sans remplir les champs | Tous les champs en erreur, pas d'appel API | ☐ | ☐ |
| A05 | Email invalide | Saisir "notanemail" | Validation inline, champ en rouge | ☐ | ☐ |

### 2.2 Connexion

| # | Scénario | Étapes | Résultat attendu | iOS | Android |
|---|---|---|---|---|---|
| B01 | Connexion valide | Saisir email + mot de passe corrects → Connexion | Accès à l'écran principal | ☐ | ☐ |
| B02 | Mauvais mot de passe | Saisir un mot de passe incorrect | Message "Identifiants incorrects" | ☐ | ☐ |
| B03 | Email inexistant | Saisir un email non enregistré | Message "Identifiants incorrects" | ☐ | ☐ |
| B04 | Persistance session | Se connecter → Fermer l'app → Rouvrir | Toujours connecté (pas de re-login) | ☐ | ☐ |
| B05 | Déconnexion | Accéder au profil → Se déconnecter | Retour à l'écran de connexion, token effacé | ☐ | ☐ |

---

## 3. Checklist de Tests — Création de Signalement

| # | Scénario | Étapes | Résultat attendu | iOS | Android |
|---|---|---|---|---|---|
| C01 | Prise de photo (caméra) | Appuyer sur "Caméra" → Prendre une photo | Photo affichée dans l'aperçu | ☐ | ☐ |
| C02 | Sélection depuis galerie | Appuyer sur "Galerie" → Choisir une image | Image affichée dans l'aperçu | ☐ | ☐ |
| C03 | Refus permission caméra | Refuser la permission caméra → Appuyer sur "Caméra" | Message explicatif + lien vers Réglages | ☐ | ☐ |
| C04 | GPS automatique | Ouvrir l'écran de création | Coordonnées GPS remplies automatiquement | ☐ | ☐ |
| C05 | GPS désactivé | Désactiver la localisation → Ouvrir création | Message d'avertissement, champs GPS vides | ☐ | ☐ |
| C06 | Sélection de catégorie | Appuyer sur le sélecteur → Choisir "Voirie" | Catégorie sélectionnée affichée | ☐ | ☐ |
| C07 | Description obligatoire | Laisser la description vide → Envoyer | Validation, message d'erreur | ☐ | ☐ |
| C08 | Envoi complet valide | Remplir tous les champs → Envoyer | Succès, référence affichée, retour à la liste | ☐ | ☐ |
| C09 | Envoi hors connexion | Couper le réseau → Tenter d'envoyer | Message "Pas de connexion internet" | ☐ | ☐ |
| C10 | Indicateur de chargement | Appuyer sur "Envoyer" | Bouton désactivé + spinner pendant l'envoi | ☐ | ☐ |

---

## 4. Checklist de Tests — Carte Interactive

| # | Scénario | Étapes | Résultat attendu | iOS | Android |
|---|---|---|---|---|---|
| D01 | Affichage de la carte | Ouvrir l'onglet Carte | Carte OpenStreetMap chargée, marqueurs visibles | ☐ | ☐ |
| D02 | Marqueurs colorés | Observer les marqueurs | Couleurs différentes selon le statut | ☐ | ☐ |
| D03 | Appui sur un marqueur | Appuyer sur un marqueur | Callout avec titre, catégorie et statut | ☐ | ☐ |
| D04 | Navigation vers détail | Appuyer sur le callout | Ouverture de l'écran détail du signalement | ☐ | ☐ |
| D05 | Bouton FAB "Signaler" | Appuyer sur le bouton "+" | Ouverture de l'écran de création | ☐ | ☐ |
| D06 | Zoom et déplacement | Pincer pour zoomer, glisser pour déplacer | Carte réactive, marqueurs repositionnés | ☐ | ☐ |
| D07 | Carte hors connexion | Couper le réseau → Ouvrir la carte | Message d'erreur ou tuiles en cache | ☐ | ☐ |

---

## 5. Checklist de Tests — Mes Signalements

| # | Scénario | Étapes | Résultat attendu | iOS | Android |
|---|---|---|---|---|---|
| E01 | Liste des signalements | Ouvrir "Mes Signalements" | Liste de ses propres signalements | ☐ | ☐ |
| E02 | Pull-to-refresh | Tirer vers le bas sur la liste | Rechargement des données | ☐ | ☐ |
| E03 | Pagination (scroll infini) | Scroller jusqu'en bas | Chargement automatique de la page suivante | ☐ | ☐ |
| E04 | Filtre par statut | Appuyer sur un filtre de statut | Liste filtrée correctement | ☐ | ☐ |
| E05 | Liste vide | Compte sans signalement | Message "Aucun signalement" affiché | ☐ | ☐ |
| E06 | Badge de statut | Observer les cartes | Badge coloré selon le statut de chaque signalement | ☐ | ☐ |

---

## 6. Checklist de Tests — Détail d'un Signalement

| # | Scénario | Étapes | Résultat attendu | iOS | Android |
|---|---|---|---|---|---|
| F01 | Affichage complet | Ouvrir un signalement | Toutes les informations affichées | ☐ | ☐ |
| F02 | Galerie photos | Signalement avec photos | Photos affichées, appui pour agrandir | ☐ | ☐ |
| F03 | Historique des statuts | Ouvrir un signalement traité | Timeline de l'historique visible | ☐ | ☐ |
| F04 | Commentaires publics | Signalement avec commentaires | Commentaires listés chronologiquement | ☐ | ☐ |
| F05 | Ajout de commentaire | Saisir un commentaire → Envoyer | Commentaire ajouté en bas de liste | ☐ | ☐ |
| F06 | Commentaire vide | Appuyer sur "Envoyer" sans texte | Validation, pas d'envoi | ☐ | ☐ |

---

## 7. Tests de Performance et UX

| # | Critère | Méthode de mesure | Seuil acceptable |
|---|---|---|---|
| P01 | Temps de démarrage à froid | Chronométrer du tap icône à l'écran principal | < 3 secondes |
| P02 | Temps de chargement de la carte | Chronométrer l'affichage des marqueurs | < 2 secondes |
| P03 | Temps d'envoi d'un signalement | Chronométrer de "Envoyer" à la confirmation | < 5 secondes |
| P04 | Fluidité du scroll | Scroller rapidement dans la liste | Pas de saccades (60 fps) |
| P05 | Taille de l'APK/IPA | Vérifier la taille du build | < 50 Mo |

---

## 8. Tests d'Accessibilité

| # | Critère | iOS | Android |
|---|---|---|---|
| G01 | Taille de police dynamique (grande police) | ☐ | ☐ |
| G02 | Mode sombre | ☐ | ☐ |
| G03 | VoiceOver / TalkBack (navigation vocale) | ☐ | ☐ |
| G04 | Contraste des couleurs (WCAG AA) | ☐ | ☐ |

---

## 9. Rapport de Test

Compléter ce tableau après chaque cycle de test :

| Champ | Valeur |
|---|---|
| **Date** | |
| **Testeur** | |
| **Version de l'app** | |
| **Appareil iOS testé** | |
| **Appareil Android testé** | |
| **Tests passés** | / total |
| **Bugs critiques** | |
| **Bugs mineurs** | |
| **Décision** | ☐ Approuvé pour production ☐ Corrections requises |

---

*Document maintenu par l'équipe CCDS — Mettre à jour à chaque nouvelle fonctionnalité.*
