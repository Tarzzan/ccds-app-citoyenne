<?php

/**
 * CCDS — UserController
 * Gestion des utilisateurs (endpoints admin).
 *
 * GET    /api/admin/users              → Liste paginée avec filtres
 * GET    /api/admin/users/{id}         → Détail d'un utilisateur + stats
 * PUT    /api/admin/users/{id}         → Modifier rôle / statut actif
 * GET    /api/admin/users/{id}/activity → Historique d'activité (incidents, votes, commentaires)
 * GET    /api/admin/stats/users        → KPIs globaux utilisateurs
 */
class UserController extends BaseController
{
    // ─────────────────────────────────────────────────────────
    // GET /api/admin/users
    // ─────────────────────────────────────────────────────────
    public function index(): void
    {
        $this->requireRole('agent');
        Permissions::check($this->user, 'users.view');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $this->getPagination();

        $role     = Security::sanitizeString($_GET['role']   ?? '');
        $status   = $_GET['status']  ?? '';   // active | inactive
        $search   = Security::sanitizeString($_GET['q']      ?? '');
        $sort     = in_array($_GET['sort'] ?? '', ['name', 'email', 'created_at', 'incidents_count'])
                    ? $_GET['sort'] : 'created_at';
        $dir      = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $where  = ['1=1'];
        $params = [];

        if ($role && in_array($role, ['citizen', 'agent', 'admin'])) {
            $where[]  = 'u.role = ?';
            $params[] = $role;
        }
        if ($status === 'active') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.is_active = 0';
        }
        if ($search) {
            $like     = '%' . $search . '%';
            $where[]  = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSQL = implode(' AND ', $where);

        // Comptage total
        $stmtCount = $this->db->prepare("
            SELECT COUNT(*) FROM users u WHERE $whereSQL
        ");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Tri par colonne calculée
        $sortSQL = match ($sort) {
            'name'            => 'u.full_name',
            'email'           => 'u.email',
            'incidents_count' => 'incidents_count',
            default           => 'u.created_at',
        };

        $stmtUsers = $this->db->prepare("
            SELECT
                u.id, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at,
                COUNT(DISTINCT i.id)  AS incidents_count,
                COUNT(DISTINCT v.id)  AS votes_count,
                COUNT(DISTINCT c.id)  AS comments_count,
                MAX(i.created_at)     AS last_incident_at
            FROM users u
            LEFT JOIN incidents i ON i.user_id = u.id
            LEFT JOIN votes     v ON v.user_id = u.id
            LEFT JOIN comments  c ON c.user_id = u.id
            WHERE $whereSQL
            GROUP BY u.id
            ORDER BY $sortSQL $dir
            LIMIT $limit OFFSET $offset
        ");
        $stmtUsers->execute($params);
        $users = $stmtUsers->fetchAll();

        $this->success($this->paginatedResponse($users, $total, $page, $limit));
    }

    // ─────────────────────────────────────────────────────────
    // GET /api/admin/users/{id}
    // ─────────────────────────────────────────────────────────
    public function show(int $id): void
    {
        $this->requireRole('agent');
        Permissions::check($this->user, 'users.view');

        $stmt = $this->db->prepare("
            SELECT
                u.id, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at,
                u.notification_status_change, u.notification_new_comment, u.notification_vote_milestone,
                COUNT(DISTINCT i.id)   AS incidents_count,
                COUNT(DISTINCT v.id)   AS votes_count,
                COUNT(DISTINCT c.id)   AS comments_count,
                COALESCE(g.points, 0)  AS gamification_points,
                MAX(i.created_at)      AS last_incident_at
            FROM users u
            LEFT JOIN incidents      i ON i.user_id = u.id
            LEFT JOIN votes          v ON v.user_id = u.id
            LEFT JOIN comments       c ON c.user_id = u.id
            LEFT JOIN user_gamification g ON g.user_id = u.id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->error('Utilisateur introuvable', 404);
        }

        // Derniers signalements
        $stmtInc = $this->db->prepare("
            SELECT i.id, i.reference, i.title, i.status, i.votes_count, i.created_at,
                   cat.name AS category_name, cat.icon AS category_icon
            FROM incidents i
            JOIN categories cat ON cat.id = i.category_id
            WHERE i.user_id = ?
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $stmtInc->execute([$id]);
        $user['recent_incidents'] = $stmtInc->fetchAll();

        // Badges
        $stmtBadges = $this->db->prepare("
            SELECT badge_key, awarded_at FROM user_badges WHERE user_id = ? ORDER BY awarded_at DESC
        ");
        $stmtBadges->execute([$id]);
        $user['badges'] = $stmtBadges->fetchAll();

        $this->success($user);
    }

    // ─────────────────────────────────────────────────────────
    // PUT /api/admin/users/{id}
    // ─────────────────────────────────────────────────────────
    public function update(int $id): void
    {
        $this->requireRole('admin');
        Permissions::check($this->user, 'users.manage');

        if ($id === $this->user['id']) {
            $this->error('Vous ne pouvez pas modifier votre propre compte via cet endpoint', 403);
        }

        $data = $this->getJsonBody();
        $sets = [];
        $params = [];

        if (isset($data['role']) && in_array($data['role'], ['citizen', 'agent', 'admin'])) {
            $sets[]   = 'role = ?';
            $params[] = $data['role'];
        }
        if (isset($data['is_active'])) {
            $sets[]   = 'is_active = ?';
            $params[] = (int)(bool)$data['is_active'];
        }
        if (empty($sets)) {
            $this->error('Aucune modification valide fournie', 400);
        }

        $params[] = $id;
        $this->db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        $this->show($id);
    }

    // ─────────────────────────────────────────────────────────
    // GET /api/admin/users/{id}/activity
    // ─────────────────────────────────────────────────────────
    public function activity(int $id): void
    {
        $this->requireRole('agent');
        Permissions::check($this->user, 'users.view');

        // Vérifier que l'utilisateur existe
        $check = $this->db->prepare("SELECT id FROM users WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            $this->error('Utilisateur introuvable', 404);
        }

        // Incidents
        $stmtInc = $this->db->prepare("
            SELECT 'incident' AS type, i.id, i.reference AS ref, i.title AS label,
                   i.status, i.created_at AS date
            FROM incidents i WHERE i.user_id = ?
            ORDER BY i.created_at DESC LIMIT 20
        ");
        $stmtInc->execute([$id]);

        // Commentaires
        $stmtCom = $this->db->prepare("
            SELECT 'comment' AS type, c.id, i.reference AS ref,
                   LEFT(c.comment, 80) AS label, NULL AS status, c.created_at AS date
            FROM comments c JOIN incidents i ON i.id = c.incident_id
            WHERE c.user_id = ?
            ORDER BY c.created_at DESC LIMIT 20
        ");
        $stmtCom->execute([$id]);

        // Votes
        $stmtVot = $this->db->prepare("
            SELECT 'vote' AS type, v.id, i.reference AS ref,
                   i.title AS label, NULL AS status, v.created_at AS date
            FROM votes v JOIN incidents i ON i.id = v.incident_id
            WHERE v.user_id = ?
            ORDER BY v.created_at DESC LIMIT 20
        ");
        $stmtVot->execute([$id]);

        $activity = array_merge(
            $stmtInc->fetchAll(),
            $stmtCom->fetchAll(),
            $stmtVot->fetchAll()
        );

        // Trier par date décroissante
        usort($activity, fn($a, $b) => strcmp($b['date'], $a['date']));

        $this->success(array_slice($activity, 0, 30));
    }

    // ─────────────────────────────────────────────────────────
    // GET /api/admin/stats/users
    // ─────────────────────────────────────────────────────────
    public function stats(): void
    {
        $this->requireRole('agent');
        Permissions::check($this->user, 'users.view');

        $stmt = $this->db->query("
            SELECT
                COUNT(*)                                    AS total_users,
                SUM(role = 'citizen')                       AS citizens,
                SUM(role = 'agent')                         AS agents,
                SUM(role = 'admin')                         AS admins,
                SUM(is_active = 1)                          AS active_users,
                SUM(is_active = 0)                          AS inactive_users,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_last_30d,
                SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))  AS new_last_7d
            FROM users
        ");
        $stats = $stmt->fetch();

        // Top citoyens par signalements
        $stmtTop = $this->db->query("
            SELECT u.id, u.full_name, u.email, COUNT(i.id) AS incidents_count
            FROM users u
            JOIN incidents i ON i.user_id = u.id
            WHERE u.role = 'citizen'
            GROUP BY u.id
            ORDER BY incidents_count DESC
            LIMIT 5
        ");
        $stats['top_contributors'] = $stmtTop->fetchAll();

        $this->success($stats);
    }
}
