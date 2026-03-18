<?php
/**
 * Ma Commune v1.3 — NotificationController (TECH-02)
 * Migration de backend/api/notifications.php vers l'architecture OO.
 */
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../config/PushNotificationService.php';

class NotificationController extends BaseController
{
    /**
     * POST /notifications/token — Enregistrer un token push
     */
    public function registerToken(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int)($auth['sub'] ?? 0);
        $this->requirePermission($auth, 'notification:register_token');
        $body   = $this->getBody();

        $token    = trim($body['token']    ?? '');
        $platform = trim($body['platform'] ?? '');

        if (empty($token) || !in_array($platform, ['ios', 'android'], true)) {
            $this->error('Token et plateforme (ios/android) requis.', 422);
        }

        // Upsert du token
        $stmt = $this->db->prepare("
            INSERT INTO push_tokens (user_id, token, platform, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE token = VALUES(token), updated_at = NOW()
        ");
        $stmt->execute([$userId, $token, $platform]);

        $this->success(['registered' => true], 201);
    }

    /**
     * GET /notifications — Liste des notifications de l'utilisateur
     */
    public function list(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int)($auth['sub'] ?? 0);
        $this->requirePermission($auth, 'notification:read_own');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $stmtTotal = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $stmtTotal->execute([$userId]);
        $total = (int)$stmtTotal->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT n.*, i.reference AS incident_reference, i.title AS incident_title
            FROM notifications n
            LEFT JOIN incidents i ON i.id = n.incident_id
            WHERE n.user_id = ?
            ORDER BY n.sent_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmtUnread = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmtUnread->execute([$userId]);
        $unreadCount = (int)$stmtUnread->fetchColumn();

        $this->success([
            'notifications' => array_map(function ($n) {
                return [
                    'id'                 => (int)$n['id'],
                    'type'               => $n['type'],
                    'title'              => $n['title'],
                    'body'               => $n['body'],
                    'is_read'            => (bool)$n['is_read'],
                    'sent_at'            => $n['sent_at'],
                    'incident_id'        => isset($n['incident_id']) ? (int)$n['incident_id'] : null,
                    'incident_reference' => $n['incident_reference'],
                    'incident_title'     => $n['incident_title'],
                ];
            }, $notifications),
            'unread_count' => $unreadCount,
            'pagination'   => [
                'total'       => $total,
                'page'        => $page,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    /**
     * PUT /notifications/{id}/read — Marquer une notification comme lue
     */
    public function markRead(int $notifId): void
    {
        $auth   = $this->requireAuth();
        $userId = (int)($auth['sub'] ?? 0);
        $this->requirePermission($auth, 'notification:mark_read');

        $stmt = $this->db->prepare("
            UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notifId, $userId]);

        if ($stmt->rowCount() === 0) {
            $this->notFound('Notification introuvable.');
        }

        $this->success(['updated' => true]);
    }

    /**
     * PUT /notifications/read-all — Marquer toutes les notifications comme lues
     */
    public function markAllRead(): void
    {
        $auth   = $this->requireAuth();
        $userId = (int)($auth['sub'] ?? 0);
        $this->requirePermission($auth, 'notification:mark_read');

        $this->db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
                 ->execute([$userId]);

        $this->success(['updated' => true]);
    }

    /**
     * POST /notifications/send — Envoyer une notification (agent/admin)
     */
    public function send(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'notification:send');

        $body       = $this->getBody();
        $incidentId = (int)($body['incident_id'] ?? 0);
        $title      = trim($body['title'] ?? '');
        $message    = trim($body['message'] ?? '');
        $targetUser = isset($body['user_id']) ? (int)$body['user_id'] : null;

        if (!$incidentId || empty($title) || empty($message)) {
            $this->error('incident_id, title et message sont requis.', 422);
        }

        // Récupérer les tokens push des destinataires
        $sql = 'SELECT pt.token, pt.user_id FROM push_tokens pt';
        $params = [];
        if ($targetUser) {
            $sql .= ' WHERE pt.user_id = ?';
            $params[] = $targetUser;
        } else {
            // Tous les utilisateurs ayant voté pour cet incident
            $sql .= ' JOIN votes v ON v.user_id = pt.user_id WHERE v.incident_id = ?';
            $params[] = $incidentId;
        }

        $stmtTokens = $this->db->prepare($sql);
        $stmtTokens->execute($params);
        $tokens = $stmtTokens->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($tokens)) {
            $this->success(['sent' => 0, 'message' => 'Aucun destinataire trouvé.']);
        }

        $pushService = new PushNotificationService();
        $sent = 0;
        foreach ($tokens as $t) {
            if ($pushService->send($t['token'], $title, $message, ['incident_id' => $incidentId])) {
                // Enregistrer en base
                $this->db->prepare("
                    INSERT INTO notifications (user_id, incident_id, type, title, body, is_read, sent_at)
                    VALUES (?, ?, 'system', ?, ?, 0, NOW())
                ")->execute([$t['user_id'], $incidentId, $title, $message]);
                $sent++;
            }
        }

        $this->success(['sent' => $sent]);
    }
}
