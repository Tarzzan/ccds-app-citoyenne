# Matrice De Readiness - Ma Commune

Date : 18 mars 2026

## Lecture

Cette matrice sert a decider rapidement ce qui est :

- `PRET LOCAL`
- `PRET AVEC RISQUE`
- `A REJOUER SUR TABLETTE`
- `NON PRET POUR LIVRAISON FINALE`

## Produit Et Marque

| Domaine | Etat | Commentaire |
|---|---|---|
| Nom produit `Ma Commune` | `PRET LOCAL` | surfaces visibles prioritaires alignees |
| Logo / icone / splash | `PRET AVEC RISQUE` | assets locaux remplaces, mais validation finale appareil non rejouee |
| Positionnement commercial | `PRET LOCAL` | strategie et narratif clarifies |
| Documentation active | `PRET LOCAL` | README et dossier de livraison structurent l'etat reel |
| Controle automatique des residus de marque | `PRET LOCAL` | bloque la reintroduction de l'ancienne nomenclature dans la couche active |
| Archives historiques `CCDS` | `PRET AVEC RISQUE` | encore presentes dans `docs/`, mais requalifiees comme archives |

## Mobile Citoyen

| Domaine | Etat | Commentaire |
|---|---|---|
| Configuration serveur | `PRET LOCAL` | testee et persistante |
| Inscription / connexion | `PRET LOCAL` | audit vert |
| Creation de signalement | `PRET LOCAL` | photo, position et categorie verifies |
| Consultation du detail | `PRET LOCAL` | historique et commentaires verifies |
| `Mon bilan citoyen` | `PRET LOCAL` | charge sans erreur |
| Notifications | `PRET LOCAL` | status, commentaire, event verifies localement |
| Consultations flash | `PRET LOCAL` | choix unique seulement |
| Agenda communal | `PRET LOCAL` | listing + RSVP verifies localement |
| Build Android cible tablette par ABI reelle | `PRET LOCAL` | APK dedie genere pour la validation appareil, `armeabi-v7a` sur la tablette locale |
| Rendu final tablette | `A REJOUER SUR TABLETTE` | non rejoue apres consolidation |

## Agent Et Admin

| Domaine | Etat | Commentaire |
|---|---|---|
| Login agent | `PRET LOCAL` | verifie |
| File territoriale | `PRET LOCAL` | verifiee dans recette |
| Changement de statut | `PRET LOCAL` | API et admin web verifies |
| Commentaire public agent | `PRET LOCAL` | verifie |
| Dashboard admin | `PRET LOCAL` | blocs signalement + communaute presents |
| Publications admin `Sondages` / `Événements` | `PRET LOCAL` | verifiees |
| Cohérence web -> notifications citoyennes | `PRET LOCAL` | verifiee |

## Backend Et Infra Locale

| Domaine | Etat | Commentaire |
|---|---|---|
| API REST | `PRET LOCAL` | audit vert |
| Base de donnees locale | `PRET LOCAL` | compatibilites schema locales corrigees |
| Upload photos | `PRET LOCAL` | upload / liste / suppression verifies |
| Export RGPD | `PRET LOCAL` | verifie |
| Audit logs CSV | `PRET LOCAL` | verifie |
| Webhooks | `PRET LOCAL` | verifie |
| Surfaces publiques `status` / `api-docs` | `PRET LOCAL` | servies et controlees |

## Demonstration Et Livraison

| Domaine | Etat | Commentaire |
|---|---|---|
| Seed de demonstration | `PRET LOCAL` | comptes citoyens `@macommune.local` |
| Credentials de demonstration | `PRET LOCAL` | comptes seed et comptes metier alignes sur `@macommune.local` |
| Mode operatoire demo | `PRET LOCAL` | documente |
| Dossier de livraison locale | `PRET LOCAL` | synthese `GO local / NO GO tablette finale` |
| APK cible tablette | `PRET LOCAL` | reference a privilegier au lieu de l'APK universel pour la phase appareil |
| Livraison finale appareil | `NON PRET POUR LIVRAISON FINALE` | validation tablette non rejouee |

## Decision

Decision courante :

- `GO` demonstration locale
- `GO` preparation phase tablette
- `NO GO` livraison finale appareil

## Pour Passer A `GO` Appareil

Il faut encore :

1. construire l'APK cible tablette pour l'ABI reelle de la tablette
2. installer uniquement cet APK sur la tablette cible
3. rejouer le parcours citoyen complet
4. rejouer le parcours agent complet
5. rejouer le parcours admin web en lien avec l'appareil
6. verifier logo, splash, configuration serveur et notifications sur l'appareil reel
7. figer les credentials definitifs de demonstration
