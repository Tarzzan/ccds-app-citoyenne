<?php
use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.6 — Webhooks, Sondages, Événements, RGPD
 * NOTE: Toutes les colonnes FK utilisent ['signed' => false] pour MySQL 8.0.
 */
class V16WebhooksPollsEvents extends AbstractMigration
{
    public function change(): void
    {
        // ─── Webhooks ────────────────────────────────────────────────────────
        $webhooks = $this->table('webhooks');
        $webhooks
            ->addColumn('target_url', 'string',  ['limit' => 500])
            ->addColumn('event',      'string',  ['limit' => 100])
            ->addColumn('secret',     'string',  ['limit' => 64])
            ->addColumn('is_active',  'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime')
            ->create();

        $deliveries = $this->table('webhook_deliveries');
        $deliveries
            ->addColumn('webhook_id',   'integer', ['signed' => false])
            ->addColumn('event',        'string',  ['limit' => 100])
            ->addColumn('status_code',  'integer', ['null' => true])
            ->addColumn('response',     'string',  ['limit' => 500, 'null' => true])
            ->addColumn('delivered_at', 'datetime')
            ->addForeignKey('webhook_id', 'webhooks', 'id', ['delete' => 'CASCADE'])
            ->create();

        // ─── Sondages (UX-10) ────────────────────────────────────────────────
        $polls = $this->table('polls');
        $polls
            ->addColumn('title',       'string',   ['limit' => 255])
            ->addColumn('description', 'text',     ['null' => true])
            ->addColumn('type',        'string',   ['limit' => 20, 'default' => 'single'])
            ->addColumn('status',      'string',   ['limit' => 20, 'default' => 'active'])
            ->addColumn('created_by',  'integer',  ['signed' => false])
            ->addColumn('ends_at',     'datetime', ['null' => true])
            ->addColumn('created_at',  'datetime')
            ->addForeignKey('created_by', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();

        $pollOptions = $this->table('poll_options');
        $pollOptions
            ->addColumn('poll_id',    'integer', ['signed' => false])
            ->addColumn('label',      'string',  ['limit' => 255])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addForeignKey('poll_id', 'polls', 'id', ['delete' => 'CASCADE'])
            ->create();

        $pollVotes = $this->table('poll_votes');
        $pollVotes
            ->addColumn('poll_id',   'integer', ['signed' => false])
            ->addColumn('option_id', 'integer', ['signed' => false])
            ->addColumn('user_id',   'integer', ['signed' => false])
            ->addColumn('voted_at',  'datetime')
            ->addForeignKey('poll_id',   'polls',        'id', ['delete' => 'CASCADE'])
            ->addForeignKey('option_id', 'poll_options', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id',   'users',        'id', ['delete' => 'CASCADE'])
            ->addIndex(['poll_id', 'user_id'], ['unique' => true])
            ->create();

        // ─── Événements communautaires (UX-12) ───────────────────────────────
        $events = $this->table('events');
        $events
            ->addColumn('title',       'string',   ['limit' => 255])
            ->addColumn('description', 'text',     ['null' => true])
            ->addColumn('location',    'string',   ['limit' => 255])
            ->addColumn('event_date',  'datetime')
            ->addColumn('created_by',  'integer',  ['signed' => false])
            ->addColumn('created_at',  'datetime')
            ->addForeignKey('created_by', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();

        $eventRsvps = $this->table('event_rsvps');
        $eventRsvps
            ->addColumn('event_id',   'integer', ['signed' => false])
            ->addColumn('user_id',    'integer', ['signed' => false])
            ->addColumn('status',     'string',  ['limit' => 20, 'default' => 'attending'])
            ->addColumn('created_at', 'datetime')
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id',  'users',  'id', ['delete' => 'CASCADE'])
            ->addIndex(['event_id', 'user_id'], ['unique' => true])
            ->create();

        // ─── Export RGPD (ADMIN-11) ───────────────────────────────────────────
        $gdprRequests = $this->table('gdpr_export_requests');
        $gdprRequests
            ->addColumn('user_id',      'integer',  ['signed' => false])
            ->addColumn('status',       'string',   ['limit' => 20, 'default' => 'pending'])
            ->addColumn('file_path',    'string',   ['limit' => 500, 'null' => true])
            ->addColumn('requested_at', 'datetime')
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();

        // ─── Mentions @ (UX-11) ──────────────────────────────────────────────
        $mentions = $this->table('comment_mentions');
        $mentions
            ->addColumn('comment_id',   'integer', ['signed' => false])
            ->addColumn('mentioned_id', 'integer', ['signed' => false])
            ->addColumn('created_at',   'datetime')
            ->addForeignKey('comment_id',   'comments', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('mentioned_id', 'users',    'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
