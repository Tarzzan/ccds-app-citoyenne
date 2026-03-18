# Audit De Reprise Produit & UX

Date : 18 mars 2026

## Objectif Réel Du Projet

`Ma Commune` n'est pas seulement une application de signalement. Son vrai rôle est de devenir une infrastructure de confiance entre habitants, agents municipaux et territoire.

Le produit doit permettre :
- de signaler vite un problème concret de voirie, propreté, éclairage ou sécurité
- de rendre visible le traitement public de ce signalement
- de transformer la participation citoyenne en relation durable avec la commune

## Constat Actuel

Le dépôt contient déjà une base fonctionnelle large :
- backend API, mobile, back-office admin
- auth, signalements, votes, commentaires, notifications, hors-ligne
- début de gamification et de tableau de bord citoyen

Mais le produit reste encore trop générique sur trois points :
- le récit : l'app parle de "signalement" mais pas encore assez de devoir citoyen, de soin du territoire et de continuité du service public
- l'identité : la direction visuelle reste proche d'une app municipale standard, sans ancrage Guyane suffisamment assumé
- la hiérarchie UX : plusieurs écrans sont fonctionnels, mais n'expriment pas clairement ce qui est prioritaire pour un citoyen sur le terrain

## Forces

- Le socle métier est déjà pertinent.
- Le projet prend en compte les contraintes de terrain : hors-ligne, photo, géolocalisation.
- La boucle citoyen -> traitement -> suivi existe déjà.
- Le back-office et le mobile partagent un langage métier cohérent.

## Faiblesses

- Trop de statuts "livrés" dans la documentation par rapport à la réalité observée du dépôt.
- Plusieurs incohérences de schéma entre code et base ont ralenti la stabilisation.
- L'expérience visuelle n'aide pas encore à percevoir la valeur publique du produit.
- La carte et le dashboard ne racontent pas encore assez l'impact collectif.

## Ambition Recommandée

Faire évoluer le produit de "formulaire de plainte" vers "plateforme de vigilance civique territoriale".

Positionnement recommandé :
- une application de service public de proximité
- pensée pour la Guyane et ses réalités de distance, de réseau, de climat et de diversité communale
- centrée sur l'utilité concrète, la clarté de traitement et la fierté citoyenne

## Décision De Repositionnement

Le projet doit maintenant assumer un nom plus simple, plus public et plus transmissible :

- nom produit : `Ma Commune`
- promesse : permettre à chaque habitant de prendre part à l'entretien, au suivi et à l'amélioration visible de son cadre de vie
- ton : civique, clair, digne, local

Ce repositionnement est meilleur que l'ancienne marque `CCDS` pour trois raisons :

- il est compréhensible immédiatement sans explication institutionnelle
- il peut vivre dans plusieurs communes sans perdre son sens
- il porte mieux l'idée de responsabilité partagée entre habitants et service public

## Principes Produit

- Terrain d'abord : l'utilisateur doit pouvoir agir vite, en mobilité, sans effort inutile.
- Preuve de traitement : chaque action doit renforcer la confiance dans la chaîne de prise en charge.
- Représentation locale : l'interface doit évoquer la Guyane sans folklore décoratif.
- Devoir citoyen : l'app doit valoriser l'entretien du commun, pas seulement la déclaration de problème.
- Sobriété robuste : l'app doit rester lisible, stable et rassurante même dans des contextes imparfaits.

## Direction UX Recommandée

- Palette inspirée du territoire : forêt, fleuve, latérite, lumière chaude, fonds sable/mist.
- Langage éditorial plus civique : "agir", "veiller", "protéger", "suivre", "servir le quartier".
- Marque plus incarnée : `Ma Commune` doit évoquer une plateforme utile de proximité, pas un sigle administratif.
- Logo à reprendre entièrement : symbole principal fondé sur la carte de la Guyane, travaillé comme un signe institutionnel contemporain et non comme un clipart.
- Hiérarchie plus claire :
  - entrée en confiance
  - action principale immédiate
  - preuves d'impact personnel et collectif
- Écrans clés à traiter en premier :
  - onboarding
  - connexion / inscription
  - carte / liste d'incidents
  - création de signalement
  - dashboard citoyen

## Priorités De Reprise

### P0

- Stabiliser les écarts schéma/code restants côté admin et backend
- Fiabiliser les parcours critiques : login, création, détail, changement de statut
- Réduire les hypothèses implicites dans le mobile

### P1

- Renommer le produit en `Ma Commune` sur les surfaces visibles prioritaires
- Définir une nouvelle identité visuelle officielle Guyane + service public
- Refaire la direction de marque mobile
- Clarifier le récit produit dans les écrans d'entrée et d'engagement
- Aligner la documentation avec l'état réel du dépôt

### P2

- Introduire une vraie carte native avec lecture territoriale plus forte
- Déployer un système d'illustration et d'icones cohérent avec le nouveau logo
- Ajouter des parcours civiques : campagnes locales, priorités communales, signalements saisonniers
- Développer un mode "preuves de service public" pour les agents et les élus

## Prochaine Marche D'Ambition

La meilleure version de `Ma Commune` ne doit pas seulement aider a signaler.
Elle doit devenir :

- un tableau de bord citoyen de la qualite du cadre de vie
- une interface de confiance entre habitants, agents et elus
- une vitrine moderne du devoir citoyen en Guyane
- une base territoriale adaptable a plusieurs communes sans perdre son ancrage initial a Kourou

Voir aussi : `docs/TODO_REPRISE_MA_COMMUNE_2026-03-18.md`

## Vision Cible

La bonne version de `Ma Commune` n'est pas "une app de plus pour signaler un trou".
La bonne version de `Ma Commune` est un outil de coordination civique de proximité, crédible, local, utile et identifiable comme un service public guyanais moderne.
