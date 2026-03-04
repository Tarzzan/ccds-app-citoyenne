<?php
/**
 * PollController — Sondages & Consultations citoyens (UX-10)
 *
 * Permet aux administrateurs de créer des sondages géolocalisés
 * et aux citoyens d'y répondre.
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
                   u.name AS created_by_name,
                   (SELECT COUNT(*) FROM poll_votes pv
                    JOIN poll_options po ON po.id = pv.poll_option_id
                    WHERE po.poll_id = p.id) AS total_votes,
                   (SELECT COUNT(*) FROM poll_options WHERE poll_id = p.id) AS options_count,
                   (SELECT pv.poll_option_id FROM poll_votes pv
                    JOIN poll_options po ON po.id = pv.poll_option_id
                    WHERE po.poll_id = p.id AND pv.user_id = :uid
                    LIMIT 1) AS user_vote_option_id
            FROM polls p
            JOIN users u ON u.id = p.created_by
            WHERE p.end_date >= NOW() OR p.end_date IS NULL
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([':uid' => $user['id']]);
        $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Charger les options pour chaque sondage
        foreach ($polls as &$poll) {
            $optStmt = $this->db->prepare("
                SELECT po.id, po.option_text,
                       COUNT(pv.id) AS vote_count
                FROM poll_options po
                LEFT JOIN poll_votes pv ON pv.poll_option_id = po.id
                WHERE po.poll_id = ?
                GROUP BY po.id
                ORDER BY po.id
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
                INSERT INTO polls (title, description, created_by, start_date, end_date, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $input['title'],
                $input['description'] ?? null,
                $user['id'],
                $input['start_date'] ?? null,
                $input['end_date']   ?? null,
            ]);
            $pollId = (int) $this->db->lastInsertId();

            foreach ($input['options'] as $optionText) {
                $optStmt = $this->db->prepare("
                    INSERT INTO poll_options (poll_id, option_text) VALUES (?, ?)
                ");
                $optStmt->execute([$pollId, trim($optionText)]);
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
            WHERE id = ? AND (end_date IS NULL OR end_date >= NOW())
        ");
        $pollStmt->execute([$pollId]);
        if (!$pollStmt->fetch()) {
            $this->error('Ce sondage est terminé ou introuvable.', 404);
        }

        // Vérifier si l'utilisateur a déjà voté
        $existingStmt = $this->db->prepare("
            SELECT pv.id FROM poll_votes pv
            JOIN poll_options po ON po.id = pv.poll_option_id
            WHERE po.poll_id = ? AND pv.user_id = ?
        ");
        $existingStmt->execute([$pollId, $user['id']]);
        if ($existingStmt->fetch()) {
            $this->error('Vous avez déjà voté sur ce sondage.', 409);
        }

        $voteStmt = $this->db->prepare("
            INSERT INTO poll_votes (poll_option_id, user_id, created_at)
            VALUES (?, ?, NOW())
        ");
        $voteStmt->execute([$optionId, $user['id']]);

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

        $optStmt = $this->db->prepare("
            SELECT po.id, po.option_text, COUNT(pv.id) AS vote_count,
                   ROUND(COUNT(pv.id) * 100.0 / NULLIF(
                       (SELECT COUNT(*) FROM poll_votes pv2
                        JOIN poll_options po2 ON po2.id = pv2.poll_option_id
                        WHERE po2.poll_id = ?), 0), 1) AS percentage
            FROM poll_options po
            LEFT JOIN poll_votes pv ON pv.poll_option_id = po.id
            WHERE po.poll_id = ?
            GROUP BY po.id
            ORDER BY vote_count DESC
        ");
        $optStmt->execute([$pollId, $pollId]);

        $this->success([
            'poll'         => $poll,
            'options'      => $optStmt->fetchAll(PDO::FETCH_ASSOC),
            'total_votes'  => array_sum(array_column($optStmt->fetchAll(PDO::FETCH_ASSOC), 'vote_count')),
        ]);
    }

    private function requireRole(array $user, string $role): void
    {
        if ($user['role'] !== $role) {
            $this->error('Accès réservé aux administrateurs.', 403);
        }
    }
}
