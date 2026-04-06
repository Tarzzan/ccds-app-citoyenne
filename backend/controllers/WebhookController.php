<?php
/**
 * WebhookController — Webhooks sortants configurables (API-03)
 *
 * Permet aux administrateurs de configurer des webhooks pour notifier
 * des systèmes externes lors d'événements Ma Commune.
 *
 * Événements supportés :
 *   - incident.created
 *   - incident.status.changed
 *   - incident.resolved
 *   - comment.created
 *   - user.registered
 */
class WebhookController extends BaseController
{
    private const VALID_EVENTS = [
        'incident.created',
        'incident.status.changed',
        'incident.resolved',
        'comment.created',
        'user.registered',
        '*',
    ];

    // ─── CRUD des webhooks (admin) ───────────────────────────────────────────

    /**
     * GET /admin/webhooks
     */
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $stmt = $this->db->query("SELECT * FROM webhooks ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->success(array_map([$this, 'normalizeWebhookRecord'], $rows));
    }

    /**
     * POST /admin/webhooks
     */
    public function create(): void
    {
        $user = $this->requireAuth();
        $userId = $this->getAuthUserId($user);
        $this->requireAdmin($user);
        $this->applyRateLimit('default', $userId);

        $input = $this->getJsonBody();
        $targetUrl = trim((string) ($input['target_url'] ?? $input['url'] ?? ''));
        $events = $this->normalizeRequestedEvents($input);

        $errors = [];
        if ($targetUrl === '') {
            $errors[] = 'L\'URL cible est requise.';
        }
        if ($events === []) {
            $errors[] = 'L\'événement est requis.';
        }
        if (!empty($errors)) $this->error(implode(' ', $errors), 422);

        foreach ($events as $event) {
            if (!in_array($event, self::VALID_EVENTS, true)) {
                $this->error('Événement invalide. Valeurs acceptées : ' . implode(', ', self::VALID_EVENTS), 400);
            }
        }

        if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            $this->error('URL cible invalide.', 400);
        }

