# Audit Pre-Deploiement Local - Ma Commune

Date : 18 mars 2026

## Portee

Cet audit ne couvre que ce qui a ete verifie localement sur :

- backend reel
- admin reel
- build Android release
- recette locale automatisee

Il ne vaut pas encore validation finale tablette, car aucun redeploiement sur appareil n'a ete effectue dans cette phase.

## Correctifs Et Evolutions Integrees

### Positionnement produit

- README remis a niveau avec l'etat reel du depot
- nouvelle strategie de commercialisation ajoutee
- tableau de bord mobile recentre sur la preuve de service et la progression des dossiers
- onboarding ajuste pour mieux raconter la reponse publique, pas seulement le depot d'alerte
- les surfaces `agenda communal` et `consultations` deja codees sont maintenant exposees depuis le dashboard mobile

### Stabilisation fonctionnelle

- `GET /api/profile/stats` ne casse plus si les tables de gamification sont absentes
- le contrat TypeScript `UserStats` est aligne avec la reponse API reelle
- les routes votes, commentaires et notifications utilisent maintenant correctement le payload JWT
- le profil mobile resynchronise l'utilisateur stocke apres mise a jour
- le routeur API gere a nouveau correctement les sous-routes texte comme `comments/{id}/report`, `notifications/token`, `notifications/read-all`, `admin/audit-logs/export` et `gamification/badges`
- `ModerationController` charge maintenant explicitement la couche d'audit utilisee lors du traitement des signalements
- `AuditLogController` supporte le schema legacy `admin_id/entity/entity_id` present en base locale et exporte le CSV sans pollution deprecation PHP
- `GdprController` ecrit ses archives dans un espace writable sous `uploads/exports` au lieu du code monte
- `GamificationController` fournit des fallbacks coherents pour les points et badges meme sans enregistrements persistants complets
- l'ouverture d'une notification push peut maintenant rediriger vers le bon signalement ou vers les surfaces `Events` / `Polls`
- les notifications d'evenement utilisent maintenant le schema reel `sent_at` et le type `event`
- les services mobiles de photos utilisent maintenant l'URL serveur configuree par l'utilisateur au lieu d'un faux domaine compile en dur
- le fallback d'URL mobile est aligne sur la configuration Expo existante `extra.API_BASE_URL` ou `EXPO_PUBLIC_API_URL`
- le backend photos supporte maintenant le schema local reel sans colonne `sort_order`, et le rapport PDF recharge les images depuis le bon dossier `uploads/incidents`
- les surfaces publiques `/status`, `/api-docs/` et `/api/openapi.yml` sont maintenant servies par Nginx et ne reposent plus sur des verifications fausses ou inaccessibles

## Verifications Realisees

### 1. Typecheck mobile

Commande :

```bash
cd mobile
pnpm run typecheck
```

Resultat :

- OK

### 2. Build Android release

Commande :

```bash
cd mobile/android
./gradlew assembleRelease
```

Resultat :

- OK
- APK release genere sans erreur bloquante

Warnings observes :

- warnings de deprecation Gradle non bloquants

### 3. Endpoint categories

Commande :

```bash
curl -sS http://127.0.0.1:8080/api/categories
```

Resultat :

- OK
- categories renvoyees correctement

### 3 bis. Surfaces publiques

Verification :

- `GET /status`
- `GET /api-docs/`
- `GET /api/openapi.yml`

Resultat :

- OK
- la page de statut publique est accessible
- la documentation API publique est servie par Nginx
- le schema OpenAPI est telechargeable
- le schema OpenAPI expose bien l'identite `Ma Commune API`

### 4. Recette locale citoyen -> agent -> admin

Commande :

```bash
bash scripts/recette_mvp_local.sh
```

Resultat :

- OK
- creation d'un compte citoyen
- creation d'un signalement
- connexion agent
- passage du dossier en `resolved`
- lecture detail admin du dossier cree

Dernier incident de recette constate lors de la passe precedente :

- reference : `MC-2026-00039`
- statut final : `resolved`

