-- =============================================================
-- Ma Commune — Application citoyenne territoriale
-- Schéma de la Base de Données MySQL
-- Version : 1.0.0 | Date : 2026-02-26
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- Base de données
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `ma_commune_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ma_commune_db`;

-- -------------------------------------------------------------
-- Table : users
-- Stocke les comptes citoyens, agents et administrateurs.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(255)    NOT NULL,
    `password_hash`VARCHAR(255)    NOT NULL,
    `full_name`    VARCHAR(255)    NOT NULL DEFAULT '',
    `phone`        VARCHAR(30)     DEFAULT NULL,
    `role`         ENUM('citizen','agent','admin') NOT NULL DEFAULT 'citizen',
    `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table : categories
-- Catégories de signalement (Voirie, Éclairage, Espaces Verts…)
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100)  NOT NULL,
    `slug`        VARCHAR(100)  NOT NULL,
    `icon`        VARCHAR(50)   NOT NULL DEFAULT 'alert-circle',
    `color`       VARCHAR(7)    NOT NULL DEFAULT '#6b7280',
    `description` TEXT          DEFAULT NULL,
    `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
    `sort_order`  INT           NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table : incidents
-- Table principale des signalements citoyens.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `incidents` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `reference`    VARCHAR(20)    NOT NULL COMMENT 'Référence lisible ex: MC-2026-00001',
    `user_id`      INT UNSIGNED   NOT NULL,
    `category_id`  INT UNSIGNED   NOT NULL,
    `title`        VARCHAR(255)   NOT NULL DEFAULT '',
    `description`  TEXT           NOT NULL,
    `latitude`     DECIMAL(10,8)  NOT NULL,
    `longitude`    DECIMAL(11,8)  NOT NULL,
    `address`      VARCHAR(500)   DEFAULT NULL COMMENT 'Adresse géocodée inverse',
    `status`       ENUM(
                     'submitted',
                     'acknowledged',
                     'in_progress',
                     'resolved',
                     'rejected'
                   ) NOT NULL DEFAULT 'submitted',
    `priority`     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `assigned_to`  INT UNSIGNED   DEFAULT NULL COMMENT 'ID de l\'agent assigné',
    `resolved_at`  TIMESTAMP      DEFAULT NULL,
    `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_incidents_reference` (`reference`),
    KEY `idx_incidents_user`     (`user_id`),
    KEY `idx_incidents_category` (`category_id`),
    KEY `idx_incidents_status`   (`status`),
    KEY `idx_incidents_location` (`latitude`, `longitude`),
    CONSTRAINT `fk_incidents_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)      ON DELETE RESTRICT,
    CONSTRAINT `fk_incidents_category` FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_incidents_agent`    FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table : photos
-- Photos attachées à un signalement.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `photos` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `incident_id` INT UNSIGNED  NOT NULL,
    `file_path`   VARCHAR(500)  NOT NULL,
    `file_name`   VARCHAR(255)  NOT NULL,
    `mime_type`   VARCHAR(50)   NOT NULL DEFAULT 'image/jpeg',
    `file_size`   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Taille en octets',
    `uploaded_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_photos_incident` (`incident_id`),
    CONSTRAINT `fk_photos_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table : comments
-- Commentaires sur un signalement (publics ou internes agents).
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comments` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `incident_id` INT UNSIGNED  NOT NULL,
    `user_id`     INT UNSIGNED  NOT NULL,
    `comment`     TEXT          NOT NULL,
    `is_internal` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = note interne agents uniquement',
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_comments_incident` (`incident_id`),
    KEY `idx_comments_user`     (`user_id`),
    CONSTRAINT `fk_comments_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comments_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Table : status_history
-- Historique des changements de statut d'un signalement.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `status_history` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `incident_id` INT UNSIGNED  NOT NULL,
    `user_id`     INT UNSIGNED  NOT NULL,
    `old_status`  VARCHAR(30)   DEFAULT NULL,
    `new_status`  VARCHAR(30)   NOT NULL,
    `note`        TEXT          DEFAULT NULL,
    `changed_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_history_incident` (`incident_id`),
    CONSTRAINT `fk_history_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_history_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================
-- Données initiales (seed)
-- =============================================================

-- Catégories par défaut
INSERT INTO `categories` (`name`, `slug`, `icon`, `color`, `description`, `sort_order`) VALUES
('Voirie & Chaussée',    'voirie',         'road',          '#D96B2B', 'Nids-de-poule, affaissements, fissures sur la chaussée.',         1),
('Éclairage Public',     'eclairage',      'lightbulb',     '#D9A22E', 'Luminaires en panne, câbles apparents, ampoules à remplacer.',    2),
('Espaces Verts',        'espaces-verts',  'tree',          '#2E8B57', 'Végétation non entretenue, arbres dangereux, pelouses.',          3),
('Propreté & Déchets',   'proprete',       'trash',         '#7C4FD9', 'Dépôts sauvages, poubelles débordantes, tags et graffitis.',      4),
('Mobilier Urbain',      'mobilier',       'bench',         '#287C96', 'Bancs, abribus, panneaux, barrières endommagés.',                 5),
('Réseaux & Inondations','reseaux',        'droplets',      '#2676D2', 'Inondations, bouches d\'égout bouchées, fuites d\'eau.',          6),
('Signalisation',        'signalisation',  'triangle-alert','#E07A22', 'Panneaux manquants, marquages au sol effacés, feux défaillants.', 7),
('Bâtiments Communaux',  'batiments',      'building-2',    '#5A6F7F', 'Dégradations sur les bâtiments et équipements municipaux.',      8);

-- Compte administrateur par défaut (mot de passe : admin@test.fr — à changer impérativement)
-- Hash bcrypt généré pour 'admin@test.fr'
INSERT INTO `users` (`email`, `password_hash`, `full_name`, `role`) VALUES
('admin@macommune.local', '$2y$12$cSU1MFVZM/eIVG4ytzwwjOnGBFKI9WuCQV9wakNvxafE8S30zJ8mG', 'Administrateur Ma Commune', 'admin');

SET FOREIGN_KEY_CHECKS = 1;
