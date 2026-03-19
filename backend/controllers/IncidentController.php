<?php
/**
 * Ma Commune v1.2 — IncidentController (TECH-01 + UX-01 + UX-02)
 *
 * GET    /api/incidents              → Liste paginée avec recherche et filtres avancés
 * POST   /api/incidents              → Créer un signalement
 * GET    /api/incidents/{id}         → Détail
 * PUT    /api/incidents/{id}         → Mettre à jour statut/priorité (agent/admin)
 * PATCH  /api/incidents/{id}         → Éditer description/photos (citoyen propriétaire, statut=submitted)
 * DELETE /api/incidents/{id}         → Supprimer (admin)
 */

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../config/PushNotificationService.php';
require_once __DIR__ . '/../config/InterventionWorkflow.php';

class IncidentController extends BaseController
{
    // ----------------------------------------------------------------
    // GET /api/incidents — Liste avec recherche et filtres avancés (UX-01)
    // ----------------------------------------------------------------
    public function index(): void
    {
        $auth = $this->getOptionalAuth();
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $this->getPagination();

        $where  = ['1=1'];
        $params = [];

        $scope = Security::sanitizeString($_GET['scope'] ?? '');
        if ($scope === 'mine') {
            if (!$auth) {
                $this->error("Authentification requise pour afficher vos signalements.", 401);
            }

            $isStaffScope = in_array($auth['role'] ?? '', ['agent', 'admin'], true);
            $where[]  = $isStaffScope ? 'i.assigned_to = ?' : 'i.user_id = ?';
            $params[] = (int)$auth['sub'];
        }

        $queue = Security::sanitizeString($_GET['queue'] ?? '');
        if ($queue === 'open') {
            $where[] = "i.status IN ('submitted', 'acknowledged', 'in_progress')";
        } elseif ($queue === 'closed') {
            $where[] = "i.status IN ('resolved', 'rejected')";
        }

        // Filtre statut
        if (!empty($_GET['status'])) {
            $where[]  = 'i.status = ?';
            $params[] = Security::sanitizeString($_GET['status']);
        }

        // Filtre catégorie
        if (!empty($_GET['category'])) {
            $id = Security::sanitizeId($_GET['category']);
            if ($id) {
                $where[]  = 'i.category_id = ?';
                $params[] = $id;
            }
        }

        // Filtre priorité
        if (!empty($_GET['priority'])) {
            $where[]  = 'i.priority = ?';
            $params[] = Security::sanitizeString($_GET['priority']);
        }

        // Filtre date (depuis)
        if (!empty($_GET['date_from'])) {
            $where[]  = 'DATE(i.created_at) >= ?';
            $params[] = Security::sanitizeString($_GET['date_from']);
        }

        // Filtre date (jusqu'à)
        if (!empty($_GET['date_to'])) {
            $where[]  = 'DATE(i.created_at) <= ?';
            $params[] = Security::sanitizeString($_GET['date_to']);
        }

        // Recherche textuelle (titre, description, référence)
        if (!empty($_GET['q'])) {
            $search   = '%' . Security::sanitizeString($_GET['q']) . '%';
            $where[]  = '(i.title LIKE ? OR i.description LIKE ? OR i.reference LIKE ?)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        // Tri
        $sortField = match(Security::sanitizeString($_GET['sort'] ?? '')) {
            'votes'      => 'i.votes_count',
            'updated_at' => 'i.updated_at',
            'priority'   => "FIELD(i.priority, 'critical', 'high', 'medium', 'low')",
            default      => 'i.created_at',
        };
        $sortDir = strtoupper(Security::sanitizeString($_GET['dir'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';

        $whereStr = implode(' AND ', $where);

        // Compter le total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM incidents i WHERE $whereStr");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Récupérer les incidents
        $stmt = $this->db->prepare("
            SELECT
                i.id, i.reference, i.title, i.description,
                i.latitude, i.longitude, i.address,
                i.status, i.priority, i.assigned_to, i.votes_count,
                i.created_at, i.updated_at,
                c.id   AS category_id,
                c.name AS category_name,
                c.icon AS category_icon,
                c.color AS category_color,
                c.service AS category_service,
                u.full_name AS reporter_name,
                assignee.full_name AS assigned_to_name,
                (SELECT file_path FROM photos WHERE incident_id = i.id ORDER BY id ASC LIMIT 1) AS thumbnail
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            JOIN users      u ON u.id = i.user_id
            LEFT JOIN users assignee ON assignee.id = i.assigned_to
            WHERE $whereStr
            ORDER BY $sortField $sortDir
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $incidents = $stmt->fetchAll();

        foreach ($incidents as &$inc) {
            if ($inc['thumbnail']) {
                $inc['thumbnail'] = UPLOAD_BASE_URL . $inc['thumbnail'];
            }
            $inc['votes_count'] = (int)$inc['votes_count'];
            $inc = $this->enrichIncidentWithInterventionContext($inc, []);
        }

        $this->success($this->paginatedResponse($incidents, $total, $page, $limit));
    }

    // ----------------------------------------------------------------
    // GET /api/incidents/{id} — Détail
    // ----------------------------------------------------------------
    public function show(int $id): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'incident:read');

        $stmt = $this->db->prepare("
            SELECT
                i.*,
                c.name  AS category_name,
                c.icon  AS category_icon,
                c.color AS category_color,
                c.service AS category_service,
                u.full_name AS reporter_name,
                u.email     AS reporter_email,
                assignee.full_name AS assigned_to_name
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            JOIN users      u ON u.id = i.user_id
            LEFT JOIN users assignee ON assignee.id = i.assigned_to
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $incident = $stmt->fetch();

        if (!$incident) {
            $this->error('Signalement introuvable.', 404);
        }

        // Photos
        $stmtP = $this->db->prepare(
            'SELECT id, file_path, file_name, mime_type, file_size, uploaded_at FROM photos WHERE incident_id = ? ORDER BY id ASC'
        );
        $stmtP->execute([$id]);
        $photos = $stmtP->fetchAll();
        foreach ($photos as &$p) {
            $p['url'] = UPLOAD_BASE_URL . $p['file_path'];
        }

        // Historique des statuts
        $stmtH = $this->db->prepare("
            SELECT sh.old_status, sh.new_status, sh.note, sh.changed_at, u.full_name AS changed_by
            FROM status_history sh
            JOIN users u ON u.id = sh.user_id
            WHERE sh.incident_id = ?
            ORDER BY sh.changed_at ASC
        ");
        $stmtH->execute([$id]);
        $statusHistory = $stmtH->fetchAll();

        $incident['photos']         = $photos;
        $incident['status_history'] = $statusHistory;
        $incident['votes_count']    = (int)$incident['votes_count'];

        $incident = $this->enrichIncidentWithInterventionContext($incident, $statusHistory);

        $this->success($incident);
    }

    // ----------------------------------------------------------------
    // POST /api/incidents — Créer un signalement
    // ----------------------------------------------------------------
    public function store(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'incident:create');

        $this->validate($_POST, [
            'category_id' => 'required|numeric',
            'description' => 'required|min:10|max:2000',
            'latitude'    => 'required|numeric',
            'longitude'   => 'required|numeric',
        ]);

        $categoryId  = Security::sanitizeId($_POST['category_id']);
        $description = Security::sanitizeString($_POST['description']);
        $latitude    = Security::sanitizeLatitude($_POST['latitude']);
        $longitude   = Security::sanitizeLongitude($_POST['longitude']);
        $title       = Security::sanitizeString($_POST['title'] ?? '');
        $address     = Security::sanitizeString($_POST['address'] ?? '');

        if (!$categoryId || !$latitude || !$longitude) {
            $this->error('Données géographiques ou catégorie invalides.', 422);
        }

        // Vérifier la catégorie
        $stmtC = $this->db->prepare('SELECT id FROM categories WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmtC->execute([$categoryId]);
        if (!$stmtC->fetch()) {
            $this->error('Catégorie invalide.', 422);
        }

        $stmt = $this->db->prepare("
            INSERT INTO incidents
                (user_id, category_id, title, description, latitude, longitude, address, reference)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'TEMP')
        ");
        $stmt->execute([
            $auth['sub'], $categoryId, $title, $description,
            $latitude, $longitude, $address,
        ]);
        $incidentId = (int)$this->db->lastInsertId();

        $reference = generate_reference($incidentId);
        $this->db->prepare('UPDATE incidents SET reference = ? WHERE id = ?')
                 ->execute([$reference, $incidentId]);

        $this->db->prepare(
            'INSERT INTO status_history (incident_id, user_id, old_status, new_status, note) VALUES (?, ?, NULL, ?, ?)'
        )->execute([$incidentId, $auth['sub'], 'submitted', 'Signalement créé par le citoyen.']);

        // Upload photo
        $uploadedPhotos = [];
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photoPath = $this->uploadPhoto($_FILES['photo'], $incidentId);
            if ($photoPath) {
                $this->db->prepare(
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

        $this->success([
            'id'        => $incidentId,
            'reference' => $reference,
            'status'    => 'submitted',
            'photos'    => $uploadedPhotos,
        ], 201, 'Signalement créé avec succès. Référence : ' . $reference);
    }

    // ----------------------------------------------------------------
    // PUT /api/incidents/{id} — Mettre à jour statut (agent/admin)
    // ----------------------------------------------------------------
    public function update(int $id): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'incident:update_status');

        $body = Security::getJsonBody();

        $validStatuses = ['acknowledged', 'in_progress', 'resolved', 'rejected'];
        $newStatus     = $body['status'] ?? null;

        if (!$newStatus || !in_array($newStatus, $validStatuses, true)) {
            $this->error('Statut invalide. Valeurs acceptées : ' . implode(', ', $validStatuses), 422);
        }

        $stmt = $this->db->prepare("
            SELECT i.id, i.status, i.category_id, c.service AS category_service
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $incident = $stmt->fetch();
        if (!$incident) {
            $this->error('Signalement introuvable.', 404);
        }

        $oldStatus  = $incident['status'];
        $note       = Security::sanitizeString($body['note'] ?? '');
        $priority   = !empty($body['priority']) ? Security::sanitizeString($body['priority']) : null;
        $assignedTo = !empty($body['assigned_to']) ? Security::sanitizeId($body['assigned_to']) : null;

        $sets   = ['status = ?', 'updated_at = NOW()'];
        $params = [$newStatus];

        if ($newStatus === 'resolved') {
            $sets[] = 'resolved_at = NOW()';
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
        $this->db->prepare('UPDATE incidents SET ' . implode(', ', $sets) . ' WHERE id = ?')
                 ->execute($params);

        $this->db->prepare(
            'INSERT INTO status_history (incident_id, user_id, old_status, new_status, note) VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $auth['sub'], $oldStatus, $newStatus, $note ?: null]);

        $context = intervention_get_incident_service_context(
            $this->db,
            $id,
            isset($incident['category_id']) ? (int)$incident['category_id'] : null,
            $incident['category_service'] ?? null
        );
        intervention_record_history($this->db, [
            'incident_id'    => $id,
            'service_id'     => $context['service_id'] ?? null,
            'actor_user_id'  => (int)$auth['sub'],
            'event_type'     => 'status_updated',
            'event_label'    => 'Statut dossier mis a jour',
            'citizen_label'  => $this->citizenLabelForStatus($newStatus, $context['service_name'] ?? null),
            'payload'        => [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'assigned_to' => $assignedTo,
            ],
        ]);

        (new PushNotificationService($this->db))->notifyStatusChange($id, $newStatus, $note ?: null);

        $this->success(['id' => $id, 'status' => $newStatus], 200, 'Signalement mis à jour.');
    }

    // ----------------------------------------------------------------
    // PATCH /api/incidents/{id} — Éditer son propre signalement (UX-02)
    // Autorisé uniquement si statut = 'submitted' et propriétaire
    // ----------------------------------------------------------------
    public function edit(int $id): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'incident:edit_own');

        $body = Security::getJsonBody();

        // Récupérer l'incident
        $stmt = $this->db->prepare('SELECT id, user_id, status FROM incidents WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $incident = $stmt->fetch();

        if (!$incident) {
            $this->error('Signalement introuvable.', 404);
        }

        // Vérifier la propriété
        if ((int)$incident['user_id'] !== (int)$auth['sub']) {
            $this->error('Vous ne pouvez modifier que vos propres signalements.', 403);
        }

        // Vérifier que le statut est encore "submitted"
        if ($incident['status'] !== 'submitted') {
            $this->error(
                "Ce signalement ne peut plus être modifié (statut : {$incident['status']}).",
                409
            );
        }

        $sets   = ['updated_at = NOW()'];
        $params = [];

        if (!empty($body['description'])) {
            $sets[]   = 'description = ?';
            $params[] = Security::sanitizeString($body['description']);
        }
        if (!empty($body['title'])) {
            $sets[]   = 'title = ?';
            $params[] = Security::sanitizeString($body['title']);
        }
        if (!empty($body['address'])) {
            $sets[]   = 'address = ?';
            $params[] = Security::sanitizeString($body['address']);
        }

        if (count($params) === 0) {
            $this->error('Aucun champ à mettre à jour.', 400);
        }

        $params[] = $id;
        $this->db->prepare('UPDATE incidents SET ' . implode(', ', $sets) . ' WHERE id = ?')
                 ->execute($params);

        $this->success(['id' => $id, 'updated' => true], 200, 'Signalement modifié.');
    }

    // ----------------------------------------------------------------
    // DELETE /api/incidents/{id} — Supprimer (admin)
    // ----------------------------------------------------------------
    public function destroy(int $id): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'incident:delete');

        $stmt = $this->db->prepare('SELECT id FROM incidents WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $this->error('Signalement introuvable.', 404);
        }

        $this->db->prepare('DELETE FROM incidents WHERE id = ?')->execute([$id]);

        $this->success(['deleted' => true], 200, 'Signalement supprimé.');
    }

    // ----------------------------------------------------------------
    // Utilitaire : Upload sécurisé d'une photo
    // ----------------------------------------------------------------
    private function uploadPhoto(array $file, int $incidentId): ?array
    {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, UPLOAD_ALLOWED, true)) return null;
        if ($file['size'] > UPLOAD_MAX_SIZE) return null;

        $subDir = UPLOAD_DIR . 'incidents/' . $incidentId . '/';
        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }

        $ext = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };
        $fileName = uniqid('photo_', true) . '.' . $ext;
        $destPath = $subDir . $fileName;
        $relPath  = 'incidents/' . $incidentId . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) return null;

        return [
            'path' => $relPath,
            'name' => $file['name'],
            'mime' => $mimeType,
            'size' => $file['size'],
        ];
    }

