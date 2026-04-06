<?php
/**
 * Ma Commune Back-Office — Détail et traitement d'un signalement
 * v1.1 : affichage des votes + envoi de notification push aux citoyens
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../backend/config/PushNotificationService.php';
$admin = require_admin_auth();
$themePalette = visual_admin_data_palette();

$id = (int)($_GET['id'] ?? 0);
if (!$id) render_error(400, 'Identifiant de signalement manquant.');

$db = Database::getInstance();

// Charger le signalement
$stmt = $db->prepare("
    SELECT i.*, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS reporter_name, u.email AS reporter_email, u.phone AS reporter_phone,
           COALESCE(i.votes_count, 0) AS votes_count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$inc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inc) render_error(404, 'Signalement introuvable.');

// Charger les photos
$photos_stmt = $db->prepare("SELECT * FROM photos WHERE incident_id = ? ORDER BY id");
$photos_stmt->execute([$id]);
$photos = $photos_stmt->fetchAll(PDO::FETCH_ASSOC);
$photos = admin_hydrate_incident_photo_rows($db, $photos);
if ($photos === []) {
    $fallbackPhotoPath = admin_demo_seed_photo_path($inc['reference'] ?? null);
    if ($fallbackPhotoPath !== null) {
        $photos = [admin_hydrate_public_photo($db, [
            'file_path' => $fallbackPhotoPath,
            'file_name' => basename($fallbackPhotoPath),
            'moderation_status' => 'visible',
            'moderation_reason' => null,
            'moderation_placeholder_key' => null,
        ])];
    }
}

// Charger l'historique des statuts
$history_stmt = $db->prepare("
    SELECT sh.*, u.full_name AS changed_by_name
    FROM status_history sh
    JOIN users u ON u.id = sh.user_id
    WHERE sh.incident_id = ?
    ORDER BY sh.changed_at DESC
");
$history_stmt->execute([$id]);
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Charger les commentaires (publics + internes)
$comments_stmt = $db->prepare("
    SELECT cm.*, u.full_name AS author_name, u.role AS author_role
    FROM comments cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.incident_id = ?
    ORDER BY cm.created_at ASC
");
$comments_stmt->execute([$id]);
$comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);

$service_tables_ready = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'intervention_plans')
    && admin_db_has_table($db, 'incident_service_history');

$services = $service_tables_ready ? intervention_get_services($db) : [];
$staff_stmt = $db->query("
    SELECT id, full_name, role
    FROM users
    WHERE role IN ('agent', 'admin') AND is_active = 1
    ORDER BY full_name ASC
");
$staff_users = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);
$service_context = intervention_get_incident_service_context(
    $db,
    $id,
    isset($inc['category_id']) ? (int)$inc['category_id'] : null,
    $inc['service'] ?? null
);

if (admin_is_service_scoped_agent($admin)) {
    $incidentServiceId = !empty($service_context['service_id']) ? (int)$service_context['service_id'] : null;
    if (!$incidentServiceId || !admin_has_service_access($admin, $incidentServiceId)) {
        render_error(403, 'Ce dossier ne fait pas partie de votre perimetre de service.');
    }
}

if (($_GET['export'] ?? '') === 'pdf') {
    $composerAutoload = __DIR__ . '/../../backend/vendor/autoload.php';
    if (file_exists($composerAutoload)) {
        require_once $composerAutoload;
    } else {
        $fpdfPath = __DIR__ . '/../../backend/vendor/fpdf/fpdf.php';
        if (!file_exists($fpdfPath)) {
            render_error(500, 'FPDF non disponible sur cet environnement.');
        }
        require_once $fpdfPath;
    }

    require_once __DIR__ . '/../../backend/config/PdfReportService.php';
    (new PdfReportService($db))->generate($id);
    exit;
}

$current_plan = $service_tables_ready ? intervention_get_current_plan($db, $id) : null;
$service_history = $service_tables_ready ? intervention_get_history($db, $id, false) : [];

function incident_plan_status_pill(string $status): array
{
    return match ($status) {
        'scheduled' => ['label' => 'Prevue', 'class' => 'badge-green'],
        'rescheduled' => ['label' => 'Replanifiee', 'class' => 'badge-blue'],
        'in_progress' => ['label' => 'En intervention', 'class' => 'badge-blue'],
        'completed' => ['label' => 'Terminee', 'class' => 'badge-green'],
        'cancelled' => ['label' => 'Annulee', 'class' => 'badge-gray'],
        'draft' => ['label' => 'Brouillon', 'class' => 'badge-yellow'],
        default => ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'badge-gray'],
    };
}

function incident_plan_normalize_string(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function incident_plan_normalize_time(?string $value): ?string
{
    $value = incident_plan_normalize_string($value);
    if ($value === null) {
        return null;
    }

    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
        return substr($value, 0, 5);
    }

    return $value;
}

function incident_plan_matches_payload(?array $existingPlan, array $payload): bool
{
    if (!$existingPlan) {
        return false;
    }

    return (int)($existingPlan['service_id'] ?? 0) === (int)($payload['service_id'] ?? 0)
        && (int)($existingPlan['assigned_user_id'] ?? 0) === (int)($payload['assigned_user_id'] ?? 0)
        && incident_plan_normalize_string($existingPlan['scheduled_date'] ?? null) === incident_plan_normalize_string($payload['scheduled_date'] ?? null)
        && incident_plan_normalize_time($existingPlan['time_window_start'] ?? null) === incident_plan_normalize_time($payload['time_window_start'] ?? null)
        && incident_plan_normalize_time($existingPlan['time_window_end'] ?? null) === incident_plan_normalize_time($payload['time_window_end'] ?? null)
        && incident_plan_normalize_string($existingPlan['internal_note'] ?? null) === incident_plan_normalize_string($payload['internal_note'] ?? null)
        && incident_plan_normalize_string($existingPlan['citizen_message'] ?? null) === incident_plan_normalize_string($payload['citizen_message'] ?? null)
        && incident_plan_normalize_string($existingPlan['source_type'] ?? 'internal') === incident_plan_normalize_string($payload['source_type'] ?? 'internal')
        && incident_plan_normalize_string($existingPlan['provider_name'] ?? null) === incident_plan_normalize_string($payload['provider_name'] ?? null);
}

// Charger les votants (v1.1) — si la table existe
$voters = [];
try {
    $voters_stmt = $db->prepare("
        SELECT u.full_name, u.email, v.created_at
        FROM votes v
        JOIN users u ON u.id = v.user_id
        WHERE v.incident_id = ?
        ORDER BY v.created_at DESC
        LIMIT 20
    ");
    $voters_stmt->execute([$id]);
    $voters = $voters_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table votes pas encore créée — ignorer
}

$status_flow = ['submitted', 'acknowledged', 'in_progress', 'resolved'];
$status_index = array_search($inc['status'], $status_flow, true);
$status_index = $status_index === false ? -1 : $status_index;
$internal_comments_count = count(array_filter($comments, static fn(array $comment): bool => !empty($comment['is_internal'])));
$public_comments_count = max(0, count($comments) - $internal_comments_count);
$moderated_photos_count = count(array_filter($photos, static fn(array $photo): bool => !empty($photo['moderation_message'])));
$history_notes_count = count(array_filter($history, static fn(array $entry): bool => !empty($entry['note'])));
$service_history_public_count = count(array_filter($service_history, static fn(array $entry): bool => !empty($entry['citizen_label'])));
$incidentCategoryVisual = category_visual_resolve($inc['cat_icon'] ?? 'road', $inc['cat_name'] ?? null);
$incidentCategorySceneUrl = category_scene_visual_url($inc['cat_icon'] ?? 'road', $inc['cat_name'] ?? null);
$incidentVisualAsset = $inc['status'] === 'resolved' ? 'MOM-04' : 'MOM-03';
$incidentVisualUrl = generated_visual_url($incidentVisualAsset);
$incidentGuideAsset = in_array($inc['status'], ['submitted', 'acknowledged'], true) ? 'CHAR-05' : 'CHAR-04';
$incidentGuideUrl = generated_visual_url($incidentGuideAsset);
$next_step_label = match ($inc['status']) {
    'submitted' => 'Confirmer la prise en charge',
    'acknowledged' => 'Passer en intervention terrain',
    'in_progress' => 'Valider l execution',
    'resolved' => 'Verifier puis archiver',
    'rejected' => 'Documenter le motif de classement',
    default => 'Relire et qualifier',
};

// --- Traitement des formulaires POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Changement de statut
    if ($action === 'change_status') {
        $new_status = $_POST['new_status'] ?? '';
        $note       = trim($_POST['note'] ?? '');
        $priority   = $_POST['priority'] ?? $inc['priority'];
        $send_notif = isset($_POST['send_notification']) ? 1 : 0;
        $valid_statuses = ['submitted','acknowledged','in_progress','resolved','rejected'];

        if (!in_array($new_status, $valid_statuses)) {
            $_SESSION['flash_error'] = 'Statut invalide.';
        } else {
            $updateFields = ['status = ?', 'priority = ?', 'updated_at = NOW()'];
            $updateParams = [$new_status, $priority];
            if ($new_status === 'resolved') {
                $updateFields[] = 'resolved_at = NOW()';
            }
            $updateParams[] = $id;

            $db->prepare('UPDATE incidents SET ' . implode(', ', $updateFields) . ' WHERE id = ?')
               ->execute($updateParams);
            $db->prepare("INSERT INTO status_history (incident_id, old_status, new_status, user_id, note, changed_at)
                          VALUES (?, ?, ?, ?, ?, NOW())")
               ->execute([$id, $inc['status'], $new_status, $admin['id'], $note ?: null]);

            // Envoyer une notification push si demandé (v1.1)
            if ($send_notif) {
                try {
                    (new PushNotificationService($db))->notifyStatusChange($id, $new_status, $note ?: null);
                    $_SESSION['flash_success'] = 'Statut mis à jour et notification envoyée.';
                } catch (Exception $e) {
                    $_SESSION['flash_success'] = 'Statut mis à jour (notification non envoyée : ' . $e->getMessage() . ').';
                }
            } else {
                $_SESSION['flash_success'] = 'Statut mis à jour avec succès.';
            }
        }
        header("Location: /admin/?page=incident_detail&id=$id");
        exit;
    }

    // Ajout de commentaire (interne ou public)
    if ($action === 'add_comment') {
        $comment     = trim($_POST['comment'] ?? '');
        $is_internal = isset($_POST['is_internal']) ? 1 : 0;
        if (strlen($comment) < 2) {
            $_SESSION['flash_error'] = 'Le commentaire est trop court.';
        } else {
            $db->prepare("INSERT INTO comments (incident_id, user_id, comment, is_internal, created_at)
                          VALUES (?, ?, ?, ?, NOW())")
               ->execute([$id, $admin['id'], $comment, $is_internal]);

            // Notifier le citoyen d'un nouveau commentaire public (v1.1)
            if (!$is_internal) {
                try {
                    (new PushNotificationService($db))->notifyNewComment($id, $admin['full_name']);
                } catch (PDOException $e) { /* Table pas encore créée */ }
            }

            $_SESSION['flash_success'] = 'Commentaire ajouté.';
        }
        header("Location: /admin/?page=incident_detail&id=$id");
        exit;
    }

    // Planification d'intervention
    if ($action === 'plan_intervention') {
        if (!$service_tables_ready) {
            $_SESSION['flash_error'] = "Le socle services/interventions n'est pas encore disponible sur cet environnement.";
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        $service_id = (int)($_POST['service_id'] ?? 0);
        $assigned_user_id = (int)($_POST['assigned_user_id'] ?? 0);
        $scheduled_date = trim($_POST['scheduled_date'] ?? '');
        $time_window_start = trim($_POST['time_window_start'] ?? '');
        $time_window_end = trim($_POST['time_window_end'] ?? '');
        $internal_note = trim($_POST['internal_note'] ?? '');
        $citizen_message = trim($_POST['citizen_message'] ?? '');
        $source_type = ($_POST['source_type'] ?? 'internal') === 'provider' ? 'provider' : 'internal';
        $provider_name = trim($_POST['provider_name'] ?? '');

        if ($service_id <= 0) {
            $_SESSION['flash_error'] = 'Choisissez un service responsable.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        if (admin_is_service_scoped_agent($admin) && !admin_has_service_access($admin, $service_id)) {
            $_SESSION['flash_error'] = 'Vous ne pouvez planifier que dans votre perimetre de service.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        if ($scheduled_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduled_date)) {
            $_SESSION['flash_error'] = 'Le format de la date planifiee est invalide.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }
        $scheduled_date_val = $scheduled_date !== '' ? $scheduled_date : null;

        if ($source_type === 'provider' && $provider_name === '') {
            $_SESSION['flash_error'] = 'Indiquez le nom du prestataire missionne.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        try {
            $db->beginTransaction();

            $service_stmt = $db->prepare('SELECT id, name FROM services WHERE id = ? AND is_active = 1 LIMIT 1');
            $service_stmt->execute([$service_id]);
            $service = $service_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$service) {
                throw new RuntimeException('Service introuvable.');
            }

            if ($assigned_user_id > 0) {
                $assignedMemberships = intervention_get_user_memberships($db, $assigned_user_id);
                $assignedInService = false;
                foreach ($assignedMemberships as $membership) {
                    if ((int)$membership['service_id'] === $service_id) {
                        $assignedInService = true;
                        break;
                    }
                }

                if (!$assignedInService) {
                    throw new RuntimeException('L agent assigne doit etre rattache au service selectionne.');
                }
            }

            $existing_plan = intervention_get_current_plan($db, $id);
            if (
                $existing_plan
                && in_array((string)($existing_plan['status'] ?? ''), ['draft', 'scheduled', 'rescheduled', 'in_progress'], true)
                && incident_plan_matches_payload($existing_plan, [
                    'service_id' => $service_id,
                    'assigned_user_id' => $assigned_user_id > 0 ? $assigned_user_id : null,
                    'scheduled_date' => $scheduled_date_val,
                    'time_window_start' => $time_window_start !== '' ? $time_window_start : null,
                    'time_window_end' => $time_window_end !== '' ? $time_window_end : null,
                    'internal_note' => $internal_note !== '' ? $internal_note : null,
                    'citizen_message' => $citizen_message !== '' ? $citizen_message : null,
                    'source_type' => $source_type,
                    'provider_name' => $provider_name !== '' ? $provider_name : null,
                ])
            ) {
                $db->rollBack();
                $_SESSION['flash_success'] = 'Aucune modification detectee sur la planification courante.';
                header("Location: /admin/?page=incident_detail&id=$id");
                exit;
            }

            $is_reschedule = !empty($existing_plan)
                && in_array($existing_plan['status'], ['draft', 'scheduled', 'rescheduled', 'in_progress'], true);

            if ($is_reschedule) {
                $db->prepare('UPDATE intervention_plans SET status = ?, updated_at = NOW() WHERE id = ?')
                   ->execute(['rescheduled', (int)$existing_plan['id']]);
            }

            $db->prepare("
                INSERT INTO intervention_plans
                    (incident_id, service_id, planned_by_user_id, assigned_user_id, status, scheduled_date,
                     time_window_start, time_window_end, internal_note, citizen_message, source_type, provider_name, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $id,
                $service_id,
                $admin['id'],
                $assigned_user_id > 0 ? $assigned_user_id : null,
                $is_reschedule ? 'rescheduled' : 'scheduled',
                $scheduled_date_val,
                $time_window_start !== '' ? $time_window_start : null,
                $time_window_end !== '' ? $time_window_end : null,
                $internal_note !== '' ? $internal_note : null,
                $citizen_message !== '' ? $citizen_message : null,
                $source_type,
                $provider_name !== '' ? $provider_name : null,
            ]);
            $plan_id = (int)$db->lastInsertId();

            $incident_sets = ['updated_at = NOW()'];
            $incident_params = [];
            if ($assigned_user_id > 0) {
                $incident_sets[] = 'assigned_to = ?';
                $incident_params[] = $assigned_user_id;
            }
            $status_transition_note = null;
            if ($inc['status'] === 'submitted') {
                $incident_sets[] = 'status = ?';
                $incident_params[] = 'acknowledged';
                $status_transition_note = 'Dossier pris en charge et intervention planifiee.';
            } elseif (in_array((string)$inc['status'], ['resolved', 'rejected'], true)) {
                $incident_sets[] = 'status = ?';
                $incident_params[] = 'acknowledged';
                $status_transition_note = 'Dossier reouvert suite a une nouvelle intervention planifiee.';
            }
            $incident_params[] = $id;
            $db->prepare('UPDATE incidents SET ' . implode(', ', $incident_sets) . ' WHERE id = ?')
               ->execute($incident_params);

            if ($status_transition_note !== null) {
                $db->prepare("
                    INSERT INTO status_history (incident_id, old_status, new_status, user_id, note, changed_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $id,
                    $inc['status'],
                    'acknowledged',
                    $admin['id'],
                    $status_transition_note,
                ]);
            }

            $window_label = trim(implode(' - ', array_filter([$time_window_start, $time_window_end])));
            $citizen_label = $is_reschedule
                ? 'Intervention reprogrammee'
                : 'Intervention planifiee';
            intervention_record_history($db, [
                'incident_id'   => $id,
                'service_id'    => $service_id,
                'plan_id'       => $plan_id,
                'actor_user_id' => $admin['id'],
                'event_type'    => $is_reschedule ? 'plan_rescheduled' : 'plan_created',
                'event_label'   => $is_reschedule
                    ? 'Planification mise a jour pour le service ' . $service['name']
                    : 'Intervention planifiee pour le service ' . $service['name'],
                'citizen_label' => $citizen_label,
                'payload'       => [
                    'scheduled_date' => $scheduled_date_val,
                    'time_window'    => $window_label !== '' ? $window_label : null,
                    'source_type'    => $source_type,
                    'provider_name'  => $provider_name !== '' ? $provider_name : null,
                ],
            ]);

            $db->commit();

            try {
                (new PushNotificationService($db))->notifyInterventionPlanned($id, [
                    'service_name' => $service['name'],
                    'scheduled_date' => $scheduled_date_val,
                    'time_window_start' => $time_window_start !== '' ? $time_window_start : null,
                    'time_window_end' => $time_window_end !== '' ? $time_window_end : null,
                    'citizen_message' => $citizen_message !== '' ? $citizen_message : null,
                    'provider_name' => $provider_name !== '' ? $provider_name : null,
                ], $is_reschedule);
            } catch (Throwable $notificationError) {
                error_log('Plan notification failed for incident ' . $id . ': ' . $notificationError->getMessage());
            }

            $_SESSION['flash_success'] = $is_reschedule
                ? 'Intervention replanifiee avec succes.'
                : 'Intervention planifiee avec succes.';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['flash_error'] = 'Echec de la planification : ' . $e->getMessage();
        }

        header("Location: /admin/?page=incident_detail&id=$id");
        exit;
    }

    if ($action === 'update_plan_status') {
        if (!$service_tables_ready) {
            $_SESSION['flash_error'] = "Le socle services/interventions n'est pas encore disponible sur cet environnement.";
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        $target_status = $_POST['target_status'] ?? '';
        $valid_targets = ['in_progress', 'completed', 'cancelled'];

        if (!in_array($target_status, $valid_targets, true)) {
            $_SESSION['flash_error'] = 'Transition de plan invalide.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        $current_plan = intervention_get_current_plan($db, $id);
        if (!$current_plan || empty($current_plan['id'])) {
            $_SESSION['flash_error'] = 'Aucune intervention en cours a mettre a jour.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        $plan_service_id = !empty($current_plan['service_id']) ? (int)$current_plan['service_id'] : null;
        if (admin_is_service_scoped_agent($admin) && (!$plan_service_id || !admin_has_service_access($admin, $plan_service_id))) {
            $_SESSION['flash_error'] = 'Vous ne pouvez mettre a jour que les interventions de votre perimetre.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        $allowed_transitions = [
            'scheduled' => ['in_progress', 'completed', 'cancelled'],
            'rescheduled' => ['in_progress', 'completed', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'draft' => ['cancelled'],
        ];
        $current_status = (string)($current_plan['status'] ?? '');
        if (!in_array($target_status, $allowed_transitions[$current_status] ?? [], true)) {
            $_SESSION['flash_error'] = 'Cette transition n est pas autorisee depuis l etat actuel.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

        try {
            $db->beginTransaction();

            $db->prepare('UPDATE intervention_plans SET status = ?, updated_at = NOW() WHERE id = ?')
               ->execute([$target_status, (int)$current_plan['id']]);

            $historyLabel = match ($target_status) {
                'in_progress' => 'Intervention demarree pour le service ' . ($current_plan['service_name'] ?? 'attribue'),
                'completed' => 'Intervention marquee comme terminee pour le service ' . ($current_plan['service_name'] ?? 'attribue'),
                'cancelled' => 'Intervention annulee pour le service ' . ($current_plan['service_name'] ?? 'attribue'),
                default => 'Intervention mise a jour',
            };
            $citizenLabel = match ($target_status) {
                'in_progress' => 'Intervention en cours',
                'completed' => 'Intervention terminee',
                'cancelled' => 'Intervention annulee',
                default => null,
            };

            intervention_record_history($db, [
                'incident_id'   => $id,
                'service_id'    => $plan_service_id,
                'plan_id'       => (int)$current_plan['id'],
                'actor_user_id' => $admin['id'],
                'event_type'    => 'plan_status_changed',
                'event_label'   => $historyLabel,
                'citizen_label' => $citizenLabel,
                'payload'       => [
                    'previous_status' => $current_status,
                    'new_status' => $target_status,
                    'scheduled_date' => $current_plan['scheduled_date'] ?? null,
                    'time_window' => trim(implode(' - ', array_filter([
                        $current_plan['time_window_start'] ?? null,
                        $current_plan['time_window_end'] ?? null,
                    ]))) ?: null,
                    'provider_name' => $current_plan['provider_name'] ?? null,
                ],
            ]);

            if ($target_status === 'in_progress' && $inc['status'] !== 'in_progress') {
                $db->prepare('UPDATE incidents SET status = ?, updated_at = NOW() WHERE id = ?')
                   ->execute(['in_progress', $id]);
                $db->prepare("
                    INSERT INTO status_history (incident_id, old_status, new_status, user_id, note, changed_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $id,
                    $inc['status'],
                    'in_progress',
                    $admin['id'],
                    'Intervention demarree sur le terrain.',
                ]);
            }

            if ($target_status === 'completed' && $inc['status'] !== 'resolved') {
                $db->prepare('UPDATE incidents SET status = ?, resolved_at = NOW(), updated_at = NOW() WHERE id = ?')
                   ->execute(['resolved', $id]);
                $db->prepare("
                    INSERT INTO status_history (incident_id, old_status, new_status, user_id, note, changed_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $id,
                    $inc['status'],
                    'resolved',
                    $admin['id'],
                    'Intervention terminee et dossier resolu.',
                ]);
            }

            $db->commit();

            try {
                (new PushNotificationService($db))->notifyInterventionUpdated($id, [
                    'service_name' => $current_plan['service_name'] ?? null,
                    'scheduled_date' => $current_plan['scheduled_date'] ?? null,
                    'time_window_start' => $current_plan['time_window_start'] ?? null,
                    'time_window_end' => $current_plan['time_window_end'] ?? null,
                    'citizen_message' => $current_plan['citizen_message'] ?? null,
                    'provider_name' => $current_plan['provider_name'] ?? null,
                ], $target_status);
            } catch (Throwable $notificationError) {
                error_log('Intervention state notification failed for incident ' . $id . ': ' . $notificationError->getMessage());
            }

            $_SESSION['flash_success'] = match ($target_status) {
                'in_progress' => 'Intervention passee en cours.',
                'completed' => 'Intervention marquee comme terminee.',
                'cancelled' => 'Intervention annulee.',
                default => 'Intervention mise a jour.',
            };
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['flash_error'] = 'Echec de la mise a jour de l intervention : ' . $e->getMessage();
        }

        header("Location: /admin/?page=incident_detail&id=$id");
        exit;
    }
}

$page_title = 'Signalement ' . e($inc['reference']);
$active_nav = 'incidents';
require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="incident-detail-admin">

<div class="dashboard-split-grid" style="margin-bottom: 20px; align-items: start;">
  <div style="display:flex; flex-direction:column;">
    <div class="page-hero <?= $incidentVisualUrl ? 'page-hero--with-visual' : '' ?>" style="margin-bottom:0; display:flex; flex-direction:row; align-items:stretch; gap:20px; flex-wrap:wrap;">
      
      <!-- Colonne Gauche : Textes & Badges -->
      <div style="flex: 1 1 350px; display:flex; flex-direction:column; justify-content:flex-start; gap:18px;">
        <div class="page-hero-copy" style="grid-column:unset; grid-row:unset;">
          <div class="page-hero-kicker">Dossier terrain</div>
          <h2 class="page-hero-title"><?= $inc['title'] ? e($inc['title']) : 'Signalement citoyen sans titre' ?></h2>
          <?php if ($isTrainingMode): ?>
          <p class="page-hero-text">
            Ce dossier doit permettre de comprendre en quelques secondes ou en est le traitement, quelle est la prochaine action utile et ce que verra le citoyen.
          </p>
          <?php endif; ?>
        </div>
        
        <div class="page-hero-metrics" style="display:flex; flex-wrap:wrap; gap:10px; grid-column:unset; grid-row:unset;">
          <div class="hero-chip" style="flex: 1 1 auto;">
            <span class="hero-chip-value"><?= e($inc['reference']) ?></span>
            <span class="hero-chip-label">reference de suivi</span>
          </div>
          <div class="hero-chip" style="flex: 1 1 auto;">
            <span class="hero-chip-value"><?= status_label($inc['status']) ?></span>
            <span class="hero-chip-label">etat actuel</span>
          </div>
          <div class="hero-chip" style="flex: 100%; max-width:100%;">
            <span class="hero-chip-value"><?= e($next_step_label) ?></span>
            <span class="hero-chip-label">prochaine action utile</span>
          </div>
        </div>
      </div>
      
      <!-- Colonne Droite : Image Dynamique -->
      <?php if ($incidentVisualUrl): ?>
        <div class="page-hero-visual" style="flex: 0 0 240px; min-height: 220px; display:flex; flex-direction:column; grid-column:unset; grid-row:unset;">
          <div class="generated-visual-panel generated-visual-panel--hero" style="width:100%; flex:1; border-radius:18px; position:relative; overflow:hidden; margin:0; display:flex; flex-direction:column; justify-content:flex-end;">
            <img src="<?= e($incidentVisualUrl) ?>" alt="Scene du dossier" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; z-index:1;">
            
            <div class="generated-visual-caption" style="position:relative; z-index:2; background:rgba(14,49,39,0.85); padding:12px; margin:0; border-radius:0;">
              <strong style="color:#fff; font-size:12px; display:block;">Contexte terrain</strong>
              <span style="color:rgba(255,255,255,0.7); font-size:11px;">Le signal et l'action prioritaire en un regard.</span>
            </div>
          </div>
        </div>
      <?php endif; ?>
      
    </div>
  </div>

  <div style="display:flex; flex-direction:column; gap:16px;">
    <div class="admin-guidance-grid" style="grid-template-columns:1fr; gap:12px; margin-bottom:0;">
      
      <?php if ($isTrainingMode): ?>
      <div class="admin-guidance-card">
        <div class="admin-guidance-kicker">Lecture rapide</div>
        <h3 style="font-size:1.1rem; margin-bottom:4px;">Ce que le dossier raconte au premier regard.</h3>
        <p style="font-size:0.85rem;">
          Priorite, citoyen concerne et traces de traitement doivent rester visibles au dessus de la ligne de flottaison.
        </p>
      </div>
      <?php endif; ?>

      <div class="admin-guidance-card">
        <div class="admin-guidance-kicker">Action recommande</div>
        <h3 style="font-size:1.1rem; margin-bottom:4px;"><?= e($next_step_label) ?></h3>
        
        <?php if ($isTrainingMode): ?>
        <p style="font-size:0.85rem;">
          Garder la direction prioritaire explicite evite l'hesitation dans la chaine de traitement.
        </p>
        <?php endif; ?>

        <?php if ($incidentGuideUrl || $incidentVisualUrl): ?>
          <div class="admin-guidance-visual" style="margin-top:12px;">
            <?= generated_visual_html($incidentGuideUrl ? $incidentGuideAsset : $incidentVisualAsset, ['class' => 'generated-visual generated-visual--contain generated-visual--portrait', 'label' => 'Repere d action dossier']) ?>
            <div class="admin-guidance-visual-copy">
              <strong>Repere agent</strong>
              <?php if ($isTrainingMode): ?>
              <span>Le dossier assigne un visage a la responsabilite immediate.</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="admin-progress-card" style="margin-bottom:0;">
      <div class="admin-progress-kicker">Progression dossier</div>
      <div class="admin-progress-row">
        <?php foreach ($status_flow as $index => $status): ?>
          <?php
            $done = $inc['status'] !== 'rejected' && $status_index >= $index;
            $active = $inc['status'] === $status;
          ?>
          <div class="admin-progress-step">
            <div class="admin-progress-dot <?= $done ? 'is-done' : '' ?> <?= $active ? 'is-active' : '' ?>"></div>
            <div class="admin-progress-label text-small"><?= e(status_label($status)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($inc['status'] === 'rejected'): ?>
        <div class="admin-progress-note text-small">
          Dossier classe sans suite. Attention au motif de cloture.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($service_context || $current_plan): ?>
<div class="admin-guidance-grid incident-detail-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Service responsable</div>
    <h3><?= e($service_context['service_name'] ?? 'Aucun service attribue') ?></h3>
    <p>
      <?= !empty($service_context['source']) && $service_context['source'] === 'plan'
        ? 'Le service affiché provient de la planification en cours.'
        : 'Le service affiché provient du rattachement categorie -> service.' ?>
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Intervention courante</div>
    <?php $currentPlanPill = $current_plan ? incident_plan_status_pill((string)$current_plan['status']) : null; ?>
    <h3><?= $current_plan ? e($currentPlanPill['label']) : 'Pas encore planifiee' ?></h3>
    <p>
      <?php if ($current_plan): ?>
        <?= e($current_plan['scheduled_date'] ?? 'Date non definie') ?>
        <?php if (!empty($current_plan['time_window_start']) || !empty($current_plan['time_window_end'])): ?>
          · <?= e(trim(implode(' - ', array_filter([$current_plan['time_window_start'] ?? null, $current_plan['time_window_end'] ?? null])))) ?>
        <?php endif; ?>
      <?php else: ?>
        Le dossier est suivi, mais aucune fenetre d intervention n est encore visible pour le citoyen.
      <?php endif; ?>
    </p>
    <?php if ($current_plan && !empty($current_plan['assigned_user_name'])): ?>
      <p class="text-muted text-small">Intervenant : <?= e($current_plan['assigned_user_name']) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="card dashboard-section-card">
  <div class="card-header">
    <span class="card-title">Cockpit d execution</span>
    <?php if ($isTrainingMode): ?>
    <span class="text-muted text-small">Lire d abord ce qui doit devenir visible pour l agent et pour le citoyen.</span>
    <?php endif; ?>
  </div>
  <div class="services-mode-band services-mode-band--tight">
    <div class="services-mode-card">
      <strong><?= e($service_context['service_name'] ?? 'Aucun') ?></strong>
      <span>service pilote</span>
    </div>
    <div class="services-mode-card">
      <strong><?= e($current_plan ? incident_plan_status_pill((string)$current_plan['status'])['label'] : 'A planifier') ?></strong>
      <span>etat d execution</span>
    </div>
    <div class="services-mode-card">
      <strong>
        <?php if ($current_plan && ($current_plan['source_type'] ?? 'internal') === 'provider' && !empty($current_plan['provider_name'])): ?>
          <?= e($current_plan['provider_name']) ?>
        <?php elseif ($current_plan): ?>
          Equipe interne
        <?php else: ?>
          En attente
        <?php endif; ?>
      </strong>
      <span>mode d execution</span>
    </div>
  </div>
  <div class="services-alert-band services-mode-band--spaced">
    <div class="services-alert is-info">
      <strong><?= e($next_step_label) ?></strong>
      <div>prochaine etape recommandee pour faire avancer le dossier</div>
    </div>
    <div class="services-alert is-warning">
      <strong>
        <?php if ($current_plan): ?>
          <?= e($current_plan['scheduled_date'] ?? 'Date a confirmer') ?>
        <?php else: ?>
          Aucun creneau
        <?php endif; ?>
      </strong>
      <div>fenetre visible actuellement cote execution</div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="incident-detail-layout">

  <!-- Colonne principale -->
  <div>

    <!-- En-tête du signalement -->
    <div class="card incident-detail-card">
      <div class="d-flex align-center justify-between incident-detail-header">
        <div>
          <code class="incident-detail-ref"><?= e($inc['reference']) ?></code>
          <h2 class="incident-detail-card-title">
            <?= $inc['title'] ? e($inc['title']) : '<span class="text-muted">Sans titre</span>' ?>
          </h2>
        </div>
        <div class="users-inline-actions incident-detail-header-actions">
          <?php include __DIR__ . '/../includes/pdf_export_button.php'; ?>
          <a href="/admin/?page=incidents" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">← Retour</a>
        </div>
      </div>

      <div class="d-flex gap-8 flex-wrap incident-detail-badges incident-detail-badges-row">
        <div class="incident-detail-category-chip" style="--category-accent:<?= e($inc['cat_color']) ?>">
          <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'md', $inc['cat_color'] ?? null) ?>
          <div class="admin-category-cell-copy">
            <span class="incident-detail-category-name"><?= e($inc['cat_name']) ?></span>
            <span class="text-muted text-small"><?= e($incidentCategoryVisual['description'] ?? '') ?></span>
          </div>
        </div>
        <span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span>
        <span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span>
        <span class="badge badge-gray">Cree le <?= format_date($inc['created_at']) ?></span>
        <!-- Badge votes (v1.1) -->
        <?php if ($inc['votes_count'] > 0): ?>
        <span class="badge incident-detail-votes-badge">
          <?= (int)$inc['votes_count'] ?> soutien<?= $inc['votes_count'] > 1 ? 's' : '' ?> citoyen<?= $inc['votes_count'] > 1 ? 's' : '' ?>
        </span>
        <?php endif; ?>
      </div>

      <p class="incident-detail-description"><?= nl2br(e($inc['description'])) ?></p>

      <div class="incident-detail-meta-list">
        <?php if ($inc['address']): ?>
        <p class="text-muted text-small">Lieu · <?= e($inc['address']) ?></p>
        <?php endif; ?>
        <p class="text-muted text-small">Coordonnées · <?= number_format($inc['latitude'],6) ?>, <?= number_format($inc['longitude'],6) ?></p>
      </div>

      <!-- CARTE ET PREUVES DU SIGNALEMENT -->
      <?php if ((!empty($inc['latitude']) && !empty($inc['longitude'])) || !empty($photos)): ?>
      <div class="card incident-detail-card" style="margin-top:20px;">
        <div class="card-header card-header--split">
          <div>
            <span class="card-title">Localisation et Preuves</span>
            <?php if ($isTrainingMode): ?>
            <p class="incident-detail-section-note">Verifiez les coordonnees GPS et les preuves visuelles attachees au dossier (cliquez sur une photo pour activer l'Inspecteur).</p>
            <?php endif; ?>
          </div>
          <?php if (!empty($photos)): ?>
            <div class="incident-detail-proof-summary">
              <span class="badge badge-gray"><?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?></span>
            </div>
          <?php endif; ?>
        </div>
        
        <?php if (!empty($inc['latitude']) && !empty($inc['longitude'])): ?>
          <div id="incident-detail-map" style="height: 250px; border-radius: 8px; z-index: 1; margin-bottom: <?= !empty($photos) ? '20px' : '0' ?>;"></div>
        <?php endif; ?>

        <?php if (!empty($photos)): ?>
          <div class="incident-detail-photo-grid">
            <?php foreach ($photos as $index => $ph): ?>
              <div class="incident-detail-photo-card"
                   data-photo-index="<?= $index ?>"
                   data-photo-url="<?= e($ph['url']) ?>">
                <span class="incident-detail-photo-thumb-wrap">
                  <img src="<?= e($ph['url']) ?>" alt="Photo" class="incident-detail-photo-thumb" loading="lazy">
                  <span class="incident-detail-photo-index"><?= ($index + 1) ?>/<?= count($photos) ?></span>
                  <span class="incident-detail-photo-hover-hint">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/><path d="M11 8v6M8 11h6"/></svg>
                  </span>
                </span>
                <div class="incident-detail-photo-meta">
                  <span class="incident-detail-photo-title"><?= e($ph['file_name'] ?? 'Preuve citoyenne') ?></span>
                  <?php if (!empty($ph['moderation_message'])): ?>
                    <small><?= e($ph['moderation_message']) ?></small>
                  <?php else: ?>
                    <small>Cliquer pour ouvrir.</small>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>



      <div class="incident-detail-reading-strip">
        <div class="incident-detail-reading-item">
          <small>Preuves</small>
          <strong><?= count($photos) ?> photo<?= count($photos) > 1 ? 's' : '' ?></strong>
          <span><?= $moderated_photos_count > 0 ? $moderated_photos_count . ' note' . ($moderated_photos_count > 1 ? 's' : '') . ' de moderation' : 'serie source lisible dans la fiche' ?></span>
        </div>
        <div class="incident-detail-reading-item">
          <small>Echanges</small>
          <strong><?= count($comments) ?> commentaire<?= count($comments) > 1 ? 's' : '' ?></strong>
          <span><?= $public_comments_count ?> public<?= $public_comments_count > 1 ? 's' : '' ?> · <?= $internal_comments_count ?> interne<?= $internal_comments_count > 1 ? 's' : '' ?></span>
        </div>
        <div class="incident-detail-reading-item">
          <small>Trace</small>
          <strong><?= count($history) + count($service_history) ?> etape<?= (count($history) + count($service_history)) > 1 ? 's' : '' ?></strong>
          <span><?= $history_notes_count ?> note<?= $history_notes_count > 1 ? 's' : '' ?> statut · <?= $service_history_public_count ?> message<?= $service_history_public_count > 1 ? 's' : '' ?> visible<?= $service_history_public_count > 1 ? 's' : '' ?></span>
        </div>
      </div>

    </div>



    <!-- Hover aperçu flottant -->
    <div id="photo-hover-preview" class="photo-hover-preview" aria-hidden="true">
      <img id="photo-hover-img" src="" alt="">
    </div>

    <!-- Modal lightbox style Instagram -->
    <div id="photo-lightbox" class="photo-lightbox" role="dialog" aria-modal="true" aria-label="Preuve citoyenne" aria-hidden="true">
      <div class="photo-lightbox-backdrop"></div>
      <div class="photo-lightbox-shell">
        <!-- Navigateur gauche/droite -->
        <button class="photo-lightbox-nav photo-lightbox-nav--prev" id="lb-prev" aria-label="Photo précédente">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button class="photo-lightbox-nav photo-lightbox-nav--next" id="lb-next" aria-label="Photo suivante">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <!-- Fermer -->
        <button class="photo-lightbox-close" id="lb-close" aria-label="Fermer">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <!-- Photo -->
        <div class="photo-lightbox-media">
          <img id="lb-img" src="" alt="Preuve citoyenne">
          <div class="photo-lightbox-counter" id="lb-counter"></div>
        </div>
        <!-- Panneau latéral -->
        <aside class="photo-lightbox-panel">
          <div class="photo-lightbox-panel-head">
            <span class="photo-lightbox-cat-dot" id="lb-cat-dot"></span>
            <div>
              <strong id="lb-incident-ref"></strong>
              <span class="photo-lightbox-incident-title" id="lb-incident-title"></span>
            </div>
            <span class="badge" id="lb-status-badge"></span>
          </div>
          <div class="photo-lightbox-panel-photo-name" id="lb-photo-name"></div>
          <div class="photo-lightbox-panel-actions">
            <a id="lb-source-link" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-secondary">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              Source originale
            </a>
          </div>
          <div class="photo-lightbox-comments" id="lb-comments">
            <!-- injecté par JS -->
          </div>

          <!-- Quick Action Planification Modal Embbed -->
          <div class="photo-lightbox-quick-action" style="padding: 20px; border-top: 1px solid rgba(0,0,0,0.06); background: rgba(245,248,246,0.5);">
            <div style="font-size: 13px; font-weight: 800; margin-bottom: 12px; color: var(--primary);">Action Rapide · Planification</div>
            <form method="POST" action="" data-async-form>
              <input type="hidden" name="action" value="plan_intervention">
              <div class="form-group" style="margin-bottom: 12px;">
                <label class="form-label" style="font-size: 11px;">Service responsable</label>
                <select name="service_id" class="form-control" style="padding: 6px; font-size: 12px; height: 32px;" required>
                  <option value="">Choisir un service…</option>
                  <?php foreach ($services as $service): ?>
                    <option value="<?= (int)$service['id'] ?>" <?= ((int)($current_plan['service_id'] ?? $service_context['service_id'] ?? 0) === (int)$service['id']) ? 'selected' : '' ?>>
                      <?= e($service['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="d-flex gap-8" style="margin-bottom: 12px;">
                <div class="form-group" style="margin-bottom: 0px; flex: 1;">
                  <label class="form-label" style="font-size: 11px;">Date prevue (opt.)</label>
                  <input type="date" name="scheduled_date" class="form-control" style="padding: 6px; font-size: 12px; height: 32px;" value="<?= e($current_plan['scheduled_date'] ?? '') ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0px; flex: 1;">
                  <label class="form-label" style="font-size: 11px;">Mode</label>
                  <select name="source_type" class="form-control" style="padding: 6px; font-size: 12px; height: 32px;">
                    <option value="internal" <?= (($current_plan['source_type'] ?? 'internal') === 'internal') ? 'selected' : '' ?>>Interne</option>
                    <option value="provider" <?= (($current_plan['source_type'] ?? '') === 'provider') ? 'selected' : '' ?>>Prestataire</option>
                  </select>
                </div>
              </div>
              <button type="submit" class="btn btn-primary" style="width: 100%; padding: 6px 12px; font-size: 12px; background: linear-gradient(180deg, var(--primary), var(--primary-dark));">
                Valider l intervention
              </button>
            </form>
          </div>
        </aside>
      </div>
    </div>

    <!-- Votants "Moi aussi" (v1.1) -->
    <?php if (!empty($voters)): ?>
    <div class="card incident-detail-card">
      <div class="card-header">
        <span class="card-title">Citoyens concernes (<?= count($voters) ?>)</span>
        <span class="text-muted text-small incident-detail-voters-note">Ont signale leur soutien</span>
      </div>
      <div class="incident-detail-voters">
        <?php foreach ($voters as $v): ?>
          <span class="incident-detail-voter-pill">
            <?= e($v['full_name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Commentaires -->
    <div class="card incident-detail-card">
      <div class="card-header card-header--split">
        <div>
          <span class="card-title">Commentaires</span>
          <p class="incident-detail-section-note">Lecture croisee des echanges publics et des notes internes sans perdre le fil du dossier.</p>
        </div>
        <div class="incident-detail-section-badges">
          <span class="badge badge-gray"><?= count($comments) ?> entree<?= count($comments) > 1 ? 's' : '' ?></span>
          <span class="badge badge-gray"><?= $public_comments_count ?> publique<?= $public_comments_count > 1 ? 's' : '' ?></span>
          <span class="badge badge-gray"><?= $internal_comments_count ?> interne<?= $internal_comments_count > 1 ? 's' : '' ?></span>
        </div>
      </div>

      <?php foreach ($comments as $cm): ?>
        <div class="incident-detail-comment-card <?= $cm['is_internal'] ? 'incident-detail-comment-card--internal' : '' ?>">
          <div class="d-flex align-center justify-between incident-detail-comment-head">
            <div>
              <strong><?= e($cm['author_name']) ?></strong>
              <span class="badge badge-<?= $cm['author_role']==='admin'?'red':($cm['author_role']==='agent'?'blue':'gray') ?> incident-detail-comment-role">
                <?= role_label($cm['author_role']) ?>
              </span>
              <?php if ($cm['is_internal']): ?>
                <span class="badge badge-yellow incident-detail-comment-internal">Interne</span>
              <?php endif; ?>
            </div>
            <span class="text-muted text-small"><?= format_date($cm['created_at']) ?></span>
          </div>
          <p class="incident-detail-comment-text"><?= nl2br(e($cm['comment'])) ?></p>
        </div>
      <?php endforeach; ?>

      <?php if (empty($comments)): ?>
        <div class="incident-detail-empty">
          <strong>Aucun commentaire pour l instant.</strong>
          <span>Le dossier peut encore etre qualifie ou documente sans echange supplementaire.</span>
        </div>
      <?php endif; ?>

      <!-- Formulaire d'ajout de commentaire -->
      <form method="POST" action="" class="incident-detail-comment-form" data-async-form>
        <input type="hidden" name="action" value="add_comment">
        <div class="form-group">
          <label class="form-label">Ajouter un commentaire</label>
          <textarea name="comment" class="form-control" rows="3"
                    placeholder="Répondre au citoyen ou ajouter une note interne…" required></textarea>
        </div>
        <div class="d-flex align-center gap-8 incident-detail-comment-form-actions">
          <label class="incident-detail-comment-toggle">
            <input type="checkbox" name="is_internal" value="1">
            Note interne (non visible par le citoyen)
          </label>
          <button type="submit" class="btn btn-primary btn-sm incident-detail-comment-submit">Envoyer</button>
        </div>
      </form>
    </div>

  </div><!-- /col principale -->

  <!-- Colonne latérale -->
  <aside class="incident-detail-sidebar">

    <!-- Informations citoyen -->
    <div class="card incident-detail-card">
      <div class="card-header"><span class="card-title">Citoyen</span></div>
      <p><strong><?= e($inc['reporter_name']) ?></strong></p>
      <p class="text-muted text-small">Email · <?= e($inc['reporter_email']) ?></p>
      <?php if ($inc['reporter_phone']): ?>
        <p class="text-muted text-small">Telephone · <?= e($inc['reporter_phone']) ?></p>
      <?php endif; ?>
    </div>

    <!-- Changer le statut + envoi notification (v1.1) -->
    <div class="card incident-detail-card">
      <div class="card-header"><span class="card-title">Traitement</span></div>
      <form method="POST" action="" data-async-form>
        <input type="hidden" name="action" value="change_status">
        <div class="form-group">
          <label class="form-label">Nouveau statut</label>
          <select name="new_status" class="form-control">
            <option value="submitted"    <?= $inc['status']==='submitted'    ?'selected':'' ?>>Soumis</option>
            <option value="acknowledged" <?= $inc['status']==='acknowledged' ?'selected':'' ?>>Pris en charge</option>
            <option value="in_progress"  <?= $inc['status']==='in_progress'  ?'selected':'' ?>>En cours de traitement</option>
            <option value="resolved"     <?= $inc['status']==='resolved'     ?'selected':'' ?>>Résolu</option>
            <option value="rejected"     <?= $inc['status']==='rejected'     ?'selected':'' ?>>Rejeté</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Priorité</label>
          <select name="priority" class="form-control">
            <option value="low"      <?= ($inc['priority']??'')==='low'      ?'selected':'' ?>>Faible</option>
            <option value="medium"   <?= ($inc['priority']??'medium')==='medium'   ?'selected':'' ?>>Normale</option>
            <option value="high"     <?= ($inc['priority']??'')==='high'     ?'selected':'' ?>>Haute</option>
            <option value="critical" <?= ($inc['priority']??'')==='critical' ?'selected':'' ?>>Critique</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Note de traitement (optionnel)</label>
          <textarea name="note" class="form-control" rows="2"
                    placeholder="Ex: Transmis au service voirie…"></textarea>
        </div>

        <!-- Option notification push (v1.1) -->
        <div class="form-group incident-detail-notify-box">
          <label class="incident-detail-notify-label">
            <input type="checkbox" name="send_notification" value="1" checked class="incident-detail-notify-checkbox">
            <div>
              <span class="incident-detail-notify-title">Notifier le citoyen</span>
              <p class="incident-detail-notify-copy">
                Envoie une notification push sur l'application mobile du citoyen
              </p>
            </div>
          </label>
        </div>

        <button type="submit" class="btn btn-success w-100 incident-detail-submit-center">
          Mettre a jour
        </button>
      </form>
    </div>

    <?php if ($service_tables_ready): ?>
    <div class="card incident-detail-card">
      <div class="card-header"><span class="card-title">Lecture d execution</span></div>
      <div class="admin-category-strip incident-detail-pill-column">
        <div class="admin-category-pill" style="--category-accent:<?= e($inc['cat_color'] ?? ($incidentCategoryVisual['accent'] ?? ($themePalette['primary'] ?? '#355160'))) ?>;">
          <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'md', $inc['cat_color'] ?? null) ?>
          <div class="admin-category-pill-copy">
            <strong>
              <?php if ($current_plan && ($current_plan['source_type'] ?? 'internal') === 'provider' && !empty($current_plan['provider_name'])): ?>
                Prestataire missionne · <?= e($current_plan['provider_name']) ?>
              <?php elseif ($current_plan && ($current_plan['source_type'] ?? 'internal') === 'internal'): ?>
                Equipe interne
              <?php else: ?>
                Service en attente de planification
              <?php endif; ?>
            </strong>
            <span>
              <?php if ($current_plan): ?>
                <?= e($current_plan['service_name'] ?? ($service_context['service_name'] ?? 'Service attribue')) ?>
                <?php if (!empty($current_plan['scheduled_date'])): ?>
                  · <?= e($current_plan['scheduled_date']) ?>
                <?php endif; ?>
                <?php if (!empty($current_plan['time_window_start']) || !empty($current_plan['time_window_end'])): ?>
                  · <?= e(trim(implode(' - ', array_filter([$current_plan['time_window_start'] ?? null, $current_plan['time_window_end'] ?? null])))) ?>
                <?php endif; ?>
              <?php else: ?>
                Le service est connu, mais aucune fenetre visible n est encore posee pour le citoyen.
              <?php endif; ?>
            </span>
          </div>
          <span class="admin-category-pill-count admin-category-pill-count--wide">
            <?= e($current_plan ? incident_plan_status_pill((string)$current_plan['status'])['label'] : 'A planifier') ?>
          </span>
        </div>
      </div>
    </div>

    <div class="card incident-detail-card">
      <div class="card-header"><span class="card-title">Planifier l intervention</span></div>
      <form method="POST" action="" data-async-form>
        <input type="hidden" name="action" value="plan_intervention">

        <div class="form-group">
          <label class="form-label">Service responsable</label>
          <select name="service_id" class="form-control" required>
            <option value="">Choisir un service…</option>
            <?php foreach ($services as $service): ?>
              <option value="<?= (int)$service['id'] ?>" <?= ((int)($current_plan['service_id'] ?? $service_context['service_id'] ?? 0) === (int)$service['id']) ? 'selected' : '' ?>>
                <?= e($service['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Agent en charge</label>
          <select name="assigned_user_id" class="form-control">
            <option value="">Laisser non assigne</option>
            <?php foreach ($staff_users as $staff): ?>
              <option value="<?= (int)$staff['id'] ?>" <?= ((int)($current_plan['assigned_user_id'] ?? $inc['assigned_to'] ?? 0) === (int)$staff['id']) ? 'selected' : '' ?>>
                <?= e($staff['full_name']) ?> · <?= e(role_label($staff['role'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="d-flex gap-8 incident-detail-plan-row">
          <div class="form-group incident-detail-plan-col">
            <label class="form-label">Date prevue (optionnelle)</label>
            <input type="date" name="scheduled_date" class="form-control" value="<?= e($current_plan['scheduled_date'] ?? '') ?>">
          </div>
          <div class="form-group incident-detail-plan-col">
            <label class="form-label">Debut</label>
            <input type="time" name="time_window_start" class="form-control" value="<?= e($current_plan['time_window_start'] ?? '') ?>">
          </div>
          <div class="form-group incident-detail-plan-col">
            <label class="form-label">Fin</label>
            <input type="time" name="time_window_end" class="form-control" value="<?= e($current_plan['time_window_end'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Mode d execution</label>
          <select name="source_type" class="form-control">
            <option value="internal" <?= (($current_plan['source_type'] ?? 'internal') === 'internal') ? 'selected' : '' ?>>Equipe interne</option>
            <option value="provider" <?= (($current_plan['source_type'] ?? '') === 'provider') ? 'selected' : '' ?>>Prestataire missionne</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Prestataire (si mission externe)</label>
          <input type="text" name="provider_name" class="form-control"
                 value="<?= e($current_plan['provider_name'] ?? '') ?>"
                 placeholder="Ex: Entreprise voirie littoral">
        </div>

        <div class="form-group">
          <label class="form-label">Message visible cote citoyen</label>
          <textarea name="citizen_message" class="form-control" rows="2"
                    placeholder="Ex: Une intervention est prevue cette semaine pour traiter ce point."><?= e($current_plan['citizen_message'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label">Note interne</label>
          <textarea name="internal_note" class="form-control" rows="2"
                    placeholder="Ex: Intervention a synchroniser avec la tournee secteur ouest."><?= e($current_plan['internal_note'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary w-100 incident-detail-submit-center">
          <?= $current_plan ? 'Mettre a jour la planification' : 'Planifier l intervention' ?>
        </button>
      </form>

      <?php if ($current_plan): ?>
        <?php $planPill = incident_plan_status_pill((string)$current_plan['status']); ?>
        <div class="incident-detail-plan-footer">
          <div class="incident-detail-plan-footer-head">
            <strong>Avancement de l intervention</strong>
            <span class="badge <?= e($planPill['class']) ?>"><?= e($planPill['label']) ?></span>
          </div>
          <p class="text-muted text-small incident-detail-plan-footer-copy">
            Utilisez ces actions pour rendre visible le passage terrain sans reouvrir toute la planification.
          </p>
          <div class="incident-detail-plan-actions">
            <?php if (in_array((string)$current_plan['status'], ['scheduled', 'rescheduled'], true)): ?>
              <form method="POST" action="" data-async-form>
                <input type="hidden" name="action" value="update_plan_status">
                <input type="hidden" name="target_status" value="in_progress">
                <button type="submit" class="btn btn-primary btn-sm">Demarrer l intervention</button>
              </form>
            <?php endif; ?>
            <?php if (in_array((string)$current_plan['status'], ['scheduled', 'rescheduled', 'in_progress'], true)): ?>
              <form method="POST" action="" data-async-form>
                <input type="hidden" name="action" value="update_plan_status">
                <input type="hidden" name="target_status" value="completed">
                <button type="submit" class="btn btn-success btn-sm">Marquer terminee</button>
              </form>
              <form method="POST" action="" onsubmit="return confirm('Annuler cette intervention ?')" data-async-form>
                <input type="hidden" name="action" value="update_plan_status">
                <input type="hidden" name="target_status" value="cancelled">
                <button type="submit" class="btn btn-outline btn-sm">Annuler</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Historique des statuts -->
    <?php if (!empty($history)): ?>
    <div class="card incident-detail-card">
      <div class="card-header card-header--split">
        <div>
          <span class="card-title">Historique</span>
          <p class="incident-detail-section-note">Trace des changements de statut et des decisions visibles dans le cycle de traitement.</p>
        </div>
        <div class="incident-detail-section-badges">
          <span class="badge badge-gray"><?= count($history) ?> etape<?= count($history) > 1 ? 's' : '' ?></span>
          <span class="badge badge-gray"><?= $history_notes_count ?> note<?= $history_notes_count > 1 ? 's' : '' ?></span>
        </div>
      </div>
      <ul class="timeline">
        <?php foreach ($history as $h): ?>
        <li class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div>
              <?php if ($h['old_status']): ?>
                <span class="badge <?= status_class($h['old_status']) ?> incident-detail-comment-role"><?= status_label($h['old_status']) ?></span>
                → 
              <?php endif; ?>
              <span class="badge <?= status_class($h['new_status']) ?>"><?= status_label($h['new_status']) ?></span>
            </div>
            <?php if ($h['note']): ?>
              <p class="incident-detail-history-note"><?= e($h['note']) ?></p>
            <?php endif; ?>
            <div class="timeline-meta">
              <?= e($h['changed_by_name']) ?> · <?= format_date($h['changed_at']) ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($service_history)): ?>
    <div class="card incident-detail-card">
      <div class="card-header card-header--split">
        <div>
          <span class="card-title">Trace d intervention</span>
          <p class="incident-detail-section-note">Lecture du passage terrain et des actions de service sans rouvrir toute la planification.</p>
        </div>
        <div class="incident-detail-section-badges">
          <span class="badge badge-gray"><?= count($service_history) ?> trace<?= count($service_history) > 1 ? 's' : '' ?></span>
          <span class="badge badge-gray"><?= $service_history_public_count ?> message<?= $service_history_public_count > 1 ? 's' : '' ?> visible<?= $service_history_public_count > 1 ? 's' : '' ?></span>
        </div>
      </div>
      <ul class="timeline">
        <?php foreach ($service_history as $entry): ?>
        <?php $payload = !empty($entry['payload_json']) ? json_decode($entry['payload_json'], true) : []; ?>
        <li class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div><strong><?= e($entry['event_label'] ?? $entry['event_type']) ?></strong></div>
            <?php if (!empty($entry['citizen_label'])): ?>
              <p class="incident-detail-history-note incident-detail-history-note--muted"><?= e($entry['citizen_label']) ?></p>
            <?php endif; ?>
            <?php if (!empty($payload['scheduled_date'])): ?>
              <p class="incident-detail-meta-note">
                Date prevue : <?= e($payload['scheduled_date']) ?>
                <?php if (!empty($payload['time_window'])): ?>
                  · <?= e($payload['time_window']) ?>
                <?php endif; ?>
              </p>
            <?php endif; ?>
            <?php if (!empty($payload['provider_name'])): ?>
              <p class="incident-detail-meta-note">Prestataire : <?= e($payload['provider_name']) ?></p>
            <?php endif; ?>
            <div class="timeline-meta">
              <?= e($entry['actor_name'] ?? 'Systeme') ?>
              <?php if (!empty($entry['service_name'])): ?>
                · <?= e($entry['service_name']) ?>
              <?php endif; ?>
              · <?= format_date($entry['created_at']) ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

  </aside><!-- /col latérale -->

</div>
</div>

<style>
/* ── Photo grid ──────────────────────────────────────────── */
.incident-detail-photo-grid {
  display: flex !important;
  flex-wrap: wrap;
  gap: 16px !important;
}
.incident-detail-photo-card {
  cursor: pointer;
  transition: transform 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.25s ease;
  position: relative;
  z-index: 1;
  border-radius: 14px;
  overflow: hidden;
  width: 180px;
  height: 180px;
  flex-shrink: 0;
  border: 1px solid rgba(0,0,0,0.06);
}
.incident-detail-photo-card:hover {
  transform: translateY(-4px) scale(1.03);
  box-shadow: 0 16px 32px rgba(15,48,38,0.18);
  z-index: 10;
}
.incident-detail-photo-thumb-wrap { position: relative; display: block; height: 100%; }
.incident-detail-photo-thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.incident-detail-photo-hover-hint {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0);
  color: #fff;
  opacity: 0;
  transition: opacity 0.2s, background 0.2s;
}
.incident-detail-photo-card:hover .incident-detail-photo-hover-hint {
  opacity: 1;
  background: rgba(0,0,0,0.32);
}

/* ── Hover preview flottant ──────────────────────────────── */
.photo-hover-preview {
  position: fixed;
  z-index: 9000;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s;
  border-radius: 10px;
  overflow: hidden;
  box-shadow: 0 16px 48px rgba(0,0,0,0.35);
  width: 260px;
  height: 260px;
  background: #111;
}
.photo-hover-preview.is-visible { opacity: 1; }
.photo-hover-preview img {
  width: 100%; height: 100%;
  object-fit: cover;
  display: block;
}

/* ── Lightbox ────────────────────────────────────────────── */
.photo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 9900;
  display: flex;
  align-items: center;
  justify-content: center;
  visibility: hidden;
  opacity: 0;
  transition: opacity 0.25s ease, visibility 0.25s ease;
}
.photo-lightbox.is-open {
  visibility: visible;
  opacity: 1;
}
.photo-lightbox.is-open .photo-lightbox-shell {
  transform: scale(1);
}
.photo-lightbox-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,0.88);
  backdrop-filter: blur(8px);
}
.photo-lightbox-shell {
  position: relative;
  display: flex;
  width: min(1000px, 95vw);
  height: min(680px, 92vh);
  background: #111;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0,0,0,0.6);
  transform: scale(0.95);
  transition: transform 0.35s cubic-bezier(0.19, 1, 0.22, 1);
}
.photo-lightbox-media {
  flex: 1 1 0%;
  background: #000;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
.photo-lightbox-media img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
  transition: opacity 0.25s;
}
.photo-lightbox-media img.is-loading { opacity: 0; transform: scale(0.98); }
.photo-lightbox-counter {
  position: absolute;
  bottom: 10px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(0,0,0,0.5);
  color: #fff;
  font-size: 11px;
  padding: 3px 10px;
  border-radius: 20px;
}
.photo-lightbox-panel {
  width: 320px;
  flex-shrink: 0;
  background: #1a1a1a;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  border-left: 1px solid rgba(255,255,255,0.08);
}
.photo-lightbox-panel-head {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 18px 16px 14px;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}
.photo-lightbox-cat-dot {
  width: 10px; height: 10px;
  border-radius: 50%;
  margin-top: 4px;
  flex-shrink: 0;
}
.photo-lightbox-panel-head > div { flex: 1; }
.photo-lightbox-panel-head strong { color: #fff; font-size: 13px; display: block; }
.photo-lightbox-incident-title { color: #999; font-size: 12px; display: block; margin-top: 2px; line-height: 1.3; }
.photo-lightbox-panel-photo-name {
  padding: 10px 16px;
  font-size: 12px;
  color: #bbb;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.photo-lightbox-panel-actions {
  padding: 10px 16px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.photo-lightbox-panel-actions .btn {
  display: inline-flex; align-items: center; gap: 6px; font-size: 12px;
  background: rgba(255,255,255,0.08); color: #ddd; border: 1px solid rgba(255,255,255,0.12);
}
.photo-lightbox-panel-actions .btn:hover { background: rgba(255,255,255,0.14); color: #fff; }
.photo-lightbox-comments {
  flex: 1;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.lb-comment {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.lb-comment-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
}
.lb-comment-author { color: #fff; font-weight: 600; }
.lb-comment-date { color: #666; margin-left: auto; }
.lb-comment-text { font-size: 12px; color: #ccc; line-height: 1.5; }
.lb-comment-internal .lb-comment-author { color: #fbbf24; }
.lb-comments-empty { color: #555; font-size: 12px; font-style: italic; padding: 8px 0; }
.photo-lightbox-nav {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  z-index: 10;
  background: rgba(0,0,0,0.5);
  border: none;
  color: #fff;
  width: 36px; height: 36px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
}
.photo-lightbox-nav:hover { background: rgba(0,0,0,0.8); }
.photo-lightbox-nav--prev { left: 8px; }
.photo-lightbox-nav--next { right: calc(320px + 8px); }
.photo-lightbox-close {
  position: absolute;
  top: 10px; right: calc(320px + 10px);
  z-index: 10;
  background: rgba(0,0,0,0.5);
  border: none;
  color: #fff;
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
}
.photo-lightbox-close:hover { background: rgba(200,0,0,0.7); }

.detail-map-popup-proof { text-align: center; font-family: inherit; }
.detail-map-popup-proof img { width: 100%; max-width: 250px; object-fit: cover; border-radius: 6px; box-shadow: 0 8px 16px rgba(0,0,0,0.15); margin-top: 8px; }
</style>

<!-- Lightbox photo viewer -->
<script>
(() => {
  const PHOTOS = <?= json_encode(array_map(static fn($ph) => [
    'url'      => $ph['url'] ?? '',
    'name'     => $ph['file_name'] ?? 'Preuve citoyenne',
    'mod'      => $ph['moderation_message'] ?? null,
  ], $photos)) ?>;

  const INCIDENT = <?= json_encode([
    'ref'    => $inc['reference'] ?? ('#' . $inc['id']),
    'title'  => $inc['title'] ?: ($inc['cat_name'] ?? ''),
    'status' => $inc['status'] ?? '',
    'color'  => $inc['cat_color'] ?? '#355160',
  ]) ?>;

  const STATUS_LABELS = {
    submitted:    'Soumis',
    acknowledged: 'Pris en charge',
    in_progress:  'En cours',
    resolved:     'Resolu',
    rejected:     'Rejete',
  };
  const STATUS_COLORS = {
    submitted:    'badge-gray',
    acknowledged: 'badge-blue',
    in_progress:  'badge-blue',
    resolved:     'badge-green',
    rejected:     'badge-red',
  };

  const COMMENTS = <?= json_encode(array_map(static fn($cm) => [
    'author'    => $cm['author_name'] ?? 'Anonyme',
    'role'      => $cm['author_role'] ?? 'citizen',
    'internal'  => (bool)$cm['is_internal'],
    'text'      => $cm['comment'] ?? '',
    'date'      => !empty($cm['created_at'])
        ? date('d/m/Y H:i', strtotime($cm['created_at']))
        : '',
  ], $comments)) ?>;

  // ── Hover preview ──────────────────────────────────────────
  const hoverEl  = document.getElementById('photo-hover-preview');
  const hoverImg = document.getElementById('photo-hover-img');
  let hoverTimer = null;

  function positionHover(e) {
    const margin = 16;
    let x = e.clientX + margin;
    let y = e.clientY + margin;
    if (x + 260 > window.innerWidth)  x = e.clientX - 260 - margin;
    if (y + 260 > window.innerHeight) y = e.clientY - 260 - margin;
    hoverEl.style.left = x + 'px';
    hoverEl.style.top  = y + 'px';
  }

  document.querySelectorAll('.incident-detail-photo-card').forEach(card => {
    const url = card.dataset.photoUrl;

    card.addEventListener('mouseenter', e => {
      hoverImg.src = url;
      hoverTimer = setTimeout(() => {
        positionHover(e);
        hoverEl.classList.add('is-visible');
      }, 180);
    });

    card.addEventListener('mousemove', positionHover);

    card.addEventListener('mouseleave', () => {
      clearTimeout(hoverTimer);
      hoverEl.classList.remove('is-visible');
    });

    card.addEventListener('click', () => {
      openLightbox(parseInt(card.dataset.photoIndex, 10));
    });
  });

  // ── Lightbox ───────────────────────────────────────────────
  const lb       = document.getElementById('photo-lightbox');
  const lbImg    = document.getElementById('lb-img');
  const lbPrev   = document.getElementById('lb-prev');
  const lbNext   = document.getElementById('lb-next');
  const lbClose  = document.getElementById('lb-close');
  const lbCounter   = document.getElementById('lb-counter');
  const lbRef       = document.getElementById('lb-incident-ref');
  const lbTitle     = document.getElementById('lb-incident-title');
  const lbStatus    = document.getElementById('lb-status-badge');
  const lbCatDot    = document.getElementById('lb-cat-dot');
  const lbPhotoName = document.getElementById('lb-photo-name');
  const lbSourceLink = document.getElementById('lb-source-link');
  const lbComments  = document.getElementById('lb-comments');

  let currentIndex = 0;

  function openLightbox(index) {
    if (!PHOTOS.length) return;
    currentIndex = Math.max(0, Math.min(index, PHOTOS.length - 1));
    renderLightbox();
    lb.classList.add('is-open');
    lb.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    hoverEl.classList.remove('is-visible');
    lbClose.focus();
  }

  function closeLightbox() {
    lb.classList.remove('is-open');
    lb.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  function renderLightbox() {
    const ph = PHOTOS[currentIndex];

    // Photo
    lbImg.classList.add('is-loading');
    lbImg.onload = () => lbImg.classList.remove('is-loading');
    lbImg.src = ph.url;

    // Compteur
    lbCounter.textContent = PHOTOS.length > 1
      ? (currentIndex + 1) + ' / ' + PHOTOS.length : '';

    // Nav visibility
    lbPrev.style.display = (currentIndex > 0) ? 'flex' : 'none';
    lbNext.style.display = (currentIndex < PHOTOS.length - 1) ? 'flex' : 'none';

    // Infos incident
    lbRef.textContent = INCIDENT.ref;
    lbTitle.textContent = INCIDENT.title;
    lbCatDot.style.background = INCIDENT.color;
    const sLabel = STATUS_LABELS[INCIDENT.status] || INCIDENT.status;
    const sClass = STATUS_COLORS[INCIDENT.status] || 'badge-gray';
    lbStatus.textContent = sLabel;
    lbStatus.className = 'badge ' + sClass;

    // Nom photo
    lbPhotoName.textContent = ph.name;

    // Lien source
    lbSourceLink.href = ph.url;

    // Commentaires
    if (!COMMENTS.length) {
      lbComments.innerHTML = '<span class="lb-comments-empty">Aucun commentaire sur ce dossier.</span>';
    } else {
      lbComments.innerHTML = COMMENTS.map(cm => {
        const cls = cm.internal ? 'lb-comment lb-comment-internal' : 'lb-comment';
        const intTag = cm.internal ? '<span style="font-size:10px;color:#fbbf24;margin-left:4px;">interne</span>' : '';
        return `<div class="${cls}">
          <div class="lb-comment-head">
            <span class="lb-comment-author">${escHtml(cm.author)}${intTag}</span>
            <span class="lb-comment-date">${escHtml(cm.date)}</span>
          </div>
          <p class="lb-comment-text">${escHtml(cm.text)}</p>
        </div>`;
      }).join('');
    }
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  lbPrev.addEventListener('click', () => { if (currentIndex > 0) { currentIndex--; renderLightbox(); } });
  lbNext.addEventListener('click', () => { if (currentIndex < PHOTOS.length - 1) { currentIndex++; renderLightbox(); } });
  lbClose.addEventListener('click', closeLightbox);
  lb.querySelector('.photo-lightbox-backdrop').addEventListener('click', closeLightbox);

  document.addEventListener('keydown', e => {
    if (!lb.classList.contains('is-open')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft' && currentIndex > 0) { currentIndex--; renderLightbox(); }
    if (e.key === 'ArrowRight' && currentIndex < PHOTOS.length - 1) { currentIndex++; renderLightbox(); }
  });
})();
</script>

<!-- Chargement de la carte Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
(() => {
  const lat = <?= json_encode((float)($inc['latitude'] ?? 0)) ?>;
  const lng = <?= json_encode((float)($inc['longitude'] ?? 0)) ?>;
  const leadPhoto = <?= json_encode(!empty($photos[0]['url']) ? $photos[0]['url'] : null) ?>;
  
  if (!lat || !lng || typeof L === 'undefined') return;
  const mapElem = document.getElementById('incident-detail-map');
  if (!mapElem) return;

  const map = L.map('incident-detail-map').setView([lat, lng], 15);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
  }).addTo(map);

  const marker = L.circleMarker([lat, lng], {
    radius: 12, 
    fillColor: '<?= addslashes($inc['cat_color'] ?? '#355160') ?>', 
    color: '#fff',
    weight: 3, 
    opacity: 1, 
    fillOpacity: 0.9
  }).addTo(map);

  let popupHtml = '<div class="detail-map-popup-proof">';
  popupHtml += '<strong><?= addslashes($inc['title'] ?: $inc['cat_name']) ?></strong>';
  if (leadPhoto) {
    popupHtml += '<br><img src="' + leadPhoto + '" alt="Preuve locale">';
  } else {
    popupHtml += '<br><small>Aucune photo citoyenne</small>';
  }
  popupHtml += '</div>';

  marker.bindPopup(popupHtml, { minWidth: 260, offset: [0, -5] });

  // Survol demande par l'utilisateur
  marker.on('mouseover', function(e) {
    this.openPopup();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
