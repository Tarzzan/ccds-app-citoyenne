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
require_once __DIR__ . '/../../backend/config/ContentModerationService.php';
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

function e(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
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

function admin_content_moderation_service(PDO $db): ContentModerationService
{
    static $instances = [];

    $key = spl_object_id($db);
    if (!isset($instances[$key])) {
        $instances[$key] = new ContentModerationService($db);
    }

    return $instances[$key];
}

function admin_photos_have_moderation_columns(PDO $db): bool
{
    return admin_db_has_column($db, 'photos', 'moderation_status');
}

if (!function_exists('slugify_ascii')) {
    function slugify_ascii(string $value): string
    {
        $value = strtolower(trim($value));
        $map = [
            'a' => ['a', 'aa', 'ae', 'à', 'á', 'â', 'ã', 'ä', 'å'],
            'c' => ['c', 'ç'],
            'e' => ['e', 'è', 'é', 'ê', 'ë'],
            'i' => ['i', 'ì', 'í', 'î', 'ï'],
            'n' => ['n', 'ñ'],
            'o' => ['o', 'ò', 'ó', 'ô', 'õ', 'ö', 'oe'],
            'u' => ['u', 'ù', 'ú', 'û', 'ü'],
            'y' => ['y', 'ÿ'],
        ];

        foreach ($map as $ascii => $variants) {
            $value = str_replace($variants, $ascii, $value);
        }

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-');
    }
}

function admin_demo_seed_photo_path(?string $reference): ?string
{
    $reference = trim((string)$reference);
    if ($reference === '') {
        return null;
    }

    $slug = slugify_ascii($reference);
    $relativePath = 'demo-seed/' . $slug . '-citizen-photo.png';
    $absolutePath = dirname(__DIR__, 2) . '/backend/uploads/' . $relativePath;

    // Fichier local disponible
    if (is_file($absolutePath)) {
        return $relativePath;
    }

    // Fallback : URL directe vers le VPS (vraies photos OpenAI)
    return 'https://admin.netetfix.com/uploads/demo-seed/' . $slug . '-citizen-photo.png';
}


function admin_incident_first_photo_select(PDO $db, string $incidentAlias = 'i', string $prefix = 'lead_photo'): string
{
    $incidentAlias = preg_replace('/[^a-zA-Z0-9_]/', '', $incidentAlias) ?: 'i';
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?: 'lead_photo';

    $select = [
        "(SELECT file_path FROM photos WHERE incident_id = {$incidentAlias}.id ORDER BY id ASC LIMIT 1) AS {$prefix}_file_path",
        "(SELECT file_name FROM photos WHERE incident_id = {$incidentAlias}.id ORDER BY id ASC LIMIT 1) AS {$prefix}_file_name",
    ];

    if (admin_photos_have_moderation_columns($db)) {
        $select[] = "(SELECT moderation_status FROM photos WHERE incident_id = {$incidentAlias}.id ORDER BY id ASC LIMIT 1) AS {$prefix}_moderation_status";
        $select[] = "(SELECT moderation_reason FROM photos WHERE incident_id = {$incidentAlias}.id ORDER BY id ASC LIMIT 1) AS {$prefix}_moderation_reason";
        $select[] = "(SELECT moderation_placeholder_key FROM photos WHERE incident_id = {$incidentAlias}.id ORDER BY id ASC LIMIT 1) AS {$prefix}_moderation_placeholder_key";
    } else {
        $select[] = "'visible' AS {$prefix}_moderation_status";
        $select[] = "NULL AS {$prefix}_moderation_reason";
        $select[] = "NULL AS {$prefix}_moderation_placeholder_key";
    }

    return implode(",\n           ", $select);
}

function admin_hydrate_public_photo(PDO $db, array $photo, string $defaultDirectory = 'incidents'): array
{
    $moderationService = admin_content_moderation_service($db);
    $photo['url'] = $moderationService->uploadUrl($photo['file_path'] ?? null, $defaultDirectory);
    return $moderationService->publicPhotoPayload($photo);
}

function admin_hydrate_incident_photo_rows(PDO $db, array $photos, string $defaultDirectory = 'incidents'): array
{
    $hydratedPhotos = [];

    foreach ($photos as $photo) {
        $photo = admin_hydrate_public_photo($db, $photo, $defaultDirectory);
        $url = trim((string)($photo['url'] ?? ''));
        if ($url === '') {
            continue;
        }

        $photo['url'] = $url;
        $hydratedPhotos[] = $photo;
    }

    return $hydratedPhotos;
}

function admin_incident_preview_photo(PDO $db, array $row, string $prefix = 'lead_photo', string $defaultDirectory = 'incidents'): ?array
{
    $filePath = trim((string)($row[$prefix . '_file_path'] ?? ''));
    if ($filePath === '') {
        $filePath = admin_demo_seed_photo_path($row['reference'] ?? null) ?? '';
    }
    if ($filePath === '') {
        return null;
    }

    return admin_hydrate_public_photo($db, [
        'file_path' => $filePath,
        'file_name' => $row[$prefix . '_file_name'] ?? null,
        'moderation_status' => $row[$prefix . '_moderation_status'] ?? 'visible',
        'moderation_reason' => $row[$prefix . '_moderation_reason'] ?? null,
        'moderation_placeholder_key' => $row[$prefix . '_moderation_placeholder_key'] ?? null,
    ], $defaultDirectory);
}

function admin_fetch_incident_preview_photos(PDO $db, array $incidentIds, string $defaultDirectory = 'incidents'): array
{
    $incidentIds = array_values(array_unique(array_filter(array_map('intval', $incidentIds), static fn(int $id): bool => $id > 0)));
    if ($incidentIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($incidentIds), '?'));
    $select = [
        'p.incident_id',
        'p.file_path',
        'p.file_name',
    ];

    if (admin_photos_have_moderation_columns($db)) {
        $select[] = 'p.moderation_status';
        $select[] = 'p.moderation_reason';
        $select[] = 'p.moderation_placeholder_key';
    } else {
        $select[] = "'visible' AS moderation_status";
        $select[] = "NULL AS moderation_reason";
        $select[] = "NULL AS moderation_placeholder_key";
    }

    $stmt = $db->prepare("
        SELECT " . implode(",\n               ", $select) . "
        FROM photos p
        INNER JOIN (
            SELECT incident_id, MIN(id) AS first_photo_id
            FROM photos
            WHERE incident_id IN ($placeholders)
            GROUP BY incident_id
        ) first_photo ON first_photo.first_photo_id = p.id
        ORDER BY p.incident_id ASC
    ");
    $stmt->execute($incidentIds);

    $photos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
        $photos[(int)$photo['incident_id']] = admin_hydrate_public_photo($db, $photo, $defaultDirectory);
    }

    return $photos;
}

