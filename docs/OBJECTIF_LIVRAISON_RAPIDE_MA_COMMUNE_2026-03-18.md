# Objectif Concret De Livraison Rapide - Ma Commune

Date : 18 mars 2026

## Cible Produit

`Ma Commune` ne doit pas chercher a sortir comme une plateforme municipale complete des la premiere version.

La bonne premiere livraison est :

> une application Android en francais pour Kourou, reliee a un back-office web responsive, permettant a un citoyen de signaler un probleme local et a un agent municipal de confirmer son traitement jusqu'a validation d'execution.

## Promesse Produit V1

`Je signale un probleme utile a la commune, je vois qu'il est pris en charge, et un agent peut confirmer son execution.`

Cette promesse est concrete, verifiable et livrable vite.

## MVP Recommande

### Cote Citoyen

- creer un compte
- configurer le serveur
- consulter la carte centree sur Kourou
- creer un signalement avec categorie, description, position et photo
- suivre ses signalements
- lire les changements de statut

### Cote Agent

- se connecter avec un role `agent`
- consulter la file des signalements
- ouvrir un signalement
- le qualifier
- changer son statut
- commenter l'action menee
- valider l'execution du traitement

### Cote Administration

- conserver le back-office web
- le rendre confortable sur tablette et smartphone
- gerer utilisateurs, categories et supervision
- voir les incidents, leurs statuts et leur historique

## Ce Qu Il Faut Reporter

- multilingue
- sondages et evenements avances
- analyse predictive
- gamification poussee
- fonctions "smart city" non indispensables au premier usage
- complexite multi-communes

## Cible De Livraison

- territoire limite a `Kourou`
- langue unique `francais`
- plateforme prioritaire `Android`
- administration principale via `web responsive`
- une seule base d'authentification avec roles :
  - `citizen`
  - `agent`
  - `admin`

## Definition De Fini

La premiere livraison est prete si les 5 parcours suivants passent sans intervention technique :

1. un citoyen installe l'application et configure le serveur
2. il cree un compte puis envoie un signalement
3. un agent ouvre le signalement, le passe en cours et valide l'execution
4. le citoyen voit le changement de statut
5. un administrateur supervise le flux depuis le web sur tablette

## Decision Produit

La bonne ambition n'est pas :

`faire beaucoup de choses`

La bonne ambition est :

`faire circuler proprement une preuve de service public local entre citoyen, agent et commune`

Si cette boucle marche vite, le produit a une base reelle. Le reste pourra s'ajouter sans deriver.
