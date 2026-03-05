<?php
/**
 * PollController — Sondages & Consultations citoyens (UX-10)
 *
 * Colonnes réelles (migration 20260304000007) :
 * - polls       : id, title, description, type, status, created_by, ends_at, created_at
 * - poll_options: id, poll_id, label, sort_order
 * - poll_votes  : id, poll_id, option_id, user_id, voted_at
 */
class PollController extends BaseController
{
    /**
     * GET /polls
     * Liste des sondages actifs (accessibles aux citoyens).
     */
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->applyRateLimit('default', $user['id']);

        $stmt = $this->db->prepare("
            SELECT p.*,
                   u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes,
                   (SELECT COUNT(*) FROM poll_options WHERE poll_id = p.id) AS options_count,
                   (SELECT pv.option_id FROM poll_votes pv
                    WHERE pv.poll_id = p.id AND pv.user_id = :uid
                    LIMIT 1) AS user_vote_id
            FROM polls p
            JOIN users u ON u.id = p.created_by
            WHERE p.ends_at >= NOW() OR p.ends_at IS NULL
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([':uid' => $user['id']]);
        $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Charger les options pour chaque sondage
        foreach ($polls as &$poll) {
            $optStmt = $this->db->prepare("
                SELECT po.id, po.label AS text,
                       COUNT(pv.id) AS votes_count
                FROM poll_options po
                LEFT JOIN poll_votes pv ON pv.option_id = po.id
                WHERE po.poll_id = ?
                GROUP BY po.id
                ORDER BY po.sort_order ASC, po.id ASC
            ");
            $optStmt->execute([$poll['id']]);
            $poll['options'] = $optStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $this->success($polls);
    }

    /**
     * POST /polls
     * Créer un sondage (admin seulement).
     */
    public function create(): void
    {
        $user = $this->requireAuth();
        $this->requireRole($user, 'admin');
        $this->applyRateLimit('default', $user['id']);

        $input = json_decode(file_get_contents('php://input'), true);

        $errors = [];
        if (empty($input['title'])) $errors[] = 'Le titre est requis.';
        if (empty($input['options']) || count($input['options']) < 2) {
            $errors[] = 'Au moins 2 options sont requises.';
        }
        if (!empty($errors)) $this->error(implode(' ', $errors), 422);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO polls (title, description, type, status, created_by, ends_at, created_at)
                VALUES (?, ?, ?, 'active', ?, ?, NOW())
            ");
            $stmt->execute([
                Security::sanitizeString($input['title']),
                Security::sanitizeString($input['description'] ?? ''),
                in_array($input['type'] ?? 'single', ['single', 'multiple']) ? $input['type'] : 'single',
                $user['id'],
                $input['ends_at'] ?? null,
            ]);
            $pollId = (int) $this->db->lastInsertId();

            foreach ($input['options'] as $idx => $optionLabel) {
                $optStmt = $this->db->prepare("
                    INSERT INTO poll_options (poll_id, label, sort_order) VALUES (?, ?, ?)
                ");
                $optStmt->execute([$pollId, Security::sanitizeString(trim($optionLabel)), $idx]);
            }

            $this->db->commit();
            $this->success(['id' => $pollId], 201, 'Sondage créé avec succès.');
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->error('Erreur lors de la création du sondage.', 500);
        }
    }

    /**
     * POST /polls/{id}/vote
     * Voter sur un sondage.
     */
    public function vote(int $pollId): void
    {
        $user = $this->requireAuth();
        $this->applyRateLimit('vote_create', $user['id']);

        $input    = json_decode(file_get_contents('php://input'), true);
        $optionId = (int) ($input['option_id'] ?? 0);

        if (!$optionId) {
            $this->error('option_id est requis.', 400);
        }

        // Vérifier que l'option appartient bien à ce sondage
        $stmt = $this->db->prepare("
            SELECT id FROM poll_options WHERE id = ? AND poll_id = ?
        ");
        $stmt->execute([$optionId, $pollId]);
        if (!$stmt->fetch()) {
            $this->error('Option invalide pour ce sondage.', 404);
        }

        // Vérifier que le sondage est actif
        $pollStmt = $this->db->prepare("
            SELECT id FROM polls
            WHERE id = ? AND status = 'active' AND (ends_at IS NULL OR ends_at >= NOW())
        ");
        $pollStmt->execute([$pollId]);
        if (!$pollStmt->fetch()) {
            $this->error('Ce sondage est terminé ou introuvable.', 404);
        }

        // Vérifier si l'utilisateur a déjà voté
        $existingStmt = $this->db->prepare("
            SELECT id FROM poll_votes
            WHERE poll_id = ? AND user_id = ?
        ");
        $existingStmt->execute([$pollId, $user['id']]);
        if ($existingStmt->fetch()) {
            $this->error('Vous avez déjà voté sur ce sondage.', 409);
        }

        $voteStmt = $this->db->prepare("
            INSERT INTO poll_votes (poll_id, option_id, user_id, voted_at)
            VALUES (?, ?, ?, NOW())
        ");
        $voteStmt->execute([$pollId, $optionId, $user['id']]);

        $this->success(['voted_option_id' => $optionId], 201, 'Vote enregistré.');
    }

    /**
     * GET /polls/{id}/results
     * Résultats détaillés d'un sondage.
     */
    public function results(int $pollId): void
    {
        $user = $this->requireAuth();

        $pollStmt = $this->db->prepare("SELECT * FROM polls WHERE id = ?");
        $pollStmt->execute([$pollId]);
        $poll = $pollStmt->fetch(PDO::FETCH_ASSOC);
        if (!$poll) $this->error('Sondage introuvable.', 404);

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM poll_votes WHERE poll_id = ?");
        $totalStmt->execute([$pollId]);
        $totalVotes = (int) $totalStmt->fetchColumn();

        $optStmt = $this->db->prepare("
            SELECT po.id, po.label AS text,
                   COUNT(pv.id) AS votes_count,
                   ROUND(COUNT(pv.id) * 100.0 / NULLIF(?, 0), 1) AS percentage
            FROM poll_options po
            LEFT JOIN poll_votes pv ON pv.option_id = po.id
            WHERE po.poll_id = ?
            GROUP BY po.id
            ORDER BY votes_count DESC
        ");
        $optStmt->execute([$totalVotes, $pollId]);

        $this->success([
            'poll'        => $poll,
            'options'     => $optStmt->fetchAll(PDO::FETCH_ASSOC),
            'total_votes' => $totalVotes,
        ]);
    }

    private function requireRole(array $user, string $role): void
    {
        if ($user['role'] !== $role) {
            $this->error('Accès réservé aux administrateurs.', 403);
        }
    }
}
