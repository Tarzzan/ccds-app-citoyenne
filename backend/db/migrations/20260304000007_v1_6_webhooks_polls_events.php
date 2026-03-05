<?php
use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.6 — Webhooks, Sondages, Événements, RGPD
 * Idempotente : chaque table est créée uniquement si elle n'existe pas déjà.
 * NOTE: Toutes les colonnes FK utilisent ['signed' => false] pour MySQL 8.0.
 */
class V16WebhooksPollsEvents extends AbstractMigration
{
    public function change(): void
    {
        // ─── Webhooks ────────────────────────────────────────────────────────
        if (!$this->hasTable('webhooks')) {
            $this->table('webhooks')
                ->addColumn('target_url', 'string',  ['limit' => 500])
                ->addColumn('event',      'string',  ['limit' => 100])
                ->addColumn('secret',     'string',  ['limit' => 64])
                ->addColumn('is_active',  'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime')
                ->create();
        }

        if (!$this->hasTable('webhook_deliveries')) {
            $this->table('webhook_deliveries')
                ->addColumn('webhook_id',   'integer', ['signed' => false])
                ->addColumn('event',        'string',  ['limit' => 100])
                ->addColumn('status_code',  'integer', ['null' => true])
                ->addColumn('response',     'string',  ['limit' => 500, 'null' => true])
                ->addColumn('delivered_at', 'datetime')
                ->addForeignKey('webhook_id', 'webhooks', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ─── Sondages (UX-10) ────────────────────────────────────────────────
        if (!$this->hasTable('polls')) {
            $this->table('polls')
                ->addColumn('title',       'string',   ['limit' => 255])
                ->addColumn('description', 'text',     ['null' => true])
                ->addColumn('type',        'string',   ['limit' => 20, 'default' => 'single'])
                ->addColumn('status',      'string',   ['limit' => 20, 'default' => 'active'])
                ->addColumn('created_by',  'integer',  ['signed' => false])
                ->addColumn('ends_at',     'datetime', ['null' => true])
                ->addColumn('created_at',  'datetime')
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('poll_options')) {
            $this->table('poll_options')
                ->addColumn('poll_id',    'integer', ['signed' => false])
                ->addColumn('label',      'string',  ['limit' => 255])
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addForeignKey('poll_id', 'polls', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('poll_votes')) {
            $this->table('poll_votes')
                ->addColumn('poll_id',   'integer', ['signed' => false])
                ->addColumn('option_id', 'integer', ['signed' => false])
                ->addColumn('user_id',   'integer', ['signed' => false])
                ->addColumn('voted_at',  'datetime')
                ->addForeignKey('poll_id',   'polls',        'id', ['delete' => 'CASCADE'])
                ->addForeignKey('option_id', 'poll_options', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('user_id',   'users',        'id', ['delete' => 'CASCADE'])
                ->addIndex(['poll_id', 'user_id'], ['unique' => true])
                ->create();
        }

        // ─── Événements communautaires (UX-12) ───────────────────────────────
        if (!$this->hasTable('events')) {
            $this->table('events')
                ->addColumn('title',       'string',   ['limit' => 255])
                ->addColumn('description', 'text',     ['null' => true])
                ->addColumn('location',    'string',   ['limit' => 255])
                ->addColumn('event_date',  'datetime')
                ->addColumn('created_by',  'integer',  ['signed' => false])
                ->addColumn('created_at',  'datetime')
                ->addForeignKey('created_by', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        if (!$this->hasTable('event_rsvps')) {
            $this->table('event_rsvps')
                ->addColumn('event_id',   'integer', ['signed' => false])
                ->addColumn('user_id',    'integer', ['signed' => false])
                ->addColumn('status',     'string',  ['limit' => 20, 'default' => 'attending'])
                ->addColumn('created_at', 'datetime')
                ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('user_id',  'users',  'id', ['delete' => 'CASCADE'])
                ->addIndex(['event_id', 'user_id'], ['unique' => true])
                ->create();
        }

        // ─── Export RGPD (ADMIN-11) ───────────────────────────────────────────
        if (!$this->hasTable('gdpr_export_requests')) {
            $this->table('gdpr_export_requests')
                ->addColumn('user_id',      'integer',  ['signed' => false])
                ->addColumn('status',       'string',   ['limit' => 20, 'default' => 'pending'])
                ->addColumn('file_path',    'string',   ['limit' => 500, 'null' => true])
                ->addColumn('requested_at', 'datetime')
                ->addColumn('completed_at', 'datetime', ['null' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ─── Mentions @ (UX-11) ──────────────────────────────────────────────
        if (!$this->hasTable('comment_mentions')) {
            $this->table('comment_mentions')
                ->addColumn('comment_id',   'integer', ['signed' => false])
                ->addColumn('mentioned_id', 'integer', ['signed' => false])
                ->addColumn('created_at',   'datetime')
                ->addForeignKey('comment_id',   'comments', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('mentioned_id', 'users',    'id', ['delete' => 'CASCADE'])
                ->create();
        }
    }
}
