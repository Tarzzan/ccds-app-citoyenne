<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.1 — Votes, Push Tokens, Notifications
 */
final class V11VotesPushNotifications extends AbstractMigration
{
    public function change(): void
    {
        // -----------------------------------------------------------------
        // Table votes (système "Moi aussi")
        // -----------------------------------------------------------------
        if (!$this->hasTable('votes')) {
            $votes = $this->table('votes');
            $votes
                ->addColumn('user_id',     'integer', ['null' => false, 'signed' => false])
                ->addColumn('incident_id', 'integer', ['null' => false, 'signed' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id',     'users',     'id', ['delete' => 'CASCADE'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['user_id', 'incident_id'], ['unique' => true, 'name' => 'uq_user_incident_vote'])
                ->create();
        }

        // Colonne votes_count sur incidents
        if ($this->hasTable('incidents') && !$this->table('incidents')->hasColumn('votes_count')) {
            $this->table('incidents')
                ->addColumn('votes_count', 'integer', ['default' => 0, 'null' => false])
                ->update();
        }

        // -----------------------------------------------------------------
        // Table push_tokens
        // -----------------------------------------------------------------
        if (!$this->hasTable('push_tokens')) {
            $tokens = $this->table('push_tokens');
            $tokens
                ->addColumn('user_id',    'integer',     ['null' => false, 'signed' => false])
                ->addColumn('token',      'string',      ['limit' => 255, 'null' => false])
                ->addColumn('platform',   'string',      ['limit' => 10, 'default' => 'expo'])
                ->addColumn('created_at', 'datetime',    ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime',    ['null' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addIndex(['user_id', 'token'], ['unique' => true, 'name' => 'uq_user_token'])
                ->create();
        }

        // -----------------------------------------------------------------
        // Table notifications
        // -----------------------------------------------------------------
        if (!$this->hasTable('notifications')) {
            $notifs = $this->table('notifications');
            $notifs
                ->addColumn('user_id',     'integer',  ['null' => false, 'signed' => false])
                ->addColumn('incident_id', 'integer',  ['null' => true, 'signed' => false])
                ->addColumn('type',        'string',   ['limit' => 50, 'null' => false])
                ->addColumn('title',       'string',   ['limit' => 255, 'null' => false])
                ->addColumn('body',        'text',     ['null' => false])
                ->addColumn('is_read',     'boolean',  ['default' => false])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('user_id',     'users',     'id', ['delete' => 'CASCADE'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'SET_NULL'])
                ->addIndex('user_id')
                ->create();
        }
    }
}