    private function enrichIncidentWithInterventionContext(array $incident, array $statusHistory): array
    {
        $serviceContext = intervention_get_incident_service_context(
            $this->db,
            (int)$incident['id'],
            isset($incident['category_id']) ? (int)$incident['category_id'] : null,
            $incident['category_service'] ?? null
        );
        $currentPlan = intervention_get_current_plan($this->db, (int)$incident['id']);
        $serviceHistory = intervention_get_history($this->db, (int)$incident['id'], false);
        $citizenHistory = intervention_get_history($this->db, (int)$incident['id'], true);

        if ($serviceContext) {
            $incident['service'] = [
                'id'     => $serviceContext['service_id'] ?? null,
                'code'   => $serviceContext['service_code'] ?? null,
                'name'   => $serviceContext['service_name'] ?? null,
                'source' => $serviceContext['source'] ?? null,
            ];
            $incident['service_id'] = $serviceContext['service_id'] ?? null;
            $incident['service_name'] = $serviceContext['service_name'] ?? null;
        } else {
            $incident['service'] = null;
            $incident['service_id'] = null;
            $incident['service_name'] = null;
        }

        if ($currentPlan) {
            $incident['current_plan'] = [
                'id'                => (int)$currentPlan['id'],
                'status'            => $currentPlan['status'],
                'service_id'        => !empty($currentPlan['service_id']) ? (int)$currentPlan['service_id'] : null,
                'service_code'      => $currentPlan['service_code'] ?? null,
                'service_name'      => $currentPlan['service_name'] ?? null,
                'planned_by_user_id'=> !empty($currentPlan['planned_by_user_id']) ? (int)$currentPlan['planned_by_user_id'] : null,
                'planned_by_name'   => $currentPlan['planned_by_name'] ?? null,
                'assigned_user_id'  => !empty($currentPlan['assigned_user_id']) ? (int)$currentPlan['assigned_user_id'] : null,
                'assigned_user_name'=> $currentPlan['assigned_user_name'] ?? null,
                'scheduled_date'    => $currentPlan['scheduled_date'] ?? null,
                'time_window_start' => $currentPlan['time_window_start'] ?? null,
                'time_window_end'   => $currentPlan['time_window_end'] ?? null,
                'internal_note'     => $currentPlan['internal_note'] ?? null,
                'citizen_message'   => $currentPlan['citizen_message'] ?? null,
                'source_type'       => $currentPlan['source_type'] ?? null,
                'provider_name'     => $currentPlan['provider_name'] ?? null,
                'created_at'        => $currentPlan['created_at'] ?? null,
                'updated_at'        => $currentPlan['updated_at'] ?? null,
            ];
        } else {
            $incident['current_plan'] = null;
        }

        $incident['service_history'] = array_map(function (array $entry): array {
            return [
                'id'            => (int)$entry['id'],
                'service_id'    => isset($entry['service_id']) ? (int)$entry['service_id'] : null,
                'service_code'  => $entry['service_code'] ?? null,
                'service_name'  => $entry['service_name'] ?? null,
                'plan_id'       => isset($entry['plan_id']) ? (int)$entry['plan_id'] : null,
                'actor_user_id' => isset($entry['actor_user_id']) ? (int)$entry['actor_user_id'] : null,
                'actor_name'    => $entry['actor_name'] ?? null,
                'event_type'    => $entry['event_type'] ?? null,
                'event_label'   => $entry['event_label'] ?? null,
                'citizen_label' => $entry['citizen_label'] ?? null,
                'payload_json'  => $entry['payload_json'] ?? null,
                'created_at'    => $entry['created_at'] ?? null,
            ];
        }, $serviceHistory);

        $incident['citizen_timeline'] = $this->buildCitizenTimeline(
            $statusHistory,
            $citizenHistory,
            $incident['service_name'] ?? null
        );

        return $incident;
    }

