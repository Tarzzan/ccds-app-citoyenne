<?php
/**
 * CCDS — API Incidents (Signalements)
 *
 * GET    /api/incidents          → Liste paginée des signalements (public)
 * POST   /api/incidents          → Créer un signalement + photo (authentifié)
 * GET    /api/incidents/{id}     → Détail d'un signalement (authentifié)
 * PUT    /api/incidents/{id}     → Mettre à jour le statut (agent/admin)
 */

function handle_incidents(string $method, ?int $id): void
{
    $db = Database::getInstance();

    switch ($method) {

        // ----------------------------------------------------------
        // GET /api/incidents  ou  GET /api/incidents/{id}
        // ----------------------------------------------------------
        case 'GET':
            if ($id) {
                get_incident_detail($db, $id);
            } else {
                get_incidents_list($db);
            }
            break;

        // ----------------------------------------------------------
        // POST /api/incidents  — Créer un signalement
        // ----------------------------------------------------------
        case 'POST':
            $auth = require_auth();
            create_incident($db, $auth);
            break;

        // ----------------------------------------------------------
        // PUT /api/incidents/{id}  — Mettre à jour le statut
        // ----------------------------------------------------------
        case 'PUT':
            if (!$id) json_error('ID du signalement manquant.', 400);
            $auth = require_auth();
            require_role($auth, ['agent', 'admin']);
            update_incident($db, $id, $auth);
            break;

        default:
            json_error('Méthode non autorisée.', 405);
    }
}

