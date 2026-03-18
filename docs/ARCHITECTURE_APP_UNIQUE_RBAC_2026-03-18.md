# Architecture cible — Application unique et gestion des rôles

## Décision produit

`Ma Commune` doit converger vers une seule application d'accès, avec une expérience adaptée au rôle connecté :

- `citizen` : signaler, suivre, commenter, contribuer
- `agent` : qualifier, traiter, répondre, piloter le terrain et valider l'exécution
- `admin` : administrer, superviser, auditer, configurer

L'objectif n'est pas de maintenir durablement une application citoyenne et une interface d'administration disjointes, mais de partager une même base produit, un même récit de marque et un même système d'authentification.

## Stratégie réaliste de convergence

### Phase 1 — maintenant

- rendre le back-office web tactile et responsive sur tablette et smartphone
- conserver les pages PHP d'administration pour sécuriser l'exploitation immédiate
- persister côté mobile le rôle utilisateur après authentification
- préparer la navigation mobile à distinguer `citizen` et `staff`

### Phase 2 — prochaine itération

- créer un espace `Pilotage` dans l'application mobile pour `agent` et `admin`
- exposer les modules terrain prioritaires dans l'app :
  - file des signalements
  - détail et changement de statut
  - réponse aux citoyens
  - carte opérationnelle
  - validation d'exécution par l'agent
- garder les écrans d'administration lourds sur le web au début

### Phase 3 — convergence

- mutualiser les composants, couleurs, libellés et patterns d'usage
- réserver le web aux usages de supervision avancée et de gestion massive
- faire de l'application mobile la porte d'entrée unique avec droits différenciés

## Principes de droits

- aucun écran sensible ne doit dépendre seulement du masquage UI
- le backend reste la source de vérité sur les permissions
- le mobile doit connaître le rôle courant pour adapter l'interface
- les rôles visibles doivent être libellés en français :
  - `Citoyen`
  - `Agent municipal`
  - `Administrateur`

## Impact UX

- un agent sur tablette doit pouvoir travailler sans zoomer ni viser de petits contrôles
- un administrateur sur smartphone doit au moins lire, rechercher, filtrer et valider des actions urgentes
- la marque `Ma Commune` doit rester cohérente quel que soit le rôle
