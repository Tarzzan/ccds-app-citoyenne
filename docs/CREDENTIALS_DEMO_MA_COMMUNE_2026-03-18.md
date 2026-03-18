# Credentials De Demonstration - Ma Commune

Date : 18 mars 2026

## Statut

Ces credentials sont prepares pour la demonstration locale et la future validation tablette.

Ils ne valent pas encore livraison finale sur appareil tant que la phase tablette n'a pas ete rejouee apres l'audit local.

## Comptes Metier Locaux

### Administration

- email : `admin@macommune.local`
- mot de passe : `admin@test.fr`
- role : `admin`
- identite visible attendue : `Administrateur Ma Commune`

### Terrain

- email : `agent@macommune.local`
- mot de passe : `agent@test.fr`
- role : `agent`
- identite visible attendue : `Agent Terrain Ma Commune`

## Comptes Citoyens

Les comptes citoyens de demonstration sont generes par :

```bash
bash scripts/seed_demo_local.sh
```

Le script affiche trois comptes citoyens seedes, tous avec le meme mot de passe.

Format des emails :

- `demo.citoyen.a.<timestamp>@macommune.local`
- `demo.citoyen.b.<timestamp>@macommune.local`
- `demo.citoyen.c.<timestamp>@macommune.local`

Mot de passe commun :

- `Citoyen@MaCommune2026!`

## URLs Locales

### API mobile

- `http://127.0.0.1:8080/api`

### Admin web

- `http://127.0.0.1:8080/admin/?page=login`

## Precautions

- ces credentials sont strictement destines a la recette locale
- ils devront etre remplaces ou regeneres avant toute diffusion hors environnement de dev
- la phase finale devra verifier que les noms affiches en interface correspondent bien a `Ma Commune`
