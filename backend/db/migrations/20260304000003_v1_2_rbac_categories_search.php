<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.2 — RBAC, icônes catégories, colonnes profil utilisateur
 */
final class V12RbacCategoriesSearch extends AbstractMigration
{
    public function change(): void
    {
        // Colonne icon sur categories
        if ($this->hasTable('categories') && !$this->table('categories')->hasColumn('icon')) {
            $this->table('categories')
                ->addColumn('icon', 'string', ['limit' => 10, 'default' => '📌', 'after' => 'name'])
                ->update();
        }

        // Colonnes préférences de notifications sur users
        if ($this->hasTable('users')) {
            $users = $this->table('users');
            if (!$users->hasColumn('notification_status_change')) {
                $users->addColumn('notification_status_change',  'boolean', ['default' => true])->update();
            }
            if (!$users->hasColumn('notification_new_comment')) {
                $users->addColumn('notification_new_comment',    'boolean', ['default' => true])->update();
            }
            if (!$users->hasColumn('notification_vote_milestone')) {
                $users->addColumn('notification_vote_milestone', 'boolean', ['default' => false])->update();
            }
        }
    }
}
