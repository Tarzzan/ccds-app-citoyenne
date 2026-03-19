<?php
/**
 * Ma Commune v1.4 — CommentController (UX-05)
 * Commentaires avec édition, suppression, réponses (threading niveau 1).
 * Compatible avec la version v1.3 (TECH-02).
 */
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../config/PushNotificationService.php';

class CommentController extends BaseController
{
    /**
     * GET /incidents/{id}/comments — Liste avec replies imbriquées (UX-05)
     */
    public function list(int $incidentId): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'comment:list');
        $role = $auth['role'] ?? 'citizen';
        $showInternal = in_array($role, ['agent', 'admin'], true);

        // Commentaires racine
        $sql = "
            SELECT c.id, c.comment, c.is_internal, c.is_edited,
                   c.parent_id, c.created_at, c.updated_at,
                   u.id AS user_id, u.full_name AS author_name, u.role AS author_role
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.incident_id = ? AND c.parent_id IS NULL
        ";
        if (!$showInternal) { $sql .= " AND c.is_internal = 0"; }
        $sql .= " ORDER BY c.created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$incidentId]);
        $roots = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Réponses
        $sqlR = "
            SELECT c.id, c.comment, c.is_internal, c.is_edited,
                   c.parent_id, c.created_at, c.updated_at,
                   u.id AS user_id, u.full_name AS author_name, u.role AS author_role
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.incident_id = ? AND c.parent_id IS NOT NULL
        ";
        if (!$showInternal) { $sqlR .= " AND c.is_internal = 0"; }
        $sqlR .= " ORDER BY c.created_at ASC";
        $stmtR = $this->db->prepare($sqlR);
        $stmtR->execute([$incidentId]);
        $allReplies = $stmtR->fetchAll(\PDO::FETCH_ASSOC);

        $roots = $this->sanitizeCommentsForAudience($roots, $auth);
        $allReplies = $this->sanitizeCommentsForAudience($allReplies, $auth);

        $repliesByParent = [];
        foreach ($allReplies as $r) { $repliesByParent[$r['parent_id']][] = $r; }
        foreach ($roots as &$root) { $root['replies'] = $repliesByParent[$root['id']] ?? []; }

        $this->success(['comments' => $roots]);
    }

    /**
     * POST /incidents/{id}/comments — Créer un commentaire
     */
    public function create(int $incidentId): void
    {
        $auth   = $this->requireAuth();
        $userId = (int)($auth['sub'] ?? 0);
        $this->requirePermission($auth, 'comment:create');

        $body       = $this->getBody();
        $comment    = trim($body['comment'] ?? '');
        $isInternal = (bool)($body['is_internal'] ?? false);
        $parentId   = isset($body['parent_id']) ? (int)$body['parent_id'] : null;

        if (mb_strlen($comment) < 2) { $this->error('Commentaire trop court.', 422); }
        if (mb_strlen($comment) > 1000) { $this->error('Commentaire trop long (max 1000).', 422); }
        if ($isInternal && !$this->hasPermission($auth, 'comment:create_internal')) { $isInternal = false; }

        $check = $this->db->prepare('SELECT id, user_id FROM incidents WHERE id = ?');
        $check->execute([$incidentId]);
        $incident = $check->fetch(\PDO::FETCH_ASSOC);
        if (!$incident) { $this->notFound('Signalement introuvable.'); }

        // Valider le parent si réponse
        if ($parentId) {
            $pStmt = $this->db->prepare("SELECT id, parent_id FROM comments WHERE id = ? AND incident_id = ?");
            $pStmt->execute([$parentId, $incidentId]);
            $parent = $pStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$parent) { $this->error('Commentaire parent introuvable.', 404); }
            if ($parent['parent_id'] !== null) { $this->error('Réponses imbriquées non autorisées.', 422); }
        }

        $stmt = $this->db->prepare("
            INSERT INTO comments (incident_id, user_id, parent_id, comment, is_internal, is_edited, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW(), NOW())
        ");
        $stmt->execute([$incidentId, $userId, $parentId, $comment, $isInternal ? 1 : 0]);
        $newId = (int)$this->db->lastInsertId();

        if (
            !$isInternal
            && (int)$incident['user_id'] !== $userId
        ) {
            $authorStmt = $this->db->prepare('SELECT full_name FROM users WHERE id = ?');
            $authorStmt->execute([$userId]);
            $commenterName = (string)($authorStmt->fetchColumn() ?: 'Un agent');

            (new PushNotificationService($this->db))->notifyNewComment($incidentId, $commenterName);
        }

        $this->success(['id' => $newId, 'comment_id' => $newId], 201);
    }

    /**
     * PUT /incidents/{id}/comments/{cid} — Modifier (auteur, fenêtre 24h) (UX-05)
     */
    public function update(int $incidentId, int $commentId): void
    {
        $auth    = $this->requireAuth();
        $userId  = (int)($auth['sub'] ?? 0);
        $body    = $this->getBody();
        $comment = trim($body['comment'] ?? '');

        if (mb_strlen($comment) < 2)    { $this->error('Commentaire trop court.', 422); }
        if (mb_strlen($comment) > 1000) { $this->error('Commentaire trop long.', 422); }

        $existing = $this->loadComment($commentId, $incidentId);
        if ((int)$existing['user_id'] !== $userId) {
            $this->error('Vous ne pouvez modifier que vos propres commentaires.', 403);
        }
        if (time() - strtotime($existing['created_at']) > 86400) {
            $this->error('Fenêtre d\'édition (24h) dépassée.', 422);
        }

        $this->db->prepare("UPDATE comments SET comment = ?, is_edited = 1, updated_at = NOW() WHERE id = ?")
                 ->execute([$comment, $commentId]);

        $this->success(['updated' => true]);
    }

    /**
     * DELETE /incidents/{id}/comments/{cid} — Supprimer (auteur ou staff) (UX-05)
     */
    public function delete(int $incidentId, int $commentId): void
    {
        $auth = $this->requireAuth();
        $existing = $this->loadComment($commentId, $incidentId);

        $isAuthor = (int)$existing['user_id'] === (int)($auth['sub'] ?? 0);
        $isStaff  = in_array($auth['role'] ?? 'citizen', ['agent', 'admin'], true);

        if (!$isAuthor && !$isStaff) {
            $this->error('Accès refusé.', 403);
        }

        // Supprimer le commentaire et ses réponses
        $this->db->prepare("DELETE FROM comments WHERE id = ? OR parent_id = ?")
                 ->execute([$commentId, $commentId]);

        $this->success(['deleted' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────
    private function loadComment(int $commentId, int $incidentId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM comments WHERE id = ? AND incident_id = ?");
        $stmt->execute([$commentId, $incidentId]);
        $c = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$c) { $this->notFound('Commentaire introuvable.'); }
        return $c;
    }

    private function sanitizeCommentsForAudience(array $comments, array $auth): array
    {
        if (in_array($auth['role'] ?? '', ['agent', 'admin'], true)) {
            return $comments;
        }

        $currentUserId = (int)($auth['sub'] ?? 0);

        return array_map(static function (array $comment) use ($currentUserId): array {
            $authorUserId = (int)($comment['user_id'] ?? 0);
            if ($authorUserId === $currentUserId) {
                return $comment;
            }

            $role = (string)($comment['author_role'] ?? 'citizen');
            $comment['user_id'] = 0;
            $comment['author_name'] = match ($role) {
                'agent' => 'Equipe municipale',
                'admin' => 'Administration communale',
                default => 'Habitant du territoire',
            };

            return $comment;
        }, $comments);
    }
}
