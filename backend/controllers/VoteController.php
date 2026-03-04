<?php
/**
 * CCDS v1.3 — VoteController (TECH-02)
 * Migration de backend/api/votes.php vers l'architecture OO.
 */
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';

class VoteController extends BaseController
{
    /**
     * GET /incidents/{id}/votes — État du vote pour un incident
     */
    public function getState(int $incidentId): void
    {
        $userId = $this->requireAuth();

        $stmt = $this->db->prepare("
            SELECT
                i.votes_count,
                EXISTS(SELECT 1 FROM votes WHERE user_id = ? AND incident_id = ?) AS user_has_voted
            FROM incidents i
            WHERE i.id = ?
        ");
        $stmt->execute([$userId, $incidentId, $incidentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->notFound('Signalement introuvable.');
        }

        $this->success([
            'votes_count'    => (int)$row['votes_count'],
            'user_has_voted' => (bool)$row['user_has_voted'],
        ]);
    }

    /**
     * POST /incidents/{id}/vote — Voter pour un incident
     */
    public function vote(int $incidentId): void
    {
        $userId = $this->requireAuth();
        $this->requirePermission('vote:create');

        // Vérifier que l'incident existe
        $check = $this->db->prepare("SELECT id FROM incidents WHERE id = ?");
        $check->execute([$incidentId]);
        if (!$check->fetch()) {
            $this->notFound('Signalement introuvable.');
        }

        try {
            $this->db->prepare("INSERT INTO votes (user_id, incident_id) VALUES (?, ?)")
                     ->execute([$userId, $incidentId]);

            $this->db->prepare("UPDATE incidents SET votes_count = votes_count + 1 WHERE id = ?")
                     ->execute([$incidentId]);

            // Vérifier les paliers de gamification (10, 50, 100 votes)
            $count = (int)$this->db->prepare("SELECT votes_count FROM incidents WHERE id = ?")
                                   ->execute([$incidentId]) && true
                     ? $this->db->query("SELECT votes_count FROM incidents WHERE id = $incidentId")->fetchColumn()
                     : 0;

            $this->success([
                'votes_count'    => $count,
                'user_has_voted' => true,
            ], 201);

        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                // Contrainte UNIQUE — vote déjà existant
                $this->error('Vous avez déjà voté pour ce signalement.', 409);
            }
            $this->serverError('Erreur lors du vote.');
        }
    }

    /**
     * DELETE /incidents/{id}/vote — Retirer son vote
     */
    public function removeVote(int $incidentId): void
    {
        $userId = $this->requireAuth();
        $this->requirePermission('vote:delete');

        $stmt = $this->db->prepare("DELETE FROM votes WHERE user_id = ? AND incident_id = ?");
        $stmt->execute([$userId, $incidentId]);

        if ($stmt->rowCount() === 0) {
            $this->error('Vous n\'avez pas voté pour ce signalement.', 404);
        }

        $this->db->prepare("UPDATE incidents SET votes_count = GREATEST(0, votes_count - 1) WHERE id = ?")
                 ->execute([$incidentId]);

        $count = (int)$this->db->query("SELECT votes_count FROM incidents WHERE id = $incidentId")->fetchColumn();

        $this->success([
            'votes_count'    => $count,
            'user_has_voted' => false,
        ]);
    }
}
