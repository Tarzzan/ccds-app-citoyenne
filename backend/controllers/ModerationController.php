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
require_once __DIR__ . '/AuditLogController.php';
require_once __DIR__ . '/../config/ContentModerationService.php';
require_once __DIR__ . '/../config/NotificationStore.php';

class ModerationController extends BaseController
{
    // ─────────────────────────────────────────────────────────────────────────
    // POST /comments/{id}/report
    // Signaler un commentaire (tout utilisateur authentifié)
    // ─────────────────────────────────────────────────────────────────────────

    public function reportComment(int $commentId): void
    {
        $user = $this->requireAuth();
        $userId = $this->getAuthUserId($user);
        $this->applyRateLimit('default', $userId);
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $reason = Security::sanitizeString($input['reason'] ?? ContentModerationService::COMMENT_REASON_INAPPROPRIATE);

        try {
            $result = (new ContentModerationService($this->db))->reportComment(
                $commentId,
                $userId,
                $reason,
                Security::sanitizeString($input['description'] ?? '')
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $code = str_contains($message, 'déjà signalé') ? 409 : (str_contains($message, 'propre commentaire') ? 400 : 422);
            $this->error($message, $code);
        }

        $this->success([
            'message' => 'Commentaire signalé. Notre équipe va examiner votre signalement.',
            'report_count' => $result['report_count'],
            'threshold' => $result['threshold'],
            'auto_hidden' => $result['auto_hidden'],
        ], 201);
    }

    public function reportPhoto(int $incidentId, int $photoId): void
    {
        $user = $this->requireAuth();
        $userId = $this->getAuthUserId($user);
        $this->applyRateLimit('default', $userId);

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $reason = Security::sanitizeString($input['reason'] ?? ContentModerationService::PHOTO_REASON_INAPPROPRIATE);

        $photoStmt = $this->db->prepare('SELECT incident_id FROM photos WHERE id = ? LIMIT 1');
        $photoStmt->execute([$photoId]);
        $photoIncidentId = (int)($photoStmt->fetchColumn() ?: 0);
        if ($photoIncidentId !== $incidentId) {
            $this->error('Photo introuvable pour ce dossier.', 404);
        }

        try {
            $result = (new ContentModerationService($this->db))->reportPhoto(
                $photoId,
                $userId,
                $reason,
                Security::sanitizeString($input['description'] ?? '')
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $code = str_contains($message, 'déjà signalé') ? 409 : (str_contains($message, 'propre photo') ? 400 : 422);
            $this->error($message, $code);
        }

        $this->success([
            'message' => 'Photo signalée. Notre équipe va examiner ce contenu.',
            'report_count' => $result['report_count'],
            'threshold' => $result['threshold'],
            'auto_hidden' => $result['auto_hidden'],
        ], 201);
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
                c.comment        AS comment_content,
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
        $userId = $this->getAuthUserId($user);
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
            AuditLogController::log($this->db, $userId, 'comment.deleted_via_moderation', 'comment', (int) $report['comment_id'], $details);
        } elseif ($action === 'dismiss') {
            $newStatus = 'dismissed';
            AuditLogController::log($this->db, $userId, 'comment_report.dismissed', 'comment_report', $reportId, $details);
        } elseif ($action === 'warn_author') {
            // Créer une notification pour l'auteur du commentaire
            NotificationStore::insert(
                $this->db,
                (int) $report['comment_author_id'],
                null,
                'moderation_warning',
                'Avertissement de modération',
                'Votre commentaire a été signalé et examiné par notre équipe de modération. Merci de respecter les règles de la communauté.',
                ['comment_id' => (int) $report['comment_id']],
                0
            );
            $newStatus = 'actioned';
            AuditLogController::log($this->db, $userId, 'comment.author_warned', 'comment', (int) $report['comment_id'], $details);
        }

        // Mettre à jour le statut du signalement
        $updateStmt = $this->db->prepare("
            UPDATE comment_reports
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$newStatus, $userId, $reportId]);

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
                (SELECT COUNT(*) FROM comment_reports) AS total_comments,
                (SELECT COUNT(*) FROM photo_reports) AS total_photos,
                (SELECT COUNT(*) FROM comment_reports WHERE status = 'pending') AS pending_comments,
                (SELECT COUNT(*) FROM photo_reports WHERE status = 'pending') AS pending_photos,
                (SELECT COUNT(*) FROM comments WHERE moderation_status IN ('auto_hidden', 'hidden_by_admin')) AS hidden_comments,
                (SELECT COUNT(*) FROM photos WHERE moderation_status IN ('auto_hidden', 'hidden_by_admin')) AS hidden_photos,
                SUM(reason = 'spam_or_advertising') AS spam_count,
                SUM(reason = 'insulting_or_aggressive') AS aggressive_count,
                SUM(reason = 'inappropriate') AS inappropriate_count,
                SUM(reason = 'personal_information') AS personal_info_count
            FROM comment_reports
        ");
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->success($stats);
    }
}
