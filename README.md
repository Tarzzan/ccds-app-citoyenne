<div align="center">

<br/>

<img src="https://img.shields.io/badge/version-0.1.0--alpha-blue?style=for-the-badge" alt="Version"/>
<img src="https://img.shields.io/badge/statut-En%20Développement-orange?style=for-the-badge" alt="Statut"/>
<img src="https://img.shields.io/badge/licence-Privé-red?style=for-the-badge" alt="Licence"/>
<img src="https://img.shields.io/badge/plateforme-iOS%20%7C%20Android-lightgrey?style=for-the-badge&logo=react" alt="Plateforme"/>

<br/><br/>

<h1>🏙️ CCDS — Application Citoyenne de Signalement</h1>

<p><em>Signalez. Suivez. Améliorez votre commune.</em></p>

</div>

---

## 🎯 Présentation du Projet

**CCDS** (Commune Citoyenne De Signalement) est une solution numérique complète permettant aux habitants d'une commune de signaler facilement des anomalies dans l'espace public — un trou dans la chaussée, un luminaire en panne, un espace vert non entretenu — directement depuis leur smartphone, en joignant une photo et en géolocalisant le problème.

Les signalements sont transmis en temps réel à un serveur central qui les dispatche automatiquement aux services municipaux compétents. Les agents peuvent ensuite prendre en charge, traiter et clôturer les incidents depuis un back-office dédié, tandis que le citoyen suit l'évolution de sa demande.

---

## 📊 Tableau de Bord de l'Avancement

<div align="center">

<table>
<thead>
<tr>
<th>Phase</th>
<th>Titre</th>
<th>Statut</th>
<th>Avancement</th>
</tr>
</thead>
<tbody>
<tr>
<td align="center"><b>1</b></td>
<td>Initialisation &amp; Architecture</td>
<td align="center">🟢 En cours</td>
<td align="center">
<img src="https://progress-bar.xyz/80/?title=80%25&width=120&color=3b82f6" alt="80%"/>
</td>
</tr>
<tr>
<td align="center"><b>2</b></td>
<td>Backend — API REST PHP/MySQL</td>
<td align="center">⚪ À venir</td>
<td align="center">
<img src="https://progress-bar.xyz/0/?title=0%25&width=120&color=6b7280" alt="0%"/>
</td>
</tr>
<tr>
<td align="center"><b>3</b></td>
<td>Application Mobile React Native</td>
<td align="center">⚪ À venir</td>
<td align="center">
<img src="https://progress-bar.xyz/0/?title=0%25&width=120&color=6b7280" alt="0%"/>
</td>
</tr>
<tr>
<td align="center"><b>4</b></td>
<td>Back-Office Web d'Administration</td>
<td align="center">⚪ À venir</td>
<td align="center">
<img src="https://progress-bar.xyz/0/?title=0%25&width=120&color=6b7280" alt="0%"/>
</td>
</tr>
<tr>
<td align="center"><b>5</b></td>
<td>Tests &amp; Déploiement</td>
<td align="center">⚪ À venir</td>
<td align="center">
<img src="https://progress-bar.xyz/0/?title=0%25&width=120&color=6b7280" alt="0%"/>
</td>
</tr>
<tr>
<td align="center"><b>6</b></td>
<td>Documentation &amp; Finalisation</td>
<td align="center">⚪ À venir</td>
<td align="center">
<img src="https://progress-bar.xyz/0/?title=0%25&width=120&color=6b7280" alt="0%"/>
</td>
</tr>
</tbody>
</table>

> **Dernière mise à jour :** 26 Février 2026 — **Phase active :** 1 / 6

</div>

---

## 🏗️ Architecture Technique

Le projet repose sur un stack technique éprouvé, pensé pour la robustesse et la maintenabilité.

<div align="center">

