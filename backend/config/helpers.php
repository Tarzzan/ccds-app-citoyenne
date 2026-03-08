<?php
/**
 * CCDS — Fonctions utilitaires partagées par toute l'API
 */

// =============================================================
// Réponses JSON standardisées
// =============================================================

/**
 * Envoie une réponse JSON de succès et termine l'exécution.
 */
function json_success(mixed $data, int $code = 200, string $message = 'OK'): void
{
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Envoie une réponse JSON d'erreur et termine l'exécution.
 */
function json_error(string $message, int $code = 400, array $errors = []): void
{
    http_response_code($code);
    $body = ['success' => false, 'message' => $message];
    if (!empty($errors)) {
        $body['errors'] = $errors;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// =============================================================
// JWT (JSON Web Token) — implémentation légère sans dépendance
// =============================================================

/**
 * Encode un payload en token JWT signé avec HMAC-SHA256.
 */
function jwt_encode(array $payload): string
{
    $header  = base64url_encode(json_encode(['alg' => JWT_ALGORITHM, 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRY;
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

/**
 * Décode et vérifie un token JWT. Retourne le payload ou null si invalide.
 */
function jwt_decode(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));

    // Comparaison sécurisée (résistante aux timing attacks)
    if (!hash_equals($expected, $sig)) return null;

    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) return null;

    return $payload;
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// =============================================================
// Authentification — Middleware
// =============================================================

/**
 * Vérifie le token JWT dans l'en-tête Authorization.
 * Retourne le payload (id, role) ou envoie une erreur 401.
 */
function require_auth(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        json_error('Token d\'authentification manquant.', 401);
    }
    $payload = jwt_decode($m[1]);
    if (!$payload) {
        json_error('Token invalide ou expiré.', 401);
    }
    return $payload;
}

/**
 * Vérifie que l'utilisateur authentifié possède l'un des rôles requis.
 */
function require_role(array $payload, array $roles): void
{
    if (!in_array($payload['role'], $roles, true)) {
        json_error('Accès refusé : droits insuffisants.', 403);
    }
}

// =============================================================
// Validation
// =============================================================

/**
 * Valide un tableau de données selon des règles simples.
 * Retourne un tableau d'erreurs (vide si tout est valide).
 */
function validate(array $data, array $rules): array
{
    $errors = [];
    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? null;
        foreach (explode('|', $rule) as $r) {
            if ($r === 'required' && ($value === null || $value === '')) {
                $errors[$field][] = "Le champ '$field' est obligatoire.";
            } elseif (str_starts_with($r, 'min:') && strlen((string)$value) < (int)substr($r, 4)) {
                $errors[$field][] = "Le champ '$field' doit contenir au moins " . substr($r, 4) . " caractères.";
            } elseif (str_starts_with($r, 'max:') && strlen((string)$value) > (int)substr($r, 4)) {
                $errors[$field][] = "Le champ '$field' ne doit pas dépasser " . substr($r, 4) . " caractères.";
            } elseif ($r === 'email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$field][] = "Le champ '$field' doit être une adresse email valide.";
            } elseif ($r === 'numeric' && $value !== null && !is_numeric($value)) {
                $errors[$field][] = "Le champ '$field' doit être un nombre.";
            }
        }
    }
    return $errors;
}

// =============================================================
// Divers
// =============================================================

/**
 * Génère une référence unique pour un signalement (ex: CCDS-2026-00042).
 */
function generate_reference(int $id): string
{
    return (defined('APP_REFERENCE_PREFIX') ? APP_REFERENCE_PREFIX : 'MC') . '-' . date('Y') . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

/**
 * Retourne le corps de la requête HTTP décodé en JSON.
 */
function get_json_body(): array
{
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}
