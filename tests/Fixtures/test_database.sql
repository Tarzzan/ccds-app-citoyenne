-- ============================================================
-- CCDS — Base de données de TEST (ccds_test)
-- À exécuter avant de lancer la suite de tests d'intégration.
-- ============================================================

CREATE DATABASE IF NOT EXISTS ccds_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ccds_test;

-- Reprendre le schéma complet depuis docs/database.sql
-- puis insérer les fixtures de test ci-dessous.

-- Compte admin de test
INSERT IGNORE INTO users (id, full_name, email, password, role, is_active, created_at)
VALUES (
    1,
    'Admin Test',
    'admin@ccds-test.fr',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: "password"
    'admin',
    1,
    NOW()
);

-- Agent de test
INSERT IGNORE INTO users (id, full_name, email, password, role, is_active, created_at)
VALUES (
    2,
    'Agent Test',
    'agent@ccds-test.fr',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: "password"
    'agent',
    1,
    NOW()
);

-- Citoyen de test
INSERT IGNORE INTO users (id, full_name, email, password, role, is_active, created_at)
VALUES (
    3,
    'Citoyen Test',
    'citoyen@ccds-test.fr',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- password: "password"
    'citizen',
    1,
    NOW()
);

-- Catégories de test (reprises du schéma principal)
INSERT IGNORE INTO categories (id, name, description, color, responsible_service, is_active) VALUES
(1, 'Voirie',          'Trous, nids-de-poule, chaussée dégradée',   '#ef4444', 'Service Voirie',          1),
(2, 'Éclairage',       'Lampadaires défaillants ou éteints',         '#f59e0b', 'Service Électricité',     1),
(3, 'Espaces verts',   'Entretien parcs, arbres, pelouses',          '#22c55e', 'Service Espaces Verts',   1),
(4, 'Mobilier urbain', 'Bancs, poubelles, panneaux dégradés',        '#3b82f6', 'Service Propreté',        1),
(5, 'Propreté',        'Dépôts sauvages, tags, déchets',             '#8b5cf6', 'Service Propreté',        1),
(6, 'Signalisation',   'Panneaux manquants ou illisibles',           '#06b6d4', 'Service Voirie',          1),
(7, 'Bâtiments',       'Dégradations sur bâtiments municipaux',      '#f97316', 'Service Patrimoine',      1),
(8, 'Autre',           'Signalement ne rentrant pas dans les autres', '#6b7280', 'Service Général',        1);

-- Incidents de test
INSERT IGNORE INTO incidents (id, reference, user_id, category_id, description, latitude, longitude, address, status, priority, created_at)
VALUES
(1, 'CCDS-20260226-TEST1', 3, 1, 'Nid-de-poule dangereux rue de la Paix', 48.8566, 2.3522, 'Rue de la Paix, Paris', 'submitted',   'high',   NOW()),
(2, 'CCDS-20260226-TEST2', 3, 2, 'Lampadaire éteint depuis 3 jours',       48.8570, 2.3530, 'Avenue de l\'Opéra',    'in_progress', 'medium', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3, 'CCDS-20260226-TEST3', 3, 3, 'Pelouse non tondue depuis 1 mois',       48.8580, 2.3510, 'Parc Monceau',          'resolved',    'low',    DATE_SUB(NOW(), INTERVAL 7 DAY));