function admin_fetch_recent_proof_incidents(PDO $db, array $options = []): array
{
    $limit = max(1, min(12, (int)($options['limit'] ?? 4)));
    $serviceIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($options['service_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));
    $onlyOpen = (bool)($options['only_open'] ?? false);

    $where = [];
    $params = [];
    $serviceJoinSql = '';
    $serviceNameSelect = 'NULL AS service_name';

    if ($onlyOpen) {
        $where[] = "i.status IN ('submitted', 'acknowledged', 'in_progress')";
    }

    if ($serviceIds !== []) {
        if (!admin_db_has_table($db, 'service_category_map') || !admin_db_has_table($db, 'services')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $serviceJoinSql = "
            JOIN service_category_map proof_scope_map ON proof_scope_map.category_id = c.id AND proof_scope_map.is_default = 1
            JOIN services proof_service ON proof_service.id = proof_scope_map.service_id
        ";
        $serviceNameSelect = 'proof_service.name AS service_name';
        $where[] = "proof_scope_map.service_id IN ($placeholders)";
        foreach ($serviceIds as $serviceId) {
            $params[] = $serviceId;
        }
    }

    $leadPhotoSelect = admin_incident_first_photo_select($db, 'i');
    $stmt = $db->prepare("
        SELECT
            i.id,
            i.reference,
            i.title,
            i.description,
            i.status,
            i.priority,
            i.created_at,
            c.name AS cat_name,
            c.color AS cat_color,
            c.icon AS cat_icon,
            u.full_name AS reporter,
            {$serviceNameSelect},
            {$leadPhotoSelect},
            (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
            (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count
        FROM incidents i
        JOIN categories c ON c.id = i.category_id
        JOIN users u ON u.id = i.user_id
        {$serviceJoinSql}
        " . (!empty($where) ? "WHERE " . implode(' AND ', $where) : '') . "
        ORDER BY i.created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);

    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($incidents as &$incident) {
        $incident['lead_photo'] = admin_incident_preview_photo($db, $incident);
        if ((int)($incident['photo_count'] ?? 0) === 0 && !empty($incident['lead_photo']['url'])) {
            $incident['photo_count'] = 1;
        }
    }
    unset($incident);

    return $incidents;
}