        $secret = bin2hex(random_bytes(20)); // Secret HMAC pour la vérification côté client
        $query = $this->buildCreateWebhookStatement();
        $params = $this->buildCreateWebhookParams($targetUrl, $events, $secret);

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        $this->success([
            'id'         => (int) $this->db->lastInsertId(),
            'secret'     => $secret,
            'note'       => 'Conservez ce secret — il ne sera plus affiché.',
            'target_url' => $targetUrl,
            'events'     => $events,
        ], 201, 'Webhook créé avec succès.');
    }

    /**
     * PUT /admin/webhooks/{id}
     */
    public function update(int $id): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $input = $this->getJsonBody();
        $updates = [];
        $params = [];

        if (array_key_exists('target_url', $input) || array_key_exists('url', $input)) {
            $targetUrl = trim((string) ($input['target_url'] ?? $input['url'] ?? ''));
            if ($targetUrl === '') {
                $this->error('L\'URL cible est requise.', 422);
            }
            if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
                $this->error('URL cible invalide.', 400);
            }

            if ($this->dbHasColumn('webhooks', 'target_url')) {
                $updates[] = 'target_url = ?';
                $params[] = $targetUrl;
            }
            if ($this->dbHasColumn('webhooks', 'url')) {
                $updates[] = 'url = ?';
                $params[] = $targetUrl;
            }
        }

        if (array_key_exists('event', $input) || array_key_exists('events', $input)) {
            $events = $this->normalizeRequestedEvents($input);
            if ($events === []) {
                $this->error('L\'événement est requis.', 422);
            }
            foreach ($events as $event) {
                if (!in_array($event, self::VALID_EVENTS, true)) {
                    $this->error('Événement invalide. Valeurs acceptées : ' . implode(', ', self::VALID_EVENTS), 400);
                }
            }

            if ($this->dbHasColumn('webhooks', 'event')) {
                $updates[] = 'event = ?';
                $params[] = $events[0];
            }
            if ($this->dbHasColumn('webhooks', 'events')) {
                $updates[] = 'events = ?';
                $params[] = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if (array_key_exists('is_active', $input)) {
            $updates[] = 'is_active = ?';
            $params[] = (int) ((bool) $input['is_active']);
        }

        if ($this->dbHasColumn('webhooks', 'updated_at')) {
            $updates[] = 'updated_at = NOW()';
        }

        if ($updates === []) {
            $this->success(null, 200, 'Aucune modification à appliquer.');
        }

        $params[] = $id;
        $stmt = $this->db->prepare(sprintf(
            'UPDATE webhooks SET %s WHERE id = ?',
            implode(', ', $updates)
        ));
        $stmt->execute($params);
        $this->success(null, 200, 'Webhook mis à jour.');
    }

    /**
     * DELETE /admin/webhooks/{id}
     */
    public function delete(int $id): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $this->db->prepare("DELETE FROM webhooks WHERE id = ?")->execute([$id]);
        $this->success(null, 200, 'Webhook supprimé.');
    }

    /**
     * POST /admin/webhooks/{id}/test
     * Envoyer un événement de test.
     */
    public function test(int $id): void
    {
        $user = $this->requireAuth();
        $userId = $this->getAuthUserId($user);
        $this->requireAdmin($user);
        $this->applyRateLimit('webhook_test', $userId);

        $stmt = $this->db->prepare("SELECT * FROM webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$webhook) $this->error('Webhook introuvable.', 404);

        $payload = [
            'event'     => 'webhook.test',
            'timestamp' => date('c'),
            'data'      => ['message' => 'Ceci est un événement de test ' . (defined('APP_NAME') ? APP_NAME : 'Ma Commune') . '.'],
        ];

        $result = $this->dispatch($webhook, $payload);
        $this->success($result, 200, 'Test envoyé.');
    }

    // ─── Dispatch (appelé par les autres contrôleurs) ────────────────────────

    /**
     * Déclenche tous les webhooks correspondant à un événement.
     * À appeler depuis les autres contrôleurs lors d'événements importants.
     *
     * @param string $event   ex: 'incident.created'
     * @param array  $data    Données de l'événement
     */
    public static function trigger(string $event, array $data): void
    {
        try {
            $db   = Database::getInstance();
            $webhooks = self::fetchMatchingWebhooks($db, $event);

            $payload = [
                'event'     => $event,
                'timestamp' => date('c'),
                'data'      => $data,
            ];

            foreach ($webhooks as $webhook) {
                // Dispatch asynchrone (fire-and-forget via curl_multi ou queue)
                self::dispatchAsync($webhook, $payload);
            }
        } catch (\Exception $e) {
            // Ne jamais bloquer l'application principale pour un webhook
            error_log("WebhookController::trigger error: " . $e->getMessage());
        }
    }

    // ─── Méthodes privées ────────────────────────────────────────────────────

    private function dispatch(array $webhook, array $payload): array
    {
        $body      = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhook['secret'] ?? '');
        $targetUrl = self::webhookTargetUrl($webhook);
        if ($targetUrl === '') {
            return [
                'status_code' => 0,
                'success'     => false,
            ];
        }

        $ch = curl_init($targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-' . strtoupper(defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MACOMMUNE') . '-Signature: sha256=' . $signature,
                'X-' . strtoupper(defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MACOMMUNE') . '-Event: ' . $payload['event'],
                'User-Agent: ' . (defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MaCommune') . '-Webhook/' . (defined('APP_VERSION') ? APP_VERSION : '1.0.0'),
            ],
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($this->hasTable('webhook_deliveries')) {
            $this->db->prepare("
                INSERT INTO webhook_deliveries (webhook_id, event, status_code, response, delivered_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([
                $webhook['id'],
                $payload['event'],
                $httpCode,
                substr($response ?: $curlError, 0, 500),
            ]);
        }

        return [
            'status_code' => $httpCode,
            'success'     => $httpCode >= 200 && $httpCode < 300,
        ];
    }

    private static function dispatchAsync(array $webhook, array $payload): void
    {
        // En production : utiliser une queue (Redis/RabbitMQ)
        // Pour l'instant : curl non-bloquant
        $body      = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $webhook['secret'] ?? '');
        $targetUrl = self::webhookTargetUrl($webhook);
        if ($targetUrl === '') {
            return;
        }

        $ch = curl_init($targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-' . strtoupper(defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MACOMMUNE') . '-Signature: sha256=' . $signature,
                'X-' . strtoupper(defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MACOMMUNE') . '-Event: ' . $payload['event'],
                'User-Agent: ' . (defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MaCommune') . '-Webhook/' . (defined('APP_VERSION') ? APP_VERSION : '1.0.0'),
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function buildCreateWebhookStatement(): string
    {
        $columns = [];
        $placeholders = [];

        if ($this->dbHasColumn('webhooks', 'target_url')) {
            $columns[] = 'target_url';
            $placeholders[] = '?';
        }
        if ($this->dbHasColumn('webhooks', 'url')) {
            $columns[] = 'url';
            $placeholders[] = '?';
        }
        if ($this->dbHasColumn('webhooks', 'event')) {
            $columns[] = 'event';
            $placeholders[] = '?';
        }
        if ($this->dbHasColumn('webhooks', 'events')) {
            $columns[] = 'events';
            $placeholders[] = '?';
        }

        $columns[] = 'secret';
        $placeholders[] = '?';
        $columns[] = 'is_active';
        $placeholders[] = '1';
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';

        if ($this->dbHasColumn('webhooks', 'updated_at')) {
            $columns[] = 'updated_at';
            $placeholders[] = 'NOW()';
        }

        return sprintf(
            'INSERT INTO webhooks (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
    }

    private function buildCreateWebhookParams(string $targetUrl, array $events, string $secret): array
    {
        $params = [];

        if ($this->dbHasColumn('webhooks', 'target_url')) {
            $params[] = $targetUrl;
        }
        if ($this->dbHasColumn('webhooks', 'url')) {
            $params[] = $targetUrl;
        }
        if ($this->dbHasColumn('webhooks', 'event')) {
            $params[] = $events[0];
        }
        if ($this->dbHasColumn('webhooks', 'events')) {
            $params[] = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $params[] = $secret;

        return $params;
    }

    private function normalizeRequestedEvents(array $input): array
    {
        $raw = $input['events'] ?? $input['event'] ?? [];
        $events = self::parseEventsValue($raw);

        if (in_array('*', $events, true)) {
            return ['*'];
        }

        return array_values(array_unique($events));
    }

    private function normalizeWebhookRecord(array $webhook): array
    {
        $events = self::parseEventsValue($webhook['events'] ?? $webhook['event'] ?? []);
        $targetUrl = self::webhookTargetUrl($webhook);

        $webhook['target_url'] = $targetUrl;
        $webhook['url'] = $targetUrl;
        $webhook['events'] = $events;
        $webhook['event'] = $events[0] ?? null;

        return $webhook;
    }

    private static function parseEventsValue(mixed $raw): array
    {
        if (is_array($raw)) {
            $events = $raw;
        } elseif (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $events = $decoded;
            } elseif (str_contains($trimmed, ',')) {
                $events = array_map('trim', explode(',', $trimmed));
            } else {
                $events = [$trimmed];
            }
        } else {
            return [];
        }

        $events = array_values(array_filter(array_map(
            static fn($event): string => trim((string) $event),
            $events
        )));

        return array_values(array_unique($events));
    }

    private static function webhookTargetUrl(array $webhook): string
    {
        return trim((string) ($webhook['target_url'] ?? $webhook['url'] ?? ''));
    }

    private static function fetchMatchingWebhooks(PDO $db, string $event): array
    {
        if (self::hasColumnOn($db, 'webhooks', 'event')) {
            $stmt = $db->prepare("
                SELECT * FROM webhooks
                WHERE is_active = 1 AND (event = ? OR event = '*')
                ORDER BY created_at DESC
            ");
            $stmt->execute([$event]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $db->query("SELECT * FROM webhooks WHERE is_active = 1 ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, static function (array $webhook) use ($event): bool {
            $events = self::parseEventsValue($webhook['events'] ?? []);
            return in_array('*', $events, true) || in_array($event, $events, true);
        }));
    }

    private static function hasColumnOn(PDO $db, string $tableName, string $columnName): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$tableName, $columnName]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
