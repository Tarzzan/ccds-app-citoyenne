# RBAC Services Prestataires Ma Commune

Date : 18 mars 2026

## But

Definir une gouvernance simple pour la phase 1, tout en reservant l ouverture future aux prestataires.

## Acteurs

### Citoyen

Peut :

- creer un signalement
- consulter son statut
- lire la chronologie citoyenne
- recevoir une information de planification simplifiee

Ne peut pas :

- voir les notes internes
- voir l organisation interne complete des services

### Agent

Peut :

- voir la file de son service
- prendre en charge un dossier
- planifier une intervention
- replanifier
- documenter le debut et la fin d intervention
- laisser des notes internes et des messages citoyens

Ne peut pas :

- modifier la structure des services
- administrer l ensemble des comptes

### Admin

Peut :

- gerer services, categories et rattachements
- gerer les membres de service
- superviser toutes les files
- planifier ou replanifier n importe quel dossier
- preparer l ouverture a des prestataires

### Prestataire

Phase 1 :

- pas d interface autonome complete
- action indirecte via un admin ou un agent qui planifie au nom du prestataire

Phase 2 :

- acces restreint au module `Prestataires`
- visibilite seulement sur les missions confiees
- possibilite d accepter, planifier, deposer preuve et marquer termine

## Regles De Gouvernance

### Regle 1

La commune reste toujours l autorite de validation finale.

### Regle 2

La chronologie citoyenne ne doit jamais exposer des details internes inutiles.

### Regle 3

Une action de planification doit garder :

- qui a planifie
- pour quel service
- a quelle date
- avec quel message visible cote citoyen

### Regle 4

Les prestataires futurs doivent operer dans un espace borne, jamais sur l ensemble du back-office.

## Ecrans Cibles Phase 1

### Admin Web

- gestion des services
- rattachement categories -> service
- rattachement users -> service
- planification sur le detail d un dossier
- historique d intervention

### Mobile Staff

- lecture du service responsable
- lecture de la prochaine intervention
- mise a jour simple du plan courant

### Mobile Citoyen

- etape `attribue au service`
- etape `intervention planifiee`
- etape `intervention reprogrammee`
- etape `intervention terminee`

## Decision Produit

Ne pas ouvrir tout de suite un role `prestataire` complet dans le coeur du produit.

Decision retenue :

- Phase 1 : structure compatible prestataire, mais execution surtout via admin/agent
- Phase 2 : plugin ou module dedie, avec interface propre et droits limites
