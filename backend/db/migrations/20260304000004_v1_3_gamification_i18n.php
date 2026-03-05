<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.3 — Gamification, internationalisation, mode sombre
 */
final class V13GamificationI18n extends AbstractMigration
{
    public function change(): void
    {
        // -----------------------------------------------------------------
        // Table user_gamification
        // -----------------------------------------------------------------
        if (!$this->hasTable('user_gamification')) {
            $this->table('user_gamification', ['id' => false, 'primary_key' => 'user_id'])
                ->addColumn('user_id',        'integer',  ['null' => false, 'signed' => false])
                ->addColumn('points',         'integer',  ['default' => 0])
                ->addColumn('last_action_at', 'datetime', ['null' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // -----------------------------------------------------------------
        // Table user_badges
        // -----------------------------------------------------------------
        if (!$this->hasTable('user_badges')) {
            $this->table('user_badges')
                ->addColumn('user_id',    'integer',  ['null' => false, 'signed' => false])
                ->addColumn('badge_key',  'string',   ['limit' => 50, 'null' => false])
                ->addColumn('awarded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['user_id', 'badge_key'], ['unique' => true, 'name' => 'uq_user_badge'])
                ->create();
        }

        // Colonne langue sur users
        if ($this->hasTable('users') && !$this->table('users')->hasColumn('language')) {
            $this->table('users')
                ->addColumn('language',  'string', ['limit' => 5, 'default' => 'fr'])
                ->addColumn('dark_mode', 'boolean', ['default' => false])
                ->update();
        }
    }
}
