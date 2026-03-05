<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration v1.4 — Photos multiples, threading commentaires
 */
final class V14PhotosCommentsThreading extends AbstractMigration
{
    public function change(): void
    {
        // -----------------------------------------------------------------
        // Table photos (upload multiple)
        // -----------------------------------------------------------------
        if (!$this->hasTable('photos')) {
            $this->table('photos')
                ->addColumn('incident_id', 'integer',  ['null' => false, 'signed' => false])
                ->addColumn('file_path',   'string',   ['limit' => 255, 'null' => false])
                ->addColumn('file_name',   'string',   ['limit' => 255, 'null' => false])
                ->addColumn('mime_type',   'string',   ['limit' => 50,  'null' => false])
                ->addColumn('file_size',   'integer',  ['null' => false])
                ->addColumn('sort_order',  'integer',  ['default' => 0])
                ->addColumn('created_at',  'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addIndex('incident_id')
                ->create();
        }

        // -----------------------------------------------------------------
        // Threading des commentaires (parent_id, is_edited, updated_at)
        // -----------------------------------------------------------------
        if ($this->hasTable('comments')) {
            $comments = $this->table('comments');
            if (!$comments->hasColumn('parent_id')) {
                $comments
                    ->addColumn('parent_id',  'integer',  ['null' => true, 'signed' => false, 'after' => 'incident_id'])
                    ->addColumn('is_edited',  'boolean',  ['default' => false])
                    ->addColumn('is_flagged', 'boolean',  ['default' => false])
                    ->addColumn('updated_at', 'datetime', ['null' => true])
                    ->addForeignKey('parent_id', 'comments', 'id', ['delete' => 'SET_NULL'])
                    ->update();
            }
        }
    }
}
