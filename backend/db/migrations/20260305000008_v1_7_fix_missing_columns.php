<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.7 — Correction des colonnes manquantes
 *
 * Colonnes détectées comme manquantes par analyse statique + tests locaux :
 * 1. incidents.priority        — utilisé dans IncidentController (filtre + tri)
 * 2. categories.service        — utilisé dans CategoryController
 * 3. status_history.note       — IncidentController utilise 'note', migration 001 a 'comment'
 * 4. push_tokens.updated_at    — utilisé dans PushNotificationService
 *
 * Idempotente : chaque colonne est ajoutée uniquement si elle n'existe pas déjà.
 */
final class V17FixMissingColumns extends AbstractMigration
{
    public function change(): void
    {
        // ── 1. incidents.priority ─────────────────────────────────────────────
        $incidents = $this->table('incidents');
        if (!$incidents->hasColumn('priority')) {
            $incidents->addColumn('priority', 'string', [
                'limit'   => 20,
                'null'    => true,
                'default' => null,
                'comment' => 'Priorité : low, medium, high, urgent',
                'after'   => 'status',
            ])->save();
        }

        // ── 2. categories.service ─────────────────────────────────────────────
        $categories = $this->table('categories');
        if (!$categories->hasColumn('service')) {
            $categories->addColumn('service', 'string', [
                'limit'   => 100,
                'null'    => true,
                'default' => null,
                'comment' => 'Service municipal responsable de cette catégorie',
                'after'   => 'color',
            ])->save();
        }

        // ── 3. status_history.note ────────────────────────────────────────────
        $history = $this->table('status_history');
        if (!$history->hasColumn('note')) {
            $history->addColumn('note', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => 'Note optionnelle sur le changement de statut',
                'after'   => 'new_status',
            ])->save();
            $this->execute(
                'UPDATE status_history SET note = `comment` WHERE note IS NULL AND `comment` IS NOT NULL'
            );
        }

        // ── 4. push_tokens.updated_at ─────────────────────────────────────────
        $pushTokens = $this->table('push_tokens');
        if (!$pushTokens->hasColumn('updated_at')) {
            $pushTokens->addColumn('updated_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => 'Date de dernière mise à jour du token',
                'after'   => 'created_at',
            ])->save();
        }
    }
}
