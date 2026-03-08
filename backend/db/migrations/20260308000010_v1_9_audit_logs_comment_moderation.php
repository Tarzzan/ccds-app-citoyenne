<?php
use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.9 — Logs d'audit (ADMIN-08) + Modération commentaires (ADMIN-07)
 */
class V19AuditLogsCommentModeration extends AbstractMigration
{
    public function up(): void
    {
        // Logs d'audit administrateur
        if (!$this->hasTable('audit_logs')) {
            $table = $this->table('audit_logs', ['id' => true, 'signed' => false]);
            $table
                ->addColumn('user_id',     'integer',  ['signed' => false])
                ->addColumn('action',      'string',   ['limit' => 100])
                ->addColumn('target_type', 'string',   ['limit' => 50,  'null' => true])
                ->addColumn('target_id',   'integer',  ['signed' => false, 'null' => true])
                ->addColumn('details',     'text',     ['null' => true])
                ->addColumn('ip_address',  'string',   ['limit' => 45,  'null' => true])
                ->addColumn('user_agent',  'string',   ['limit' => 255, 'null' => true])
                ->addColumn('created_at',  'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_id'])
                ->addIndex(['action'])
                ->addIndex(['target_type', 'target_id'])
                ->addIndex(['created_at'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // Signalements de commentaires
        if (!$this->hasTable('comment_reports')) {
            $table = $this->table('comment_reports', ['id' => true, 'signed' => false]);
            $table
                ->addColumn('comment_id',  'integer',  ['signed' => false])
                ->addColumn('reporter_id', 'integer',  ['signed' => false])
                ->addColumn('reason',      'enum',     ['values' => ['spam','harassment','inappropriate','misinformation','other'], 'default' => 'other'])
                ->addColumn('description', 'text',     ['null' => true])
                ->addColumn('status',      'enum',     ['values' => ['pending','reviewed','dismissed','actioned'], 'default' => 'pending'])
                ->addColumn('reviewed_by', 'integer',  ['signed' => false, 'null' => true])
                ->addColumn('reviewed_at', 'timestamp', ['null' => true])
                ->addColumn('created_at',  'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['comment_id', 'reporter_id'], ['unique' => true])
                ->addIndex(['status'])
                ->addForeignKey('comment_id',  'comments', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('reporter_id', 'users',    'id', ['delete' => 'CASCADE'])
                ->addForeignKey('reviewed_by', 'users',    'id', ['delete' => 'SET_NULL'])
                ->create();
        }
    }

    public function down(): void
    {
        $this->table('comment_reports')->drop()->save();
        $this->table('audit_logs')->drop()->save();
    }
}
