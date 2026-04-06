<?php
/**
 * Ma Commune v1.3 — NotificationController (TECH-02)
 * Migration de backend/api/notifications.php vers l'architecture OO.
 */
require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../config/PushNotificationService.php';
require_once __DIR__ . '/../config/NotificationStore.php';
require_once __DIR__ . '/../config/InterventionWorkflow.php';

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

        $sentColumn = NotificationStore::timestampColumn($this->db);
        $incidentExpr = NotificationStore::incidentIdExpression($this->db, 'n');
        $stmt = $this->db->prepare("
            SELECT
                n.*,
                {$incidentExpr} AS derived_incident_id,
                n.{$sentColumn} AS sent_at_value,
                i.reference AS incident_reference,
                i.title AS incident_title
            FROM notifications n
            LEFT JOIN incidents i ON i.id = {$incidentExpr}
            WHERE n.user_id = ?
            ORDER BY n.{$sentColumn} DESC
        ");
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $mappedNotifications = array_map(function ($n) {
            $interventionContext = $this->resolveNotificationInterventionContext($n);

            return [
                'id'                   => (int)$n['id'],
                'type'                 => $n['type'],
                'title'                => $n['title'],
                'body'                 => $n['body'],
                'is_read'              => (bool)$n['is_read'],
                'sent_at'              => $n['sent_at_value'],
                'incident_id'          => isset($n['derived_incident_id']) ? (int)$n['derived_incident_id'] : null,
                'incident_reference'   => $n['incident_reference'],
                'incident_title'       => $n['incident_title'],
                'intervention_context' => $interventionContext,
            ];
        }, $notifications);

        $visibleNotifications = $this->collapseVisibleDuplicates($mappedNotifications);
        $total = count($visibleNotifications);
        $offset = ($page - 1) * $limit;
        $pageNotifications = array_slice($visibleNotifications, $offset, $limit);
        $unreadCount = count(array_filter(
            $visibleNotifications,
            static fn(array $notification): bool => empty($notification['is_read'])
        ));

        $this->success([
            'notifications' => $pageNotifications,
            'unread_count' => $unreadCount,
            'pagination'   => [
                'total'       => $total,
                'page'        => $page,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    }

    private function collapseVisibleDuplicates(array $notifications): array
    {
        $collapsed = [];
        $seenSignatures = [];

        foreach ($notifications as $notification) {
            $signature = $this->notificationVisibleSignature($notification);
            if (isset($seenSignatures[$signature])) {
                continue;
            }

            $seenSignatures[$signature] = true;
            $collapsed[] = $notification;
        }

        return $collapsed;
    }

    private function notificationVisibleSignature(array $notification): string
    {
        return implode('|', [
            trim((string)($notification['type'] ?? '')),
            (string)((int)($notification['incident_id'] ?? 0)),
            trim((string)($notification['title'] ?? '')),
            trim((string)($notification['body'] ?? '')),
        ]);
    }

    private function resolveNotificationInterventionContext(array $notification): ?array
    {
        $incidentId = !empty($notification['incident_id']) ? (int)$notification['incident_id'] : 0;
        $type = (string)($notification['type'] ?? '');

        if ($incidentId <= 0 || !in_array($type, ['intervention_plan', 'intervention_update'], true)) {
            return null;
        }

        $eventTypes = $type === 'intervention_plan'
            ? ['plan_created', 'plan_rescheduled']
            : ['plan_status_changed'];

        $context = $this->findInterventionContextFromHistory(
            $incidentId,
            (string)($notification['sent_at'] ?? ''),
            $eventTypes
        );

        if ($context !== null) {
            return $context;
        }

        $plan = intervention_get_current_plan($this->db, $incidentId);
        if (!$plan) {
            return null;
        }

        return [
            'service_name' => $plan['service_name'] ?? null,
            'source_type' => $plan['source_type'] ?? null,
            'provider_name' => $plan['provider_name'] ?? null,
            'plan_status' => $plan['status'] ?? null,
            'scheduled_date' => $plan['scheduled_date'] ?? null,
            'time_window_start' => $plan['time_window_start'] ?? null,
            'time_window_end' => $plan['time_window_end'] ?? null,
        ];
    }

    private function findInterventionContextFromHistory(int $incidentId, string $sentAt, array $eventTypes): ?array
    {
        if (!intervention_table_exists($this->db, 'incident_service_history')) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($eventTypes), '?'));
        $params = array_merge([$incidentId], $eventTypes);

        $timeClause = '';
        if ($sentAt !== '') {
            $timeClause = ' AND h.created_at <= DATE_ADD(?, INTERVAL 2 MINUTE)';
            $params[] = $sentAt;
        }

        $stmt = $this->db->prepare("
            SELECT
                h.payload_json,
                h.created_at,
                h.event_type,
                s.name AS service_name
            FROM incident_service_history h
            LEFT JOIN services s ON s.id = h.service_id
            WHERE h.incident_id = ?
              AND h.event_type IN ($placeholders)
              $timeClause
            ORDER BY h.created_at DESC, h.id DESC
            LIMIT 1
        ");
        $stmt->execute($params);
        $entry = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$entry) {
            return null;
        }

        $payload = [];
        if (!empty($entry['payload_json'])) {
            $decoded = json_decode((string)$entry['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $timeWindow = trim((string)($payload['time_window'] ?? ''));
        [$timeStart, $timeEnd] = $this->splitTimeWindow($timeWindow);

        return [
            'service_name' => $entry['service_name'] ?? null,
            'source_type' => $payload['source_type'] ?? null,
            'provider_name' => $payload['provider_name'] ?? null,
            'plan_status' => $entry['event_type'] === 'plan_status_changed' ? ($payload['status'] ?? null) : null,
            'scheduled_date' => $payload['scheduled_date'] ?? null,
            'time_window_start' => $timeStart,
            'time_window_end' => $timeEnd,
        ];
    }

    private function splitTimeWindow(string $timeWindow): array
    {
        if ($timeWindow === '') {
            return [null, null];
        }

        $parts = preg_split('/\s*-\s*/', $timeWindow);
        if (!$parts || count($parts) === 1) {
            return [trim($timeWindow), null];
        }

        return [trim((string)$parts[0]), trim((string)$parts[1])];
    }

    /**
     * PUT /notifications/{id}/read — Marquer une notification comme lue
     */
    public function markRead(int $notifId): void
    {
        $auth   = $this->requireAuth();
        $userId = (int)($auth['sub'] ?? 0);
        $this->requirePermission($auth, 'notification:mark_read');

        $notification = $this->findUserNotification($notifId, $userId);
        if (!$notification) {
            $this->notFound('Notification introuvable.');
        }

        $conditions = [
            'user_id = ?',
            'type = ?',
            'title = ?',
            'body = ?',
        ];
        $params = [
            $userId,
            $notification['type'],
            $notification['title'],
            $notification['body'],
        ];

        $incidentExpr = NotificationStore::incidentIdExpression($this->db);
        if ($incidentExpr !== 'NULL') {
            $conditions[] = "COALESCE({$incidentExpr}, 0) = COALESCE(?, 0)";
            $params[] = $notification['incident_id'] ?? null;
        }

        $stmt = $this->db->prepare(sprintf(
            'UPDATE notifications SET is_read = 1 WHERE %s',
            implode(' AND ', $conditions)
        ));
        $stmt->execute($params);

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

    private function findUserNotification(int $notifId, int $userId): ?array
    {
        $sentColumn = NotificationStore::timestampColumn($this->db);
        $incidentExpr = NotificationStore::incidentIdExpression($this->db);
        $stmt = $this->db->prepare("
            SELECT
                id,
                user_id,
                type,
                title,
                body,
                is_read,
                {$incidentExpr} AS incident_id,
                {$sentColumn} AS sent_at
            FROM notifications
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$notifId, $userId]);
        $notification = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $notification ?: null;
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
                NotificationStore::insert(
                    $this->db,
                    (int) $t['user_id'],
                    $incidentId,
                    'system',
                    $title,
                    $message,
                    ['audience' => $targetUser ? 'user' : 'voters'],
                    0
                );
                $sent++;
            }
        }

        $this->success(['sent' => $sent]);
    }
}
