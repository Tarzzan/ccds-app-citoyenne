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
$f_search   = trim($_GET['q']    ?? '');
$f_priority = $_GET['priority']  ?? '';
$f_date_from= trim($_GET['date_from'] ?? '');
$f_date_to  = trim($_GET['date_to']   ?? '');
$f_sort     = in_array($_GET['sort'] ?? '', ['votes_count','updated_at','created_at']) ? $_GET['sort'] : 'created_at';
$f_dir      = ($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

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
           COALESCE(planned_service.name, resolved_service.name, c.service) AS service_name,
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

require_once __DIR__ . '/../includes/layout.php';

// Construire l'URL de base pour la pagination et le tri
$base_params = array_filter([
    'page'      => 'incidents',
    'status'    => $f_status,
    'cat'       => $f_cat,
    'service'   => $f_service,
    'plan'      => $f_plan,
    'executor'  => $f_executor,
    'priority'  => $f_priority,
    'q'         => $f_search,
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
    'sort'      => $f_sort,
    'dir'       => $f_dir,
]);
$base_url = '/admin/?' . http_build_query($base_params);

// Helper tri
function sort_url(string $field, string $current_sort, string $current_dir, string $base): string {
    $new_dir = ($current_sort === $field && $current_dir === 'DESC') ? 'ASC' : 'DESC';
    return $base . '&sort=' . $field . '&dir=' . $new_dir;
}
function sort_icon(string $field, string $current_sort, string $current_dir): string {
    if ($current_sort !== $field) return '<span style="opacity:.3">↕</span>';
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

$active_filters = array_filter([$f_status, $f_cat, $f_service, $f_plan, $f_executor, $f_search, $f_priority, $f_date_from, $f_date_to]);
$open_count = 0;
$resolved_count = 0;
foreach ($incidents as $inc) {
    if (in_array($inc['status'], ['submitted', 'acknowledged', 'in_progress'], true)) {
        $open_count++;
    }
    if ($inc['status'] === 'resolved') {
        $resolved_count++;
    }
}
?>

<div class="page-hero">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">File de traitement</div>
    <h2 class="page-hero-title">Lire vite la pression terrain et ouvrir les bons dossiers.</h2>
    <p class="page-hero-text">
      Cette vue doit aider a filtrer le bruit, faire ressortir les urgences et donner un point d entree direct vers l action utile.
    </p>
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
</div>

<!-- Filtres avancés v1.2 -->
<div class="card" style="padding:16px 24px;margin-bottom:16px;">
  <?php if ($scope_notice): ?>
    <div class="alert alert-info" style="margin-bottom:12px"><?= e($scope_notice) ?></div>
  <?php endif; ?>
  <form method="GET" action="" id="filter-form">
    <input type="hidden" name="page" value="incidents">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
      <input type="text" name="q" class="form-control"
             placeholder="🔍 Réf, titre, description, citoyen…"
             value="<?= e($f_search) ?>" style="grid-column:span 2">
      <select name="status" class="form-control">
        <option value="">Tous les statuts</option>
        <option value="submitted"    <?= $f_status==='submitted'    ?'selected':'' ?>>Soumis</option>
        <option value="acknowledged" <?= $f_status==='acknowledged' ?'selected':'' ?>>Pris en charge</option>
        <option value="in_progress"  <?= $f_status==='in_progress'  ?'selected':'' ?>>En cours</option>
        <option value="resolved"     <?= $f_status==='resolved'     ?'selected':'' ?>>Résolus</option>
        <option value="rejected"     <?= $f_status==='rejected'     ?'selected':'' ?>>Rejetés</option>
      </select>
      <select name="cat" class="form-control">
        <option value="">Toutes les catégories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= $cat['id'] ?>" <?= $f_cat==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($service_tables_ready): ?>
        <select name="service" class="form-control">
          <option value="">Tous les services</option>
          <?php foreach ($services as $service): ?>
            <option value="<?= (int)$service['id'] ?>" <?= (string)$f_service === (string)$service['id'] ? 'selected' : '' ?>>
              <?= e($service['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?php if ($planning_tables_ready): ?>
        <select name="plan" class="form-control">
          <option value="">Toutes les interventions</option>
          <option value="unplanned" <?= $f_plan === 'unplanned' ? 'selected' : '' ?>>A planifier</option>
          <option value="scheduled" <?= $f_plan === 'scheduled' ? 'selected' : '' ?>>Prevues</option>
          <option value="overdue" <?= $f_plan === 'overdue' ? 'selected' : '' ?>>En retard</option>
          <option value="in_progress" <?= $f_plan === 'in_progress' ? 'selected' : '' ?>>En intervention</option>
          <option value="completed" <?= $f_plan === 'completed' ? 'selected' : '' ?>>Terminees</option>
        </select>
        <select name="executor" class="form-control">
          <option value="">Tous les intervenants</option>
          <option value="internal" <?= $f_executor === 'internal' ? 'selected' : '' ?>>Equipe interne</option>
          <option value="provider" <?= $f_executor === 'provider' ? 'selected' : '' ?>>Prestataire missionne</option>
        </select>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <select name="priority" class="form-control" style="width:160px">
        <option value="">Toutes priorités</option>
        <option value="critical" <?= $f_priority==='critical'?'selected':'' ?>>🔴 Critique</option>
        <option value="high"     <?= $f_priority==='high'    ?'selected':'' ?>>🟠 Haute</option>
        <option value="medium"   <?= $f_priority==='medium'  ?'selected':'' ?>>🟡 Normale</option>
        <option value="low"      <?= $f_priority==='low'     ?'selected':'' ?>>🟢 Faible</option>
      </select>
      <input type="date" name="date_from" class="form-control" style="width:150px"
             value="<?= e($f_date_from) ?>" title="Date de début">
      <input type="date" name="date_to" class="form-control" style="width:150px"
             value="<?= e($f_date_to) ?>" title="Date de fin">
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <a href="/admin/?page=incidents" class="btn btn-outline">Réinitialiser</a>
      <a href="<?= $base_url ?>&export=csv" class="btn btn-outline" style="margin-left:auto">
        📥 Export CSV
      </a>
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

<!-- Tableau -->
<div class="card">
  <div class="card-header">
    <span class="card-title">
      <?= $total ?> signalement<?= $total > 1 ? 's' : '' ?>
      <?php if ($f_status || $f_cat || $f_service || $f_plan || $f_executor || $f_search || $f_priority || $f_date_from || $f_date_to): ?>
        <span class="badge badge-blue" style="margin-left:8px">Filtré</span>
      <?php endif; ?>
    </span>
    <span class="text-muted text-small">
      <?= $resolved_count ?> resolu<?= $resolved_count > 1 ? 's' : '' ?> · <?= $open_count ?> encore ouvert<?= $open_count > 1 ? 's' : '' ?>
    </span>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th><a href="<?= sort_url('created_at', $f_sort, $f_dir, $base_url) ?>" style="color:inherit;text-decoration:none">
            Date <?= sort_icon('created_at', $f_sort, $f_dir) ?>
          </a></th>
          <th>Référence</th>
          <th>Titre / Description</th>
          <th>Catégorie</th>
          <th>Service</th>
          <th>Intervention</th>
          <th>Statut</th>
          <th>Priorité</th>
          <th><a href="<?= sort_url('votes_count', $f_sort, $f_dir, $base_url) ?>" style="color:inherit;text-decoration:none">
            👍 <?= sort_icon('votes_count', $f_sort, $f_dir) ?>
          </a></th>
          <th>Citoyen</th>
          <th>📷 💬</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidents as $inc): ?>
        <?php $planState = incident_plan_state($inc); ?>
        <?php $planSummary = incident_plan_summary($inc); ?>
        <tr>
          <td class="text-muted text-small" style="white-space:nowrap"><?= format_date_short($inc['created_at']) ?></td>
          <td><code style="font-size:11px"><?= e($inc['reference']) ?></code></td>
          <td>
            <div style="font-weight:600;font-size:13px;margin-bottom:2px">
              <?= e($inc['title'] ?: 'Sans titre') ?>
            </div>
            <div class="text-muted text-small truncate" title="<?= e($inc['description']) ?>" style="max-width:220px">
              <?= e($inc['description']) ?>
            </div>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'sm', $inc['cat_color'] ?? null) ?>
              <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
                <?= e($inc['cat_name']) ?>
              </span>
            </div>
          </td>
          <td class="text-muted text-small"><?= e($inc['service_name'] ?: '—') ?></td>
          <td>
            <span class="badge <?= e($planState['class']) ?>"><?= e($planState['label']) ?></span>
            <?php if ($planSummary): ?>
              <div class="text-muted text-small" style="margin-top:4px;max-width:180px">
                <?= e($planSummary) ?>
              </div>
            <?php endif; ?>
            <?php if (($inc['current_plan_source_type'] ?? '') === 'provider' && !empty($inc['current_plan_provider_name'])): ?>
              <div class="text-small" style="margin-top:4px;color:#7c3aed;font-weight:700;max-width:180px">
                Prestataire missionne
              </div>
            <?php endif; ?>
          </td>
          <td><span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span></td>
          <td><span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span></td>
          <td class="text-center">
            <?php if ($inc['votes_count'] > 0): ?>
              <span style="color:#f59e0b;font-weight:700">👍 <?= $inc['votes_count'] ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:13px"><?= e($inc['reporter']) ?></div>
            <div class="text-muted text-small"><?= e($inc['reporter_email']) ?></div>
          </td>
          <td class="text-center text-small">
            <?= $inc['photo_count'] > 0 ? '📷 '.$inc['photo_count'] : '' ?>
            <?= $inc['comment_count'] > 0 ? ' 💬 '.$inc['comment_count'] : '' ?>
            <?= $inc['photo_count'] == 0 && $inc['comment_count'] == 0 ? '<span class="text-muted">—</span>' : '' ?>
          </td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-primary btn-sm">Traiter</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($incidents)): ?>
        <tr><td colspan="12" class="text-center text-muted" style="padding:40px">
          <?= $f_search ? "Aucun résultat pour \"" . e($f_search) . "\"." : 'Aucun signalement trouvé.' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination" style="padding:16px 0 0;">
    <a href="<?= $base_url ?>&p=<?= max(1, $page_num-1) ?>"
       class="page-btn <?= $page_num <= 1 ? 'disabled' : '' ?>">← Préc.</a>
    <?php for ($i = max(1,$page_num-2); $i <= min($total_pages, $page_num+2); $i++): ?>
      <a href="<?= $base_url ?>&p=<?= $i ?>"
         class="page-btn <?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="<?= $base_url ?>&p=<?= min($total_pages, $page_num+1) ?>"
       class="page-btn <?= $page_num >= $total_pages ? 'disabled' : '' ?>">Suiv. →</a>
    <span class="text-muted text-small" style="margin-left:8px">
      Page <?= $page_num ?> / <?= $total_pages ?> (<?= $total ?> résultats)
    </span>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
