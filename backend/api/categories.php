<?php
/**
 * CCDS — API Catégories
 * GET /api/categories      → Liste toutes les catégories actives
 * GET /api/categories/{id} → Détail d'une catégorie
 */

function handle_categories(string $method, ?int $id): void
{
    $db = Database::getInstance();

    if ($method === 'GET') {
        if ($id) {
            // Détail d'une catégorie
            $stmt = $db->prepare('SELECT * FROM categories WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$id]);
            $cat = $stmt->fetch();
            if (!$cat) {
                json_error('Catégorie introuvable.', 404);
            }
            json_success($cat);
        } else {
            // Liste de toutes les catégories actives
            $stmt = $db->query(
                'SELECT id, name, slug, icon, color, description FROM categories
                 WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
            );
            json_success($stmt->fetchAll());
        }
    } else {
        json_error('Méthode non autorisée.', 405);
    }
}
