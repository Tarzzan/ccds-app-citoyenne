<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.8 — Correction des colonnes manquantes (suite)
 *
 * Colonnes ajoutées après audit exhaustif contrôleurs vs migrations :
 * 1. incidents.resolved_at   — IncidentController: SET resolved_at = NOW()
 * 2. incidents.assigned_to   — IncidentController: SET assigned_to = ?
 * 3. users.two_factor_recovery_codes — TwoFactorController (migration v1.5 ne l'ajoute pas)
 *
 * Toutes les colonnes sont ajoutées de façon idempotente (hasColumn).
 */
final class V18FixRemainingColumns extends AbstractMigration
{
    public function change(): void
    {
        // ── 1. incidents.resolved_at ──────────────────────────────────────────
        $incidents = $this->table('incidents');
        if (!$incidents->hasColumn('resolved_at')) {
            $incidents->addColumn('resolved_at', 'datetime', [
                'null'    => true,
                'default' => null,
                'comment' => 'Date de résolution du signalement',
                'after'   => 'updated_at',
            ])->save();
        }

        // ── 2. incidents.assigned_to ──────────────────────────────────────────
        if (!$incidents->hasColumn('assigned_to')) {
            $incidents->addColumn('assigned_to', 'integer', [
                'null'     => true,
                'default'  => null,
                'signed'   => false,
                'comment'  => 'ID de l\'agent assigné au signalement',
                'after'    => 'resolved_at',
            ])->save();
        }

        // ── 3. users.two_factor_recovery_codes ────────────────────────────────
        $users = $this->table('users');
        if (!$users->hasColumn('two_factor_recovery_codes')) {
            $users->addColumn('two_factor_recovery_codes', 'text', [
                'null'    => true,
                'default' => null,
                'comment' => 'Codes de récupération 2FA (JSON, hashés)',
                'after'   => 'two_factor_secret',
            ])->save();
        }
    }
}
