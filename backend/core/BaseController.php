<?php
/**
 * CCDS v1.2 — Contrôleur de base (TECH-01)
 *
 * Toutes les classes contrôleurs héritent de cette classe.
 * Fournit les méthodes communes : réponses JSON, authentification, pagination.
 */

abstract class BaseController
{
    protected PDO $db;
    protected RateLimiter $rateLimiter;

    public function __construct()
    {
        $this->db          = Database::getInstance();
        $this->rateLimiter = new RateLimiter();
    }

    /**
     * Applique le rate limiting pour un endpoint donné.
     * Ajoute automatiquement les headers X-RateLimit-* à la réponse.
     */
    protected function applyRateLimit(string $endpoint, ?int $userId = null): void
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
        // Prendre la première IP en cas de proxy chain
        $ip = trim(explode(',', $ip)[0]);

        // Ajouter les headers de rate limiting
        foreach ($this->rateLimiter->getHeaders($endpoint, $ip, $userId) as $header => $value) {
            header("{$header}: {$value}");
        }

        try {
            $this->rateLimiter->check($endpoint, $ip, $userId);
        } catch (RateLimitException $e) {
            header('Retry-After: ' . $e->retryAfter);
            $this->error($e->getMessage(), 429);
        }
    }

    // ----------------------------------------------------------------
    // Réponses JSON standardisées
    // ----------------------------------------------------------------

    protected function success(mixed $data, int $code = 200, string $message = 'OK'): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function error(string $message, int $code = 400, array $errors = []): void
    {
        http_response_code($code);
        $body = ['success' => false, 'message' => $message];
        if (!empty($errors)) {
            $body['errors'] = $errors;
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ----------------------------------------------------------------
    // Authentification
    // ----------------------------------------------------------------

    /**
     * Vérifie le token JWT et retourne le payload.
     * Envoie une erreur 401 si absent ou invalide.
     */
    protected function requireAuth(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            $this->error("Token d'authentification manquant.", 401);
        }
        $payload = jwt_decode($m[1]);
        if (!$payload) {
            $this->error('Token invalide ou expiré.', 401);
        }
        return $payload;
    }

    /**
     * Exige une permission RBAC pour l'utilisateur authentifié.
     */
    protected function requirePermission(array $auth, string $permission): void
    {
        Permissions::require($auth, $permission);
    }

    // ----------------------------------------------------------------
    // Pagination
    // ----------------------------------------------------------------

    /**
     * Retourne les paramètres de pagination depuis la requête GET.
     */
    protected function getPagination(int $defaultLimit = 20, int $maxLimit = 50): array
    {
        $page   = max(1, (int)($_GET['page']  ?? 1));
        $limit  = min($maxLimit, max(1, (int)($_GET['limit'] ?? $defaultLimit)));
        $offset = ($page - 1) * $limit;
        return compact('page', 'limit', 'offset');
    }

    /**
     * Construit la réponse paginée standard.
     */
    protected function paginatedResponse(array $items, int $total, int $page, int $limit): array
    {
        return [
            'items'       => $items,
            'pagination'  => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / max(1, $limit)),
            ],
        ];
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * Valide un tableau de données selon des règles simples.
     * Appelle $this->error() si des erreurs sont trouvées.
     */
    protected function validate(array $data, array $rules): void
    {
        $errors = validate($data, $rules);
        if (!empty($errors)) {
            $this->error('Données invalides.', 422, $errors);
        }
    }
}
