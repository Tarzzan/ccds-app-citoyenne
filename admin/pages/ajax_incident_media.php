<?php
/**
 * AJAX Endpoint : média et commentaires publics d'un signalement.
 */

require_admin_auth();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'invalid_id']);
    exit;
}

$db = Database::getInstance();

$photoSelect = [
    'id',
    'file_path',
    'file_name',
    'uploaded_at AS created_at',
];

if (admin_photos_have_moderation_columns($db)) {
    $photoSelect[] = 'moderation_status';
    $photoSelect[] = 'moderation_reason';
    $photoSelect[] = 'moderation_placeholder_key';
} else {
    $photoSelect[] = "'visible' AS moderation_status";
    $photoSelect[] = "NULL AS moderation_reason";
    $photoSelect[] = "NULL AS moderation_placeholder_key";
}

$stmtPhotos = $db->prepare("
    SELECT " . implode(",\n           ", $photoSelect) . "
    FROM photos
    WHERE incident_id = ?
    ORDER BY id ASC
");
$stmtPhotos->execute([$id]);

$photos = [];
foreach ($stmtPhotos->fetchAll(PDO::FETCH_ASSOC) as $photo) {
    $hydrated = admin_hydrate_public_photo($db, $photo);
    if (!empty($hydrated['url'])) {
        $photos[] = $hydrated;
    }
}

$stmtComments = $db->prepare("
    SELECT c.id, c.comment, c.created_at, COALESCE(u.full_name, 'Citoyen(ne) engage(e)') AS author
    FROM comments c
    LEFT JOIN users u ON u.id = c.user_id
    WHERE c.incident_id = ? AND c.is_internal = 0
    ORDER BY c.created_at ASC
");
$stmtComments->execute([$id]);
$comments = array_map(static function (array $comment): array {
    return [
        'id' => (int)$comment['id'],
        'content' => (string)($comment['comment'] ?? ''),
        'created_at' => (string)($comment['created_at'] ?? ''),
        'author' => (string)($comment['author'] ?? 'Citoyen(ne) engage(e)'),
    ];
}, $stmtComments->fetchAll(PDO::FETCH_ASSOC));

$stmtMeta = $db->prepare("
    SELECT i.reference, i.title, i.description, i.created_at, u.full_name AS reporter_name
    FROM incidents i
    LEFT JOIN users u ON u.id = i.user_id
    WHERE i.id = ?
");
$stmtMeta->execute([$id]);
$meta = $stmtMeta->fetch(PDO::FETCH_ASSOC) ?: [
    'reference' => null,
    'title' => 'Dossier introuvable',
    'description' => '',
    'created_at' => null,
    'reporter_name' => 'Inconnu',
];

if ($photos === []) {
    $seedPath = admin_demo_seed_photo_path($meta['reference'] ?? null);
    if ($seedPath !== null) {
        $photos[] = admin_hydrate_public_photo($db, [
            'file_path' => $seedPath,
            'file_name' => basename($seedPath),
            'created_at' => $meta['created_at'] ?? null,
            'moderation_status' => 'visible',
            'moderation_reason' => null,
            'moderation_placeholder_key' => null,
        ], '');
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'photos' => $photos,
    'comments' => $comments,
    'meta' => $meta,
]);
exit;
