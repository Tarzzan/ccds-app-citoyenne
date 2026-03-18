# Recette MVP - Citoyen Agent Admin

Date : 18 mars 2026

## Objectif

Valider rapidement la boucle minimale de service public local :

- un citoyen signale
- un agent traite et valide l'execution
- un administrateur supervise

## Comptes locaux de reference

- `admin@macommune.local` / `Admin@MaCommune2026!` : Administrateur Ma Commune
- `agent@macommune.local` / `Agent@MaCommune2026!` : Agent Terrain Ma Commune
- comptes citoyens de demonstration : generes par `bash scripts/seed_demo_local.sh`
- mot de passe citoyen seed : `Citoyen@MaCommune2026!`

## Verification automatisee disponible

Avant une recette manuelle, lancer :

```bash
bash scripts/audit_local_predeploy.sh
```

Ce script verifie localement :

- typecheck mobile
- build Android release
- categories API
- surfaces publiques `status` et `api-docs`
- boucle citoyen -> agent -> admin
- login admin web
- votes
- commentaires
- notifications
- photos incident
- publications communaute via admin web

## Parcours 1 - Citoyen

1. Ouvrir l'application Android.
2. Configurer le serveur local :
   `http://127.0.0.1:8080/api`
3. Se connecter avec un compte seed `@macommune.local` ou creer un compte citoyen.
4. Creer un signalement avec categorie, description, position et photo.
5. Verifier que le dossier apparait dans `Mes signalements`.
6. Verifier que l'ecran `Mon bilan citoyen` charge sans erreur.
7. Verifier l'acces a `Consultations` et `Agenda communal`.
8. Verifier qu'une notification apparait apres changement de statut ou commentaire agent.

## Parcours 2 - Agent

1. Se connecter avec `agent@macommune.local`.
2. Ouvrir l'onglet `A traiter`.
3. Verifier que `File territoriale` liste les dossiers du territoire.
4. Ouvrir un signalement.
5. Passer le dossier en `En cours` ou `Resolu`.
6. Ajouter une note ou un commentaire public de traitement.
7. Si le dossier est termine, utiliser `Valider l'execution`.
8. Verifier que le citoyen peut ensuite lire la notification et rouvrir le detail.

## Parcours 3 - Admin Web

1. Ouvrir `http://127.0.0.1:8080/admin/?page=login`
2. Se connecter avec `admin@macommune.local` / `Admin@MaCommune2026!`
3. Verifier le dashboard sur tablette.
4. Ouvrir le signalement traite par l'agent.
5. Verifier l'historique de statut et la note.
6. Ouvrir `Sondages` puis `Événements`.
7. Verifier qu'un changement de statut depuis le web genere aussi une notification citoyenne coherente.

## Definition de passe

La recette est validee si :

- le citoyen voit son dossier dans `Mes signalements`
- le citoyen peut ouvrir `Mon bilan citoyen` sans erreur
- le citoyen peut consulter une consultation active et un rendez-vous communal
- l'agent voit la file territoriale et peut mettre a jour le statut
- le citoyen recoit une notification exploitable avec retour possible vers le dossier
- la validation d'execution apparait dans l'historique
- l'admin voit le meme dossier et le meme historique dans le web
- l'admin peut publier ou superviser la couche communaute sans incoherence visible
