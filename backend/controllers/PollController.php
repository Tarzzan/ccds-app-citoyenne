<?php
/**
 * PollController — Sondages & Consultations citoyens (UX-10)
 *
 * Colonnes réelles (migration 20260304000007) :
 * - polls       : id, title, description, type, status, created_by, ends_at, created_at
 * - poll_options: id, poll_id, label, sort_order
 * - poll_votes  : id, poll_id, option_id, user_id, voted_at
 *
 * Note V1 :
 * - les consultations sont volontairement limitees au choix unique
 * - le schema courant garantit un seul vote par utilisateur et par consultation
 */
class PollController extends BaseController
{
    private ?bool $pollHasStatus = null;
    private ?bool $pollHasType = null;
    private ?bool $pollHasOptionLabel = null;
    private ?bool $pollHasOptionSortOrder = null;
    private ?bool $pollHasOptionVotes = null;
    private ?bool $pollVoteHasVotedAt = null;

    /**
     * GET /polls
     * Liste des sondages actifs (accessibles aux citoyens).
     */
    public function index(): void
    {
        $user   = $this->requireAuth();
        $userId = (int)($user['sub'] ?? 0);
        $this->applyRateLimit('default', $userId);

        $statusFilter = $this->pollStatusFilter('p');
        $stmt = $this->db->prepare("
            SELECT p.*,
                   {$this->pollStatusExpression('p')} AS effective_status,
                   u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes,
                   (SELECT COUNT(*) FROM poll_options WHERE poll_id = p.id) AS options_count,
                   (SELECT pv.option_id FROM poll_votes pv
                    WHERE pv.poll_id = p.id AND pv.user_id = :uid
                    LIMIT 1) AS user_vote_id
            FROM polls p
            JOIN users u ON u.id = p.created_by
            WHERE {$statusFilter}
              AND (p.ends_at >= NOW() OR p.ends_at IS NULL)
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $polls = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Charger les options pour chaque sondage
        foreach ($polls as &$poll) {
            $optStmt = $this->db->prepare("
                SELECT po.id, {$this->pollOptionTextExpression('po')} AS text,
                       {$this->pollOptionVotesExpression('po', 'pv')} AS votes_count
                FROM poll_options po
                LEFT JOIN poll_votes pv ON pv.option_id = po.id
                WHERE po.poll_id = ?
                GROUP BY po.id
                ORDER BY {$this->pollOptionOrderExpression('po')}
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
        $userId = (int)($user['sub'] ?? 0);
        $this->applyRateLimit('default', $userId);

        $input = json_decode(file_get_contents('php://input'), true);

        $errors = [];
        if (empty($input['title'])) $errors[] = 'Le titre est requis.';
        if (empty($input['options']) || count($input['options']) < 2) {
            $errors[] = 'Au moins 2 options sont requises.';
        }
        if (($input['type'] ?? 'single') !== 'single') {
            $errors[] = 'La V1 supporte uniquement les consultations a choix unique.';
        }
        if (!empty($errors)) $this->error(implode(' ', $errors), 422);

        $this->db->beginTransaction();
        try {
            $columns = ['title', 'description'];
            $placeholders = ['?', '?'];
            $params = [
                Security::sanitizeString($input['title']),
                Security::sanitizeString($input['description'] ?? ''),
            ];

            if ($this->hasPollType()) {
                $columns[] = 'type';
                $placeholders[] = '?';
                $params[] = 'single';
            }

            if ($this->hasPollStatus()) {
                $columns[] = 'status';
                $placeholders[] = "'active'";
            } elseif ($this->dbHasColumn('polls', 'is_active')) {
                $columns[] = 'is_active';
                $placeholders[] = '1';
            }

            $columns[] = 'created_by';
            $placeholders[] = '?';
            $params[] = $userId;

            $columns[] = 'ends_at';
            $placeholders[] = '?';
            $params[] = $input['ends_at'] ?? null;

            $columns[] = 'created_at';
            $placeholders[] = 'NOW()';

            $stmt = $this->db->prepare(sprintf(
                'INSERT INTO polls (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $placeholders)
            ));
            $stmt->execute($params);
            $pollId = (int) $this->db->lastInsertId();

            foreach ($input['options'] as $idx => $optionLabel) {
                $optionText = Security::sanitizeString(trim($optionLabel));
                if ($optionText === '') {
                    continue;
                }

                $columns = ['poll_id'];
                $placeholders = ['?'];
                $params = [$pollId];

                if ($this->hasPollOptionLabel()) {
                    $columns[] = 'label';
                    $placeholders[] = '?';
                    $params[] = $optionText;
                } else {
                    $columns[] = 'text';
                    $placeholders[] = '?';
                    $params[] = $optionText;
                }

                if ($this->hasPollOptionSortOrder()) {
                    $columns[] = 'sort_order';
                    $placeholders[] = '?';
                    $params[] = $idx;
                }

                if ($this->hasPollOptionVotes()) {
                    $columns[] = 'votes';
                    $placeholders[] = '0';
                }

                $optStmt = $this->db->prepare(sprintf(
                    'INSERT INTO poll_options (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', $placeholders)
                ));
                $optStmt->execute($params);
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
        $user   = $this->requireAuth();
        $userId = (int)($user['sub'] ?? 0);
        $this->applyRateLimit('vote_create', $userId);

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
            WHERE id = ? AND {$this->pollStatusFilter('')} AND (ends_at IS NULL OR ends_at >= NOW())
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
        $existingStmt->execute([$pollId, $userId]);
        if ($existingStmt->fetch()) {
            $this->error('Vous avez déjà voté sur ce sondage.', 409);
        }

        $voteColumns = ['poll_id', 'option_id', 'user_id'];
        $voteValues = ['?', '?', '?'];
        if ($this->hasPollVoteVotedAt()) {
            $voteColumns[] = 'voted_at';
            $voteValues[] = 'NOW()';
        } elseif ($this->dbHasColumn('poll_votes', 'created_at')) {
            $voteColumns[] = 'created_at';
            $voteValues[] = 'NOW()';
        }

        $voteStmt = $this->db->prepare(sprintf(
            'INSERT INTO poll_votes (%s) VALUES (%s)',
            implode(', ', $voteColumns),
            implode(', ', $voteValues)
        ));
        $voteStmt->execute([$pollId, $optionId, $userId]);

        if ($this->hasPollOptionVotes()) {
            $this->db->prepare('UPDATE poll_options SET votes = votes + 1 WHERE id = ?')->execute([$optionId]);
        }

        $this->success(['voted_option_id' => $optionId], 201, 'Vote enregistré.');
    }

    /**
     * GET /polls/{id}/results
     * Résultats détaillés d'un sondage.
     */
    public function results(int $pollId): void
    {
        $this->requireAuth();

        $pollStmt = $this->db->prepare("SELECT * FROM polls WHERE id = ?");
        $pollStmt->execute([$pollId]);
        $poll = $pollStmt->fetch(PDO::FETCH_ASSOC);
        if (!$poll) $this->error('Sondage introuvable.', 404);

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM poll_votes WHERE poll_id = ?");
        $totalStmt->execute([$pollId]);
        $totalVotes = (int) $totalStmt->fetchColumn();

        $optStmt = $this->db->prepare("
            SELECT po.id, {$this->pollOptionTextExpression('po')} AS text,
                   {$this->pollOptionVotesExpression('po', 'pv')} AS votes_count,
                   ROUND({$this->pollOptionVotesExpression('po', 'pv')} * 100.0 / NULLIF(?, 0), 1) AS percentage
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

    private function hasPollStatus(): bool
    {
        return $this->pollHasStatus ??= $this->dbHasColumn('polls', 'status');
    }

    private function hasPollType(): bool
    {
        return $this->pollHasType ??= $this->dbHasColumn('polls', 'type');
    }

    private function hasPollOptionLabel(): bool
    {
        return $this->pollHasOptionLabel ??= $this->dbHasColumn('poll_options', 'label');
    }

    private function hasPollOptionSortOrder(): bool
    {
        return $this->pollHasOptionSortOrder ??= $this->dbHasColumn('poll_options', 'sort_order');
    }

    private function hasPollOptionVotes(): bool
    {
        return $this->pollHasOptionVotes ??= $this->dbHasColumn('poll_options', 'votes');
    }

    private function hasPollVoteVotedAt(): bool
    {
        return $this->pollVoteHasVotedAt ??= $this->dbHasColumn('poll_votes', 'voted_at');
    }

    private function pollStatusExpression(string $alias = 'p'): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return $this->hasPollStatus()
            ? "{$prefix}status"
            : "CASE WHEN COALESCE({$prefix}is_active, 0) = 1 THEN 'active' ELSE 'closed' END";
    }

    private function pollStatusFilter(string $alias = 'p'): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return $this->hasPollStatus()
            ? "{$prefix}status = 'active'"
            : "COALESCE({$prefix}is_active, 0) = 1";
    }

    private function pollOptionTextExpression(string $alias = 'po'): string
    {
        $prefix = rtrim($alias, '.') . '.';
        return $this->hasPollOptionLabel() ? "{$prefix}label" : "{$prefix}text";
    }

    private function pollOptionVotesExpression(string $optionsAlias = 'po', string $votesAlias = 'pv'): string
    {
        $optionPrefix = rtrim($optionsAlias, '.') . '.';
        $votesPrefix = rtrim($votesAlias, '.') . '.';
        return $this->hasPollOptionVotes()
            ? "GREATEST(COALESCE(MAX({$optionPrefix}votes), 0), COUNT({$votesPrefix}id))"
            : "COUNT({$votesPrefix}id)";
    }

    private function pollOptionOrderExpression(string $alias = 'po'): string
    {
        $prefix = rtrim($alias, '.') . '.';
        return $this->hasPollOptionSortOrder()
            ? "{$prefix}sort_order ASC, {$prefix}id ASC"
            : "{$prefix}id ASC";
    }
}