| Composant | Technologie | Rôle |
|---|---|---|
| **Serveur** | Apache + PHP 8+ | Hébergement de l'API et du back-office |
| **Base de Données** | MySQL 8 | Stockage des utilisateurs, signalements et médias |
| **API** | REST + JWT | Communication sécurisée entre le serveur et les clients |
| **Application Mobile** | React Native (Expo) | Application iOS et Android depuis une base de code unique |
| **Back-Office** | PHP + HTML/CSS/JS | Interface de gestion pour les agents municipaux |

</div>

---

## 📁 Structure du Projet

```
ccds-app-citoyenne/
│
├── 📂 backend/                 # Serveur PHP
│   ├── 📂 api/                 # Endpoints de l'API REST
│   ├── 📂 config/              # Configuration BDD et constantes
│   └── 📂 uploads/             # Stockage des photos uploadées
│
├── 📂 mobile/                  # Application React Native (Expo)
│   └── 📂 src/
│       ├── 📂 screens/         # Composants des écrans
│       ├── 📂 components/      # Composants réutilisables
│       ├── 📂 navigation/      # Configuration de la navigation
│       └── 📂 services/        # Appels à l'API (fetch)
│
├── 📂 docs/                    # Documentation technique
│
├── 📄 ROADMAP.md               # Feuille de route détaillée (source de vérité)
└── 📄 README.md                # Ce fichier
```

---

## ✨ Fonctionnalités Clés

<div align="center">

| Pour le Citoyen | Pour les Agents Municipaux |
|---|---|
| 📸 Photo du problème depuis l'app | 📋 Tableau de bord de tous les signalements |
| 📍 Géolocalisation automatique | 🔀 Dispatching par catégorie et service |
| 🗂️ Choix de la catégorie (voirie, espaces verts...) | ✅ Changement de statut des incidents |
| 🔔 Notifications de suivi en temps réel | 💬 Commentaires internes et publics |
| 🗺️ Carte interactive des signalements | 📊 Statistiques et tableaux de bord |
| 📜 Historique de mes signalements | 👥 Gestion des comptes agents |

</div>

---

## 🔑 Catégories de Signalement

Le système supporte les catégories suivantes, extensibles depuis le back-office :

`🛣️ Voirie & Chaussée` &nbsp; `💡 Éclairage Public` &nbsp; `🌿 Espaces Verts` &nbsp; `🗑️ Propreté & Déchets` &nbsp; `🚧 Mobilier Urbain` &nbsp; `🌊 Réseaux & Inondations` &nbsp; `🚦 Signalisation` &nbsp; `🏚️ Bâtiments Communaux`

---

## 🚀 Démarrage Rapide (Pour les Développeurs)

### Prérequis

- PHP 8.1+, MySQL 8+, Apache avec `mod_rewrite` activé
- Node.js 18+, `npm` ou `yarn`
- Expo CLI (`npm install -g expo-cli`)

### Installation du Backend

```bash
# 1. Cloner le dépôt
git clone https://github.com/Tarzzan/ccds-app-citoyenne.git
cd ccds-app-citoyenne/backend

# 2. Configurer la base de données
# Importer le fichier docs/database.sql dans votre MySQL
# Copier et renseigner le fichier de configuration
cp config/config.example.php config/config.php

# 3. Configurer Apache pour pointer vers le dossier backend/
```

### Installation de l'Application Mobile

```bash
cd ccds-app-citoyenne/mobile

# Installer les dépendances
npm install

# Lancer en mode développement
npx expo start
```

---

## 🤖 Note pour l'Agent IA

> Ce dépôt est géré de manière autonome par un agent IA. En cas de reprise de mission, la **première action impérative** est de lire le fichier [`ROADMAP.md`](./ROADMAP.md) pour connaître l'état exact du projet et la prochaine tâche à accomplir. Ce fichier est la seule source de vérité.

---

<div align="center">

<br/>

Développé avec ❤️ pour améliorer la vie citoyenne · Projet **CCDS** · 2026

</div>
