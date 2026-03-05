<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration 001 — Schéma initial CCDS Citoyen
 * Crée toutes les tables de base : users, categories, incidents, photos,
 * comments, status_history, votes, push_tokens, notifications.
 *
 * Idempotente : chaque table est créée uniquement si elle n'existe pas déjà.
 * NOTE: Toutes les colonnes FK utilisent ['signed' => false] pour être
 * compatibles avec les id UNSIGNED générés par Phinx sur MySQL 8.0.
 */
final class InitialSchema extends AbstractMigration
{
    public function change(): void
    {
        // ── users ──────────────────────────────────────────────
        if (!$this->hasTable('users')) {
            $this->table('users')
                ->addColumn('full_name', 'string', ['limit' => 100])
                ->addColumn('email', 'string', ['limit' => 150])
                ->addColumn('password', 'string', ['limit' => 255])
                ->addColumn('phone', 'string', ['limit' => 20, 'null' => true])
                ->addColumn('role', 'enum', ['values' => ['citizen', 'agent', 'admin'], 'default' => 'citizen'])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('notification_status_change', 'boolean', ['default' => true])
                ->addColumn('notification_new_comment', 'boolean', ['default' => true])
                ->addColumn('notification_vote_milestone', 'boolean', ['default' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['email'], ['unique' => true])
                ->create();
        }

        // ── categories ─────────────────────────────────────────
        if (!$this->hasTable('categories')) {
            $this->table('categories')
                ->addColumn('name', 'string', ['limit' => 80])
                ->addColumn('icon', 'string', ['limit' => 10, 'default' => '📌'])
                ->addColumn('color', 'string', ['limit' => 7, 'default' => '#2563EB'])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        // ── incidents ──────────────────────────────────────────
        if (!$this->hasTable('incidents')) {
            $this->table('incidents')
                ->addColumn('reference', 'string', ['limit' => 30])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('category_id', 'integer', ['signed' => false])
                ->addColumn('title', 'string', ['limit' => 200])
                ->addColumn('description', 'text', ['null' => true])
                ->addColumn('address', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('latitude', 'decimal', ['precision' => 10, 'scale' => 7])
                ->addColumn('longitude', 'decimal', ['precision' => 10, 'scale' => 7])
                ->addColumn('status', 'enum', ['values' => ['submitted', 'in_progress', 'resolved', 'rejected'], 'default' => 'submitted'])
                ->addColumn('votes_count', 'integer', ['default' => 0])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['reference'], ['unique' => true])
                ->addIndex(['user_id'])
                ->addIndex(['category_id'])
                ->addIndex(['status'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'RESTRICT'])
                ->create();
        }

        // ── photos ─────────────────────────────────────────────
        if (!$this->hasTable('photos')) {
            $this->table('photos')
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('file_path', 'string', ['limit' => 255])
                ->addColumn('file_name', 'string', ['limit' => 100])
                ->addColumn('mime_type', 'string', ['limit' => 50])
                ->addColumn('file_size', 'integer')
                ->addColumn('sort_order', 'integer', ['default' => 0])
                ->addColumn('uploaded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['incident_id'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── comments ───────────────────────────────────────────
        if (!$this->hasTable('comments')) {
            $this->table('comments')
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('parent_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('comment', 'text')
                ->addColumn('is_edited', 'boolean', ['default' => false])
                ->addColumn('edited_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['incident_id'])
                ->addIndex(['user_id'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── status_history ─────────────────────────────────────
        if (!$this->hasTable('status_history')) {
            $this->table('status_history')
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('changed_by', 'integer', ['signed' => false])
                ->addColumn('old_status', 'string', ['limit' => 30])
                ->addColumn('new_status', 'string', ['limit' => 30])
                ->addColumn('comment', 'text', ['null' => true])
                ->addColumn('changed_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['incident_id'])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── votes ──────────────────────────────────────────────
        if (!$this->hasTable('votes')) {
            $this->table('votes')
                ->addColumn('incident_id', 'integer', ['signed' => false])
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['incident_id', 'user_id'], ['unique' => true])
                ->addForeignKey('incident_id', 'incidents', 'id', ['delete' => 'CASCADE'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── push_tokens ────────────────────────────────────────
        if (!$this->hasTable('push_tokens')) {
            $this->table('push_tokens')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('token', 'string', ['limit' => 255])
                ->addColumn('platform', 'enum', ['values' => ['ios', 'android', 'web'], 'default' => 'android'])
                ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_id', 'token'], ['unique' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── notifications ──────────────────────────────────────
        if (!$this->hasTable('notifications')) {
            $this->table('notifications')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('incident_id', 'integer', ['null' => true, 'signed' => false])
                ->addColumn('type', 'string', ['limit' => 50])
                ->addColumn('title', 'string', ['limit' => 200])
                ->addColumn('body', 'text')
                ->addColumn('is_read', 'boolean', ['default' => false])
                ->addColumn('sent_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_id'])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── user_gamification ──────────────────────────────────
        if (!$this->hasTable('user_gamification')) {
            $this->table('user_gamification', ['id' => false, 'primary_key' => ['user_id']])
                ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('points', 'integer', ['default' => 0])
                ->addColumn('last_action_at', 'datetime', ['null' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }

        // ── user_badges ────────────────────────────────────────
        if (!$this->hasTable('user_badges')) {
            $this->table('user_badges')
                ->addColumn('user_id', 'integer', ['signed' => false])
                ->addColumn('badge_key', 'string', ['limit' => 50])
                ->addColumn('awarded_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                ->addIndex(['user_id', 'badge_key'], ['unique' => true])
                ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
                ->create();
        }
    }
}
