<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.7 — Correction des colonnes manquantes
 *
 * Problèmes détectés par analyse statique :
 * 1. categories.service — colonne utilisée dans CategoryController mais absente de la migration 001
 * 2. status_history.note — IncidentController utilise 'note' mais la migration 001 a créé 'comment'
 *
 * Cette migration :
 * - Ajoute categories.service (string nullable)
 * - Ajoute status_history.note (alias de comment, pour compatibilité)
 *   Note : on ajoute 'note' comme nouvelle colonne et on migre les données de 'comment'
 */
final class V17FixMissingColumns extends AbstractMigration
{
    public function change(): void
    {
        // ── 1. Ajouter categories.service ─────────────────────────────────────
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

        // ── 2. Ajouter status_history.note (renommage de comment) ─────────────
        // La migration 001 a créé 'comment', mais les contrôleurs utilisent 'note'.
        // On ajoute 'note' et on copie les données existantes de 'comment'.
        $history = $this->table('status_history');
        if (!$history->hasColumn('note')) {
            $history->addColumn('note', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => 'Note optionnelle sur le changement de statut',
                'after'   => 'new_status',
            ])->save();

            // Copier les données existantes de 'comment' vers 'note'
            $this->execute('UPDATE status_history SET note = comment WHERE note IS NULL AND comment IS NOT NULL');
        }
    }
}
