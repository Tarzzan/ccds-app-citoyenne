-- ============================================================
-- Migration v1.1 — Améliorations CCDS Citoyen
-- Fonctionnalités : Votes "Moi aussi", Notifications Push
-- ============================================================

-- 1. Table des votes "Moi aussi"
CREATE TABLE IF NOT EXISTS `votes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL COMMENT 'NULL si vote anonyme',
  `ip_address`  VARCHAR(45)  NOT NULL DEFAULT '',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vote_user`      (`incident_id`, `user_id`),
  UNIQUE KEY `uq_vote_ip`        (`incident_id`, `ip_address`),
  KEY `idx_incident_id`          (`incident_id`),
  CONSTRAINT `fk_votes_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_votes_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ajouter le compteur de votes dénormalisé sur incidents (performance)
ALTER TABLE `incidents`
  ADD COLUMN IF NOT EXISTS `votes_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `priority`;

-- 3. Table des tokens de notifications push
CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `platform`   ENUM('ios','android','web') NOT NULL DEFAULT 'android',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_push_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Table des notifications envoyées (historique)
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `incident_id` INT UNSIGNED NULL,
  `type`        ENUM('status_change','new_comment','vote_milestone','system') NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `body`        TEXT NOT NULL,
  `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
  `sent_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_notif`     (`user_id`, `is_read`),
  KEY `idx_incident_notif` (`incident_id`),
  CONSTRAINT `fk_notif_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_notif_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Ajouter la colonne offline_queue_id pour traçabilité des signalements hors-ligne
ALTER TABLE `incidents`
  ADD COLUMN IF NOT EXISTS `offline_id` VARCHAR(36) NULL UNIQUE COMMENT 'UUID généré côté mobile en mode hors-ligne' AFTER `reference`;
