<?php
/**
 * CCDS v1.2 — Classe de sécurité centralisée (SEC-01)
 *
 * Protections :
 * - Sanitisation XSS sur toutes les entrées
 * - Rate limiting par IP (en mémoire APCu ou fichier)
 * - Validation renforcée des types
 * - En-têtes de sécurité HTTP
 */

class Security
{
    // Nombre maximum de requêtes par fenêtre de temps
    private const RATE_LIMIT_MAX     = 60;
    private const RATE_LIMIT_WINDOW  = 60; // secondes

    // ----------------------------------------------------------------
    // En-têtes de sécurité HTTP
    // ----------------------------------------------------------------

    /**
     * Applique les en-têtes de sécurité HTTP recommandés.
     */
    public static function applySecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    // ----------------------------------------------------------------
    // Sanitisation XSS
    // ----------------------------------------------------------------

    /**
     * Nettoie une chaîne de caractères contre les injections XSS.
     */
    public static function sanitizeString(mixed $value): string
    {
        if ($value === null) return '';
        return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitise récursivement un tableau de données (ex: corps JSON).
     * Les clés numériques et les booléens/entiers ne sont pas modifiés.
     */
    public static function sanitizeArray(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $cleanKey = self::sanitizeString($key);
            if (is_array($value)) {
                $clean[$cleanKey] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $clean[$cleanKey] = self::sanitizeString($value);
            } else {
                // int, float, bool, null → pas de sanitisation de chaîne
                $clean[$cleanKey] = $value;
            }
        }
        return $clean;
    }

    /**
     * Retourne le corps JSON de la requête, sanitisé.
     */
    public static function getJsonBody(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];
        return self::sanitizeArray($data);
    }

    // ----------------------------------------------------------------
    // Rate Limiting (par IP, stockage fichier)
    // ----------------------------------------------------------------

    /**
     * Vérifie si l'IP courante dépasse la limite de requêtes.
     * Envoie une erreur 429 si la limite est dépassée.
     */
    public static function checkRateLimit(): void
    {
        $ip      = self::getClientIp();
        $key     = 'rl_' . md5($ip);
        $dir     = sys_get_temp_dir() . '/' . (defined('APP_SLUG') ? APP_SLUG : 'ma_commune') . '_rl/';

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $file = $dir . $key . '.json';
        $now  = time();
        $data = ['count' => 0, 'window_start' => $now];

        if (file_exists($file)) {
            $stored = json_decode(file_get_contents($file), true);
            if ($stored && ($now - $stored['window_start']) < self::RATE_LIMIT_WINDOW) {
                $data = $stored;
            }
        }

        $data['count']++;
        file_put_contents($file, json_encode($data), LOCK_EX);

        if ($data['count'] > self::RATE_LIMIT_MAX) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Trop de requêtes. Veuillez réessayer dans quelques secondes.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ----------------------------------------------------------------
    // Utilitaires
    // ----------------------------------------------------------------

    /**
     * Retourne l'adresse IP réelle du client (derrière un proxy si nécessaire).
     */
    public static function getClientIp(): string
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Valide et nettoie un entier (ex: ID de ressource).
     * Retourne null si la valeur n'est pas un entier positif valide.
     */
    public static function sanitizeId(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int !== false ? (int)$int : null;
    }

    /**
     * Valide une latitude (entre -90 et 90).
     */
    public static function sanitizeLatitude(mixed $value): ?float
    {
        $f = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($f === false || $f < -90 || $f > 90) return null;
        return round($f, 8);
    }

    /**
     * Valide une longitude (entre -180 et 180).
     */
    public static function sanitizeLongitude(mixed $value): ?float
    {
        $f = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($f === false || $f < -180 || $f > 180) return null;
        return round($f, 8);
    }
}
