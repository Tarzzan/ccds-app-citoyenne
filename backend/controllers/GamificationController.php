<?php
/**
 * CCDS v1.3 — GamificationController (GAMIF-01)
 * Système de points et badges pour l'engagement citoyen.
 *
 * Règles de points :
 *   - Créer un signalement : +10 pts
 *   - Voter pour un signalement : +2 pts
 *   - Commenter un signalement : +3 pts
 *   - Signalement résolu : +20 pts bonus
 *
 * Badges :
 *   - explorer      : Premier signalement créé
 *   - active        : 10 signalements créés
 *   - top           : Dans le top 10% des contributeurs
 *   - popular       : Un signalement avec 50 votes
 *   - resolved      : 5 signalements résolus
 *   - voter         : 20 votes donnés
 *   - commenter     : 10 commentaires postés
 */
require_once __DIR__ . '/../core/BaseController.php';

class GamificationController extends BaseController
{
    // Définition des badges
    private const BADGES = [
        'explorer'  => ['label' => 'Explorateur',       'icon' => '🗺️',  'desc' => 'Premier signalement créé'],
        'active'    => ['label' => 'Contributeur actif','icon' => '⭐',  'desc' => '10 signalements créés'],
        'top'       => ['label' => 'Top contributeur',  'icon' => '🏆',  'desc' => 'Top 10% des citoyens'],
        'popular'   => ['label' => 'Signalement populaire','icon' => '🔥','desc' => 'Un signalement avec 50 votes'],
        'resolved'  => ['label' => 'Problème résolu',   'icon' => '✅',  'desc' => '5 signalements résolus'],
        'voter'     => ['label' => 'Votant engagé',     'icon' => '👍',  'desc' => '20 votes donnés'],
        'commenter' => ['label' => 'Commentateur',      'icon' => '💬',  'desc' => '10 commentaires postés'],
    ];

