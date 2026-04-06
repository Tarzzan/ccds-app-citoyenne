<?php
/**
 * Ma Commune v1.2 — Statistiques & analytiques enrichis (ADMIN-01)
 * Nouveaux KPIs : votes, citoyens actifs, délai médian.
 * Nouveaux graphiques : carte de chaleur horaire, tendance résolus vs soumis.
 * Export CSV des données de la période.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Statistiques';
$active_nav = 'stats';
$themePalette = visual_admin_data_palette();
$statusPalette = visual_admin_status_palette();

$db = Database::getInstance();
$service_tables_ready = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'intervention_plans');
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;
$incident_scope_join = '';
$incident_scope_where = '';
$execution_scope_where = '';
$lead_photo_select = admin_incident_first_photo_select($db, 'i');

if ($agent_is_scoped) {
    if (!empty($agent_service_scope_ids)) {
        $safeServiceIds = implode(',', array_map('intval', $agent_service_scope_ids));
        $incident_scope_join = "
            JOIN categories scoped_category ON scoped_category.id = incidents.category_id
            JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = scoped_category.id AND scoped_service_map.is_default = 1
        ";
        $incident_scope_where = " AND scoped_service_map.service_id IN ($safeServiceIds)";
        $execution_scope_where = " AND COALESCE(latest_plan.service_id, scoped_service_map.service_id) IN ($safeServiceIds)";
        $scope_notice = $admin['primary_service_name']
            ? 'Les statistiques sont limitees au service ' . $admin['primary_service_name'] . '.'
            : 'Les statistiques sont limitees a vos services rattaches.';
    } else {
        $incident_scope_where = ' AND 1 = 0';
        $execution_scope_where = ' AND 1 = 0';
        $scope_notice = 'Aucun service ne vous est encore attribue. Les statistiques resteront vides tant que le rattachement n est pas renseigne.';
    }
}

// --- Période ---
$period = (int)($_GET['period'] ?? 30);
if (!in_array($period, [7, 30, 90, 365])) $period = 30;

// --- Export CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->prepare("
        SELECT i.reference, i.title, i.description, i.status, i.priority,
               i.votes_count, i.created_at, i.updated_at,
               c.name AS category, u.full_name AS reporter, u.email AS reporter_email
        FROM incidents i
        JOIN categories c ON c.id = i.category_id
        JOIN users u ON u.id = i.user_id
        " . ($agent_is_scoped
            ? "JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = c.id AND scoped_service_map.is_default = 1"
            : '') . "
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        " . ($agent_is_scoped ? "AND scoped_service_map.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : '') . "
        ORDER BY i.created_at DESC
    ");
    $rows->execute([$period]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stats_' . date('Y-m-d') . '_' . $period . 'j.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Référence','Titre','Statut','Priorité','Catégorie','Votes','Citoyen','Email','Créé le','Mis à jour'], ';');
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, array_values($r), ';');
    }
    fclose($out);
    exit;
}

// --- KPIs principaux ---
$kpis = $db->prepare("
    SELECT
        COUNT(*)                                AS total,
        SUM(incidents.status = 'resolved')                AS resolved,
        SUM(incidents.status = 'rejected')                AS rejected,
        SUM(incidents.status IN ('submitted','acknowledged','in_progress')) AS open,
        AVG(CASE WHEN incidents.status = 'resolved' AND incidents.updated_at IS NOT NULL
                 THEN TIMESTAMPDIFF(HOUR, incidents.created_at, incidents.updated_at) END) AS avg_resolution_hours,
        SUM(incidents.votes_count)                        AS total_votes
    FROM incidents
    $incident_scope_join
    WHERE incidents.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    $incident_scope_where
");
$kpis->execute([$period]);
$kpis = $kpis->fetch(PDO::FETCH_ASSOC);

$resolution_rate = $kpis['total'] > 0
    ? round($kpis['resolved'] / $kpis['total'] * 100, 1)
    : 0;

// Citoyens actifs (ayant soumis au moins 1 signalement dans la période)
$active_citizens = $db->prepare("
    SELECT COUNT(DISTINCT user_id) FROM incidents
    $incident_scope_join
    WHERE incidents.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    $incident_scope_where
");
$active_citizens->execute([$period]);
$active_citizens = (int)$active_citizens->fetchColumn();

// --- Évolution quotidienne (soumis vs résolus) ---
$evolution = $db->prepare("
    SELECT
        DATE(incidents.created_at) AS day,
        COUNT(*) AS submitted,
        SUM(incidents.status = 'resolved') AS resolved
    FROM incidents
    $incident_scope_join
    WHERE incidents.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    $incident_scope_where
    GROUP BY DATE(incidents.created_at)
    ORDER BY day ASC
");
$evolution->execute([$period]);
$evolution = $evolution->fetchAll(PDO::FETCH_ASSOC);

// --- Par statut ---
$by_status = $db->query("
    SELECT incidents.status, COUNT(*) AS cnt
    FROM incidents
    $incident_scope_join
    WHERE 1 = 1
    $incident_scope_where
    GROUP BY incidents.status
")->fetchAll(PDO::FETCH_ASSOC);

// --- Par catégorie (période) ---
$by_cat = $db->prepare("
    SELECT c.name, c.color, c.icon, COUNT(i.id) AS cnt, COALESCE(SUM(i.votes_count),0) AS votes
    FROM categories c
    " . ($agent_is_scoped
        ? "JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = c.id AND scoped_service_map.is_default = 1"
        : '') . "
    LEFT JOIN incidents i ON i.category_id = c.id
        AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    " . ($agent_is_scoped
        ? "AND scoped_service_map.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")"
        : '') . "
    GROUP BY c.id
    ORDER BY cnt DESC
");
$by_cat->execute([$period]);
$by_cat = $by_cat->fetchAll(PDO::FETCH_ASSOC);
foreach ($by_cat as &$cat) {
    $visual = category_visual_resolve($cat['icon'] ?? null, $cat['name'] ?? null);
    $cat['visual_description'] = $visual['description'] ?? '';
}
unset($cat);

// --- Top 5 zones ---
$top_zones = $db->prepare("
    SELECT address, COUNT(*) AS cnt, SUM(votes_count) AS votes
    FROM incidents
    $incident_scope_join
    WHERE incidents.address IS NOT NULL AND incidents.address != ''
      AND incidents.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      $incident_scope_where
    GROUP BY address
    ORDER BY cnt DESC
    LIMIT 5
");
$top_zones->execute([$period]);
$top_zones = $top_zones->fetchAll(PDO::FETCH_ASSOC);

// --- Activité par jour de la semaine ---
$by_weekday = $db->prepare("
    SELECT DAYOFWEEK(incidents.created_at) AS dow, COUNT(*) AS cnt
    FROM incidents
    $incident_scope_join
    WHERE incidents.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    $incident_scope_where
    GROUP BY DAYOFWEEK(incidents.created_at)
    ORDER BY dow ASC
");
$by_weekday->execute([$period]);
$by_weekday_raw = $by_weekday->fetchAll(PDO::FETCH_KEY_PAIR);
$weekdays = ['', 'Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$by_weekday_data = [];
for ($d = 1; $d <= 7; $d++) {
    $by_weekday_data[] = ['day' => $weekdays[$d], 'cnt' => $by_weekday_raw[$d] ?? 0];
}

// --- Carte de chaleur horaire (24h × 7j) ---
$heatmap_raw = $db->prepare("
    SELECT HOUR(incidents.created_at) AS h, DAYOFWEEK(incidents.created_at) AS dow, COUNT(*) AS cnt
    FROM incidents
    $incident_scope_join
    WHERE incidents.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    $incident_scope_where
    GROUP BY HOUR(incidents.created_at), DAYOFWEEK(incidents.created_at)
");
$heatmap_raw->execute([$period]);
$heatmap = array_fill(0, 7, array_fill(0, 24, 0));
foreach ($heatmap_raw->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $heatmap[$row['dow'] - 1][$row['h']] = (int)$row['cnt'];
}

// --- Top 5 signalements les plus votés ---
$top_voted = $db->prepare("
    SELECT i.reference, i.title, i.description, i.votes_count, i.status,
           c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           {$lead_photo_select}
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    " . ($agent_is_scoped
        ? "JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = c.id AND scoped_service_map.is_default = 1"
        : '') . "
    WHERE i.votes_count > 0
      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      " . ($agent_is_scoped
        ? "AND scoped_service_map.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")"
        : '') . "
    ORDER BY i.votes_count DESC
    LIMIT 5
");
$top_voted->execute([$period]);
$top_voted = $top_voted->fetchAll(PDO::FETCH_ASSOC);
foreach ($top_voted as &$topIncident) {
    $visual = category_visual_resolve($topIncident['cat_icon'] ?? null, $topIncident['cat_name'] ?? null);
    $topIncident['cat_description'] = $visual['description'] ?? '';
    $topIncident['lead_photo'] = admin_incident_preview_photo($db, $topIncident);
}
unset($topIncident);

$execution_summary = null;
if ($service_tables_ready) {
    $executionSummaryStmt = $db->prepare("
        SELECT
            SUM(CASE WHEN i.status IN ('submitted','acknowledged','in_progress') AND latest_plan.id IS NOT NULL
                AND COALESCE(latest_plan.status, '') NOT IN ('completed', 'cancelled')
                AND COALESCE(latest_plan.source_type, 'internal') = 'internal' THEN 1 ELSE 0 END) AS internal_count,
            SUM(CASE WHEN i.status IN ('submitted','acknowledged','in_progress') AND latest_plan.id IS NOT NULL
                AND COALESCE(latest_plan.status, '') NOT IN ('completed', 'cancelled')
                AND latest_plan.source_type = 'provider' THEN 1 ELSE 0 END) AS provider_count,
            SUM(CASE WHEN i.status IN ('submitted','acknowledged','in_progress') AND latest_plan.id IS NULL THEN 1 ELSE 0 END) AS unplanned_count
        FROM incidents i
        JOIN categories scoped_category ON scoped_category.id = i.category_id
        LEFT JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = scoped_category.id AND scoped_service_map.is_default = 1
        LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
            SELECT p2.id
            FROM intervention_plans p2
            WHERE p2.incident_id = i.id
            ORDER BY p2.created_at DESC, p2.id DESC
            LIMIT 1
        )
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        $execution_scope_where
    ");
    $executionSummaryStmt->execute([$period]);
    $execution_summary = $executionSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$statsHeroSceneAsset = visual_admin_slot_asset('stats_scene', 'ILL-05') ?? visual_admin_slot_asset('stats_scene', 'ILL-01');
$statsHeroInsetAsset = visual_admin_slot_asset('stats_inset', 'ILL-02') ?? visual_admin_slot_asset('stats_inset', 'ILL-03');
$statsHeroAgentAsset = visual_admin_slot_asset('stats_agent', 'CHAR-04') ?? visual_admin_slot_asset('stats_agent', 'CHAR-05');
$statsHeroHasVisual = (bool)($statsHeroSceneAsset || $statsHeroInsetAsset || $statsHeroAgentAsset);

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="stats-admin">

<?php if ($scope_notice): ?>
  <div class="alert alert-info stats-scope-alert"><?= e($scope_notice) ?></div>
<?php endif; ?>

<div class="page-hero <?= $statsHeroHasVisual ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Lecture analytique</div>
    <h2 class="page-hero-title">Mesurer la charge, la résolution et l’engagement citoyen.</h2>
    <p class="page-hero-text">
      Cette vue consolide les volumes, la dynamique de résolution, les catégories dominantes et les zones de pression afin d’aider le pilotage local à arbitrer plus vite.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$period ?></span>
      <span class="hero-chip-label">jours analysés</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['total'] ?></span>
      <span class="hero-chip-label">signalements sur la fenêtre</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $resolution_rate ?>%</span>
      <span class="hero-chip-label">taux de résolution</span>
    </div>
  </div>
  <?php if ($statsHeroHasVisual): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
        <?php if ($statsHeroSceneAsset): ?>
          <?= generated_visual_html($statsHeroSceneAsset, ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Lecture analytique locale']) ?>
        <?php endif; ?>
        <?php if ($statsHeroInsetAsset): ?>
          <?= generated_visual_html($statsHeroInsetAsset, ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Categorie sous pression']) ?>
        <?php endif; ?>
        <?php if ($statsHeroAgentAsset): ?>
          <?= generated_visual_html($statsHeroAgentAsset, ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais analytique']) ?>
        <?php endif; ?>
        <div class="generated-visual-caption hero-visual-stack-copy">
          <strong>Statistiques lisibles</strong>
          <span>La vue analytique remet un repere terrain et agent pour transformer les chiffres en lecture de charge, pas seulement en tableau.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($by_cat)): ?>
  <div class="admin-category-strip admin-category-strip--spaced">
    <?php foreach (array_slice($by_cat, 0, 4) as $cat): ?>
      <div class="admin-category-pill">
        <?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'md', $cat['color'] ?? null) ?>
        <div class="admin-category-pill-copy">
          <strong><?= e($cat['name']) ?></strong>
          <span><?= e($cat['visual_description'] ?: 'Categorie suivie dans les statistiques') ?></span>
        </div>
        <span class="admin-category-pill-count admin-category-pill-count--wide"><?= (int)$cat['cnt'] ?> signalements</span>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="admin-empty-state admin-empty-state--spaced">
    <strong>Aucune catégorie dominante sur la période.</strong>
    <span>Étendre la fenêtre ou attendre davantage de signalements pour faire émerger une lecture métier utile.</span>
  </div>
<?php endif; ?>

<?php if ($execution_summary): ?>
  <div class="card stats-card-gap--compact">
    <div class="card-header">
      <span class="card-title">Cockpit d execution statistique</span>
      <span class="text-muted text-small">La période doit d abord raconter comment les dossiers ouverts sont actuellement portes, pas seulement combien ils sont.</span>
    </div>
    <div class="services-mode-band services-mode-band--tight">
      <div class="services-mode-card">
        <strong><?= (int)($execution_summary['unplanned_count'] ?? 0) ?></strong>
        <span>dossier(s) ouvert(s) encore sans plan visible</span>
      </div>
      <div class="services-mode-card">
        <strong><?= (int)($execution_summary['internal_count'] ?? 0) ?></strong>
        <span>dossier(s) actuellement portes en interne</span>
      </div>
      <div class="services-mode-card">
        <strong><?= (int)($execution_summary['provider_count'] ?? 0) ?></strong>
        <span>dossier(s) actuellement portes par un prestataire</span>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- En-tête avec sélecteur de période et export -->
<div class="stats-toolbar">
  <div class="stats-toolbar-group">
    <span class="fw-bold text-small">Période :</span>
    <?php foreach ([7=>'7 jours', 30=>'30 jours', 90=>'3 mois', 365=>'1 an'] as $p => $label): ?>
      <a href="/admin/?page=stats&period=<?= $p ?>"
         data-async-link
         class="btn btn-sm <?= $period === $p ? 'btn-primary' : 'btn-outline' ?>">
        <?= $label ?>
      </a>
    <?php endforeach; ?>
  </div>
  <a href="/admin/?page=stats&period=<?= $period ?>&export=csv" class="btn btn-outline btn-sm">
    Export CSV (<?= $period ?> jours)
  </a>
</div>

<!-- KPIs enrichis (7 cartes) -->
<div class="stats-grid stats-kpi-grid--primary">
  <div class="stat-card">
    <div class="stat-icon blue">SIG</div>
    <div>
      <div class="stat-value"><?= $kpis['total'] ?></div>
      <div class="stat-label">Signalements</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow">OUV</div>
    <div>
      <div class="stat-value"><?= $kpis['open'] ?></div>
      <div class="stat-label">Ouverts</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">OK</div>
    <div>
      <div class="stat-value"><?= $kpis['resolved'] ?></div>
      <div class="stat-label">Résolus (<?= $resolution_rate ?>%)</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">DEL</div>
    <div>
      <div class="stat-value">
        <?php
        $h = $kpis['avg_resolution_hours'];
        if ($h === null) echo 'N/A';
        elseif ($h < 24) echo round($h) . 'h';
        else echo round($h / 24) . 'j';
        ?>
      </div>
      <div class="stat-label">Délai moyen résolution</div>
    </div>
  </div>
</div>
<div class="stats-grid stats-kpi-grid--secondary">
  <div class="stat-card">
    <div class="stat-icon yellow">SOU</div>
    <div>
      <div class="stat-value"><?= number_format((int)$kpis['total_votes']) ?></div>
      <div class="stat-label">Votes "Moi aussi"</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">USR</div>
    <div>
      <div class="stat-value"><?= $active_citizens ?></div>
      <div class="stat-label">Citoyens actifs</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">REF</div>
    <div>
      <div class="stat-value"><?= $kpis['rejected'] ?></div>
      <div class="stat-label">Rejetés</div>
    </div>
  </div>
</div>

<!-- Graphiques ligne 1 : Évolution + Statuts -->
<div class="stats-chart-grid stats-chart-grid--main stats-chart-grid--main-gap">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Soumis vs Résolus (<?= $period ?> jours)</span>
    </div>
    <div class="chart-container">
      <canvas id="chartEvo"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">Répartition par statut</span>
    </div>
    <div class="chart-container">
      <canvas id="chartStatus"></canvas>
    </div>
  </div>
</div>

<!-- Graphiques ligne 2 : Catégories + Jours de la semaine -->
<div class="stats-chart-grid stats-chart-grid--split stats-card-gap">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Signalements par catégorie</span>
    </div>
    <div class="chart-container">
      <canvas id="chartCat"></canvas>
    </div>
    <div class="stats-category-list stats-category-list--tight">
      <?php foreach ($by_cat as $cat): ?>
        <div class="stats-category-row">
          <?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'md', $cat['color'] ?? null) ?>
          <div class="stats-category-copy">
            <div class="fw-bold text-truncate"><?= e($cat['name']) ?></div>
            <div class="text-small text-muted"><?= (int)$cat['cnt'] ?> signalement<?= ((int)$cat['cnt']) > 1 ? 's' : '' ?> · <?= (int)$cat['votes'] ?> soutien<?= (int)$cat['votes'] > 1 ? 's' : '' ?></div>
            <?php if (!empty($cat['visual_description'])): ?>
              <div class="text-small text-muted stats-category-description"><?= e($cat['visual_description']) ?></div>
            <?php endif; ?>
          </div>
          <span class="badge badge-tone" style="--badge-accent:<?= e($cat['color']) ?>"><?= (int)$cat['cnt'] ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (empty($by_cat)): ?>
        <div class="admin-empty-state admin-empty-state--compact">
          <strong>Aucune catégorie à comparer sur la période.</strong>
          <span>Le graphique restera vide tant que la fenêtre choisie ne contient pas assez de dossiers catégorisés.</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">Activité par jour de la semaine</span>
    </div>
    <div class="chart-container">
      <canvas id="chartWeekday"></canvas>
    </div>
  </div>
</div>

<!-- Carte de chaleur horaire (ADMIN-01) -->
<div class="card stats-card-gap">
  <div class="card-header">
    <span class="card-title">Carte de chaleur — Heure × Jour de la semaine</span>
  </div>
  <div class="stats-heatmap-shell">
    <table class="stats-heatmap-table">
      <thead>
        <tr>
          <th class="stats-heatmap-head">Heure</th>
          <?php for ($h = 0; $h < 24; $h++): ?>
            <th class="stats-heatmap-hour"><?= sprintf('%02d', $h) ?>h</th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $dow_labels = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $max_heat = max(1, max(array_map(fn($row) => max($row), $heatmap)));
        for ($d = 0; $d < 7; $d++):
        ?>
        <tr>
          <td class="stats-heatmap-day"><?= $dow_labels[$d] ?></td>
          <?php for ($h = 0; $h < 24; $h++):
            $v   = $heatmap[$d][$h];
            $pct = $max_heat > 0 ? $v / $max_heat : 0;
            $r   = (int)(220 - $pct * 170);
            $g   = (int)(240 - $pct * 170);
            $b   = (int)(255 - $pct * 200);
            $bg  = "rgb($r,$g,$b)";
            $fg  = $pct > 0.5 ? '#fff' : '#374151';
          ?>
            <td class="stats-heatmap-cell" style="--heatmap-bg:<?= $bg ?>;--heatmap-fg:<?= $fg ?>;"
                title="<?= $dow_labels[$d] ?> <?= sprintf('%02d', $h) ?>h : <?= $v ?> signalement<?= $v>1?'s':'' ?>">
              <?= $v > 0 ? $v : '' ?>
            </td>
          <?php endfor; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Top votés + Top zones -->
<div class="stats-bottom-grid stats-card-gap">
  <!-- Top 5 signalements les plus votés -->
  <?php if (!empty($top_voted)): ?>
  <div class="card">
    <div class="card-header"><span class="card-title">Top 5 signalements les plus soutenus</span></div>
    <div class="stats-ranked-list">
    <?php foreach ($top_voted as $i => $inc): ?>
    <div class="stats-ranked-item">
      <span class="stats-rank-bullet stats-rank-bullet--amber"><?= $i+1 ?></span>
      <div class="stats-ranked-visuals">
        <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'md', $inc['cat_color'] ?? null) ?>
        <?php if (!empty($inc['lead_photo']['url'])): ?>
          <span class="admin-proof-thumb-wrap">
            <img src="<?= e($inc['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb admin-proof-thumb--small">
          </span>
        <?php endif; ?>
      </div>
      <div class="stats-ranked-copy">
        <div class="fw-bold text-truncate">
          <?= e($inc['title'] ?: substr($inc['description'], 0, 50)) ?>
        </div>
        <div class="stats-ranked-meta">
          <span class="badge badge-tone badge-xs" style="--badge-accent:<?= e($inc['cat_color']) ?>"><?= e($inc['cat_name']) ?></span>
          <span class="badge badge-xs <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span>
        </div>
        <?php if (!empty($inc['cat_description'])): ?>
          <div class="text-small text-muted stats-ranked-description"><?= e($inc['cat_description']) ?></div>
        <?php endif; ?>
        <?php if (!empty($inc['lead_photo']['moderation_message'])): ?>
          <div class="text-small text-muted stats-ranked-description"><?= e($inc['lead_photo']['moderation_message']) ?></div>
        <?php elseif (!empty($inc['lead_photo']['url'])): ?>
          <div class="text-small text-muted stats-ranked-description">Preuve citoyenne visible dans le dossier.</div>
        <?php endif; ?>
      </div>
      <span class="stats-votes-count"><?= $inc['votes_count'] ?> soutien<?= $inc['votes_count'] > 1 ? 's' : '' ?></span>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="card-header"><span class="card-title">Top 5 signalements les plus soutenus</span></div>
    <div class="admin-empty-state admin-empty-state--compact">
      <strong>Aucun dossier soutenu sur la période.</strong>
      <span>Cette surface se remplira dès qu un signalement recevra des soutiens citoyens dans la fenêtre analysée.</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- Top 5 zones -->
  <?php if (!empty($top_zones)): ?>
  <div class="card">
    <div class="card-header"><span class="card-title">Top 5 zones les plus signalées</span></div>
    <div class="stats-ranked-list">
    <?php foreach ($top_zones as $i => $zone): ?>
    <div class="stats-ranked-item">
      <span class="stats-rank-bullet stats-rank-bullet--blue"><?= $i+1 ?></span>
      <span class="stats-zone-address"><?= e($zone['address']) ?></span>
      <div class="stats-zone-meta">
        <div class="badge badge-blue"><?= $zone['cnt'] ?> sign.</div>
        <?php if ($zone['votes'] > 0): ?>
          <div class="stats-zone-votes"><?= $zone['votes'] ?> soutien<?= $zone['votes'] > 1 ? 's' : '' ?></div>
        <?php endif; ?>
      </div>
      <div class="stats-zone-bar">
        <div class="stats-zone-bar-fill" style="--zone-fill:<?= round($zone['cnt']/$top_zones[0]['cnt']*100) ?>%;"></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="card-header"><span class="card-title">Top 5 zones les plus signalées</span></div>
    <div class="admin-empty-state admin-empty-state--compact">
      <strong>Aucune zone dominante à isoler.</strong>
      <span>Quand plusieurs dossiers se concentreront sur une même adresse, cette vue aidera à visualiser la pression locale.</span>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
(() => {
const evoCanvas = document.getElementById('chartEvo');
const statusCanvas = document.getElementById('chartStatus');
const catCanvas = document.getElementById('chartCat');
const weekdayCanvas = document.getElementById('chartWeekday');
if (!evoCanvas || !statusCanvas || !catCanvas || !weekdayCanvas || typeof Chart === 'undefined') {
  return;
}

const themePalette = <?= json_encode($themePalette, JSON_UNESCAPED_SLASHES) ?>;
const statusPalette = <?= json_encode($statusPalette, JSON_UNESCAPED_SLASHES) ?>;

function withAlpha(hex, alpha) {
  const value = String(hex || '').replace('#', '');
  if (value.length !== 6) return hex;
  const r = parseInt(value.slice(0, 2), 16);
  const g = parseInt(value.slice(2, 4), 16);
  const b = parseInt(value.slice(4, 6), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

[evoCanvas, statusCanvas, catCanvas, weekdayCanvas].forEach((canvas) => {
  Chart.getChart(canvas)?.destroy();
});

const evoData = <?= json_encode($evolution) ?>;
new Chart(evoCanvas, {
  type: 'bar',
  data: {
    labels: evoData.map(d => {
      const dt = new Date(d.day);
      return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short' });
    }),
    datasets: [
      {
        label: 'Soumis',
        data: evoData.map(d => d.submitted),
        backgroundColor: withAlpha(themePalette.primary, 0.72),
        borderRadius: 4,
      },
      {
        label: 'Résolus',
        data: evoData.map(d => d.resolved),
        backgroundColor: withAlpha(themePalette.success, 0.78),
        borderRadius: 4,
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: themePalette.grid } },
      x: { grid: { display: false } }
    }
  }
});

const statusData = <?= json_encode($by_status) ?>;
const statusLabels = { submitted:'Soumis', acknowledged:'Pris en charge', in_progress:'En cours', resolved:'Résolu', rejected:'Rejeté' };
new Chart(statusCanvas, {
  type: 'doughnut',
  data: {
    labels: statusData.map(s => statusLabels[s.status] || s.status),
    datasets: [{
      data: statusData.map(s => s.cnt),
      backgroundColor: statusData.map(s => statusPalette[s.status] || statusPalette.default),
      borderWidth: 2, borderColor: themePalette.surface,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } } }
  }
});

const catData = <?= json_encode($by_cat) ?>;
new Chart(catCanvas, {
  type: 'bar',
  data: {
    labels: catData.map(c => c.name),
    datasets: [{
      label: 'Signalements',
      data: catData.map(c => c.cnt),
      backgroundColor: catData.map(c => c.color + 'cc'),
      borderRadius: 6,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: themePalette.grid } },
      y: { grid: { display: false } }
    }
  }
});

const wdData = <?= json_encode($by_weekday_data) ?>;
new Chart(weekdayCanvas, {
  type: 'bar',
  data: {
    labels: wdData.map(d => d.day),
    datasets: [{
      label: 'Signalements',
      data: wdData.map(d => d.cnt),
      backgroundColor: wdData.map((_, i) => i >= 1 && i <= 5
        ? withAlpha(themePalette.primary, 0.72)
        : withAlpha(themePalette.secondary, 0.58)),
      borderRadius: 6,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: themePalette.grid } },
      x: { grid: { display: false } }
    }
  }
});
})();
</script>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