    private function buildCitizenTimeline(array $statusHistory, array $serviceHistory, ?string $serviceName): array
    {
        $timeline = [];

        foreach ($statusHistory as $entry) {
            $timeline[] = [
                'type'       => 'status',
                'label'      => $this->citizenLabelForStatus($entry['new_status'] ?? '', $serviceName),
                'detail'     => $entry['note'] ?? null,
                'created_at' => $entry['changed_at'] ?? null,
            ];
        }

        foreach ($serviceHistory as $entry) {
            if (!empty($entry['citizen_label'])) {
                $timeline[] = [
                    'type'       => 'service',
                    'label'      => $entry['citizen_label'],
                    'detail'     => $this->buildCitizenServiceTimelineDetail($entry),
                    'created_at' => $entry['created_at'] ?? null,
                ];
            }
        }

        usort($timeline, static function (array $a, array $b): int {
            return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
        });

        $latestServiceTimelineKeys = [];
        foreach ($timeline as $entry) {
            if (($entry['type'] ?? '') !== 'service') {
                continue;
            }

            $signature = trim((string)($entry['label'] ?? '')) . '|' . trim((string)($entry['detail'] ?? ''));
            $latestServiceTimelineKeys[$signature] = (string)($entry['created_at'] ?? '');
        }

        $deduped = [];
        foreach ($timeline as $entry) {
            if (($entry['type'] ?? '') === 'service') {
                $signature = trim((string)($entry['label'] ?? '')) . '|' . trim((string)($entry['detail'] ?? ''));
                $isLatestServiceOccurrence = ($latestServiceTimelineKeys[$signature] ?? null) === (string)($entry['created_at'] ?? '');
                if (!$isLatestServiceOccurrence) {
                    continue;
                }
            }

            $lastIndex = count($deduped) - 1;
            $lastEntry = $lastIndex >= 0 ? $deduped[$lastIndex] : null;

            if (
                $lastEntry
                && ($lastEntry['created_at'] ?? null) === ($entry['created_at'] ?? null)
                && trim((string)($lastEntry['label'] ?? '')) === trim((string)($entry['label'] ?? ''))
            ) {
                $preferredDetail = trim((string)($entry['detail'] ?? ''));
                if ($preferredDetail !== '') {
                    $deduped[$lastIndex]['detail'] = $preferredDetail;
                }
                if (($lastEntry['type'] ?? '') !== 'service' && ($entry['type'] ?? '') === 'service') {
                    $deduped[$lastIndex]['type'] = 'service';
                }
                continue;
            }

            $deduped[] = $entry;
        }

        return $deduped;
    }

