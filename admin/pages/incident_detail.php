<?php
/**
 * Ma Commune Back-Office — Détail et traitement d'un signalement
 * v1.1 : affichage des votes + envoi de notification push aux citoyens
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../backend/config/PushNotificationService.php';
$admin = require_admin_auth();

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
$current_plan = $service_tables_ready ? intervention_get_current_plan($db, $id) : null;
$service_history = $service_tables_ready ? intervention_get_history($db, $id, false) : [];

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
$incidentVisualAsset = $inc['status'] === 'resolved' ? 'MOM-04' : 'MOM-03';
$incidentVisualUrl = generated_visual_url($incidentVisualAsset);
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

        if ($scheduled_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduled_date)) {
            $_SESSION['flash_error'] = 'La date planifiee est obligatoire.';
            header("Location: /admin/?page=incident_detail&id=$id");
            exit;
        }

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

            $existing_plan = intervention_get_current_plan($db, $id);
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
                $scheduled_date,
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
            if ($inc['status'] === 'submitted') {
                $incident_sets[] = 'status = ?';
                $incident_params[] = 'acknowledged';
            }
            $incident_params[] = $id;
            $db->prepare('UPDATE incidents SET ' . implode(', ', $incident_sets) . ' WHERE id = ?')
               ->execute($incident_params);

            if ($inc['status'] === 'submitted') {
                $db->prepare("
                    INSERT INTO status_history (incident_id, old_status, new_status, user_id, note, changed_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ")->execute([
                    $id,
                    'submitted',
                    'acknowledged',
                    $admin['id'],
                    'Dossier pris en charge et intervention planifiee.',
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
                    'scheduled_date' => $scheduled_date,
                    'time_window'    => $window_label !== '' ? $window_label : null,
                    'source_type'    => $source_type,
                    'provider_name'  => $provider_name !== '' ? $provider_name : null,
                ],
            ]);

            $db->commit();

            try {
                (new PushNotificationService($db))->notifyInterventionPlanned($id, [
                    'service_name' => $service['name'],
                    'scheduled_date' => $scheduled_date,
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
}

$page_title = 'Signalement ' . e($inc['reference']);
$active_nav = 'incidents';
require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-hero">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Dossier terrain</div>
    <h2 class="page-hero-title"><?= $inc['title'] ? e($inc['title']) : 'Signalement citoyen sans titre' ?></h2>
    <p class="page-hero-text">
      Ce dossier doit permettre de comprendre en quelques secondes ou en est le traitement, quelle est la prochaine action utile et ce que verra le citoyen.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= e($inc['reference']) ?></span>
      <span class="hero-chip-label">reference de suivi</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= status_label($inc['status']) ?></span>
      <span class="hero-chip-label">etat actuel</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= e($next_step_label) ?></span>
      <span class="hero-chip-label">prochaine action utile</span>
    </div>
  </div>
</div>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Lecture rapide</div>
    <h3>Ce que le dossier raconte au premier regard.</h3>
    <p>
      Categorie, priorite, progression, citoyen concerne et trace de traitement doivent rester visibles sans devoir relire toute la fiche.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Action recommande</div>
    <h3><?= e($next_step_label) ?></h3>
    <p>
      Garder la prochaine etape explicite evite les traitements hesitants et rend la reponse plus lisible pour toute la chaine commune-terrain-citoyen.
    </p>
    <?php if ($incidentVisualUrl): ?>
      <div class="admin-guidance-visual">
        <?= generated_visual_html($incidentVisualAsset, ['class' => 'generated-visual generated-visual--contain generated-visual--portrait', 'label' => 'Scene de suivi dossier']) ?>
        <div class="admin-guidance-visual-copy">
          <strong>Scene de dossier disponible</strong>
          <span>Le futur lot visuel pourra rendre cet etat de traitement immediatement lisible pour les agents.</span>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="admin-progress-card">
  <div class="admin-progress-kicker">Progression dossier</div>
  <div class="admin-progress-row">
    <?php foreach ($status_flow as $index => $status): ?>
      <?php
        $done = $inc['status'] !== 'rejected' && $status_index >= $index;
        $active = $inc['status'] === $status;
      ?>
      <div class="admin-progress-step">
        <div class="admin-progress-dot <?= $done ? 'is-done' : '' ?> <?= $active ? 'is-active' : '' ?>"></div>
        <div class="admin-progress-label"><?= e(status_label($status)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($inc['status'] === 'rejected'): ?>
    <div class="admin-progress-note">
      Ce dossier est actuellement classe sans suite. La valeur de cette fiche depend surtout d un motif clair et d une trace de verification.
    </div>
  <?php endif; ?>
</div>

<?php if ($service_context || $current_plan): ?>
<div class="admin-guidance-grid" style="margin-top:18px">
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
    <h3><?= $current_plan ? e(ucfirst(str_replace('_', ' ', $current_plan['status']))) : 'Pas encore planifiee' ?></h3>
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
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

  <!-- Colonne principale -->
  <div>

    <!-- En-tête du signalement -->
    <div class="card">
      <div class="d-flex align-center justify-between" style="margin-bottom:16px">
        <div>
          <code style="font-size:12px;color:#94a3b8"><?= e($inc['reference']) ?></code>
          <h2 style="font-size:20px;font-weight:800;margin-top:4px">
            <?= $inc['title'] ? e($inc['title']) : '<span class="text-muted">Sans titre</span>' ?>
          </h2>
        </div>
        <a href="/admin/?page=incidents" class="btn btn-outline btn-sm">← Retour</a>
      </div>

      <div class="d-flex gap-8 flex-wrap" style="margin-bottom:16px;align-items:center">
        <div style="display:flex;align-items:center;gap:10px;padding:6px 12px;border-radius:14px;background:<?= e($inc['cat_color']) ?>14;border:1px solid <?= e($inc['cat_color']) ?>33">
          <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'sm', $inc['cat_color'] ?? null) ?>
          <span style="font-size:13px;font-weight:700;color:<?= e($inc['cat_color']) ?>"><?= e($inc['cat_name']) ?></span>
        </div>
        <span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span>
        <span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span>
        <span class="badge badge-gray">📅 <?= format_date($inc['created_at']) ?></span>
        <!-- Badge votes (v1.1) -->
        <?php if ($inc['votes_count'] > 0): ?>
        <span class="badge" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0">
          👍 <?= (int)$inc['votes_count'] ?> vote<?= $inc['votes_count'] > 1 ? 's' : '' ?> "Moi aussi"
        </span>
        <?php endif; ?>
      </div>

      <p style="font-size:15px;line-height:1.7;color:#374151;margin-bottom:16px"><?= nl2br(e($inc['description'])) ?></p>

      <?php if ($inc['address']): ?>
      <p class="text-muted text-small">📍 <?= e($inc['address']) ?></p>
      <?php endif; ?>
      <p class="text-muted text-small">🗺️ Coordonnées : <?= number_format($inc['latitude'],6) ?>, <?= number_format($inc['longitude'],6) ?></p>
    </div>

    <!-- Photos -->
    <?php if (!empty($photos)): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">📷 Photos (<?= count($photos) ?>)</span></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <?php foreach ($photos as $ph): ?>
          <a href="<?= e($ph['url']) ?>" target="_blank">
            <img src="<?= e($ph['url']) ?>" alt="Photo"
                 style="width:160px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #e2e8f0;">
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Votants "Moi aussi" (v1.1) -->
    <?php if (!empty($voters)): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">👍 Citoyens concernés (<?= count($voters) ?>)</span>
        <span class="text-muted text-small" style="margin-left:8px">Ont voté "Moi aussi"</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($voters as $v): ?>
          <span style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:20px;padding:4px 12px;font-size:13px;color:#15803d">
            <?= e($v['full_name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Commentaires -->
    <div class="card">
      <div class="card-header"><span class="card-title">💬 Commentaires</span></div>

      <?php foreach ($comments as $cm): ?>
        <div style="background:<?= $cm['is_internal'] ? '#fef9c3' : '#f8fafc' ?>;border-radius:10px;padding:14px;margin-bottom:12px;border-left:3px solid <?= $cm['is_internal'] ? '#f59e0b' : '#e2e8f0' ?>">
          <div class="d-flex align-center justify-between" style="margin-bottom:6px">
            <div>
              <strong><?= e($cm['author_name']) ?></strong>
              <span class="badge badge-<?= $cm['author_role']==='admin'?'red':($cm['author_role']==='agent'?'blue':'gray') ?>" style="margin-left:6px;font-size:10px">
                <?= role_label($cm['author_role']) ?>
              </span>
              <?php if ($cm['is_internal']): ?>
                <span class="badge badge-yellow" style="margin-left:4px;font-size:10px">🔒 Note interne</span>
              <?php endif; ?>
            </div>
            <span class="text-muted text-small"><?= format_date($cm['created_at']) ?></span>
          </div>
          <p style="margin:0;font-size:14px;line-height:1.6"><?= nl2br(e($cm['comment'])) ?></p>
        </div>
      <?php endforeach; ?>

      <?php if (empty($comments)): ?>
        <p class="text-muted text-small">Aucun commentaire pour l'instant.</p>
      <?php endif; ?>

      <!-- Formulaire d'ajout de commentaire -->
      <form method="POST" action="" style="margin-top:16px">
        <input type="hidden" name="action" value="add_comment">
        <div class="form-group">
          <label class="form-label">Ajouter un commentaire</label>
          <textarea name="comment" class="form-control" rows="3"
                    placeholder="Répondre au citoyen ou ajouter une note interne…" required></textarea>
        </div>
        <div class="d-flex align-center gap-8">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="is_internal" value="1">
            🔒 Note interne (non visible par le citoyen)
          </label>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto">Envoyer</button>
        </div>
      </form>
    </div>

  </div><!-- /col principale -->

  <!-- Colonne latérale -->
  <div>

    <!-- Informations citoyen -->
    <div class="card">
      <div class="card-header"><span class="card-title">👤 Citoyen</span></div>
      <p><strong><?= e($inc['reporter_name']) ?></strong></p>
      <p class="text-muted text-small">📧 <?= e($inc['reporter_email']) ?></p>
      <?php if ($inc['reporter_phone']): ?>
        <p class="text-muted text-small">📞 <?= e($inc['reporter_phone']) ?></p>
      <?php endif; ?>
    </div>

    <!-- Changer le statut + envoi notification (v1.1) -->
    <div class="card">
      <div class="card-header"><span class="card-title">⚙️ Traitement</span></div>
      <form method="POST" action="">
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
        <div class="form-group" style="background:#f0fdf4;border-radius:8px;padding:12px;border:1px solid #bbf7d0">
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
            <input type="checkbox" name="send_notification" value="1" checked style="margin-top:2px">
            <div>
              <span style="font-size:13px;font-weight:600;color:#15803d">🔔 Notifier le citoyen</span>
              <p style="font-size:11px;color:#166534;margin:2px 0 0">
                Envoie une notification push sur l'application mobile du citoyen
              </p>
            </div>
          </label>
        </div>

        <button type="submit" class="btn btn-success w-100" style="justify-content:center">
          ✅ Mettre à jour
        </button>
      </form>
    </div>

    <?php if ($service_tables_ready): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">🗓️ Planifier l intervention</span></div>
      <form method="POST" action="">
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

        <div class="d-flex gap-8">
          <div class="form-group" style="flex:1">
            <label class="form-label">Date prevue</label>
            <input type="date" name="scheduled_date" class="form-control" value="<?= e($current_plan['scheduled_date'] ?? '') ?>" required>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Debut</label>
            <input type="time" name="time_window_start" class="form-control" value="<?= e($current_plan['time_window_start'] ?? '') ?>">
          </div>
          <div class="form-group" style="flex:1">
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

        <button type="submit" class="btn btn-primary w-100" style="justify-content:center">
          <?= $current_plan ? 'Mettre a jour la planification' : 'Planifier l intervention' ?>
        </button>
      </form>
    </div>
    <?php endif; ?>

    <!-- Historique des statuts -->
    <?php if (!empty($history)): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">📜 Historique</span></div>
      <ul class="timeline">
        <?php foreach ($history as $h): ?>
        <li class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div>
              <?php if ($h['old_status']): ?>
                <span class="badge <?= status_class($h['old_status']) ?>" style="font-size:10px"><?= status_label($h['old_status']) ?></span>
                → 
              <?php endif; ?>
              <span class="badge <?= status_class($h['new_status']) ?>"><?= status_label($h['new_status']) ?></span>
            </div>
            <?php if ($h['note']): ?>
              <p style="font-size:13px;margin:4px 0 0"><?= e($h['note']) ?></p>
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
    <div class="card">
      <div class="card-header"><span class="card-title">🧭 Trace d intervention</span></div>
      <ul class="timeline">
        <?php foreach ($service_history as $entry): ?>
        <?php $payload = !empty($entry['payload_json']) ? json_decode($entry['payload_json'], true) : []; ?>
        <li class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div><strong><?= e($entry['event_label'] ?? $entry['event_type']) ?></strong></div>
            <?php if (!empty($entry['citizen_label'])): ?>
              <p style="font-size:13px;margin:4px 0 0;color:#374151"><?= e($entry['citizen_label']) ?></p>
            <?php endif; ?>
            <?php if (!empty($payload['scheduled_date'])): ?>
              <p style="font-size:12px;margin:4px 0 0;color:#64748b">
                Date prevue : <?= e($payload['scheduled_date']) ?>
                <?php if (!empty($payload['time_window'])): ?>
                  · <?= e($payload['time_window']) ?>
                <?php endif; ?>
              </p>
            <?php endif; ?>
            <?php if (!empty($payload['provider_name'])): ?>
              <p style="font-size:12px;margin:4px 0 0;color:#64748b">Prestataire : <?= e($payload['provider_name']) ?></p>
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

  </div><!-- /col latérale -->

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
