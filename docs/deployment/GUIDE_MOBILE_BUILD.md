# Guide de Build & Publication Mobile — CCDS Citoyen

> **Public Cible :** Développeur Mobile
> **Objectif :** Compiler l'application React Native avec Expo EAS, la publier sur les stores, et gérer les mises à jour.

---

## 1. Prérequis

Avant de commencer, assurez-vous d'avoir :

- Un compte développeur Apple (pour iOS) et un compte Google Play Console (pour Android).
- Un compte Expo : [expo.dev](https://expo.dev/)
- Node.js (LTS) et npm installés sur votre machine de développement.

### 1.1. Installation d'Expo EAS CLI

EAS (Expo Application Services) est la suite d'outils cloud pour les applications Expo. Installez son interface en ligne de commande :

```bash
npm install -g eas-cli
```

Connectez-vous à votre compte Expo :

```bash
eas login
```

---

## 2. Configuration du Projet

Le projet est déjà pré-configuré. Le fichier `eas.json` à la racine du dossier `mobile/` définit les profils de build.

**Fichier :** `mobile/eas.json`

```json
{
  "cli": {
    "version": ">= 5.9.3"
  },
  "build": {
    "development": {
      "developmentClient": true,
      "distribution": "internal"
    },
    "preview": {
      "distribution": "internal"
    },
    "production": {}
  },
  "submit": {
    "production": {}
  }
}
```

Ce fichier permet de créer des builds pour différents environnements. Nous utiliserons principalement le profil `production`.

---

## 3. Build de l'Application

Placez-vous dans le dossier `mobile/` du projet pour lancer les commandes de build.

### 3.1. Build Android (.apk / .aab)

Pour générer les fichiers pour le Google Play Store, lancez la commande suivante :

```bash
cd mobile/
eas build -p android --profile production
```

EAS vous guidera pour :
1.  Créer un nouveau Keystore ou en utiliser un existant (laissez EAS le générer et le gérer pour vous).
2.  Choisir le type de binaire : `.apk` (pour les tests directs) ou `.aab` (Android App Bundle, **recommandé pour la publication**).

Une fois le build terminé, EAS vous fournira un lien pour télécharger l'artefact.

### 3.2. Build iOS (.ipa)

Pour générer le fichier `.ipa` pour l'App Store, la commande est similaire :

```bash
cd mobile/
eas build -p ios --profile production
```

EAS vous demandera :
1.  Votre Apple ID (pour se connecter à votre compte développeur).
2.  De choisir votre Team Apple Developer.
3.  De créer un nouveau Provisioning Profile et un nouveau Distribution Certificate (laissez EAS s'en occuper).

Le processus de build iOS est plus long car il s'exécute sur des machines macOS dans le cloud.

---

## 4. Soumission aux Stores

Une fois les binaires générés, vous pouvez les soumettre aux plateformes.

### 4.1. Google Play Store

1.  Connectez-vous à la [Google Play Console](https://play.google.com/console).
2.  Créez une nouvelle application "CCDS Citoyen".
3.  Remplissez toutes les informations requises (description, captures d'écran, politique de confidentialité).
4.  Dans la section "Production", importez le fichier `.aab` généré par EAS.
5.  Déployez la version.

### 4.2. Apple App Store

EAS peut automatiser la soumission pour vous.

```bash
cd mobile/
eas submit -p ios --latest
```

Cette commande va :
1.  Télécharger le dernier build `.ipa` réussi.
2.  L'uploader sur App Store Connect.
3.  Vous devrez ensuite vous connecter à [App Store Connect](https://appstoreconnect.apple.com/) pour finaliser les informations de la version et la soumettre à la validation d'Apple.

---

## 5. Mises à Jour OTA (Over-the-Air)

L'un des grands avantages d'Expo est la possibilité de déployer des mises à jour (corrections de bugs, changements de texte ou d'images) sans avoir à refaire tout le processus de build et de soumission. C'est ce qu'on appelle une mise à jour OTA.

1.  Assurez-vous que vos modifications sont commitées et poussées sur GitHub.
2.  Créez une nouvelle mise à jour :

```bash
cd mobile/
eas update --branch production --message "Correction d'un bug d'affichage sur la carte"
```

Les utilisateurs recevront automatiquement la mise à jour la prochaine fois qu'ils ouvriront l'application.