    private function buildCitizenServiceTimelineDetail(array $entry): ?string
    {
        $parts = [];
        $payload = [];

        if (!empty($entry['payload_json'])) {
            $decoded = json_decode((string)$entry['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $scheduledDate = trim((string)($payload['scheduled_date'] ?? ''));
        if ($scheduledDate !== '') {
            $parts[] = $this->formatCitizenTimelineDate($scheduledDate);
        }

        $timeWindow = trim((string)($payload['time_window'] ?? ''));
        if ($timeWindow !== '') {
            $parts[] = $timeWindow;
        }

        $providerName = trim((string)($payload['provider_name'] ?? ''));
        $sourceType = trim((string)($payload['source_type'] ?? ''));
        if ($providerName !== '') {
            $parts[] = 'Prestataire ' . $providerName;
        } elseif ($sourceType === 'internal') {
            $parts[] = 'Equipe interne';
        }

        if (!empty($entry['service_name'])) {
            array_unshift($parts, 'Service ' . trim((string)$entry['service_name']));
        }

        $eventLabel = trim((string)($entry['event_label'] ?? ''));
        $citizenLabel = trim((string)($entry['citizen_label'] ?? ''));

        if (!empty($parts)) {
            return implode(' · ', $parts);
        }

        if ($eventLabel !== '' && $eventLabel !== $citizenLabel) {
            return $eventLabel;
        }

        return null;
    }

    private function formatCitizenTimelineDate(string $date): string
    {
        try {
            $dt = new DateTimeImmutable($date);
            return $dt->format('d/m/Y');
        } catch (Throwable $e) {
            return $date;
        }
    }

    private function citizenLabelForStatus(string $status, ?string $serviceName): string
    {
        return match ($status) {
            'submitted' => 'Signalement recu',
            'acknowledged' => $serviceName
                ? 'Attribue au service ' . $serviceName
                : 'Pris en charge par la commune',
            'in_progress' => 'Intervention en cours',
            'resolved' => 'Intervention terminee',
            'rejected' => 'Signalement classe avec justification',
            default => 'Mise a jour du dossier',
        };
    }
}
