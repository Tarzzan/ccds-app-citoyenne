<?php
/**
 * Ma Commune Back-Office — Bootstrap
 * Charge la configuration partagée avec le backend et démarre la session.
 */

// Charger la config et les helpers du backend
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/Database.php';
require_once __DIR__ . '/../../backend/config/helpers.php';
require_once __DIR__ . '/../../backend/config/InterventionWorkflow.php';
require_once __DIR__ . '/category_visuals.php';
require_once __DIR__ . '/generated_visuals.php';
require_once __DIR__ . '/../../backend/core/Security.php';

// Démarrer la session PHP sécurisée
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // Mettre true en production (HTTPS)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ----------------------------------------------------------------
// Fonctions d'authentification Back-Office (session PHP)
// ----------------------------------------------------------------

/**
 * Vérifie si l'utilisateur est connecté au back-office.
 * Redirige vers la page de login si ce n'est pas le cas.
 */
function require_admin_auth(): array
{
    if (empty($_SESSION['admin_user'])) {
        header('Location: /admin/?page=login');
        exit;
    }

    $db = Database::getInstance();
    $_SESSION['admin_user'] = admin_enrich_user_with_service_scope($db, $_SESSION['admin_user']);

    return $_SESSION['admin_user'];
}

/**
 * Vérifie si l'utilisateur a le rôle 'admin' (et non juste 'agent').
 */
function require_admin_role(): void
{
    $user = require_admin_auth();
    if ($user['role'] !== 'admin') {
        render_error(403, 'Accès réservé aux administrateurs.');
    }
}

/**
 * Retourne l'utilisateur connecté ou null.
 */
function current_admin(): ?array
{
    return $_SESSION['admin_user'] ?? null;
}

function admin_enrich_user_with_service_scope(PDO $db, array $user): array
{
    if (empty($user['id']) || !admin_db_has_table($db, 'user_service_memberships')) {
        $user['service_memberships'] = [];
        $user['allowed_service_ids'] = [];
        $user['primary_service_id'] = null;
        $user['primary_service_name'] = null;
        return $user;
    }

    $memberships = intervention_get_user_memberships($db, (int)$user['id']);
    $allowedServiceIds = array_values(array_map(
        static fn(array $membership): int => (int)$membership['service_id'],
        array_filter($memberships, static fn(array $membership): bool => !empty($membership['service_id']))
    ));
    $primaryMembership = $memberships[0] ?? null;

    $user['service_memberships'] = $memberships;
    $user['allowed_service_ids'] = $allowedServiceIds;
    $user['primary_service_id'] = $primaryMembership ? (int)$primaryMembership['service_id'] : null;
    $user['primary_service_name'] = $primaryMembership['service_name'] ?? null;

    return $user;
}

function admin_is_service_scoped_agent(array $user): bool
{
    return ($user['role'] ?? null) === 'agent' && !empty($user['allowed_service_ids']);
}

function admin_allowed_service_ids(array $user): array
{
    return array_values(array_map('intval', $user['allowed_service_ids'] ?? []));
}

function admin_has_service_access(array $user, ?int $serviceId): bool
{
    if (($user['role'] ?? null) === 'admin') {
        return true;
    }

    if ($serviceId === null || $serviceId <= 0) {
        return false;
    }

    return in_array($serviceId, admin_allowed_service_ids($user), true);
}

// ----------------------------------------------------------------
// Fonctions utilitaires d'affichage
// ----------------------------------------------------------------

function render_error(int $code, string $message): void
{
    http_response_code($code);
    echo "<div style='font-family:sans-serif;padding:40px;color:#ef4444;'>
            <h2>Erreur $code</h2><p>$message</p>
            <a href='/admin/' style='color:#1d4ed8'>← Retour au tableau de bord</a>
          </div>";
    exit;
}

function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function format_date(string $date): string
{
    return (new DateTime($date))->format('d/m/Y à H:i');
}

function format_date_short(string $date): string
{
    return (new DateTime($date))->format('d/m/Y');
}

// Libellés et couleurs des statuts
function status_label(string $status): string
{
    return [
        'submitted'    => 'Soumis',
        'acknowledged' => 'Pris en charge',
        'in_progress'  => 'En cours',
        'resolved'     => 'Résolu',
        'rejected'     => 'Rejeté',
    ][$status] ?? $status;
}

function status_class(string $status): string
{
    return [
        'submitted'    => 'badge-gray',
        'acknowledged' => 'badge-blue',
        'in_progress'  => 'badge-yellow',
        'resolved'     => 'badge-green',
        'rejected'     => 'badge-red',
    ][$status] ?? 'badge-gray';
}

function priority_label(string $p): string
{
    return ['low' => 'Faible', 'medium' => 'Normale', 'high' => 'Haute', 'critical' => 'Critique'][$p] ?? $p;
}

function priority_class(string $p): string
{
    return ['low' => 'badge-gray', 'medium' => 'badge-blue', 'high' => 'badge-yellow', 'critical' => 'badge-red'][$p] ?? 'badge-gray';
}

function role_label(string $r): string
{
    return ['citizen' => 'Citoyen', 'agent' => 'Agent', 'admin' => 'Administrateur'][$r] ?? $r;
}

function admin_db_has_column(PDO $db, string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function admin_db_has_table(PDO $db, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        $cache[$table] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}