### 5. Login admin web

Verification :

- `GET /admin/?page=login` accessible
- `POST /admin/?page=login` avec `admin@macommune.local` redirige bien vers `/admin/?page=dashboard`
- le HTML du dashboard est bien rendu apres authentification

Resultat :

- OK

### 6. Tableau de bord / profil stats

Verification :

- login API agent
- appel `GET /api/profile/stats`

Resultat :

- OK
- plus de fatal SQL sur l'absence de `user_points`

### 7. Votes citoyen

Verification :

- creation d'un signalement de test
- vote
- lecture de l'etat du vote
- retrait du vote

Resultat :

- OK

### 8. Commentaires citoyen

Verification :

- creation d'un signalement de test
- ajout d'un commentaire public
- relecture de la liste des commentaires

Resultat :

- OK

### 9. Notifications API

Verification :

- login citoyen puis creation d'un incident
- passage agent du dossier en `in_progress`
- ajout d'un commentaire agent
- appel `GET /api/notifications` cote citoyen

Resultat :

- OK
- route fonctionnelle et pagination repond sans fatal
- notifications `status_change` et `new_comment` bien creees avec `incident_id`

### 10. Syntaxe PHP des correctifs backend

Verification :

- `php -l` dans le conteneur `php` sur les fichiers modifies

Resultat :

- OK

### 10 bis. Photos d'incident

Verification :

- creation d'un signalement de test
- upload d'une image PNG sur `POST /api/incidents/{id}/photos`
- relecture `GET /api/incidents/{id}/photos`
- suppression `DELETE /api/incidents/{id}/photos/{photoId}`

Resultat :

- OK
- upload, listing et suppression fonctionnels
- compatibilite confirmee avec le schema local actuel de la table `photos`

### 11. Parcours admin web detaille

Verification :

- login admin via formulaire web
- ouverture de la liste `/admin/?page=incidents`
- presence d'un signalement de test dans la liste
- ouverture du detail
- changement de statut via le formulaire HTML admin

Resultat :

- OK
- le dossier de test est visible dans la liste
- la page detail reflète bien le statut `Pris en charge` apres soumission

### 12. Cohérence notifications depuis l'admin web

Verification :

- creation d'un signalement citoyen
- changement de statut depuis `/admin/?page=incident_detail`
- ajout d'un commentaire public depuis le web admin
- lecture `GET /api/notifications` cote citoyen

Resultat :

- OK
- les notifications `status_change` et `new_comment` sont bien creees
- `incident_id` est bien present
- le commentaire affiche maintenant `Administrateur Ma Commune`

### 13. Modules secondaires existants dans l'app

Verification :

- creation d'un evenement via API admin
- listing des evenements cote citoyen
- RSVP cote citoyen
- creation d'un sondage via API admin
- listing des sondages cote citoyen
- vote cote citoyen
- lecture des resultats

Resultat :

- OK
- les usages JWT sont alignes sur `sub`
- les surfaces `events` et `polls` ne reposent plus sur un contrat de donnees faux

### 14. Moderation des commentaires

Verification :

- creation d'un commentaire citoyen
- signalement du commentaire par un second citoyen via `POST /api/comments/{id}/report`
- lecture de la file `GET /api/admin/moderation/reports`
- traitement admin `PUT /api/admin/moderation/reports/{id}`

Resultat :

- OK
- le signalement remonte bien en file d'attente
- le traitement admin renvoie bien `dismissed`
- l'appel d'audit associe ne casse plus le flux

### 15. Gamification et RGPD

Verification :

- creation d'un incident citoyen puis ajout d'un commentaire
- lecture `GET /api/gamification`
- lecture `GET /api/gamification/badges`
- generation `POST /api/gdpr/export`
- telechargement `GET /api/gdpr/download/{filename}`

Resultat :

- OK
- les points derives sont coherents sur un profil minimal (`13` apres 1 incident + 1 commentaire)
- le badge `explorer` remonte bien dans les stats et dans le catalogue
- l'archive RGPD contient bien profil, incidents, commentaires et metadonnees d'export

