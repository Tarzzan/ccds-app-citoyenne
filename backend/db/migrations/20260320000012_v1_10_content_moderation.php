<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V110ContentModeration extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('photos')) {
            $photos = $this->table('photos');

            if (!$photos->hasColumn('moderation_decided_at')) {
                $moderationStatusOptions = [
                    'values' => ['visible', 'reported', 'auto_hidden', 'hidden_by_admin', 'restored'],
                    'default' => 'visible',
                ];
                if ($photos->hasColumn('sort_order')) {
                    $moderationStatusOptions['after'] = 'sort_order';
                } elseif ($photos->hasColumn('file_size')) {
                    $moderationStatusOptions['after'] = 'file_size';
                }

                $photos
                    ->addColumn('moderation_status', 'enum', $moderationStatusOptions)
                    ->addColumn('moderation_reason', 'string', ['limit' => 60, 'null' => true, 'after' => 'moderation_status'])
                    ->addColumn('moderation_placeholder_key', 'string', ['limit' => 60, 'null' => true, 'after' => 'moderation_reason'])
                    ->addColumn('moderation_report_count', 'integer', ['default' => 0, 'after' => 'moderation_placeholder_key'])
                    ->addColumn('moderation_reported_at', 'datetime', ['null' => true, 'after' => 'moderation_report_count'])
                    ->addColumn('moderation_hidden_at', 'datetime', ['null' => true, 'after' => 'moderation_reported_at'])
                    ->addColumn('moderation_decided_by', 'integer', ['signed' => false, 'null' => true, 'after' => 'moderation_hidden_at'])
                    ->addColumn('moderation_decided_at', 'datetime', ['null' => true, 'after' => 'moderation_decided_by'])
                    ->addIndex(['moderation_status'])
                    ->addIndex(['moderation_report_count'])
                    ->addForeignKey('moderation_decided_by', 'users', 'id', ['delete' => 'SET_NULL'])
                    ->update();
            }
        }

        if ($this->hasTable('comments')) {
            $comments = $this->table('comments');

            if (!$comments->hasColumn('moderation_decided_at')) {
                $comments
                    ->addColumn('moderation_status', 'enum', [
                        'values' => ['visible', 'reported', 'auto_hidden', 'hidden_by_admin', 'restored'],
                        'default' => 'visible',
                        'after' => 'is_internal',
                    ])
                    ->addColumn('moderation_reason', 'string', ['limit' => 60, 'null' => true, 'after' => 'moderation_status'])
                    ->addColumn('moderation_report_count', 'integer', ['default' => 0, 'after' => 'moderation_reason'])
                    ->addColumn('moderation_reported_at', 'datetime', ['null' => true, 'after' => 'moderation_report_count'])
                    ->addColumn('moderation_hidden_at', 'datetime', ['null' => true, 'after' => 'moderation_reported_at'])
                    ->addColumn('moderation_decided_by', 'integer', ['signed' => false, 'null' => true, 'after' => 'moderation_hidden_at'])
                    ->addColumn('moderation_decided_at', 'datetime', ['null' => true, 'after' => 'moderation_decided_by'])
                    ->addIndex(['moderation_status'])
                    ->addIndex(['moderation_report_count'])
                    ->addForeignKey('moderation_decided_by', 'users', 'id', ['delete' => 'SET_NULL'])
                    ->update();
            }
        }

        if ($this->hasTable('comment_reports')) {
            $this->execute("UPDATE comment_reports SET reason = 'spam' WHERE reason = 'spam'");
            $this->execute("UPDATE comment_reports SET reason = 'harassment' WHERE reason = 'harassment'");
            $this->execute("UPDATE comment_reports SET reason = 'inappropriate' WHERE reason IN ('misinformation', 'other', 'inappropriate')");
            $this->execute("
                ALTER TABLE comment_reports
                MODIFY COLUMN reason ENUM(
                    'spam',
                    'harassment',
                    'insulting_or_aggressive',
                    'spam_or_advertising',
                    'inappropriate',
                    'personal_information'
                ) NOT NULL DEFAULT 'inappropriate'
            ");
            $this->execute("UPDATE comment_reports SET reason = 'spam_or_advertising' WHERE reason = 'spam'");
            $this->execute("UPDATE comment_reports SET reason = 'insulting_or_aggressive' WHERE reason = 'harassment'");
            $this->execute("
                ALTER TABLE comment_reports
                MODIFY COLUMN reason ENUM(
                    'insulting_or_aggressive',
                    'spam_or_advertising',
                    'inappropriate',
                    'personal_information'
                ) NOT NULL DEFAULT 'inappropriate'
            ");
        }

        if (!$this->hasTable('photo_reports')) {
            $this->table('photo_reports', ['id' => true, 'signed' => false])
                ->addColumn('photo_id', 'integer', ['signed' => false])
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('reporter_id', 'integer', ['signed' => false])
                ->addColumn('reason', 'enum', [
                    'values' => ['photo_under_18', 'photo_sensitive', 'photo_inappropriate'],
                    'default' => 'photo_inappropriate',
                ])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('status', 'enum', [
                    'values' => ['pending', 'dismissed', 'actioned'],
                    'default' => 'pending',
                ])
                ->addColumn('reviewed_by', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('reviewed_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['photo_id', 'reporter_id'], ['unique' => true])
                ->addIndex(['incident_id'])
                ->addIndex(['status'])
                ->addForeignKey('photo_id', 'photos', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('reporter_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('reviewed_by', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        if (!$this->hasTable('moderation_settings')) {
            $this->table('moderation_settings', ['id' => false, 'primary_key' => ['setting_key']])
                ->addColumn('setting_key', 'string', ['limit' => 100, 'null' => false])
                ->addColumn('setting_value', 'text')
                ->addColumn('updated_by', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addForeignKey('updated_by', 'users', 'id', ['delete' => 'SET_NULL'])
                ->create();
        }

        $defaults = [
            'photo_auto_hide_threshold' => '5',
            'comment_auto_hide_threshold' => '5',
            'photo_auto_hide_enabled' => '1',
            'comment_auto_hide_enabled' => '1',
            'photo_tie_breaker_order' => 'photo_under_18,photo_sensitive,photo_inappropriate',
            'photo_public_message_under_18' => 'Image retirée car réservée aux adultes.',
            'photo_public_message_sensitive' => 'Image retirée car sensible.',
            'photo_public_message_inappropriate' => 'Image retirée car non appropriée.',
            'comment_public_message' => 'Commentaire masqué par modération.',
        ];

        foreach ($defaults as $key => $value) {
            $escapedKey = addslashes($key);
            $escapedValue = addslashes($value);
            $this->execute("
                INSERT INTO moderation_settings (setting_key, setting_value, updated_at)
                VALUES ('{$escapedKey}', '{$escapedValue}', NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
        }
    }

    public function down(): void
    {
        if ($this->hasTable('photo_reports')) {
            $this->table('photo_reports')->drop()->save();
        }

        if ($this->hasTable('moderation_settings')) {
            $this->table('moderation_settings')->drop()->save();
        }

        if ($this->hasTable('photos') && $this->table('photos')->hasColumn('moderation_status')) {
            $photos = $this->table('photos');
            if ($photos->hasForeignKey('moderation_decided_by')) {
                $photos->dropForeignKey('moderation_decided_by')->save();
            }
            $photos
                ->removeIndex(['moderation_status'])
                ->removeIndex(['moderation_report_count'])
                ->removeColumn('moderation_status')
                ->removeColumn('moderation_reason')
                ->removeColumn('moderation_placeholder_key')
                ->removeColumn('moderation_report_count')
                ->removeColumn('moderation_reported_at')
                ->removeColumn('moderation_hidden_at')
                ->removeColumn('moderation_decided_by')
                ->removeColumn('moderation_decided_at')
                ->update();
        }

        if ($this->hasTable('comments') && $this->table('comments')->hasColumn('moderation_status')) {
            $comments = $this->table('comments');
            if ($comments->hasForeignKey('moderation_decided_by')) {
                $comments->dropForeignKey('moderation_decided_by')->save();
            }
            $comments
                ->removeIndex(['moderation_status'])
                ->removeIndex(['moderation_report_count'])
                ->removeColumn('moderation_status')
                ->removeColumn('moderation_reason')
                ->removeColumn('moderation_report_count')
                ->removeColumn('moderation_reported_at')
                ->removeColumn('moderation_hidden_at')
                ->removeColumn('moderation_decided_by')
                ->removeColumn('moderation_decided_at')
                ->update();
        }
    }
}
