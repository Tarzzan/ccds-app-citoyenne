# TODO De Reprise - Ma Commune

Date : 18 mars 2026

## Decision Produit

Le projet change de nom et doit devenir `Ma Commune`.

Ce changement implique plus qu'un renommage :

- nouvelle promesse produit
- nouvelle identite visuelle
- nouveau niveau d'ambition UX
- nouveau recit de service public local

## Vision Renforcee

`Ma Commune` doit devenir une plateforme civique territoriale de proximite.

Le produit cible doit permettre :

- de signaler rapidement un besoin concret du territoire
- de suivre visiblement la prise en charge publique
- de faire ressentir que chaque contribution participe a l'entretien du commun
- de rendre la commune plus lisible, plus proche et plus accountable

## Chantiers Prioritaires

### P0 - Cadrage De Marque

- Valider officiellement le nouveau nom `Ma Commune`
- Remplacer progressivement les derniers residus `CCDS`, `CCDS Citoyen` et `MaCommune` hors archives historiques par une nomenclature unique `Ma Commune`
- Definir un vocabulaire produit stable :
  - `Ma Commune`
  - `espace citoyen`
  - `service public local`
  - `suivi du territoire`
- Conserver une distinction technique si necessaire entre nom produit et identifiants internes

### P0 - Logo

- Concevoir un logo principal fonde sur la carte de la Guyane
- Exiger un rendu de qualite :
  - vectoriel
  - simple
  - lisible en petit
  - exploitable en icone mobile, splash, admin, documentation et favicon
- Eviter :
  - clipart
  - silhouette brute sans travail graphique
  - folklore decoratif
  - surcharge institutionnelle
- Produire au minimum :
  - logo principal
  - monogramme / app icon
  - version monochrome
  - zone de protection et tailles minimales

### P0 - Structure D'Ambition Produit

- Recentrer le produit autour du triptyque :
  - signaler
  - suivre
  - servir le territoire
- Faire passer l'app de "outil de signalement" a "interface de responsabilite civique"
- Clarifier la cible prioritaire :
  - habitants de Kourou d'abord
  - extensible ensuite aux autres communes de Guyane
- Cadrer la premiere livraison autour de la boucle :
  - citoyen signale
  - agent traite et valide l'execution
  - admin supervise
- Conserver l'administration web comme surface d'exploitation principale a court terme

### P1 - Reprise UX

- Refaire les surfaces d'entree autour de `Ma Commune`
- Repenser l'onboarding pour raconter :
  - pourquoi agir
  - comment la commune suit
  - ce que devient le signalement
- Revoir la page carte pour qu'elle exprime le territoire, pas seulement des points
- Revoir le dashboard citoyen pour montrer :
  - contribution personnelle
  - utilite collective
  - reponse publique
- Revoir les messages systeme pour un ton plus civique, moins technique

### P1 - Reprise Graphique

- Definir un systeme visuel complet `Ma Commune`
- Construire une identite Guyane sobre et contemporaine :
  - formes inspirees du territoire
  - palette ancree dans foret, fleuve, laterite, sable, lumiere tropicale
  - typographie plus distinctive que la base actuelle
- Unifier :
  - logo
  - icones
  - badges
  - illustrations
  - ecrans de vide
  - splash / launcher

### P1 - Personnages Et Imagerie Narrative

- Figer une bible personnages avant toute nouvelle production visuelle :
  - 3 pistes mascotte
  - 2 pistes agent communal
  - 1 duo final a retenir
- Sortir definitivement du melange entre :
  - icones fonctionnelles
  - badges categories
  - illustrations editoriales terrain
- Produire un premier socle d imagerie terrain narrative pour les categories prioritaires :
  - voirie
  - eclairage public
  - proprete
  - inondations et reseaux
  - espaces verts
  - mobilier urbain
  - signalisation
  - batiments communaux
- Brancher cette imagerie sur les moments produit a plus fort impact :
  - onboarding
  - remerciement apres signalement
  - etats vides
  - suivi d un dossier
  - categories de signalement

### P2 - Alignement Technique

- Renommer les constantes visibles :
  - `APP_NAME`
  - `APP_SHORT_NAME`
  - textes mobile
  - textes admin
  - docs de deploiement
- Prefixe de reference local migre vers `MC-...`, confirmer seulement la regle de passage en preproduction cible
- Auditer les captures, slides, docs, seeds et tests contenant encore l'ancien nom

## Contraintes De Mise En Oeuvre

- Tout doit etre en francais pour cette phase
- Le territoire de depart doit etre Kourou
- La Guyane doit etre representee avec justesse et sobriete
- Le design doit inspirer confiance, service et responsabilite

## Livrables Attendus

- Charte courte `Ma Commune`
- Logo Guyane final en haute qualite
- App icon et favicon derives du logo
- Renommage produit des surfaces visibles prioritaires
- Refonte UX des ecrans d'entree, carte, dashboard et profil
- Mise a jour de la documentation produit
- Cadrage MVP de livraison rapide avec definition de fini

## Questions A Trancher Plus Tard

- Regle de reprise en preproduction pour les references incident deja migrees localement en `MC`
- Niveau exact de personnalisation par commune
- Eventuelle co-signature institutionnelle avec la collectivite ou la mairie
