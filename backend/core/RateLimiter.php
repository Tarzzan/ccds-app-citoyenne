<?php
/**
 * RateLimiter — Middleware de limitation de débit avancé (TECH-04)
 *
 * Stratégie à deux niveaux :
 *  1. Par IP (protection contre les bots et attaques DDoS)
 *  2. Par utilisateur authentifié (protection contre les abus légitimes)
 *
 * Stockage : fichiers JSON dans /tmp/ccds_ratelimit/ (compatible sans Redis)
 * En production, remplacer par Redis pour de meilleures performances.
 */
class RateLimiter
{
    private string $storageDir;

    // ─── Configuration des limites ───────────────────────────────────────────
    private array $limits = [
        // Endpoints d'authentification — très restrictifs
        'auth_login'    => ['ip' => ['requests' => 10,  'window' => 900],  'user' => null],
        'auth_register' => ['ip' => ['requests' => 5,   'window' => 3600], 'user' => null],
        'auth_2fa'      => ['ip' => ['requests' => 10,  'window' => 600],  'user' => null],

        // Endpoints de création — modérément restrictifs
        'incident_create'  => ['ip' => ['requests' => 20,  'window' => 3600], 'user' => ['requests' => 10,  'window' => 3600]],
        'comment_create'   => ['ip' => ['requests' => 60,  'window' => 3600], 'user' => ['requests' => 30,  'window' => 3600]],
        'vote_create'      => ['ip' => ['requests' => 100, 'window' => 3600], 'user' => ['requests' => 50,  'window' => 3600]],
        'photo_upload'     => ['ip' => ['requests' => 30,  'window' => 3600], 'user' => ['requests' => 15,  'window' => 3600]],

        // Endpoints de lecture — permissifs
        'incident_list'    => ['ip' => ['requests' => 300, 'window' => 3600], 'user' => ['requests' => 600, 'window' => 3600]],
        'incident_detail'  => ['ip' => ['requests' => 500, 'window' => 3600], 'user' => ['requests' => 1000,'window' => 3600]],

        // API publique — limitée mais accessible
        'public_api'       => ['ip' => ['requests' => 100, 'window' => 3600], 'user' => null],

        // Webhooks — très restrictifs (admin seulement)
        'webhook_test'     => ['ip' => ['requests' => 5,   'window' => 3600], 'user' => ['requests' => 5,   'window' => 3600]],

        // Défaut global
        'default'          => ['ip' => ['requests' => 200, 'window' => 3600], 'user' => ['requests' => 400, 'window' => 3600]],
    ];

    public function __construct()
    {
        $this->storageDir = sys_get_temp_dir() . '/ccds_ratelimit/';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0700, true);
        }
    }

    /**
     * Vérifie et enregistre une requête.
     *
     * @param string   $endpoint  Clé de l'endpoint (ex: 'incident_create')
     * @param string   $ip        Adresse IP du client
     * @param int|null $userId    ID de l'utilisateur authentifié (null si anonyme)
     * @throws RateLimitException si la limite est dépassée
     */
    public function check(string $endpoint, string $ip, ?int $userId = null): void
    {
        $config = $this->limits[$endpoint] ?? $this->limits['default'];

        // Vérification par IP
        if (!empty($config['ip'])) {
            $this->checkLimit(
                key: "ip_{$ip}_{$endpoint}",
                requests: $config['ip']['requests'],
                window: $config['ip']['window'],
                type: 'IP',
                identifier: $ip
            );
        }

        // Vérification par utilisateur (si authentifié et si la limite est définie)
        if ($userId !== null && !empty($config['user'])) {
            $this->checkLimit(
                key: "user_{$userId}_{$endpoint}",
                requests: $config['user']['requests'],
                window: $config['user']['window'],
                type: 'utilisateur',
                identifier: (string) $userId
            );
        }
    }

    /**
     * Retourne les headers de rate limiting pour la réponse HTTP.
     */
    public function getHeaders(string $endpoint, string $ip, ?int $userId = null): array
    {
        $config = $this->limits[$endpoint] ?? $this->limits['default'];
        $ipConfig = $config['ip'] ?? ['requests' => 200, 'window' => 3600];

        $key = "ip_{$ip}_{$endpoint}";
        $data = $this->loadBucket($key);
        $remaining = max(0, $ipConfig['requests'] - ($data['count'] ?? 0));
        $reset = ($data['window_start'] ?? time()) + $ipConfig['window'];

        return [
            'X-RateLimit-Limit'     => $ipConfig['requests'],
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset'     => $reset,
            'X-RateLimit-Window'    => $ipConfig['window'],
        ];
    }

    // ─── Méthodes privées ────────────────────────────────────────────────────

    private function checkLimit(string $key, int $requests, int $window, string $type, string $identifier): void
    {
        $data = $this->loadBucket($key);
        $now  = time();

        // Réinitialiser si la fenêtre est expirée
        if (empty($data['window_start']) || ($now - $data['window_start']) >= $window) {
            $data = ['window_start' => $now, 'count' => 0];
        }

        $data['count']++;

        if ($data['count'] > $requests) {
            $retryAfter = $data['window_start'] + $window - $now;
            $this->saveBucket($key, $data);
            throw new RateLimitException(
                message: "Trop de requêtes pour ce {$type}. Réessayez dans {$retryAfter} secondes.",
                retryAfter: $retryAfter
            );
        }

        $this->saveBucket($key, $data);
    }

    private function loadBucket(string $key): array
    {
        $file = $this->storageDir . md5($key) . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $content = file_get_contents($file);
        return json_decode($content, true) ?? [];
    }

    private function saveBucket(string $key, array $data): void
    {
        $file = $this->storageDir . md5($key) . '.json';
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Nettoie les buckets expirés (à appeler périodiquement via cron).
     */
    public function cleanup(): int
    {
        $cleaned = 0;
        $files = glob($this->storageDir . '*.json') ?: [];
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (empty($data['window_start'])) {
                unlink($file);
                $cleaned++;
                continue;
            }
            // Supprimer les buckets dont la fenêtre la plus longue (3600s) est expirée
            if ((time() - $data['window_start']) > 7200) {
                unlink($file);
                $cleaned++;
            }
        }
        return $cleaned;
    }
}

/**
 * Exception levée quand la limite de débit est dépassée.
 */
class RateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message, 429);
    }
}
