<?php
/**
 * PublicApiController — API Publique lecture seule (API-02)
 *
 * Expose des données anonymisées aux tiers (médias, chercheurs, développeurs).
 * Authentification par clé API (header X-API-Key).
 * Rate limiting : 100 requêtes/heure par IP.
 */
class PublicApiController extends BaseController
{
    /**
     * Vérifie la clé API publique (optionnelle — augmente les limites si présente).
     */
    private function checkApiKey(): ?string
    {
        return $_SERVER['HTTP_X_API_KEY'] ?? null;
    }

    /**
     * GET /public/incidents
     * Liste paginée des signalements anonymisés.
     *
     * Paramètres : page, per_page, status, category_id, since, bbox
     */
    public function incidents(): void
    {
        $this->applyRateLimit('public_api');

        $page       = max(1, (int) ($_GET['page']        ?? 1));
        $perPage    = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset     = ($page - 1) * $perPage;
        $status     = $_GET['status']      ?? null;
        $categoryId = $_GET['category_id'] ?? null;
        $since      = $_GET['since']       ?? null;
        $bbox       = $_GET['bbox']        ?? null; // "lat_min,lng_min,lat_max,lng_max"

        $where  = ['i.status != "deleted"'];
        $params = [];

        if ($status)     { $where[] = 'i.status = ?';      $params[] = $status; }
        if ($categoryId) { $where[] = 'i.category_id = ?'; $params[] = $categoryId; }
        if ($since)      { $where[] = 'i.created_at >= ?'; $params[] = $since; }
        if ($bbox) {
            $coords = explode(',', $bbox);
            if (count($coords) === 4) {
                $where[]  = 'i.latitude BETWEEN ? AND ?';
                $where[]  = 'i.longitude BETWEEN ? AND ?';
                $params[] = (float) $coords[0];
                $params[] = (float) $coords[2];
                $params[] = (float) $coords[1];
                $params[] = (float) $coords[3];
            }
        }

        $whereClause = implode(' AND ', $where);

        $total = $this->db->prepare("SELECT COUNT(*) FROM incidents i WHERE {$whereClause}");
        $total->execute($params);
        $totalCount = (int) $total->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT
                i.id,
                i.title,
                i.description,
                i.address,
                i.latitude,
                i.longitude,
                i.status,
                i.votes_count,
                c.name AS category,
                c.icon AS category_icon,
                i.created_at,
                i.updated_at
                -- Pas de user_id ni d'email : anonymisation RGPD
            FROM incidents i
            LEFT JOIN categories c ON c.id = i.category_id
            WHERE {$whereClause}
            ORDER BY i.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmt->execute($params);
        $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->success([
            'data'       => $incidents,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $totalCount,
                'total_pages' => (int) ceil($totalCount / $perPage),
            ],
            'meta' => [
                'source'    => defined('APP_NAME') ? APP_NAME : 'Ma Commune',
                'license'   => 'Open Data Commons Attribution License (ODC-By)',
                'generated' => date('c'),
            ],
        ]);
    }

    /**
     * GET /public/stats
     * Statistiques globales anonymisées.
     */
    public function stats(): void
    {
        $this->applyRateLimit('public_api');

        $stats = [
            'total_incidents'   => (int) $this->db->query("SELECT COUNT(*) FROM incidents")->fetchColumn(),
            'resolved'          => (int) $this->db->query("SELECT COUNT(*) FROM incidents WHERE status = 'resolved'")->fetchColumn(),
            'in_progress'       => (int) $this->db->query("SELECT COUNT(*) FROM incidents WHERE status = 'in_progress'")->fetchColumn(),
            'submitted'         => (int) $this->db->query("SELECT COUNT(*) FROM incidents WHERE status = 'submitted'")->fetchColumn(),
            'total_votes'       => (int) $this->db->query("SELECT SUM(votes_count) FROM incidents")->fetchColumn(),
            'total_citizens'    => (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'citizen' AND is_active = 1")->fetchColumn(),
            'resolution_rate'   => null,
        ];

        if ($stats['total_incidents'] > 0) {
            $stats['resolution_rate'] = round($stats['resolved'] / $stats['total_incidents'] * 100, 1);
        }

        // Top 5 catégories
        $catStmt = $this->db->query("
            SELECT c.name, c.icon, COUNT(i.id) AS count
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            GROUP BY c.id
            ORDER BY count DESC
            LIMIT 5
        ");
        $stats['top_categories'] = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->success($stats);
    }

    /**
     * GET /public/categories
     * Liste des catégories disponibles.
     */
    public function categories(): void
    {
        $this->applyRateLimit('public_api');

        $stmt = $this->db->query("
            SELECT id, name, icon,
                   (SELECT COUNT(*) FROM incidents WHERE category_id = categories.id) AS incident_count
            FROM categories
            WHERE is_active = 1
            ORDER BY name ASC
        ");
        $this->success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