    /**
     * GET /gamification — Stats de l'utilisateur connecté
     */
    public function stats(): void
    {
        $userId = $this->requireAuth();

        // Récupérer ou créer les stats de gamification
        $row = $this->db->prepare("SELECT * FROM user_gamification WHERE user_id = ?");
        $row->execute([$userId]);
        $gamif = $row->fetch(\PDO::FETCH_ASSOC);

        if (!$gamif) {
            // Calculer les stats depuis les tables existantes
            $this->recalculate($userId);
            $row->execute([$userId]);
            $gamif = $row->fetch(\PDO::FETCH_ASSOC) ?? ['points' => 0];
        }

        // Badges obtenus
        $badgeStmt = $this->db->prepare("SELECT badge_key, awarded_at FROM user_badges WHERE user_id = ? ORDER BY awarded_at DESC");
        $badgeStmt->execute([$userId]);
        $earnedBadges = $badgeStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Rang parmi tous les utilisateurs
        $rankStmt = $this->db->query("
            SELECT COUNT(*) + 1 AS rank
            FROM user_gamification
            WHERE points > (SELECT COALESCE(points, 0) FROM user_gamification WHERE user_id = $userId)
        ");
        $rank = (int)($rankStmt->fetchColumn() ?? 1);

        // Total utilisateurs
        $total = (int)$this->db->query("SELECT COUNT(*) FROM users WHERE role = 'citizen'")->fetchColumn();

        // Statistiques détaillées
        $incidents_count = (int)$this->db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId")->fetchColumn();
        $votes_count     = (int)$this->db->query("SELECT COUNT(*) FROM votes WHERE user_id = $userId")->fetchColumn();
        $comments_count  = (int)$this->db->query("SELECT COUNT(*) FROM comments WHERE user_id = $userId")->fetchColumn();
        $resolved_count  = (int)$this->db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId AND status = 'resolved'")->fetchColumn();

        // Prochain badge à débloquer
        $nextBadge = $this->getNextBadge($userId, $incidents_count, $votes_count, $comments_count, $resolved_count, $earnedBadges);

        $this->success([
            'points'          => (int)($gamif['points'] ?? 0),
            'rank'            => $rank,
            'total_users'     => $total,
            'percentile'      => $total > 0 ? round((1 - ($rank / $total)) * 100) : 100,
            'incidents_count' => $incidents_count,
            'votes_count'     => $votes_count,
            'comments_count'  => $comments_count,
            'resolved_count'  => $resolved_count,
            'badges'          => array_map(function ($b) {
                $def = self::BADGES[$b['badge_key']] ?? ['label' => $b['badge_key'], 'icon' => '🏅', 'desc' => ''];
                return [
                    'key'         => $b['badge_key'],
                    'label'       => $def['label'],
                    'icon'        => $def['icon'],
                    'description' => $def['desc'],
                    'awarded_at'  => $b['awarded_at'],
                ];
            }, $earnedBadges),
            'next_badge' => $nextBadge,
        ]);
    }

    /**
     * GET /gamification/badges — Liste de tous les badges disponibles
     */
    public function badges(): void
    {
        $userId = $this->requireAuth();

        $earnedStmt = $this->db->prepare("SELECT badge_key FROM user_badges WHERE user_id = ?");
        $earnedStmt->execute([$userId]);
        $earned = array_column($earnedStmt->fetchAll(\PDO::FETCH_ASSOC), 'badge_key');

        $badges = [];
        foreach (self::BADGES as $key => $def) {
            $badges[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'icon'        => $def['icon'],
                'description' => $def['desc'],
                'earned'      => in_array($key, $earned, true),
            ];
        }

        $this->success($badges);
    }

    /**
     * Ajouter des points à un utilisateur et vérifier les badges.
     * Appelé depuis les autres contrôleurs après une action.
     */
    public static function addPoints(\PDO $db, int $userId, int $points, string $action): void
    {
        // Upsert dans user_gamification
        $db->prepare("
            INSERT INTO user_gamification (user_id, points, last_action_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE points = points + ?, last_action_at = NOW()
        ")->execute([$userId, $points, $points]);

        // Vérifier les badges à débloquer
        self::checkAndAwardBadges($db, $userId, $action);
    }

    // ----------------------------------------------------------------
    // Méthodes privées
    // ----------------------------------------------------------------

    private function recalculate(int $userId): void
    {
        $incidents = (int)$this->db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId")->fetchColumn();
        $votes     = (int)$this->db->query("SELECT COUNT(*) FROM votes WHERE user_id = $userId")->fetchColumn();
        $comments  = (int)$this->db->query("SELECT COUNT(*) FROM comments WHERE user_id = $userId")->fetchColumn();
        $resolved  = (int)$this->db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId AND status = 'resolved'")->fetchColumn();

        $points = ($incidents * 10) + ($votes * 2) + ($comments * 3) + ($resolved * 20);

        $this->db->prepare("
            INSERT INTO user_gamification (user_id, points, last_action_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE points = ?, last_action_at = NOW()
        ")->execute([$userId, $points, $points]);
    }

    private static function checkAndAwardBadges(\PDO $db, int $userId, string $action): void
    {
        $incidents = (int)$db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId")->fetchColumn();
        $votes     = (int)$db->query("SELECT COUNT(*) FROM votes WHERE user_id = $userId")->fetchColumn();
        $comments  = (int)$db->query("SELECT COUNT(*) FROM comments WHERE user_id = $userId")->fetchColumn();
        $resolved  = (int)$db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId AND status = 'resolved'")->fetchColumn();

        $toAward = [];

        if ($incidents >= 1)  $toAward[] = 'explorer';
        if ($incidents >= 10) $toAward[] = 'active';
        if ($votes >= 20)     $toAward[] = 'voter';
        if ($comments >= 10)  $toAward[] = 'commenter';
        if ($resolved >= 5)   $toAward[] = 'resolved';

        // Badge "popular" : un signalement avec 50+ votes
        $popular = $db->query("SELECT COUNT(*) FROM incidents WHERE user_id = $userId AND votes_count >= 50")->fetchColumn();
        if ($popular > 0) $toAward[] = 'popular';

        // Badge "top" : dans le top 10%
        $total = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'citizen'")->fetchColumn();
        $rank  = (int)$db->query("
            SELECT COUNT(*) + 1 FROM user_gamification
            WHERE points > (SELECT COALESCE(points, 0) FROM user_gamification WHERE user_id = $userId)
        ")->fetchColumn();
        if ($total > 0 && ($rank / $total) <= 0.10) $toAward[] = 'top';

        // Insérer uniquement les badges non encore obtenus
        foreach ($toAward as $badge) {
            try {
                $db->prepare("INSERT IGNORE INTO user_badges (user_id, badge_key, awarded_at) VALUES (?, ?, NOW())")
                   ->execute([$userId, $badge]);
            } catch (\PDOException $e) {
                // Ignorer les doublons
            }
        }
    }

    private function getNextBadge(
        int $userId,
        int $incidents,
        int $votes,
        int $comments,
        int $resolved,
        array $earnedBadges
    ): ?array {
        $earned = array_column($earnedBadges, 'badge_key');

        $candidates = [
            ['key' => 'explorer',  'condition' => $incidents < 1,  'progress' => $incidents,  'required' => 1,  'label' => 'Explorateur'],
            ['key' => 'active',    'condition' => $incidents < 10, 'progress' => $incidents,  'required' => 10, 'label' => 'Contributeur actif'],
            ['key' => 'voter',     'condition' => $votes < 20,     'progress' => $votes,      'required' => 20, 'label' => 'Votant engagé'],
            ['key' => 'commenter', 'condition' => $comments < 10,  'progress' => $comments,   'required' => 10, 'label' => 'Commentateur'],
            ['key' => 'resolved',  'condition' => $resolved < 5,   'progress' => $resolved,   'required' => 5,  'label' => 'Problème résolu'],
        ];

        foreach ($candidates as $c) {
            if (!in_array($c['key'], $earned, true) && $c['condition']) {
                $def = self::BADGES[$c['key']];
                return [
                    'key'      => $c['key'],
                    'label'    => $def['label'],
                    'icon'     => $def['icon'],
                    'progress' => $c['progress'],
                    'required' => $c['required'],
                ];
            }
        }

        return null; // Tous les badges obtenus
    }
}
