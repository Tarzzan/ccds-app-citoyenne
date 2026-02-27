# Guide de Build Mobile — Expo EAS (iOS & Android)

> **Outil :** Expo Application Services (EAS) | **Version Expo :** SDK 50+

Ce guide décrit la procédure complète pour générer les builds de production de l'application CCDS pour iOS (App Store) et Android (Google Play Store), en utilisant Expo Application Services (EAS Build).

---

## 1. Prérequis

### 1.1 Comptes nécessaires

| Service | Rôle | URL |
|---|---|---|
| **Expo** | Hébergement des builds EAS | [expo.dev](https://expo.dev) |
| **Apple Developer** | Distribution iOS (App Store) | [developer.apple.com](https://developer.apple.com) — 99 $/an |
| **Google Play Console** | Distribution Android | [play.google.com/console](https://play.google.com/console) — 25 $ unique |

### 1.2 Outils locaux

```bash
# Node.js 18+ requis
node --version

# Installer EAS CLI globalement
npm install -g eas-cli

# Se connecter à son compte Expo
eas login
eas whoami
```

---

## 2. Configuration du Projet

### 2.1 Mettre à jour `app.json`

Avant de builder, vérifier et compléter le fichier `mobile/app.json` :

```json
{
  "expo": {
    "name": "CCDS Citoyen",
    "slug": "ccds-app-citoyenne",
    "version": "1.0.0",
    "orientation": "portrait",
    "icon": "./assets/icon.png",
    "userInterfaceStyle": "light",
    "splash": {
      "image": "./assets/splash.png",
      "resizeMode": "contain",
      "backgroundColor": "#1d4ed8"
    },
    "ios": {
      "supportsTablet": false,
      "bundleIdentifier": "fr.ccds.app-citoyenne",
      "buildNumber": "1",
      "infoPlist": {
        "NSCameraUsageDescription": "CCDS utilise la caméra pour photographier les problèmes à signaler.",
        "NSPhotoLibraryUsageDescription": "CCDS accède à votre galerie pour joindre des photos à vos signalements.",
        "NSLocationWhenInUseUsageDescription": "CCDS utilise votre position pour localiser précisément votre signalement.",
        "NSLocationAlwaysAndWhenInUseUsageDescription": "CCDS utilise votre position pour localiser précisément votre signalement."
      }
    },
    "android": {
      "adaptiveIcon": {
        "foregroundImage": "./assets/adaptive-icon.png",
        "backgroundColor": "#1d4ed8"
      },
      "package": "fr.ccds.app_citoyenne",
      "versionCode": 1,
      "permissions": [
        "CAMERA",
        "READ_EXTERNAL_STORAGE",
        "WRITE_EXTERNAL_STORAGE",
        "ACCESS_FINE_LOCATION",
        "ACCESS_COARSE_LOCATION"
      ]
    },
    "plugins": [
      ["expo-camera",   { "cameraPermission": "CCDS utilise la caméra pour photographier les problèmes." }],
      ["expo-location", { "locationAlwaysAndWhenInUsePermission": "CCDS utilise votre position pour localiser le signalement." }],
      "expo-image-picker"
    ],
    "extra": {
      "eas": {
        "projectId": "VOTRE_PROJECT_ID_EXPO"
      }
    }
  }
}
```

### 2.2 Configurer les variables d'environnement de production

```bash
cd mobile/

# Créer le fichier .env.production
cat > .env.production << EOF
API_BASE_URL=https://votre-domaine.fr/api
APP_ENV=production
EOF
```

### 2.3 Créer le fichier `eas.json`

```bash
cd mobile/
```

Créer `mobile/eas.json` :

```json
{
  "cli": {
    "version": ">= 7.0.0"
  },
  "build": {
    "development": {
      "developmentClient": true,
      "distribution": "internal",
      "ios": { "simulator": true },
      "android": { "buildType": "apk" }
    },
    "preview": {
      "distribution": "internal",
      "ios": { "simulator": false },
      "android": { "buildType": "apk" },
      "env": {
        "API_BASE_URL": "https://staging.votre-domaine.fr/api"
      }
    },
    "production": {
      "distribution": "store",
      "ios": { "buildConfiguration": "Release" },
      "android": { "buildType": "app-bundle" },
      "env": {
        "API_BASE_URL": "https://votre-domaine.fr/api"
      }
    }
  },
  "submit": {
    "production": {
      "ios": {
        "appleId": "votre-apple-id@email.com",
        "ascAppId": "VOTRE_APP_STORE_CONNECT_APP_ID",
        "appleTeamId": "VOTRE_TEAM_ID"
      },
      "android": {
        "serviceAccountKeyPath": "./google-service-account.json",
        "track": "production"
      }
    }
  }
}
```

---

## 3. Build Android (Google Play Store)

### 3.1 Initialiser EAS dans le projet

```bash
cd mobile/
eas build:configure
```

### 3.2 Build de test interne (APK)

```bash
# Génère un APK installable directement sur un appareil
eas build --platform android --profile preview
```

Une fois le build terminé, télécharger l'APK depuis le tableau de bord Expo ou via le lien fourni dans le terminal.

### 3.3 Build de production (AAB pour le Play Store)

```bash
# Génère un Android App Bundle (.aab) pour le Play Store
eas build --platform android --profile production
```

### 3.4 Soumettre au Google Play Store

**Prérequis :** Créer un compte de service Google Play et télécharger le fichier JSON.

```bash
# Soumission automatique
eas submit --platform android --profile production

# Ou manuellement :
# 1. Télécharger le .aab depuis expo.dev
# 2. Aller sur play.google.com/console
# 3. Production → Créer une nouvelle version → Importer le .aab
```

---

## 4. Build iOS (App Store)

### 4.1 Prérequis Apple

Avant de builder pour iOS, s'assurer d'avoir :

- Un compte Apple Developer actif (99 $/an)
- Un identifiant d'application créé sur [developer.apple.com](https://developer.apple.com)
- Un certificat de distribution et un profil de provisionnement (EAS les gère automatiquement)

### 4.2 Build de test (Simulateur)

```bash
# Build pour simulateur iOS (ne nécessite pas de compte Apple Developer)
eas build --platform ios --profile development
```

### 4.3 Build de production (IPA pour l'App Store)

```bash
# EAS gère automatiquement les certificats et profils de provisionnement
eas build --platform ios --profile production
```

EAS vous demandera vos identifiants Apple lors du premier build. Il créera et gérera automatiquement :
- Le certificat de distribution iOS
- Le profil de provisionnement App Store

### 4.4 Soumettre à l'App Store Connect

```bash
# Soumission automatique via EAS Submit
eas submit --platform ios --profile production

# Ou manuellement :
# 1. Télécharger le .ipa depuis expo.dev
# 2. Utiliser Transporter (macOS) ou altool pour uploader
# 3. Compléter les métadonnées sur App Store Connect
```

---

## 5. Build des Deux Plateformes Simultanément

```bash
# Lancer les deux builds en parallèle (économise du temps)
eas build --platform all --profile production
```

---

## 6. Checklist Avant Soumission

### Android (Google Play)

- [ ] `versionCode` incrémenté dans `app.json`
- [ ] `version` mise à jour (ex: "1.0.1")
- [ ] Captures d'écran préparées (min. 2 par format)
- [ ] Description de l'app rédigée en français
- [ ] Politique de confidentialité publiée et URL renseignée
- [ ] Icône 512×512 px préparée
- [ ] Bannière Feature Graphic 1024×500 px préparée
- [ ] Build testé sur un appareil physique Android

### iOS (App Store)

- [ ] `buildNumber` incrémenté dans `app.json`
- [ ] `version` mise à jour
- [ ] Captures d'écran préparées (iPhone 6.5" et 5.5")
- [ ] Description de l'app rédigée en français
- [ ] Politique de confidentialité publiée et URL renseignée
- [ ] Icône 1024×1024 px sans transparence préparée
- [ ] Informations de contact du support renseignées
- [ ] Build testé sur un appareil physique iOS
- [ ] Déclaration de confidentialité (App Privacy) complétée sur App Store Connect

---

## 7. Mises à Jour (OTA — Over The Air)

Expo permet de pousser des mises à jour JavaScript sans passer par les stores, pour les changements mineurs :

```bash
# Publier une mise à jour OTA (sans rebuild natif)
eas update --branch production --message "Correction bug formulaire signalement"
```

> **Important :** Les mises à jour OTA ne fonctionnent que pour les changements JavaScript/TypeScript. Tout changement dans les permissions, les plugins natifs ou `app.json` nécessite un nouveau build complet.

---

## 8. Numérotation des Versions

Adopter la convention **Semantic Versioning** :

| Champ | Signification | Exemple |
|---|---|---|
| `version` (ex: `1.2.3`) | Version affichée aux utilisateurs | `1.0.0` → `1.0.1` |
| `versionCode` (Android) | Entier croissant, jamais décroissant | `1`, `2`, `3`… |
| `buildNumber` (iOS) | Chaîne croissante | `"1"`, `"2"`, `"3"`… |

---

*Document maintenu par l'équipe CCDS — Mettre à jour à chaque nouvelle version de l'application.*
