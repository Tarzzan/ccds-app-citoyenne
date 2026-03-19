# Brief Chaine Intervention Services Ma Commune

Date : 18 mars 2026

## Intention

Faire evoluer `Ma Commune` d un outil de signalement et de suivi vers une chaine d intervention territoriale traçable.

Le citoyen ne doit plus seulement voir :

- que son signalement existe
- qu un statut a change

Il doit pouvoir comprendre :

- quel service prend le dossier
- si une intervention est planifiee
- qui porte la responsabilite de la prochaine etape utile
- si une mission externe intervient, sans faire disparaitre la responsabilite publique

## Probleme Produit A Resoudre

Aujourd hui, l application donne deja une preuve de suivi.

Mais elle ne formalise pas encore assez clairement :

- la relation `categorie -> service`
- la relation `agent -> service`
- la planification d une operation
- la traçabilite d execution
- la lecture citoyenne d une intervention reelle

## Ambition

Positionner `Ma Commune` comme une plateforme de pilotage operationnel et de transparence locale.

Le produit doit permettre :

- d orienter automatiquement un signalement vers le bon service
- d outiller l agent et l administrateur pour planifier l action
- de conserver un historique compréhensible de la prise en charge
- d ouvrir plus tard la chaine a des prestataires missionnes, sans perdre la gouvernance publique

## Choix Produit Retenu

### Phase 1

Integrer maintenant un noyau leger de chaine d intervention directement dans le produit principal.

Ce noyau comprend :

- des `services`
- un rattachement `categorie -> service referent`
- un rattachement `agent -> service`
- une `planification d intervention` sur le dossier
- une `chronologie operationnelle`
- une traduction citoyenne simple des etapes de traitement

### Phase 2

Preparer un futur module ou plugin `Prestataires et interventions externes`.

Ce module n est pas ouvert maintenant dans le MVP principal.

Il devra plus tard permettre :

- de missionner un prestataire
- d accepter ou proposer un creneau
- de planifier une intervention externe
- de deposer une preuve d execution
- de laisser la validation finale a la commune

## Parcours Cible

1. Le citoyen cree un signalement.
2. La categorie designe un service referent.
3. Le dossier apparait dans la file du service.
4. Un agent ou un admin prend en charge.
5. Une operation est planifiee :
   - date
   - creneau
   - note interne
   - message citoyen simplifie
6. Le dossier passe en intervention.
7. L intervention est documentee puis cloturee.
8. Le citoyen lit une chronologie claire.

## Lecture Cote Citoyen

La complexite metier doit etre traduite en langage simple :

- `Recu`
- `Attribue au service voirie`
- `Intervention planifiee`
- `Intervention en cours`
- `Termine`
- `Reprogramme`
- `Cloture avec justification`

Le citoyen ne doit pas voir un jargon interne du type :

- backlog
- ticket
- assignation brute
- ordre d intervention

## Lecture Cote Agent Et Admin

Les surfaces metier doivent apporter :

- le service responsable
- l agent en charge
- la prochaine action utile
- la planification courante
- la chronologie d intervention
- l historique des replanifications

## Place Future Des Prestataires

Le prestataire n est pas un acteur souverain du dossier.

Le produit doit conserver un principe fort :

- la commune reste responsable
- le prestataire agit dans un cadre trace
- l historique doit montrer qui a planifie et qui a execute

## Benefices Produit

- meilleure lisibilite du travail des services
- meilleure preuve de service rendu
- reduction de l impression de dossier opaque
- meilleure base commerciale pour vendre l outil a une commune
- meilleure transition future vers un module prestataire

## Limites Volontaires De La Phase 1

Ne pas ouvrir tout de suite :

- un portail prestataire complet
- une gestion de flotte ou d equipes complexe
- un moteur de SLA avance
- une planification multi-operations lourde

Le but de cette phase est d ajouter de la profondeur metier sans ralentir le projet.

## Definition De Reussite

La phase 1 est reussie si :

- chaque categorie a un service referent
- chaque agent staff est relie a un service
- un dossier peut etre planifie proprement
- cette planification laisse une trace horodatee
- le citoyen voit une etape `intervention planifiee`
- la structure prepare une extension future `Prestataires`
