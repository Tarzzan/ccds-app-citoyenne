<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.5 — Authentification à deux facteurs (SEC-03)
 */
final class V15TwoFactorAuth extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('users')) {
            $users = $this->table('users');

            if (!$users->hasColumn('two_factor_method')) {
                $users
                    ->addColumn('two_factor_method',         'string',  ['limit' => 20, 'default' => 'none'])
                    ->addColumn('two_factor_secret',         'string',  ['limit' => 255, 'null' => true])
                    ->addColumn('two_factor_recovery_codes', 'text',    ['null' => true])
                    ->update();
            }
        }

        // Table audit_logs (ADMIN-08)
        if (!$this->hasTable('audit_logs')) {
            $this->table('audit_logs')
                ->addColumn('admin_id',   'integer',  ['null' => false, 'signed' => false])
                ->addColumn('action',     'string',   ['limit' => 100, 'null' => false])
                ->addColumn('entity',     'string',   ['limit' => 50,  'null' => false])
                ->addColumn('entity_id',  'integer',  ['null' => true, 'signed' => false])
                ->addColumn('old_value',  'text',     ['null' => true])
                ->addColumn('new_value',  'text',     ['null' => true])
                ->addColumn('ip_address', 'string',   ['limit' => 45, 'null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('admin_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addIndex('admin_id')
                ->addIndex('entity')
                ->create();
        }

        // Colonne is_flagged sur comments (ADMIN-07)
        if ($this->hasTable('comments') && !$this->table('comments')->hasColumn('is_flagged')) {
            $this->table('comments')
                ->addColumn('is_flagged', 'boolean', ['default' => false])
                ->update();
        }
    }
}
