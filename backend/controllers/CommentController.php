<?php
/**
 * CCDS v1.3 — CommentController (TECH-02)
 * Migration de backend/api/comments.php vers l'architecture OO.
 */
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';

class CommentController extends BaseController
{
    /**
     * GET /incidents/{id}/comments — Liste des commentaires
     */
    public function list(int $incidentId): void
    {
        $userId = $this->requireAuth();
        $role   = $this->user['role'] ?? 'citizen';

        // Les commentaires internes ne sont visibles que par agents et admins
        $showInternal = in_array($role, ['agent', 'admin'], true);

        $sql = "
            SELECT c.id, c.comment, c.is_internal, c.created_at,
                   u.id AS user_id, u.full_name AS user_name, u.role AS user_role
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.incident_id = ?
        ";
        if (!$showInternal) {
            $sql .= " AND c.is_internal = 0";
        }
        $sql .= " ORDER BY c.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$incidentId]);
        $comments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->success(array_map(function ($c) {
            return [
                'id'          => (int)$c['id'],
                'comment'     => $c['comment'],
                'is_internal' => (bool)$c['is_internal'],
                'created_at'  => $c['created_at'],
                'user_id'     => (int)$c['user_id'],
                'user_name'   => $c['user_name'],
                'user_role'   => $c['user_role'],
            ];
        }, $comments));
    }

    /**
     * POST /incidents/{id}/comments — Ajouter un commentaire
     */
    public function create(int $incidentId): void
    {
        $userId = $this->requireAuth();
        $this->requirePermission('comment:create');

        $body        = $this->getBody();
        $comment     = trim($body['comment'] ?? '');
        $isInternal  = (bool)($body['is_internal'] ?? false);

        if (strlen($comment) < 2) {
            $this->error('Le commentaire doit contenir au moins 2 caractères.', 422);
        }

        // Seuls les agents et admins peuvent créer des commentaires internes
        if ($isInternal && !$this->hasPermission('comment:create_internal')) {
            $isInternal = false;
        }

        // Vérifier que l'incident existe
        $check = $this->db->prepare("SELECT id FROM incidents WHERE id = ?");
        $check->execute([$incidentId]);
        if (!$check->fetch()) {
            $this->notFound('Signalement introuvable.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO comments (incident_id, user_id, comment, is_internal, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$incidentId, $userId, $comment, $isInternal ? 1 : 0]);
        $newId = (int)$this->db->lastInsertId();

        // Récupérer le commentaire créé
        $row = $this->db->prepare("
            SELECT c.*, u.full_name AS user_name, u.role AS user_role
            FROM comments c JOIN users u ON u.id = c.user_id
            WHERE c.id = ?
        ");
        $row->execute([$newId]);
        $created = $row->fetch(\PDO::FETCH_ASSOC);

        $this->success([
            'id'          => $newId,
            'comment'     => $created['comment'],
            'is_internal' => (bool)$created['is_internal'],
            'created_at'  => $created['created_at'],
            'user_name'   => $created['user_name'],
            'user_role'   => $created['user_role'],
        ], 201);
    }

    /**
     * DELETE /comments/{id} — Supprimer un commentaire (admin uniquement)
     */
    public function delete(int $commentId): void
    {
        $this->requireAuth();
        $this->requirePermission('comment:delete');

        $stmt = $this->db->prepare("DELETE FROM comments WHERE id = ?");
        $stmt->execute([$commentId]);

        if ($stmt->rowCount() === 0) {
            $this->notFound('Commentaire introuvable.');
        }

        $this->success(['deleted' => true]);
    }
}
