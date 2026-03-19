# Modele Donnees Services Interventions Ma Commune

Date : 18 mars 2026

## But

Definir le socle de donnees pour la phase 1 :

- services
- rattachements agents
- planification d intervention
- chronologie traçable

## Etat Actuel A Preserver

Le schema contient deja un champ `categories.service`.

Ce champ reste utile comme compatibilite de transition, mais il ne suffit pas pour une vraie chaine d intervention.

La cible doit aller vers des relations explicites plutot que de simples libelles texte.

## Tables Proposees

### services

Colonnes minimales :

- `id`
- `code`
- `name`
- `description`
- `is_active`
- `created_at`
- `updated_at`

Exemples :

- `voirie`
- `eclairage_public`
- `proprete_urbaine`
- `espaces_verts`
- `batiments`

### service_category_map

Relation entre categories et services.

Colonnes minimales :

- `id`
- `service_id`
- `category_id`
- `priority_order`
- `is_default`
- `created_at`

Usage :

- une categorie peut avoir un service principal
- on garde la possibilite future de plusieurs services possibles

### user_service_memberships

Relation entre utilisateurs staff et services.

Colonnes minimales :

- `id`
- `user_id`
- `service_id`
- `role_in_service`
- `is_primary`
- `created_at`

Valeurs possibles `role_in_service` :

- `manager`
- `agent`
- `viewer`

### intervention_plans

Planification visible d une operation.

Colonnes minimales :

- `id`
- `incident_id`
- `service_id`
- `planned_by_user_id`
- `assigned_user_id`
- `status`
- `scheduled_date`
- `time_window_start`
- `time_window_end`
- `internal_note`
- `citizen_message`
- `source_type`
- `provider_name`
- `created_at`
- `updated_at`

Valeurs possibles `status` :

- `draft`
- `scheduled`
- `rescheduled`
- `in_progress`
- `completed`
- `cancelled`

Valeurs possibles `source_type` :

- `internal`
- `provider`

### incident_service_history

Historique simple et traçable des moments metier.

Colonnes minimales :

- `id`
- `incident_id`
- `service_id`
- `plan_id`
- `actor_user_id`
- `event_type`
- `event_label`
- `citizen_label`
- `payload_json`
- `created_at`

Exemples `event_type` :

- `service_assigned`
- `taken_in_charge`
- `plan_created`
- `plan_rescheduled`
- `intervention_started`
- `intervention_completed`
- `provider_requested`

## Extension Future Prestataires

Ne pas ouvrir maintenant la table complete de portail prestataire si on veut tenir le rythme.

Mais preparer des champs compatibles :

- `source_type`
- `provider_name`

Phase 2 pourra ajouter :

### providers

- `id`
- `name`
- `contact_name`
- `contact_email`
- `contact_phone`
- `is_active`

### provider_users

- `id`
- `provider_id`
- `user_id`
- `role`

### provider_assignments

- `id`
- `plan_id`
- `provider_id`
- `assigned_by_user_id`
- `status`
- `accepted_at`
- `scheduled_at`
- `completed_at`

## Impacts API

### Incident Read Model

Ajouter progressivement au detail dossier :

- `service`
- `service_id`
- `service_name`
- `current_plan`
- `current_plan_status`
- `current_plan_date`
- `current_plan_window`
- `citizen_timeline`

### Admin / Staff Actions

Nouvelles actions cibles :

- attribuer a un service
- prendre en charge
- planifier
- replanifier
- marquer debut d intervention
- marquer fin d intervention

## Strategie De Migration

1. Creer `services`.
2. Backfiller depuis `categories.service`.
3. Creer `service_category_map`.
4. Rattacher les categories existantes.
5. Ajouter `user_service_memberships`.
6. Ajouter `intervention_plans`.
7. Ajouter `incident_service_history`.
8. Garder `categories.service` en lecture transitoire.

## Regle De Simplicite

Pour la phase 1 :

- un seul `plan actif` par dossier suffit
- une seule `affectation principale` suffit
- le vrai objectif est la lisibilite, pas la sophistication logistique