### 16. Audit logs, webhooks et routes notifications

Verification :

- `GET /api/admin/audit-logs`
- `GET /api/admin/audit-logs/export`
- creation d'un webhook puis `POST /api/webhooks/{id}/test`
- `POST /api/notifications/token`
- `PUT /api/notifications/read-all`
- creation d'un evenement puis lecture de `GET /api/notifications`

Resultat :

- OK
- la liste admin et l'export CSV fonctionnent sur le schema legacy d'audit present localement
- l'export CSV n'emet plus de warning PHP parasite
- le webhook de test repond bien `status_code: 200` avec une cible locale valide
- les routes notifications texte repondent correctement de nouveau
- une creation d'evenement produit bien une notification `type: event` compatible mobile

### 17. Publications Communaute Via Admin Web

Verification :

- connexion admin web par session PHP
- creation d'un evenement depuis `?page=events`
- creation d'une consultation depuis `?page=polls`
- verification de leur presence dans les pages admin
- verification de leur presence dans `GET /api/events` et `GET /api/polls`
- verification d'une notification citoyenne `type: event` apres publication admin web
- cloture admin web de la consultation puis verification de sa disparition dans `GET /api/polls`

Resultat :

- OK
- le back-office peut maintenant publier directement un rendez-vous communal et une consultation sans passer par l'API brute
- la page admin des consultations est alignee avec le vrai statut `active` au lieu de l'ancien `open`
- la V1 a ete explicitement recadree sur des consultations a choix unique, coherentes avec le schema et le mobile reels
- une consultation cloturee en admin web disparait bien du catalogue citoyen actif
- le bootstrap admin charge desormais explicitement `Security`, ce qui rend les sanitizations fiables dans les pages de gestion

## Statut Des Parcours P0

### Valides localement

- build mobile release reproductible
- categories API
- inscription citoyen API
- connexion citoyen API
- creation de signalement API
- lecture detail signalement API
- connexion agent API
- changement de statut signalement API
- commentaires API
- votes API
- notifications API
- moderation commentaires API
- gamification API
- export RGPD API
- audit logs admin API
- webhooks admin API
- connexion admin web
- ouverture dashboard admin web
- ouverture liste admin web
- ouverture detail admin web
- changement de statut depuis l'admin web
- creation d'evenement depuis l'admin web
- creation de consultation depuis l'admin web
- cloture de consultation depuis l'admin web

### Partiellement valides

- `Mon bilan` mobile valide cote API, pas encore reverifie visuellement sur tablette dans cette phase
- surfaces mobiles rebrandees, mais pas encore revalidees sur appareil apres ce lot de changements
- navigation push mobile reverifiee en code et au build, mais pas encore rejouee sur tablette physique dans cette phase

### Non encore revalidees dans cet audit

- ajout de commentaire public via mobile reel
- navigation depuis notification vers detail sur appareil
- parcours complet tablette jusqu'au shell admin tactile
- rendu mobile final des ecrans `Mon bilan`, notifications et onboarding sur tablette apres ce lot backend

## Risques Restants

- gros volume de changements non commits dans le depot, pas uniquement lies a ce lot
- validation tablette a refaire apres ce lot avant livraison finale
- migration locale des references incidents vers le prefixe `MC` faite, a rejouer proprement en preproduction cible si necessaire
- plusieurs surfaces techniques gardent encore des commentaires internes `CCDS`, meme si les surfaces visibles prioritaires convergent vers `Ma Commune`
- les consultations multi-choix ne doivent pas etre remises dans le discours commercial tant qu'elles ne sont pas implementees de bout en bout

## Decision

La base locale est a nouveau exploitable et nettement plus credible.

Le projet peut continuer vers un audit final de livraison, a condition de faire ensuite :

1. une revalidation visuelle et fonctionnelle sur tablette
2. une verification guidee des parcours mobile les plus sensibles
3. une consolidation propre du worktree avant diffusion
