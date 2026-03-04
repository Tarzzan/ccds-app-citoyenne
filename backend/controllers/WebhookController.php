<?php
/**
 * WebhookController — Webhooks sortants configurables (API-03)
 *
 * Permet aux administrateurs de configurer des webhooks pour notifier
 * des systèmes externes lors d'événements CCDS.
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
    // ─── CRUD des webhooks (admin) ───────────────────────────────────────────

    /**
     * GET /admin/webhooks
     */
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $stmt = $this->db->query("SELECT * FROM webhooks ORDER BY created_at DESC");
        $this->success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * POST /admin/webhooks
     */
    public function create(): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);
        $this->applyRateLimit('default', $user['id']);

        $input = json_decode(file_get_contents('php://input'), true);

        $errors = [];
        if (empty($input['target_url'])) $errors[] = 'L\'URL cible est requise.';
        if (empty($input['event']))      $errors[] = 'L\'événement est requis.';
        if (!empty($errors)) $this->error(implode(' ', $errors), 422);

        $validEvents = [
            'incident.created', 'incident.status.changed', 'incident.resolved',
            'comment.created', 'user.registered', '*',
        ];
        if (!in_array($input['event'], $validEvents)) {
            $this->error('Événement invalide. Valeurs acceptées : ' . implode(', ', $validEvents), 400);
        }

        if (!filter_var($input['target_url'], FILTER_VALIDATE_URL)) {
            $this->error('URL cible invalide.', 400);
        }

        $stmt = $this->db->prepare("
            INSERT INTO webhooks (target_url, event, secret, is_active, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ");
        $secret = bin2hex(random_bytes(20)); // Secret HMAC pour la vérification côté client
        $stmt->execute([$input['target_url'], $input['event'], $secret]);

        $this->success([
            'id'         => (int) $this->db->lastInsertId(),
            'secret'     => $secret,
            'note'       => 'Conservez ce secret — il ne sera plus affiché.',
        ], 201, 'Webhook créé avec succès.');
    }

    /**
     * PUT /admin/webhooks/{id}
     */
    public function update(int $id): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);

        $input = json_decode(file_get_contents('php://input'), true);
        $stmt  = $this->db->prepare("
            UPDATE webhooks SET
                target_url = COALESCE(?, target_url),
                event      = COALESCE(?, event),
                is_active  = COALESCE(?, is_active)
            WHERE id = ?
        ");
        $stmt->execute([
            $input['target_url'] ?? null,
            $input['event']      ?? null,
            isset($input['is_active']) ? (int) $input['is_active'] : null,
            $id,
        ]);
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
        $this->requireAdmin($user);
        $this->applyRateLimit('webhook_test', $user['id']);

        $stmt = $this->db->prepare("SELECT * FROM webhooks WHERE id = ?");
        $stmt->execute([$id]);
        $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$webhook) $this->error('Webhook introuvable.', 404);

        $payload = [
            'event'     => 'webhook.test',
            'timestamp' => date('c'),
            'data'      => ['message' => 'Ceci est un événement de test CCDS Citoyen.'],
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
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT * FROM webhooks
                WHERE is_active = 1 AND (event = ? OR event = '*')
            ");
            $stmt->execute([$event]);
            $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

        $ch = curl_init($webhook['target_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-CCDS-Signature: sha256=' . $signature,
                'X-CCDS-Event: ' . $payload['event'],
                'User-Agent: CCDS-Webhook/1.6',
            ],
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        // Enregistrer le résultat
        $this->db->prepare("
            INSERT INTO webhook_deliveries (webhook_id, event, status_code, response, delivered_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([
            $webhook['id'],
            $payload['event'],
            $httpCode,
            substr($response ?: $curlError, 0, 500),
        ]);

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

        $ch = curl_init($webhook['target_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-CCDS-Signature: sha256=' . $signature,
                'X-CCDS-Event: ' . $payload['event'],
                'User-Agent: CCDS-Webhook/1.6',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function requireAdmin(array $user): void
    {
        if ($user['role'] !== 'admin') {
            $this->error('Accès réservé aux administrateurs.', 403);
        }
    }
}
