<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V111GoogleAuthPrep extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $users = $this->table('users');

        if (!$users->hasColumn('auth_provider')) {
            $users
                ->addColumn('auth_provider', 'enum', [
                    'values' => ['local', 'google'],
                    'default' => 'local',
                    'after' => 'role',
                ])
                ->addIndex(['auth_provider'])
                ->update();
        }

        if (!$users->hasColumn('auth_provider_subject')) {
            $users
                ->addColumn('auth_provider_subject', 'string', [
                    'limit' => 191,
                    'null' => true,
                    'after' => 'auth_provider',
                ])
                ->addIndex(['auth_provider_subject'], ['unique' => true])
                ->update();
        }

        if (!$users->hasColumn('avatar_url')) {
            $users
                ->addColumn('avatar_url', 'string', [
                    'limit' => 255,
                    'null' => true,
                    'after' => 'phone',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('users')) {
            return;
        }

        $users = $this->table('users');

        if ($users->hasColumn('auth_provider_subject')) {
            $users
                ->removeIndex(['auth_provider_subject'])
                ->removeColumn('auth_provider_subject')
                ->update();
        }

        if ($users->hasColumn('auth_provider')) {
            $users
                ->removeIndex(['auth_provider'])
                ->removeColumn('auth_provider')
                ->update();
        }

        if ($users->hasColumn('avatar_url')) {
            $users
                ->removeColumn('avatar_url')
                ->update();
        }
    }
}
