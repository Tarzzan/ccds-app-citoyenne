<?php
/**
 * CCDS v1.2 — CategoryController (ADMIN-02 + TECH-01)
 *
 * GET    /api/categories        → Liste des catégories actives
 * POST   /api/categories        → Créer une catégorie (admin)
 * PUT    /api/categories/{id}   → Mettre à jour (admin)
 * DELETE /api/categories/{id}   → Supprimer (admin)
 */

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../core/Security.php';

class CategoryController extends BaseController
{
    // ----------------------------------------------------------------
    // GET /api/categories
    // ----------------------------------------------------------------
    public function index(): void
    {
        $showAll = !empty($_GET['all']); // admin peut voir les inactives

        $sql = $showAll
            ? 'SELECT id, name, icon, color, service, is_active FROM categories ORDER BY name ASC'
            : 'SELECT id, name, icon, color, service FROM categories WHERE is_active = 1 ORDER BY name ASC';

        $categories = $this->db->query($sql)->fetchAll();

        $this->success($categories);
    }

    // ----------------------------------------------------------------
    // POST /api/categories — Créer (admin)
    // ----------------------------------------------------------------
    public function store(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'category:create');

        $body = Security::getJsonBody();

        $this->validate($body, [
            'name'  => 'required|min:2|max:100',
            'icon'  => 'required|max:10',
            'color' => 'required|max:7',
        ]);

        // Vérifier l'unicité du nom
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
        $stmt->execute([trim($body['name'])]);
        if ($stmt->fetch()) {
            $this->error('Une catégorie avec ce nom existe déjà.', 409);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO categories (name, icon, color, service, is_active) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Security::sanitizeString($body['name']),
            Security::sanitizeString($body['icon']),
            Security::sanitizeString($body['color']),
            Security::sanitizeString($body['service'] ?? ''),
            isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
        ]);

        $id = (int)$this->db->lastInsertId();

        $this->success(['id' => $id], 201, 'Catégorie créée.');
    }

    // ----------------------------------------------------------------
    // PUT /api/categories/{id} — Mettre à jour (admin)
    // ----------------------------------------------------------------
    public function update(int $id): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'category:update');

        $body = Security::getJsonBody();

        $stmt = $this->db->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $this->error('Catégorie introuvable.', 404);
        }

        $sets   = [];
        $params = [];

        if (!empty($body['name'])) {
            $sets[]   = 'name = ?';
            $params[] = Security::sanitizeString($body['name']);
        }
        if (!empty($body['icon'])) {
            $sets[]   = 'icon = ?';
            $params[] = Security::sanitizeString($body['icon']);
        }
        if (!empty($body['color'])) {
            $sets[]   = 'color = ?';
            $params[] = Security::sanitizeString($body['color']);
        }
        if (isset($body['service'])) {
            $sets[]   = 'service = ?';
            $params[] = Security::sanitizeString($body['service']);
        }
        if (isset($body['is_active'])) {
            $sets[]   = 'is_active = ?';
            $params[] = (int)(bool)$body['is_active'];
        }

        if (empty($sets)) {
            $this->error('Aucun champ à mettre à jour.', 400);
        }

        $params[] = $id;
        $this->db->prepare('UPDATE categories SET ' . implode(', ', $sets) . ' WHERE id = ?')
                 ->execute($params);

        $this->success(['updated' => true], 200, 'Catégorie mise à jour.');
    }

    // ----------------------------------------------------------------
    // DELETE /api/categories/{id} — Supprimer (admin)
    // ----------------------------------------------------------------
    public function destroy(int $id): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'category:delete');

        $stmt = $this->db->prepare('SELECT id FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $this->error('Catégorie introuvable.', 404);
        }

        // Vérifier si des incidents utilisent cette catégorie
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM incidents WHERE category_id = ?');
        $stmt->execute([$id]);
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            // Désactiver plutôt que supprimer pour préserver l'intégrité
            $this->db->prepare('UPDATE categories SET is_active = 0 WHERE id = ?')->execute([$id]);
            $this->success(
                ['disabled' => true, 'incidents_count' => $count],
                200,
                "Catégorie désactivée (elle est utilisée par $count signalement(s))."
            );
        }

        $this->db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        $this->success(['deleted' => true], 200, 'Catégorie supprimée.');
    }
}