// --------------------------------------------------------------
// Lister les signalements avec filtres et pagination
// --------------------------------------------------------------
function get_incidents_list(PDO $db): void
{
    $page     = max(1, (int)($_GET['page']     ?? 1));
    $limit    = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset   = ($page - 1) * $limit;
    $status   = $_GET['status']   ?? null;
    $category = $_GET['category'] ?? null;

    $where  = ['1=1'];
    $params = [];

    if ($status) {
        $where[]  = 'i.status = ?';
        $params[] = $status;
    }
    if ($category) {
        $where[]  = 'i.category_id = ?';
        $params[] = (int)$category;
    }

    $whereStr = implode(' AND ', $where);

    // Compter le total pour la pagination
    $countStmt = $db->prepare("SELECT COUNT(*) FROM incidents i WHERE $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Récupérer les incidents avec les infos de catégorie
    $stmt = $db->prepare("
        SELECT
            i.id, i.reference, i.title, i.description,
            i.latitude, i.longitude, i.address,
            i.status, i.priority, i.created_at, i.updated_at,
            c.id   AS category_id,
            c.name AS category_name,
            c.icon AS category_icon,
            c.color AS category_color,
            u.full_name AS reporter_name,
            (SELECT file_path FROM photos WHERE incident_id = i.id ORDER BY id ASC LIMIT 1) AS thumbnail
        FROM incidents i
        JOIN categories c ON c.id = i.category_id
        JOIN users      u ON u.id = i.user_id
        WHERE $whereStr
        ORDER BY i.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $incidents = $stmt->fetchAll();

    // Formater les URLs des thumbnails
    foreach ($incidents as &$inc) {
        if ($inc['thumbnail']) {
            $inc['thumbnail'] = UPLOAD_BASE_URL . $inc['thumbnail'];
        }
    }

    json_success([
        'incidents'  => $incidents,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

// --------------------------------------------------------------
// Détail d'un signalement avec photos et historique
// --------------------------------------------------------------
function get_incident_detail(PDO $db, int $id): void
{
    require_auth(); // Authentification requise pour voir le détail

    $stmt = $db->prepare("
        SELECT
            i.*,
            c.name  AS category_name,
            c.icon  AS category_icon,
            c.color AS category_color,
            u.full_name AS reporter_name,
            u.email     AS reporter_email
        FROM incidents i
        JOIN categories c ON c.id = i.category_id
        JOIN users      u ON u.id = i.user_id
        WHERE i.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $incident = $stmt->fetch();

    if (!$incident) {
        json_error('Signalement introuvable.', 404);
    }

    // Photos associées
    $stmtP = $db->prepare(
        'SELECT id, file_path, file_name, mime_type, file_size, uploaded_at FROM photos WHERE incident_id = ? ORDER BY id ASC'
    );
    $stmtP->execute([$id]);
    $photos = $stmtP->fetchAll();
    foreach ($photos as &$p) {
        $p['url'] = UPLOAD_BASE_URL . $p['file_path'];
    }

    // Historique des statuts
    $stmtH = $db->prepare("
        SELECT sh.old_status, sh.new_status, sh.note, sh.changed_at, u.full_name AS changed_by
        FROM status_history sh
        JOIN users u ON u.id = sh.user_id
        WHERE sh.incident_id = ?
        ORDER BY sh.changed_at ASC
    ");
    $stmtH->execute([$id]);

    $incident['photos']         = $photos;
    $incident['status_history'] = $stmtH->fetchAll();

    json_success($incident);
}

// --------------------------------------------------------------
// Créer un nouveau signalement (multipart/form-data avec photo)
// --------------------------------------------------------------
function create_incident(PDO $db, array $auth): void
{
    // Validation des champs POST
    $errors = validate($_POST, [
        'category_id' => 'required|numeric',
        'description' => 'required|min:10|max:2000',
        'latitude'    => 'required|numeric',
        'longitude'   => 'required|numeric',
    ]);
    if (!empty($errors)) {
        json_error('Données invalides.', 422, $errors);
    }

    $categoryId  = (int)$_POST['category_id'];
    $description = trim($_POST['description']);
    $latitude    = (float)$_POST['latitude'];
    $longitude   = (float)$_POST['longitude'];
    $title       = trim($_POST['title'] ?? '');
    $address     = trim($_POST['address'] ?? '');

    // Vérifier que la catégorie existe
    $stmtC = $db->prepare('SELECT id FROM categories WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmtC->execute([$categoryId]);
    if (!$stmtC->fetch()) {
        json_error('Catégorie invalide.', 422);
    }

    // Insérer l'incident (référence générée après insertion)
    $stmt = $db->prepare("
        INSERT INTO incidents
            (user_id, category_id, title, description, latitude, longitude, address, reference)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'TEMP')
    ");
    $stmt->execute([
        $auth['sub'], $categoryId, $title, $description,
        $latitude, $longitude, $address,
    ]);
    $incidentId = (int)$db->lastInsertId();

    // Mettre à jour la référence lisible
    $reference = generate_reference($incidentId);
    $db->prepare('UPDATE incidents SET reference = ? WHERE id = ?')
       ->execute([$reference, $incidentId]);

    // Enregistrer le premier statut dans l'historique
    $db->prepare(
        'INSERT INTO status_history (incident_id, user_id, old_status, new_status, note) VALUES (?, ?, NULL, ?, ?)'
    )->execute([$incidentId, $auth['sub'], 'submitted', 'Signalement créé par le citoyen.']);

    // --- Gestion de l'upload de photo ---
    $uploadedPhotos = [];
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photoPath = upload_photo($_FILES['photo'], $incidentId);
        if ($photoPath) {
            $db->prepare(
                'INSERT INTO photos (incident_id, file_path, file_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $incidentId,
                $photoPath['path'],
                $photoPath['name'],
                $photoPath['mime'],
                $photoPath['size'],
            ]);
            $uploadedPhotos[] = UPLOAD_BASE_URL . $photoPath['path'];
        }
    }

    json_success([
        'id'        => $incidentId,
        'reference' => $reference,
        'status'    => 'submitted',
        'photos'    => $uploadedPhotos,
    ], 201, 'Signalement créé avec succès. Référence : ' . $reference);
}

// --------------------------------------------------------------
// Mettre à jour le statut d'un signalement (agents/admin)
// --------------------------------------------------------------
function update_incident(PDO $db, int $id, array $auth): void
{
    $body = get_json_body();

    $validStatuses = ['acknowledged', 'in_progress', 'resolved', 'rejected'];
    $newStatus     = $body['status'] ?? null;

    if (!$newStatus || !in_array($newStatus, $validStatuses, true)) {
        json_error('Statut invalide. Valeurs acceptées : ' . implode(', ', $validStatuses), 422);
    }

    // Récupérer l'incident actuel
    $stmt = $db->prepare('SELECT id, status FROM incidents WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $incident = $stmt->fetch();
    if (!$incident) {
        json_error('Signalement introuvable.', 404);
    }

    $oldStatus   = $incident['status'];
    $note        = trim($body['note'] ?? '');
    $priority    = $body['priority'] ?? null;
    $assignedTo  = isset($body['assigned_to']) ? (int)$body['assigned_to'] : null;

    // Construire la requête de mise à jour dynamiquement
    $sets   = ['status = ?', 'updated_at = NOW()'];
    $params = [$newStatus];

    if ($newStatus === 'resolved') {
        $sets[]   = 'resolved_at = NOW()';
    }
    if ($priority) {
        $sets[]   = 'priority = ?';
        $params[] = $priority;
    }
    if ($assignedTo) {
        $sets[]   = 'assigned_to = ?';
        $params[] = $assignedTo;
    }

    $params[] = $id;
    $db->prepare('UPDATE incidents SET ' . implode(', ', $sets) . ' WHERE id = ?')
       ->execute($params);

    // Enregistrer dans l'historique
    $db->prepare(
        'INSERT INTO status_history (incident_id, user_id, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)'
    )->execute([$id, $auth['sub'], $oldStatus, $newStatus, $note ?: null]);

    json_success(['id' => $id, 'status' => $newStatus], 200, 'Signalement mis à jour.');
}

// --------------------------------------------------------------
// Fonction utilitaire : Upload sécurisé d'une photo
// --------------------------------------------------------------
function upload_photo(array $file, int $incidentId): ?array
{
    // Vérification du type MIME réel (pas seulement l'extension)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, UPLOAD_ALLOWED, true)) {
        return null; // Type non autorisé
    }

    if ($file['size'] > UPLOAD_MAX_SIZE) {
        return null; // Fichier trop lourd
    }

    // Créer un sous-dossier par incident pour l'organisation
    $subDir = UPLOAD_DIR . 'incidents/' . $incidentId . '/';
    if (!is_dir($subDir)) {
        mkdir($subDir, 0755, true);
    }

    // Nom de fichier sécurisé et unique
    $ext      = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };
    $fileName = uniqid('photo_', true) . '.' . $ext;
    $destPath = $subDir . $fileName;
    $relPath  = 'incidents/' . $incidentId . '/' . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return [
        'path' => $relPath,
        'name' => $file['name'],
        'mime' => $mimeType,
        'size' => $file['size'],
    ];
}
