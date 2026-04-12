<?php
/**
 * Ma Commune v1.2 — Liste des signalements (ADMIN-03)
 * Filtres avancés : statut, catégorie, priorité, date, recherche textuelle, tri, votes.
 * Export CSV.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Signalements';
$active_nav = 'incidents';
$themePalette = visual_admin_data_palette();

$db = Database::getInstance();
$service_tables_ready = admin_db_has_table($db, 'services') && admin_db_has_table($db, 'service_category_map');
$planning_tables_ready = $service_tables_ready && admin_db_has_table($db, 'intervention_plans');
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;

// --- Paramètres de filtre et pagination ---
$per_page   = 20;
$page_num   = max(1, (int)($_GET['p']        ?? 1));
$offset     = ($page_num - 1) * $per_page;
$f_status   = $_GET['status']    ?? '';
$f_cat      = $_GET['cat']       ?? '';
$f_service  = $_GET['service']   ?? '';
$f_plan     = $_GET['plan']      ?? '';
$f_executor = $_GET['executor']  ?? '';
$f_proof    = ($_GET['proof'] ?? '') === 'with_photo' ? 'with_photo' : '';
$f_search   = trim($_GET['q']    ?? '');
$f_priority = $_GET['priority']  ?? '';
$f_date_from= trim($_GET['date_from'] ?? '');
$f_date_to  = trim($_GET['date_to']   ?? '');
$f_sort     = in_array($_GET['sort'] ?? '', ['votes_count','updated_at','created_at']) ? $_GET['sort'] : 'created_at';
$f_dir      = ($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';
$purge_message = '';
$purge_error   = '';

// --- Purge en masse (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $purge_date = trim($_POST['purge_before_date'] ?? '');
    $purge_token = trim($_POST['purge_token'] ?? '');

    if ($purge_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purge_date)) {
        $purge_error = 'Date invalide.';
    } elseif ($purge_token !== md5('purge_' . $purge_date . '_' . ($admin['id'] ?? 0))) {
        $purge_error = 'Jeton de securite invalide. Veuillez recharger la page.';
    } else {
        try {
            $db->beginTransaction();

            // Compter les incidents concernés
            $countStmt = $db->prepare('SELECT COUNT(*) FROM incidents WHERE created_at < ?');
            $countStmt->execute([$purge_date . ' 00:00:00']);
            $purge_count = (int)$countStmt->fetchColumn();

            if ($purge_count === 0) {
                $purge_error = 'Aucun dossier anterieur au ' . $purge_date . '.';
                $db->rollBack();
            } else {
                // Suppression en cascade
                $idStmt = $db->prepare('SELECT id FROM incidents WHERE created_at < ?');
                $idStmt->execute([$purge_date . ' 00:00:00']);
                $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $db->prepare("DELETE FROM photos WHERE incident_id IN ($placeholders)")->execute($ids);
                $db->prepare("DELETE FROM comments WHERE incident_id IN ($placeholders)")->execute($ids);
                if (admin_db_has_table($db, 'intervention_plans')) {
                    $db->prepare("DELETE FROM intervention_plans WHERE incident_id IN ($placeholders)")->execute($ids);
                }
                if (admin_db_has_table($db, 'votes')) {
                    $db->prepare("DELETE FROM votes WHERE incident_id IN ($placeholders)")->execute($ids);
                }
                $db->prepare("DELETE FROM incidents WHERE id IN ($placeholders)")->execute($ids);

                $db->commit();
                $purge_message = $purge_count . ' dossier(s) anterieur(s) au ' . $purge_date . ' supprime(s) definitivement.';
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            $purge_error = 'Erreur lors de la purge : ' . $e->getMessage();
        }
    }
}

// --- Comptage AJAX pour la purge ---
if (isset($_GET['ajax_purge_count']) && ($_GET['ajax_purge_count'] ?? '') !== '') {
    $cDate = trim($_GET['ajax_purge_count']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cDate)) {
        $cStmt = $db->prepare('SELECT COUNT(*) FROM incidents WHERE created_at < ?');
        $cStmt->execute([$cDate . ' 00:00:00']);
        header('Content-Type: application/json');
        echo json_encode(['count' => (int)$cStmt->fetchColumn(), 'token' => md5('purge_' . $cDate . '_' . ($admin['id'] ?? 0))]);
        exit;
    }
}

if ($agent_is_scoped && $f_service !== '' && !in_array((int)$f_service, $agent_service_scope_ids, true)) {
    $f_service = (string)($admin['primary_service_id'] ?? $agent_service_scope_ids[0] ?? '');
}

// Construction de la clause WHERE
$where  = ['1=1'];
$params = [];
if ($f_status)    { $where[] = 'i.status = ?';                                    $params[] = $f_status; }
if ($f_cat)       { $where[] = 'i.category_id = ?';                               $params[] = $f_cat; }
if ($f_service && $service_tables_ready) { $where[] = 'COALESCE(planned_service.id, resolved_service.id) = ?';   $params[] = $f_service; }
if ($f_plan && $planning_tables_ready) {
    if ($f_plan === 'unplanned') {
        $where[] = "((latest_plan.id IS NULL OR latest_plan.status = 'draft' OR latest_plan.scheduled_date IS NULL) AND i.status IN ('submitted', 'acknowledged', 'in_progress'))";
    } elseif ($f_plan === 'scheduled') {
        $where[] = "(latest_plan.status IN ('scheduled', 'rescheduled') AND latest_plan.scheduled_date IS NOT NULL AND latest_plan.scheduled_date >= CURDATE())";
    } elseif ($f_plan === 'overdue') {
        $where[] = "(latest_plan.status IN ('scheduled', 'rescheduled') AND latest_plan.scheduled_date IS NOT NULL AND latest_plan.scheduled_date < CURDATE())";
    } elseif ($f_plan === 'in_progress') {
        $where[] = "latest_plan.status = 'in_progress'";
    } elseif ($f_plan === 'completed') {
        $where[] = "(latest_plan.status = 'completed' OR i.status = 'resolved')";
    }
}
if ($f_executor && $planning_tables_ready) {
    if ($f_executor === 'internal') {
        $where[] = "(latest_plan.source_type = 'internal' OR latest_plan.source_type IS NULL)";
    } elseif ($f_executor === 'provider') {
        $where[] = "latest_plan.source_type = 'provider'";
    }
}
if ($f_proof === 'with_photo') {
    $where[] = 'EXISTS (SELECT 1 FROM photos ph WHERE ph.incident_id = i.id)';
}
if ($f_priority)  { $where[] = 'i.priority = ?';                                  $params[] = $f_priority; }
if ($f_date_from) { $where[] = 'DATE(i.created_at) >= ?';                         $params[] = $f_date_from; }
if ($f_date_to)   { $where[] = 'DATE(i.created_at) <= ?';                         $params[] = $f_date_to; }
if ($f_search)    {
    $where[] = '(i.reference LIKE ? OR i.title LIKE ? OR i.description LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
    $like = "%$f_search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($agent_is_scoped) {
    if (!empty($agent_service_scope_ids)) {
        $placeholders = implode(',', array_fill(0, count($agent_service_scope_ids), '?'));
        $where[] = "COALESCE(planned_service.id, resolved_service.id) IN ($placeholders)";
        foreach ($agent_service_scope_ids as $serviceId) {
            $params[] = $serviceId;
        }
        $scope_notice = $admin['primary_service_name']
            ? 'Votre file est limitee au service ' . $admin['primary_service_name'] . '.'
            : 'Votre file est limitee a vos services rattaches.';
    } else {
        $where[] = '1 = 0';
        $scope_notice = 'Aucun service ne vous est encore attribue. Cette file restera vide tant que le rattachement n est pas renseigne.';
    }
}
$where_sql = implode(' AND ', $where);

$service_join_sql = $service_tables_ready
    ? "
    LEFT JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
    LEFT JOIN services resolved_service ON resolved_service.id = scm.service_id
    " . ($planning_tables_ready ? "
    LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
        SELECT p2.id
        FROM intervention_plans p2
        WHERE p2.incident_id = i.id
        ORDER BY p2.created_at DESC, p2.id DESC
        LIMIT 1
    )
    LEFT JOIN services planned_service ON planned_service.id = latest_plan.service_id
    LEFT JOIN users plan_assignee ON plan_assignee.id = latest_plan.assigned_user_id
    " : "
    LEFT JOIN services planned_service ON 1 = 0
    LEFT JOIN users plan_assignee ON 1 = 0
    ")
    : '';

$service_name_select = $service_tables_ready
    ? 'COALESCE(planned_service.name, resolved_service.name, c.service) AS service_name'
    : 'c.service AS service_name';
$lead_photo_select = admin_incident_first_photo_select($db, 'i');

// Compter le total
$count_stmt = $db->prepare("
    SELECT COUNT(*)
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    $service_join_sql
    WHERE $where_sql
");
$count_stmt->execute($params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

// Récupérer les signalements
$sql = "
    SELECT i.id, i.reference, i.title, i.description, i.status, i.priority,
           i.votes_count, i.created_at, i.updated_at,
           c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           {$service_name_select},
           u.full_name AS reporter, u.email AS reporter_email,
           " . ($planning_tables_ready ? "
           latest_plan.id AS current_plan_id,
           latest_plan.status AS current_plan_status,
           latest_plan.scheduled_date AS current_plan_date,
           latest_plan.time_window_start AS current_plan_time_start,
           latest_plan.time_window_end AS current_plan_time_end,
           latest_plan.citizen_message AS current_plan_message,
           latest_plan.source_type AS current_plan_source_type,
           latest_plan.provider_name AS current_plan_provider_name,
           plan_assignee.full_name AS current_plan_assignee
           " : "
           NULL AS current_plan_id,
           NULL AS current_plan_status,
           NULL AS current_plan_date,
           NULL AS current_plan_time_start,
           NULL AS current_plan_time_end,
           NULL AS current_plan_message,
           NULL AS current_plan_source_type,
           NULL AS current_plan_provider_name,
           NULL AS current_plan_assignee
           ") . ",
           {$lead_photo_select},
           (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
           (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    $service_join_sql
    WHERE $where_sql
    ORDER BY i.$f_sort $f_dir
";

if (!$export_csv) {
    $sql .= " LIMIT $per_page OFFSET $offset";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($incidents as &$incident) {
    $incident['lead_photo'] = admin_incident_preview_photo($db, $incident);
    if ((int)($incident['photo_count'] ?? 0) === 0 && !empty($incident['lead_photo']['url'])) {
        $incident['photo_count'] = 1;
    }
}
unset($incident);

if ($f_proof === 'with_photo' && $total === 0) {
    $fallbackWhere = array_values(array_filter(
        $where,
        static fn(string $clause): bool => $clause !== 'EXISTS (SELECT 1 FROM photos ph WHERE ph.incident_id = i.id)'
    ));
    $fallbackWhereSql = implode(' AND ', $fallbackWhere);

    $fallbackSql = "
        SELECT i.id, i.reference, i.title, i.description, i.status, i.priority,
               i.votes_count, i.created_at, i.updated_at,
               c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
               {$service_name_select},
               u.full_name AS reporter, u.email AS reporter_email,
               " . ($planning_tables_ready ? "
               latest_plan.id AS current_plan_id,
               latest_plan.status AS current_plan_status,
               latest_plan.scheduled_date AS current_plan_date,
               latest_plan.time_window_start AS current_plan_time_start,
               latest_plan.time_window_end AS current_plan_time_end,
               latest_plan.citizen_message AS current_plan_message,
               latest_plan.source_type AS current_plan_source_type,
               latest_plan.provider_name AS current_plan_provider_name,
               plan_assignee.full_name AS current_plan_assignee
               " : "
               NULL AS current_plan_id,
               NULL AS current_plan_status,
               NULL AS current_plan_date,
               NULL AS current_plan_time_start,
               NULL AS current_plan_time_end,
               NULL AS current_plan_message,
               NULL AS current_plan_source_type,
               NULL AS current_plan_provider_name,
               NULL AS current_plan_assignee
               ") . ",
               {$lead_photo_select},
               (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
               (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count
        FROM incidents i
        JOIN categories c ON c.id = i.category_id
        JOIN users u ON u.id = i.user_id
        $service_join_sql
        WHERE $fallbackWhereSql
        ORDER BY i.$f_sort $f_dir
    ";

    $fallbackStmt = $db->prepare($fallbackSql);
    $fallbackStmt->execute($params);
    $fallbackIncidents = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fallbackIncidents as &$fallbackIncident) {
        $fallbackIncident['lead_photo'] = admin_incident_preview_photo($db, $fallbackIncident);
        if ((int)($fallbackIncident['photo_count'] ?? 0) === 0 && !empty($fallbackIncident['lead_photo']['url'])) {
            $fallbackIncident['photo_count'] = 1;
        }
    }
    unset($fallbackIncident);

    $fallbackIncidents = array_values(array_filter(
        $fallbackIncidents,
        static fn(array $incident): bool => !empty($incident['lead_photo']['url'])
    ));

    $total = count($fallbackIncidents);
    $total_pages = max(1, (int)ceil($total / $per_page));
    $incidents = $export_csv ? $fallbackIncidents : array_slice($fallbackIncidents, $offset, $per_page);
}

// --- Export CSV ---
if ($export_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="signalements_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Référence','Titre','Statut','Priorité','Catégorie','Votes','Citoyen','Email','Date'], ';');
    foreach ($incidents as $inc) {
        fputcsv($out, [
            $inc['reference'], $inc['title'] ?: substr($inc['description'],0,60),
            status_label($inc['status']), priority_label($inc['priority'] ?? 'medium'),
            $inc['cat_name'], $inc['votes_count'],
            $inc['reporter'], $inc['reporter_email'],
            format_date_short($inc['created_at']),
        ], ';');
    }
    fclose($out);
    exit;
}

// Catégories pour le filtre
$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$services = $service_tables_ready ? intervention_get_services($db) : [];
if ($agent_is_scoped && !empty($agent_service_scope_ids)) {
    $services = array_values(array_filter($services, static fn(array $service): bool => in_array((int)$service['id'], $agent_service_scope_ids, true)));
}

$proofScopeServiceIds = [];
if ($f_service !== '') {
    $proofScopeServiceIds = [(int)$f_service];
} elseif ($agent_is_scoped) {
    $proofScopeServiceIds = $agent_service_scope_ids;
}
$proofIncidents = admin_fetch_recent_proof_incidents($db, [
    'limit' => 4,
    'service_ids' => $proofScopeServiceIds,
    'only_open' => true,
]);

require_once __DIR__ . '/../includes/layout.php';

// Construire l'URL de base pour la pagination et le tri
$base_params = array_filter([
    'page'      => 'incidents',
    'status'    => $f_status,
    'cat'       => $f_cat,
    'service'   => $f_service,
    'plan'      => $f_plan,
    'executor'  => $f_executor,
    'proof'     => $f_proof,
    'priority'  => $f_priority,
    'q'         => $f_search,
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
    'sort'      => $f_sort,
    'dir'       => $f_dir,
]);
$base_url = '/admin/?' . http_build_query($base_params);
$proof_filter_params = $base_params;
$proof_filter_params['proof'] = 'with_photo';
$proof_filter_url = '/admin/?' . http_build_query($proof_filter_params);

// Helper tri
function sort_url(string $field, string $current_sort, string $current_dir, string $base): string {
    $new_dir = ($current_sort === $field && $current_dir === 'DESC') ? 'ASC' : 'DESC';
    return $base . '&sort=' . $field . '&dir=' . $new_dir;
}
function sort_icon(string $field, string $current_sort, string $current_dir): string {
    if ($current_sort !== $field) return '<span class="incidents-sort-icon">↕</span>';
    return $current_dir === 'DESC' ? '↓' : '↑';
}

function incident_plan_state(array $incident): array
{
    $status = (string)($incident['current_plan_status'] ?? '');
    $date = trim((string)($incident['current_plan_date'] ?? ''));
    $isOpenIncident = in_array((string)$incident['status'], ['submitted', 'acknowledged', 'in_progress'], true);
    $today = date('Y-m-d');

    if ($status === 'in_progress') {
        return ['label' => 'En intervention', 'class' => 'badge-blue'];
    }

    if (in_array($status, ['scheduled', 'rescheduled'], true) && $date !== '') {
        if ($date < $today) {
            return ['label' => 'En retard', 'class' => 'badge-red'];
        }

        return ['label' => 'Prévue', 'class' => 'badge-green'];
    }

    if ($status === 'completed' || (string)$incident['status'] === 'resolved') {
        return ['label' => 'Terminée', 'class' => 'badge-green'];
    }

    if ($status === 'cancelled') {
        return ['label' => 'Annulee', 'class' => 'badge-gray'];
    }

    if ($isOpenIncident) {
        return ['label' => 'A planifier', 'class' => 'badge-yellow'];
    }

    return ['label' => 'Sans plan', 'class' => 'badge-gray'];
}

function incident_plan_summary(array $incident): ?string
{
    $date = trim((string)($incident['current_plan_date'] ?? ''));
    $timeWindow = trim(implode(' - ', array_filter([
        $incident['current_plan_time_start'] ?? null,
        $incident['current_plan_time_end'] ?? null,
    ])));
    $assignee = trim((string)($incident['current_plan_assignee'] ?? ''));

    if ($date === '' && $assignee === '' && trim((string)($incident['current_plan_message'] ?? '')) === '') {
        return null;
    }

    $parts = [];
    if ($date !== '') {
        $parts[] = format_date_short($date);
    }
    if ($timeWindow !== '') {
        $parts[] = $timeWindow;
    }
    if ($assignee !== '') {
        $parts[] = $assignee;
    }
    $providerName = trim((string)($incident['current_plan_provider_name'] ?? ''));
    $sourceType = trim((string)($incident['current_plan_source_type'] ?? ''));
    if ($sourceType === 'provider' && $providerName !== '') {
        $parts[] = 'Prestataire : ' . $providerName;
    } elseif ($sourceType === 'internal') {
        $parts[] = 'Equipe interne';
    }

    if (!empty($parts)) {
        return implode(' · ', $parts);
    }

    $message = trim((string)($incident['current_plan_message'] ?? ''));
    return $message !== '' ? $message : null;
}

$active_filters = array_filter([$f_status, $f_cat, $f_service, $f_plan, $f_executor, $f_proof, $f_search, $f_priority, $f_date_from, $f_date_to]);
$open_count = 0;
$resolved_count = 0;
$plan_ready_count = 0;
$plan_overdue_count = 0;
$plan_unplanned_count = 0;
$plan_in_progress_count = 0;
$provider_count = 0;
$categoryHighlights = [];
foreach ($incidents as $inc) {
    if (in_array($inc['status'], ['submitted', 'acknowledged', 'in_progress'], true)) {
        $open_count++;
    }
    if ($inc['status'] === 'resolved') {
        $resolved_count++;
    }
    $planState = incident_plan_state($inc);
    if ($planState['label'] === 'Prévue') {
        $plan_ready_count++;
    } elseif ($planState['label'] === 'En retard') {
        $plan_overdue_count++;
    } elseif ($planState['label'] === 'A planifier') {
        $plan_unplanned_count++;
    } elseif ($planState['label'] === 'En intervention') {
        $plan_in_progress_count++;
    }
    if (($inc['current_plan_source_type'] ?? '') === 'provider') {
        $provider_count++;
    }

    $visual = category_visual_resolve($inc['cat_icon'] ?? 'road', $inc['cat_name'] ?? null);
    $key = ($visual['key'] ?? 'road') . '::' . ($inc['cat_name'] ?? '');
    if (!isset($categoryHighlights[$key])) {
        $categoryHighlights[$key] = [
            'key' => $visual['key'] ?? 'road',
            'name' => $inc['cat_name'] ?? ($visual['label'] ?? 'Categorie'),
            'short_label' => $visual['short_label'] ?? ($visual['label'] ?? 'Categorie'),
            'description' => $visual['description'] ?? '',
            'accent' => $visual['accent'] ?? ($inc['cat_color'] ?? ($themePalette['primary'] ?? '#355160')),
            'icon' => $inc['cat_icon'] ?? 'road',
            'count' => 0,
        ];
    }
    $categoryHighlights[$key]['count']++;
}
uasort($categoryHighlights, static function (array $a, array $b): int {
    return $b['count'] <=> $a['count'];
});
$categoryHighlights = array_slice(array_values($categoryHighlights), 0, 4);
$incidentsHeroPortraits = array_values(array_filter([
    [
        'asset' => visual_admin_slot_asset('incidents_primary', 'CHAR-05'),
        'label' => 'Arbitrage des signalements',
        'title' => 'Lecture rapide',
    ],
    [
        'asset' => visual_admin_slot_asset('incidents_secondary', 'CHAR-04'),
        'label' => 'Pilotage terrain',
        'title' => 'Ouverture dossier',
    ],
], static function (array $portrait): bool {
    return !empty($portrait['asset']) && generated_visual_url($portrait['asset']) !== null;
}));
?>

<div class="page-async-scope" data-async-scope="incidents-admin">
<div class="page-hero <?= !empty($incidentsHeroPortraits) ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">File de traitement</div>
    <h2 class="page-hero-title">Lire vite la pression terrain et ouvrir les bons dossiers.</h2>
    <?php if ($isTrainingMode): ?>
    <p class="page-hero-text">
      Cette vue doit aider a filtrer le bruit, faire ressortir les urgences et donner un point d entree direct vers l action utile.
    </p>
    <?php endif; ?>
    <div class="page-hero-actions">
      <a href="/admin/?page=incidents&export=csv" class="btn btn-outline btn-sm">Export CSV</a>
      <a href="<?= e($proof_filter_url) ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Dossiers avec preuves</a>
      <a href="/admin/?page=map" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir la carte</a>
      <a href="/admin/?page=dashboard" class="btn btn-primary btn-sm" data-async-link data-async-scope="admin-main">Retour cockpit</a>
    </div>

    <?php if ($purge_message): ?>
      <div class="alert alert-success" style="margin-top:12px; padding:10px 14px; border-radius:8px; font-size:13px;">✅ <?= e($purge_message) ?></div>
    <?php endif; ?>
    <?php if ($purge_error): ?>
      <div class="alert alert-danger" style="margin-top:12px; padding:10px 14px; border-radius:8px; font-size:13px;">❌ <?= e($purge_error) ?></div>
    <?php endif; ?>

    <div class="card" style="margin-top:12px; padding:14px 16px; border-radius:10px; border-left: 4px solid #dc3545;">
      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
        <strong style="font-size:13px; color:#dc3545;">🗑 Purge en masse</strong>
        <span class="text-muted text-small">Supprimer definitivement tous les dossiers anterieurs a une date.</span>
      </div>
      <form method="POST" id="purge-form" style="display:flex; align-items:center; gap:8px; margin-top:10px; flex-wrap:wrap;">
        <input type="hidden" name="action" value="bulk_delete">
        <input type="hidden" name="purge_token" id="purge-token" value="">
        <label for="purge-date" style="font-size:13px; white-space:nowrap;">Anterieurs au :</label>
        <input type="date" name="purge_before_date" id="purge-date" class="form-control" style="font-size:13px; padding:4px 8px; height:32px; max-width:160px;" required>
        <span id="purge-count-label" class="badge badge-gray" style="font-size:12px;">—</span>
        <a href="/admin/?page=incidents&export=csv" class="btn btn-outline btn-sm" style="font-size:12px; padding:3px 10px;">Exporter CSV d'abord</a>
        <button type="submit" id="purge-btn" class="btn btn-sm" disabled style="background:#dc3545; color:#fff; padding:4px 14px; font-size:12px; border:none; border-radius:6px; cursor:pointer;">Purger</button>
      </form>
      <script>
      (function(){
        var dateInput = document.getElementById('purge-date');
        var countLabel = document.getElementById('purge-count-label');
        var tokenInput = document.getElementById('purge-token');
        var purgeBtn = document.getElementById('purge-btn');
        var purgeForm = document.getElementById('purge-form');
        var debounceTimer;
        dateInput.addEventListener('change', function(){
          clearTimeout(debounceTimer);
          var d = dateInput.value;
          if (!d) { countLabel.textContent = '—'; purgeBtn.disabled = true; return; }
          countLabel.textContent = '...';
          debounceTimer = setTimeout(function(){
            fetch('/admin/?page=incidents&ajax_purge_count=' + encodeURIComponent(d))
              .then(function(r){ return r.json(); })
              .then(function(data){
                countLabel.textContent = data.count + ' dossier(s)';
                tokenInput.value = data.token;
                purgeBtn.disabled = data.count === 0;
              })
              .catch(function(){ countLabel.textContent = 'Erreur'; });
          }, 300);
        });
        purgeForm.addEventListener('submit', function(e){
          var count = countLabel.textContent;
          if (!confirm('⚠️ ATTENTION\n\nVous allez supprimer DEFINITIVEMENT ' + count + ' avec toutes leurs photos, commentaires et plans d\'intervention.\n\nCette action est IRREVERSIBLE.\n\nContinuer ?')) {
            e.preventDefault();
          }
        });
      })();
      </script>
    </div>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$total ?></span>
      <span class="hero-chip-label">dossiers visibles</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$open_count ?></span>
      <span class="hero-chip-label">encore ouverts sur cette vue</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($active_filters) ?></span>
      <span class="hero-chip-label">filtre(s) actifs</span>
    </div>
  </div>
  <?php if (!empty($incidentsHeroPortraits)): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <div class="dashboard-hero-portraits">
          <?php foreach ($incidentsHeroPortraits as $portrait): ?>
            <figure class="dashboard-hero-portrait-card">
              <?= generated_visual_html($portrait['asset'], ['class' => 'generated-visual generated-visual--portrait dashboard-hero-portrait', 'label' => $portrait['label']]) ?>
              <figcaption><?= e($portrait['title']) ?></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
        <?php if ($isTrainingMode): ?>
        <div class="generated-visual-caption">
          <strong>Pression terrain lisible</strong>
          <span>La file remet en avant le duo agents stylise pour lire les urgences et ouvrir le bon dossier.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- Filtres avancés v1.2 : Compact Toolbar -->
<div class="card incidents-filter-card" style="padding: 12px; margin-bottom: 16px; border-radius: 12px;">
  <?php if ($scope_notice): ?>
    <div class="alert alert-info incidents-scope-alert" style="padding: 8px 12px; font-size: 12px; margin-bottom: 12px;"><?= e($scope_notice) ?></div>
  <?php endif; ?>
  
  <form method="GET" action="" id="filter-form" data-async-form style="margin: 0;">
    <input type="hidden" name="page" value="incidents">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
      <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <strong style="font-size: 14px; margin-right: 4px; color: #1e293b;">File d'intervention</strong>
        <span class="badge badge-gray"><?= (int)count($active_filters ?? []) ?> filtre<?= count($active_filters ?? []) > 1 ? 's' : '' ?></span>
        <span class="badge badge-gray"><?= (int)$open_count ?> dossier<?= (int)$open_count > 1 ? 's' : '' ?></span>
        <?php if ((int)$plan_overdue_count > 0): ?>
          <span class="badge badge-danger-soft"><?= (int)$plan_overdue_count ?> retards urgents</span>
        <?php endif; ?>
      </div>
      <div style="display: flex; gap: 6px;">
        <button type="submit" class="btn btn-primary btn-sm" style="padding: 4px 12px;">Appliquer</button>
        <a href="/admin/?page=incidents" class="btn btn-outline btn-sm" data-async-link style="padding: 4px 12px;">Réinitialiser</a>
        <a href="<?= $base_url ?>&export=csv" class="btn btn-outline btn-sm" style="padding: 4px 12px;">Export CSV</a>
      </div>
    </div>

    <div class="incidents-filter-grid" style="gap: 8px; margin-bottom: 8px;">
      <input type="text" name="q" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px;"
             placeholder="Mot-clé, référence..." value="<?= e($f_search) ?>">
             
      <select name="status" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px;">
        <option value="">Tous les statuts</option>
        <option value="submitted"    <?= $f_status==='submitted'    ?'selected':'' ?>>Soumis</option>
        <option value="acknowledged" <?= $f_status==='acknowledged' ?'selected':'' ?>>Pris en charge</option>
        <option value="in_progress"  <?= $f_status==='in_progress'  ?'selected':'' ?>>En cours</option>
        <option value="resolved"     <?= $f_status==='resolved'     ?'selected':'' ?>>Résolus</option>
        <option value="rejected"     <?= $f_status==='rejected'     ?'selected':'' ?>>Rejetés</option>
      </select>
      
      <select name="cat" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px;">
        <option value="">Toutes les catégories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $f_cat==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      
      <?php if ($service_tables_ready): ?>
        <select name="service" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px;">
          <option value="">Tous les services</option>
          <?php foreach ($services as $service): ?>
            <option value="<?= (int)$service['id'] ?>" <?= (string)$f_service === (string)$service['id'] ? 'selected' : '' ?>>
              <?= e($service['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      
      <?php if ($planning_tables_ready): ?>
        <select name="plan" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px;">
          <option value="">Tous les plannings</option>
          <option value="unplanned" <?= $f_plan === 'unplanned' ? 'selected' : '' ?>>À planifier</option>
          <option value="scheduled" <?= $f_plan === 'scheduled' ? 'selected' : '' ?>>Prévues</option>
          <option value="overdue" <?= $f_plan === 'overdue' ? 'selected' : '' ?>>En retard</option>
          <option value="in_progress" <?= $f_plan === 'in_progress' ? 'selected' : '' ?>>En intervention</option>
          <option value="completed" <?= $f_plan === 'completed' ? 'selected' : '' ?>>Terminées</option>
        </select>
        <select name="executor" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px;">
          <option value="">Tous les intervenants</option>
          <option value="internal" <?= $f_executor === 'internal' ? 'selected' : '' ?>>Équipe interne</option>
          <option value="provider" <?= $f_executor === 'provider' ? 'selected' : '' ?>>Prestataire</option>
        </select>
      <?php endif; ?>
    </div>
    
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
      <select name="proof" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px; max-width: 180px;">
        <option value="">Toutes les preuves</option>
        <option value="with_photo" <?= $f_proof === 'with_photo' ? 'selected' : '' ?>>Accompagné d'une photo</option>
      </select>
      
      <select name="priority" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px; max-width: 150px;">
        <option value="">Toutes priorités</option>
        <option value="critical" <?= $f_priority==='critical'?'selected':'' ?>>Critique</option>
        <option value="high"     <?= $f_priority==='high'    ?'selected':'' ?>>Haute</option>
        <option value="medium"   <?= $f_priority==='medium'  ?'selected':'' ?>>Normale</option>
        <option value="low"      <?= $f_priority==='low'     ?'selected':'' ?>>Faible</option>
      </select>
      
      <input type="date" name="date_from" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px; max-width: 140px;"
             value="<?= e($f_date_from) ?>" title="Date de début">
      <input type="date" name="date_to" class="form-control" style="font-size:13px; padding: 4px 8px; height:32px; max-width: 140px;"
             value="<?= e($f_date_to) ?>" title="Date de fin">
    </div>
  </form>
</div>
  </form>
</div>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Lecture rapide</div>
    <h3>Commencer par ce qui doit bouger aujourd hui.</h3>
    <p>
      Priorite haute, statut encore ouvert et citoyen en attente de reponse visible : c est la combinaison la plus utile a ouvrir en premier.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Bonne pratique</div>
    <h3>Filtrer moins, ouvrir mieux.</h3>
    <p>
      L enjeu n est pas de parcourir toute la table. Le bon geste est de reduire la vue, ouvrir un dossier et documenter l action de facon lisible.
    </p>
  </div>
</div>

<?php if (!empty($categoryHighlights)): ?>
  <div class="admin-category-strip">
    <?php foreach ($categoryHighlights as $index => $highlight): ?>
      <div class="admin-category-pill <?= $index === 0 ? 'admin-category-pill--lead' : '' ?>" style="--category-accent:<?= e($highlight['accent']) ?>;">
        <?= category_visual_html($highlight['icon'], $highlight['name'], 'md', $highlight['accent']) ?>
        <div class="admin-category-pill-copy">
          <small><?= $index === 0 ? 'Categorie dominante' : 'Categorie suivie' ?></small>
          <strong><?= e($highlight['short_label']) ?></strong>
          <span><?= e($highlight['description']) ?></span>
        </div>
        <span class="admin-category-pill-count <?= $index === 0 ? 'admin-category-pill-count--wide' : '' ?>">
          <?= (int)$highlight['count'] ?> dossier<?= (int)$highlight['count'] > 1 ? 's' : '' ?>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($proofIncidents)): ?>
  <div class="card dashboard-section-card">
    <div class="card-header">
      <span class="card-title">Dernières preuves citoyennes de la file</span>
      <span class="text-muted text-small">Les derniers dossiers avec photo restent visibles ici, meme si la file courante est dominee par des signalements sans image.</span>
    </div>
    <div class="dashboard-proof-grid">
      <?php foreach ($proofIncidents as $proofIncident): ?>
        <a href="/admin/?page=incident_detail&id=<?= (int)$proofIncident['id'] ?>" class="dashboard-proof-card" data-async-link data-async-scope="admin-main">
          <span class="dashboard-proof-card-media">
            <?php if (!empty($proofIncident['lead_photo']['url'])): ?>
              <img src="<?= e($proofIncident['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="dashboard-proof-card-image">
            <?php else: ?>
              <span class="dashboard-proof-card-empty">Aucune photo</span>
            <?php endif; ?>
          </span>
          <span class="dashboard-proof-card-copy">
            <span class="dashboard-proof-card-topline">
              <?= category_visual_html($proofIncident['cat_icon'] ?? 'road', $proofIncident['cat_name'], 'sm', $proofIncident['cat_color'] ?? null) ?>
              <span class="dashboard-proof-card-meta">
                <strong><?= e($proofIncident['cat_name']) ?></strong>
                <span><?= e($proofIncident['reference']) ?> · <?= e($proofIncident['reporter']) ?></span>
              </span>
            </span>
            <span class="dashboard-proof-card-description"><?= e($proofIncident['title'] ?: $proofIncident['description']) ?></span>
            <span class="dashboard-proof-card-foot">
              <?php if (!empty($proofIncident['service_name'])): ?>
                <span class="badge badge-gray"><?= e($proofIncident['service_name']) ?></span>
              <?php endif; ?>
              <span class="badge badge-gray"><?= (int)$proofIncident['photo_count'] ?> photo<?= (int)$proofIncident['photo_count'] > 1 ? 's' : '' ?></span>
              <?php if ((int)$proofIncident['comment_count'] > 0): ?>
                <span class="badge badge-gray"><?= (int)$proofIncident['comment_count'] ?> commentaire<?= (int)$proofIncident['comment_count'] > 1 ? 's' : '' ?></span>
              <?php endif; ?>
              <span class="badge <?= status_class($proofIncident['status']) ?>"><?= status_label($proofIncident['status']) ?></span>
            </span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card dashboard-section-card">
  <div class="card-header">
    <span class="card-title">Lecture d execution</span>
    <span class="text-muted text-small">Utiliser d abord cette vue pour ouvrir les dossiers qui doivent passer a l action.</span>
  </div>
  <div class="services-mode-band services-mode-band--tight">
    <div class="services-mode-card">
      <strong><?= (int)$plan_unplanned_count ?></strong>
      <span>a planifier</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$plan_ready_count ?></strong>
      <span>prevues</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$plan_overdue_count ?></strong>
      <span>en retard</span>
    </div>
  </div>
  <div class="services-mode-band services-mode-band--spaced">
    <div class="services-mode-card">
      <strong><?= (int)$plan_in_progress_count ?></strong>
      <span>en intervention</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$provider_count ?></strong>
      <span>prestataire</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$open_count ?></strong>
      <span>dossiers ouverts</span>
    </div>
  </div>
</div>

<!-- Tableau -->
<div class="card">
  <div class="card-header">
    <div>
      <span class="card-title">
        <?= $total ?> signalement<?= $total > 1 ? 's' : '' ?>
        <?php if ($f_status || $f_cat || $f_service || $f_plan || $f_executor || $f_search || $f_priority || $f_date_from || $f_date_to): ?>
        <span class="badge badge-blue incidents-filtered-badge">Filtré</span>
        <?php endif; ?>
      </span>
      <p class="incidents-table-lead">
        La table sert a arbitrer une file deja reduite. Ouvrir d abord les dossiers a forte priorite, avec preuve visible ou intervention en retard.
      </p>
    </div>
    <span class="text-muted text-small">
      <?= $resolved_count ?> resolu<?= $resolved_count > 1 ? 's' : '' ?> · <?= $open_count ?> encore ouvert<?= $open_count > 1 ? 's' : '' ?>
    </span>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th><a href="<?= sort_url('created_at', $f_sort, $f_dir, $base_url) ?>" class="incidents-sort-link" data-async-link>
            Dossier & Catégorie <?= sort_icon('created_at', $f_sort, $f_dir) ?>
          </a></th>
          <th>Titre / Description</th>
          <th>Service</th>
          <th>Intervention</th>
          <th>État & Priorité</th>
          <th><a href="<?= sort_url('votes_count', $f_sort, $f_dir, $base_url) ?>" class="incidents-sort-link" data-async-link>
            Soutien <?= sort_icon('votes_count', $f_sort, $f_dir) ?>
          </a></th>
          <th>Citoyen</th>
          <th>Preuves</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidents as $inc): ?>
        <?php $planState = incident_plan_state($inc); ?>
        <?php $planSummary = incident_plan_summary($inc); ?>
        <tr>
          <td style="min-width: 170px;">
            <div class="admin-category-cell" style="--category-accent:<?= e($inc['cat_color'] ?: ($themePalette['primary'] ?? '#355160')) ?>; margin-bottom: 6px;">
              <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'sm', $inc['cat_color'] ?? null) ?>
              <div class="admin-category-cell-copy">
                <span class="incidents-row-title" style="white-space: normal; line-height: 1.2;"><?= e($inc['cat_name']) ?></span>
              </div>
            </div>
            <div class="text-muted text-small incidents-nowrap">
              <code class="incidents-ref" style="display:inline-block; margin-right: 4px;"><?= e($inc['reference']) ?></code>
              <?= format_date_short($inc['created_at']) ?>
            </div>
          </td>
          <td>
            <div class="incidents-row-title">
              <?= e($inc['title'] ?: 'Sans titre') ?>
            </div>
            <div class="text-muted text-small truncate-3-lines incidents-row-description" title="<?= e($inc['description']) ?>">
              <?= e($inc['description']) ?>
            </div>
          </td>
          <td class="text-muted text-small"><?= e($inc['service_name'] ?: '—') ?></td>
          <td>
            <span class="badge <?= e($planState['class']) ?>"><?= e($planState['label']) ?></span>
            <?php if ($planSummary): ?>
              <div class="text-muted text-small incidents-plan-meta">
                <?= e($planSummary) ?>
              </div>
            <?php endif; ?>
            <?php if (($inc['current_plan_source_type'] ?? '') === 'provider' && !empty($inc['current_plan_provider_name'])): ?>
              <div class="text-small incidents-plan-provider">
                Prestataire missionne
              </div>
            <?php endif; ?>
          </td>
          <td>
            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
              <span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span>
              <span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span>
            </div>
          </td>
          <td class="text-center">
            <?php if ($inc['votes_count'] > 0): ?>
              <span class="incidents-vote-copy"><?= $inc['votes_count'] ?> soutien<?= $inc['votes_count'] > 1 ? 's' : '' ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="incidents-row-title"><?= e($inc['reporter']) ?></div>
            <div class="text-muted text-small truncate" style="max-width: 140px;" title="<?= e($inc['reporter_email']) ?>"><?= e($inc['reporter_email']) ?></div>
          </td>
          <td>
            <div class="admin-proof-cell">
              <?php if (!empty($inc['lead_photo']['url'])): ?>
                <a href="/admin/?page=incident_detail&id=<?= (int)$inc['id'] ?>" class="admin-proof-thumb-link js-insta-preview" data-id="<?= (int)$inc['id'] ?>" data-async-link data-async-scope="admin-main" aria-label="Previsualiser la preuve citoyenne" title="Ouvrir la fenetre preuve">
                  <span class="admin-proof-thumb-wrap">
                    <img src="<?= e($inc['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb">
                    <?php if ((int)$inc['photo_count'] > 1): ?>
                      <span class="admin-proof-thumb-badge">+<?= (int)$inc['photo_count'] - 1 ?></span>
                    <?php endif; ?>
                  </span>
                </a>
              <?php else: ?>
                <div class="admin-proof-thumb admin-proof-thumb--empty">Aucune</div>
              <?php endif; ?>
              <div class="admin-proof-copy">
                <div class="admin-proof-counts">
                  <?php if ((int)$inc['photo_count'] > 0): ?>
                    <span class="badge badge-gray"><?= (int)$inc['photo_count'] ?> photo<?= (int)$inc['photo_count'] > 1 ? 's' : '' ?></span>
                  <?php endif; ?>
                  <?php if ((int)$inc['comment_count'] > 0): ?>
                    <span class="badge badge-gray"><?= (int)$inc['comment_count'] ?> doc<?= (int)$inc['comment_count'] > 1 ? 's' : '' ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!empty($inc['lead_photo']['moderation_message'])): ?>
                  <div class="admin-proof-note"><?= e($inc['lead_photo']['moderation_message']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-primary btn-sm" data-async-link data-async-scope="admin-main">Traiter</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($incidents)): ?>
        <tr><td colspan="12" class="text-center text-muted incidents-empty-row">
          <?= $f_search ? "Aucun résultat pour \"" . e($f_search) . "\"." : 'Aucun signalement trouvé.' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination incidents-pagination">
    <a href="<?= $base_url ?>&p=<?= max(1, $page_num-1) ?>"
       data-async-link
       class="page-btn <?= $page_num <= 1 ? 'disabled' : '' ?>">← Préc.</a>
    <?php for ($i = max(1,$page_num-2); $i <= min($total_pages, $page_num+2); $i++): ?>
      <a href="<?= $base_url ?>&p=<?= $i ?>"
         data-async-link
         class="page-btn <?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="<?= $base_url ?>&p=<?= min($total_pages, $page_num+1) ?>"
       data-async-link
       class="page-btn <?= $page_num >= $total_pages ? 'disabled' : '' ?>">Suiv. →</a>
    <span class="text-muted text-small incidents-pagination-summary">
      Page <?= $page_num ?> / <?= $total_pages ?> (<?= $total ?> résultats)
    </span>
  </div>
  <?php endif; ?>
</div>

</div>

<?php require_once __DIR__ . '/../includes/insta_preview.php'; ?>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
