<?php
/**
 * ModerationController — Modération des commentaires (ADMIN-07)
 *
 * Endpoints :
 *   POST /comments/{id}/report              — Signaler un commentaire (citoyen)
 *   GET  /admin/moderation/reports          — File d'attente de modération (admin)
 *   PUT  /admin/moderation/reports/{id}     — Traiter un signalement (admin)
 *   GET  /admin/moderation/stats            — Statistiques de modération
 */
class ModerationController extends BaseController
{
    // ─────────────────────────────────────────────────────────────────────────
    // POST /comments/{id}/report
    // Signaler un commentaire (tout utilisateur authentifié)
    // ─────────────────────────────────────────────────────────────────────────

    public function reportComment(int $commentId): void
    {
        $user = $this->requireAuth();
        $this->applyRateLimit('default', $user['id']);

        // Vérifier que le commentaire existe
        $stmt = $this->db->prepare("SELECT id, user_id FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$comment) {
            $this->error('Commentaire introuvable.', 404);
        }

        // Un utilisateur ne peut pas signaler son propre commentaire
        if ((int) $comment['user_id'] === (int) $user['id']) {
            $this->error('Vous ne pouvez pas signaler votre propre commentaire.', 400);
        }

        $input  = json_decode(file_get_contents('php://input'), true);
        $reason = $input['reason'] ?? 'other';
        $validReasons = ['spam', 'harassment', 'inappropriate', 'misinformation', 'other'];
        if (!in_array($reason, $validReasons)) {
            $this->error('Raison invalide. Valeurs acceptées : ' . implode(', ', $validReasons), 400);
        }

        // Vérifier si l'utilisateur a déjà signalé ce commentaire
        $existingStmt = $this->db->prepare(
            "SELECT id FROM comment_reports WHERE comment_id = ? AND reporter_id = ?"
        );
        $existingStmt->execute([$commentId, $user['id']]);
        if ($existingStmt->fetch()) {
            $this->error('Vous avez déjà signalé ce commentaire.', 409);
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO comment_reports (comment_id, reporter_id, reason, description, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $insertStmt->execute([
            $commentId,
            $user['id'],
            $reason,
            Security::sanitizeString($input['description'] ?? ''),
        ]);

        $this->success(['message' => 'Commentaire signalé. Notre équipe va examiner votre signalement.'], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/moderation/reports
    // File d'attente de modération (admin seulement)
    // ─────────────────────────────────────────────────────────────────────────

    public function getReports(): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $status = $_GET['status'] ?? 'pending';
        $validStatuses = ['pending', 'reviewed', 'dismissed', 'actioned', 'all'];
        if (!in_array($status, $validStatuses)) {
            $status = 'pending';
        }

        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $whereStatus = $status !== 'all' ? "WHERE cr.status = '{$status}'" : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM comment_reports cr {$whereStatus}");
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT
                cr.*,
                c.content        AS comment_content,
                c.user_id        AS comment_author_id,
                author.full_name AS comment_author_name,
                reporter.full_name AS reporter_name,
                reporter.email     AS reporter_email,
                reviewer.full_name AS reviewer_name,
                i.id             AS incident_id,
                i.title          AS incident_title
            FROM comment_reports cr
            JOIN comments c      ON c.id  = cr.comment_id
            JOIN users author    ON author.id  = c.user_id
            JOIN users reporter  ON reporter.id = cr.reporter_id
            LEFT JOIN users reviewer ON reviewer.id = cr.reviewed_by
            LEFT JOIN incidents i ON i.id = c.incident_id
            {$whereStatus}
            ORDER BY cr.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute();
        $reports = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->success([
            'data' => $reports,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $limit,
                'pages'    => (int) ceil($total / $limit),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /admin/moderation/reports/{id}
    // Traiter un signalement : dismissed (rejeté) ou actioned (commentaire supprimé)
    // ─────────────────────────────────────────────────────────────────────────

    public function reviewReport(int $reportId): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $input  = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        $validActions = ['dismiss', 'delete_comment', 'warn_author'];

        if (!in_array($action, $validActions)) {
            $this->error('Action invalide. Valeurs acceptées : ' . implode(', ', $validActions), 400);
        }

        // Récupérer le signalement
        $stmt = $this->db->prepare("SELECT cr.*, c.user_id AS comment_author_id FROM comment_reports cr JOIN comments c ON c.id = cr.comment_id WHERE cr.id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$report) {
            $this->error('Signalement introuvable.', 404);
        }
        if ($report['status'] !== 'pending') {
            $this->error('Ce signalement a déjà été traité.', 409);
        }

        $newStatus = 'reviewed';
        $details   = ['action' => $action, 'note' => Security::sanitizeString($input['note'] ?? '')];

        if ($action === 'delete_comment') {
            // Supprimer le commentaire
            $deleteStmt = $this->db->prepare("DELETE FROM comments WHERE id = ?");
            $deleteStmt->execute([$report['comment_id']]);
            $newStatus = 'actioned';

            // Log d'audit
            AuditLogController::log($this->db, (int) $user['id'], 'comment.deleted_via_moderation', 'comment', (int) $report['comment_id'], $details);
        } elseif ($action === 'dismiss') {
            $newStatus = 'dismissed';
            AuditLogController::log($this->db, (int) $user['id'], 'comment_report.dismissed', 'comment_report', $reportId, $details);
        } elseif ($action === 'warn_author') {
            // Créer une notification pour l'auteur du commentaire
            $notifStmt = $this->db->prepare("
                INSERT INTO notifications (user_id, type, title, message, created_at)
                VALUES (?, 'moderation_warning', 'Avertissement de modération', ?, NOW())
            ");
            $notifStmt->execute([
                $report['comment_author_id'],
                'Votre commentaire a été signalé et examiné par notre équipe de modération. Merci de respecter les règles de la communauté.',
            ]);
            $newStatus = 'actioned';
            AuditLogController::log($this->db, (int) $user['id'], 'comment.author_warned', 'comment', (int) $report['comment_id'], $details);
        }

        // Mettre à jour le statut du signalement
        $updateStmt = $this->db->prepare("
            UPDATE comment_reports
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$newStatus, $user['id'], $reportId]);

        $this->success(['message' => 'Signalement traité avec succès.', 'status' => $newStatus]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/moderation/stats
    // ─────────────────────────────────────────────────────────────────────────

    public function getStats(): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $stmt = $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'pending')   AS pending,
                SUM(status = 'reviewed')  AS reviewed,
                SUM(status = 'dismissed') AS dismissed,
                SUM(status = 'actioned')  AS actioned,
                SUM(reason = 'spam')           AS spam_count,
                SUM(reason = 'harassment')     AS harassment_count,
                SUM(reason = 'inappropriate')  AS inappropriate_count,
                SUM(reason = 'misinformation') AS misinformation_count
            FROM comment_reports
        ");
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->success($stats);
    }
}
