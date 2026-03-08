<?php
/**
 * CCDS v1.2 — AuthController (TECH-01 + UX-03)
 *
 * POST /api/register        → Inscription
 * POST /api/login           → Connexion
 * GET  /api/profile         → Lire son profil
 * PUT  /api/profile         → Mettre à jour son profil
 * PUT  /api/profile/password → Changer son mot de passe
 */

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../core/Security.php';

class AuthController extends BaseController
{
    // ----------------------------------------------------------------
    // POST /api/register
    // ----------------------------------------------------------------
    public function register(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error('Méthode non autorisée.', 405);
        }

        $body = Security::getJsonBody();

        $this->validate($body, [
            'email'     => 'required|email|max:255',
            'password'  => 'required|min:8|max:128',
            'full_name' => 'required|min:2|max:255',
        ]);

        $email = strtolower(trim($body['email']));

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $this->error('Cette adresse email est déjà utilisée.', 409);
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password, full_name, phone) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            $hash,
            trim($body['full_name']),
            isset($body['phone']) ? trim($body['phone']) : null,
        ]);

        $userId = (int)$this->db->lastInsertId();
        $token  = jwt_encode(['sub' => $userId, 'role' => 'citizen', 'email' => $email]);

        $this->success([
            'token'      => $token,
            'expires_in' => JWT_EXPIRY,
            'user'       => [
                'id'        => $userId,
                'email'     => $email,
                'full_name' => trim($body['full_name']),
                'role'      => 'citizen',
            ],
        ], 201, 'Compte créé avec succès.');
    }

    // ----------------------------------------------------------------
    // POST /api/login
    // ----------------------------------------------------------------
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error('Méthode non autorisée.', 405);
        }

        $body = Security::getJsonBody();

        $this->validate($body, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $email = strtolower(trim($body['email']));

        $stmt = $this->db->prepare(
            'SELECT id, email, password, full_name, role, is_active FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['password'], $user['password'])) {
            $this->error('Email ou mot de passe incorrect.', 401);
        }

        if (!(bool)$user['is_active']) {
            $this->error("Ce compte a été désactivé. Contactez l'administration.", 403);
        }

        $token = jwt_encode([
            'sub'   => (int)$user['id'],
            'role'  => $user['role'],
            'email' => $user['email'],
        ]);

        $this->success([
            'token'      => $token,
            'expires_in' => JWT_EXPIRY,
            'user'       => [
                'id'        => (int)$user['id'],
                'email'     => $user['email'],
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ],
        ], 200, 'Connexion réussie.');
    }

    // ----------------------------------------------------------------
    // GET /api/profile  (UX-03)
    // ----------------------------------------------------------------
    public function getProfile(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'user:read_own');

        $stmt = $this->db->prepare(
            'SELECT id, email, full_name, phone, role, created_at,
                    notification_status_change, notification_new_comment, notification_vote_milestone
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$auth['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->error('Utilisateur introuvable.', 404);
        }

        $this->success([
            'id'        => (int)$user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'phone'     => $user['phone'],
            'role'      => $user['role'],
            'created_at'=> $user['created_at'],
            'notification_preferences' => [
                'status_change'    => (bool)($user['notification_status_change'] ?? true),
                'new_comment'      => (bool)($user['notification_new_comment'] ?? true),
                'vote_milestone'   => (bool)($user['notification_vote_milestone'] ?? false),
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // PUT /api/profile  (UX-03)
    // ----------------------------------------------------------------
    public function updateProfile(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'user:update_own');

        $body = Security::getJsonBody();

        $this->validate($body, [
            'full_name' => 'required|min:2|max:255',
        ]);

        // Mettre à jour les préférences de notification si fournies
        $notifStatusChange   = isset($body['notification_preferences']['status_change'])
            ? (int)(bool)$body['notification_preferences']['status_change'] : 1;
        $notifNewComment     = isset($body['notification_preferences']['new_comment'])
            ? (int)(bool)$body['notification_preferences']['new_comment'] : 1;
        $notifVoteMilestone  = isset($body['notification_preferences']['vote_milestone'])
            ? (int)(bool)$body['notification_preferences']['vote_milestone'] : 0;

        $stmt = $this->db->prepare(
            'UPDATE users
             SET full_name = ?,
                 phone = ?,
                 notification_status_change = ?,
                 notification_new_comment = ?,
                 notification_vote_milestone = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim($body['full_name']),
            isset($body['phone']) ? trim($body['phone']) : null,
            $notifStatusChange,
            $notifNewComment,
            $notifVoteMilestone,
            $auth['sub'],
        ]);

        $this->success(['updated' => true], 200, 'Profil mis à jour.');
    }

    // ----------------------------------------------------------------
    // GET /api/profile/stats  (UX-07 — Tableau de bord citoyen)
    // ----------------------------------------------------------------
    public function getStats(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int) $auth['sub'];

        // KPIs de base
        $stmt = $this->db->prepare('
            SELECT
                COUNT(*)                                             AS incidents_count,
                SUM(status = "resolved")                            AS resolved_count,
                SUM(status = "in_progress")                         AS in_progress_count,
                SUM(status = "submitted" OR status = "acknowledged") AS pending_count,
                ROUND(
                    AVG(CASE WHEN status = "resolved"
                        THEN TIMESTAMPDIFF(HOUR, i.created_at,
                            (SELECT sh.changed_at FROM status_history sh
                             WHERE sh.incident_id = i.id AND sh.new_status = "resolved"
                             ORDER BY sh.changed_at ASC LIMIT 1))
                        ELSE NULL END
                    ), 1
                ) AS avg_resolution_hours
            FROM incidents i
            WHERE user_id = ?
        ');
        $stmt->execute([$userId]);
        $kpis = $stmt->fetch();

        // Points et rang
        $stmtPoints = $this->db->prepare(
            'SELECT COALESCE(SUM(points), 0) AS total_points FROM user_points WHERE user_id = ?'
        );
        $stmtPoints->execute([$userId]);
        $totalPoints = (int) $stmtPoints->fetchColumn();

        $stmtRank = $this->db->prepare('
            SELECT COUNT(*) + 1 AS user_rank
            FROM (
                SELECT user_id, SUM(points) AS pts
                FROM user_points
                GROUP BY user_id
                HAVING pts > ?
            ) ranked
        ');
        $stmtRank->execute([$totalPoints]);
        $rank = (int) $stmtRank->fetchColumn();

        $totalUsers = (int) $this->db->query(
            'SELECT COUNT(*) FROM users WHERE is_active = 1'
        )->fetchColumn();

        // Votes et commentaires
        $stmtVotes = $this->db->prepare('SELECT COUNT(*) FROM votes WHERE user_id = ?');
        $stmtVotes->execute([$userId]);
        $votesCount = (int) $stmtVotes->fetchColumn();

        $stmtComments = $this->db->prepare('SELECT COUNT(*) FROM comments WHERE user_id = ?');
        $stmtComments->execute([$userId]);
        $commentsCount = (int) $stmtComments->fetchColumn();

        // Badges récents
        $stmtBadges = $this->db->prepare('
            SELECT b.name AS label, b.icon, ub.earned_at
            FROM user_badges ub
            JOIN badges b ON b.id = ub.badge_id
            WHERE ub.user_id = ?
            ORDER BY ub.earned_at DESC
            LIMIT 5
        ');
        $stmtBadges->execute([$userId]);
        $badges = $stmtBadges->fetchAll();

        // Signalements récents
        $stmtRecent = $this->db->prepare('
            SELECT i.id, i.title, i.status, i.reference,
                   c.icon AS category_icon
            FROM incidents i
            LEFT JOIN categories c ON c.id = i.category_id
            WHERE i.user_id = ?
            ORDER BY i.created_at DESC
            LIMIT 5
        ');
        $stmtRecent->execute([$userId]);
        $recentIncidents = $stmtRecent->fetchAll();

        // Évolution mensuelle (6 derniers mois)
        $stmtMonthly = $this->db->prepare('
            SELECT
                DATE_FORMAT(created_at, "%Y-%m") AS month,
                COUNT(*)                          AS count
            FROM incidents
            WHERE user_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ');
        $stmtMonthly->execute([$userId]);
        $monthly = $stmtMonthly->fetchAll();

        $this->success([
            'incidents_count'      => (int)  $kpis['incidents_count'],
            'resolved_count'       => (int)  $kpis['resolved_count'],
            'in_progress_count'    => (int)  $kpis['in_progress_count'],
            'pending_count'        => (int)  $kpis['pending_count'],
            'avg_resolution_hours' => $kpis['avg_resolution_hours'] !== null
                                        ? (float) $kpis['avg_resolution_hours'] : null,
            'points'               => $totalPoints,
            'rank'                 => $rank,
            'total_users'          => $totalUsers,
            'votes_cast'           => $votesCount,
            'comments_count'       => $commentsCount,
            'badges'               => $badges,
            'recent_incidents'     => $recentIncidents,
            'monthly_activity'     => $monthly,
        ]);
    }

    // ----------------------------------------------------------------
    // PUT /api/profile/password  (UX-03)
    // ----------------------------------------------------------------
    public function changePassword(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'user:update_own');

        $body = Security::getJsonBody();

        $this->validate($body, [
            'current_password' => 'required',
            'new_password'     => 'required|min:8|max:128',
        ]);

        // Vérifier l'ancien mot de passe
        $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$auth['sub']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['current_password'], $user['password'])) {
            $this->error('Mot de passe actuel incorrect.', 401);
        }

        $newHash = password_hash($body['new_password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([$newHash, $auth['sub']]);

        $this->success(['updated' => true], 200, 'Mot de passe modifié avec succès.');
    }
}
