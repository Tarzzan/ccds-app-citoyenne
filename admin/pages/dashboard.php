<?php
/**
 * Ma Commune Back-Office — Tableau de bord
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Tableau de bord';
$active_nav = 'dashboard';
$themePalette = visual_admin_data_palette();
$statusPalette = visual_admin_status_palette();

$db = Database::getInstance();
$service_tables_ready = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'intervention_plans');
$latestPlansSql = "
    SELECT latest_plan.*
    FROM intervention_plans latest_plan
    INNER JOIN (
        SELECT incident_id, MAX(id) AS latest_id
        FROM intervention_plans
        GROUP BY incident_id
    ) latest_lookup ON latest_lookup.latest_id = latest_plan.id
";
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;
$incident_scope_join = '';
$incident_scope_where = '';
$service_scope_where = '';
$scoped_incidents_link = '/admin/?page=incidents';
$lead_photo_select = admin_incident_first_photo_select($db, 'i');

if ($agent_is_scoped) {
    if (!empty($agent_service_scope_ids)) {
        $safeServiceIds = implode(',', array_map('intval', $agent_service_scope_ids));
        $incident_scope_join = "
            JOIN categories scoped_category ON scoped_category.id = incidents.category_id
            JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = scoped_category.id AND scoped_service_map.is_default = 1
        ";
        $incident_scope_where = "WHERE scoped_service_map.service_id IN ($safeServiceIds)";
        $service_scope_where = "WHERE s.id IN ($safeServiceIds)";
        $scope_notice = $admin['primary_service_name']
            ? 'Votre tableau de bord est limite au service ' . $admin['primary_service_name'] . '.'
            : 'Votre tableau de bord est limite a vos services rattaches.';
        $scoped_incidents_link = '/admin/?page=incidents&service=' . (int)($admin['primary_service_id'] ?? $agent_service_scope_ids[0]);
    } else {
        $incident_scope_join = '';
        $incident_scope_where = 'WHERE 1 = 0';
        $service_scope_where = 'WHERE 1 = 0';
        $scope_notice = 'Aucun service ne vous est encore attribue. Les indicateurs restent vides tant que votre rattachement n est pas renseigne.';
    }
}

// --- KPIs globaux ---
$kpis = $db->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(incidents.status = 'submitted')                            AS submitted,
        SUM(incidents.status = 'in_progress')                          AS in_progress,
        SUM(incidents.status = 'resolved')                             AS resolved,
        SUM(incidents.status = 'rejected')                             AS rejected,
        SUM(DATE(incidents.created_at) = CURDATE())                    AS today,
        SUM(incidents.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS week
    FROM incidents
    $incident_scope_join
    $incident_scope_where
")->fetch(PDO::FETCH_ASSOC);

// --- Répartition par catégorie ---
$by_cat = $db->query("
    SELECT c.name, c.color, c.icon, COUNT(i.id) AS cnt
    FROM categories c
    LEFT JOIN incidents i ON i.category_id = c.id
    " . ($agent_is_scoped
        ? "JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = c.id AND scoped_service_map.is_default = 1
           WHERE scoped_service_map.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")"
        : '') . "
    GROUP BY c.id ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// --- Évolution sur 14 jours ---
$evolution = $db->query("
    SELECT DATE(incidents.created_at) AS day, COUNT(*) AS cnt
    FROM incidents
    $incident_scope_join
    " . ($incident_scope_where === '' ? 'WHERE' : $incident_scope_where . ' AND') . " incidents.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(incidents.created_at)
    ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- 10 derniers signalements ---
$recent = $db->query("
    SELECT i.id, i.reference, i.description, i.status, i.priority,
           i.created_at, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS reporter,
           {$lead_photo_select},
           (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
           (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    " . ($agent_is_scoped
        ? "JOIN service_category_map scoped_service_map ON scoped_service_map.category_id = c.id AND scoped_service_map.is_default = 1"
        : '') . "
    " . ($agent_is_scoped
        ? "WHERE scoped_service_map.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")"
        : '') . "
    ORDER BY i.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent as &$recentIncident) {
    $recentIncident['lead_photo'] = admin_incident_preview_photo($db, $recentIncident);
    if ((int)($recentIncident['photo_count'] ?? 0) === 0 && !empty($recentIncident['lead_photo']['url'])) {
        $recentIncident['photo_count'] = 1;
    }
}
unset($recentIncident);

$recentProofs = admin_fetch_recent_proof_incidents($db, [
    'limit' => 4,
    'service_ids' => $agent_is_scoped ? $agent_service_scope_ids : [],
]);

// --- Nombre d'utilisateurs actifs ---
$user_count = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

// --- KPIs communaute (consultations & evenements) ---
$community = [
    'active_polls' => 0,
    'poll_votes' => 0,
    'upcoming_events' => 0,
    'event_attendees' => 0,
];
$community_recent_polls = [];
$community_upcoming_events = [];
$dashboardHeroPortraits = array_values(array_filter([
    [
        'asset' => visual_admin_slot_asset('dashboard_primary', 'CHAR-04'),
        'label' => 'Agent operationnel 1',
        'title' => 'Repere terrain',
    ],
    [
        'asset' => visual_admin_slot_asset('dashboard_secondary', 'CHAR-05'),
        'label' => 'Agent operationnel 2',
        'title' => 'Presence d equipe',
    ],
], static function (array $portrait): bool {
    return !empty($portrait['asset']) && generated_visual_url($portrait['asset']) !== null;
}));
$serviceSignals = [
    'services_active' => 0,
    'plans_today' => 0,
    'overdue_plans' => 0,
    'unplanned_open' => 0,
    'internal_active_plans' => 0,
    'provider_active_plans' => 0,
];
$serviceWorkload = [];
$categoryHighlights = [];
$pollsReady = admin_db_has_table($db, 'polls') && admin_db_has_table($db, 'poll_votes');
$eventsReady = admin_db_has_table($db, 'events') && admin_db_has_table($db, 'event_rsvps');
$pollStatusWhere = admin_db_has_column($db, 'polls', 'status')
    ? "status = 'active'"
    : (admin_db_has_column($db, 'polls', 'is_active') ? 'is_active = 1' : '1 = 1');
$pollStatusSelect = admin_db_has_column($db, 'polls', 'status')
    ? 'p.status AS effective_status'
    : (admin_db_has_column($db, 'polls', 'is_active')
        ? "CASE WHEN p.is_active = 1 THEN 'active' ELSE 'closed' END AS effective_status"
        : "'active' AS effective_status");
$eventDateColumn = admin_db_has_column($db, 'events', 'event_date')
    ? 'event_date'
    : (admin_db_has_column($db, 'events', 'starts_at') ? 'starts_at' : null);

try {
    if ($pollsReady || $eventsReady) {
        $communitySelect = [];

        if ($pollsReady) {
            $communitySelect[] = "(SELECT COUNT(*) FROM polls WHERE {$pollStatusWhere} AND (ends_at IS NULL OR ends_at >= NOW())) AS active_polls";
            $communitySelect[] = "(SELECT COUNT(*) FROM poll_votes pv JOIN polls p ON p.id = pv.poll_id WHERE {$pollStatusWhere} AND (p.ends_at IS NULL OR p.ends_at >= NOW())) AS poll_votes";

            $communityRecentPollsSql = "
                SELECT
                    p.id, p.title, p.ends_at, {$pollStatusSelect},
                    (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes
                FROM polls p
                WHERE {$pollStatusWhere} AND (p.ends_at IS NULL OR p.ends_at >= NOW())
                ORDER BY COALESCE(p.ends_at, '9999-12-31 23:59:59') ASC, p.created_at DESC
                LIMIT 3
            ";
            $community_recent_polls = $db->query($communityRecentPollsSql)->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($eventsReady && $eventDateColumn !== null) {
            $communitySelect[] = "(SELECT COUNT(*) FROM events WHERE {$eventDateColumn} >= NOW()) AS upcoming_events";
            $communitySelect[] = "(SELECT COUNT(*) FROM event_rsvps r JOIN events e ON e.id = r.event_id WHERE e.{$eventDateColumn} >= NOW() AND r.status = 'attending') AS event_attendees";

            $communityUpcomingEventsSql = "
                SELECT
                    e.id, e.title, e.location, e.{$eventDateColumn} AS event_date,
                    (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.status = 'attending') AS attendees_count
                FROM events e
                WHERE e.{$eventDateColumn} >= NOW()
                ORDER BY e.{$eventDateColumn} ASC
                LIMIT 3
            ";
            $community_upcoming_events = $db->query($communityUpcomingEventsSql)->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($communitySelect)) {
            $community = array_merge(
                $community,
                $db->query('SELECT ' . implode(",\n            ", $communitySelect))->fetch(PDO::FETCH_ASSOC) ?: []
            );
        }
    }
} catch (Throwable $e) {
    $community_recent_polls = [];
    $community_upcoming_events = [];
}

if ($service_tables_ready) {
    try {
        $serviceSignals = array_merge($serviceSignals, $db->query("
            SELECT
                (SELECT COUNT(*) FROM services " . ($agent_is_scoped ? "WHERE id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ") AND is_active = 1" : "WHERE is_active = 1") . ") AS services_active,
                (SELECT COUNT(*) FROM ($latestPlansSql) current_plan WHERE current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') AND current_plan.scheduled_date = CURDATE() " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . ") AS plans_today,
                (SELECT COUNT(*) FROM ($latestPlansSql) current_plan WHERE current_plan.status IN ('scheduled', 'rescheduled') AND current_plan.scheduled_date IS NOT NULL AND current_plan.scheduled_date < CURDATE() " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . ") AS overdue_plans,
                (SELECT COUNT(*) FROM ($latestPlansSql) current_plan WHERE current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') AND (current_plan.source_type = 'internal' OR current_plan.source_type IS NULL) " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . ") AS internal_active_plans,
                (SELECT COUNT(*) FROM ($latestPlansSql) current_plan WHERE current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') AND current_plan.source_type = 'provider' " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . ") AS provider_active_plans,
                (
                    SELECT COUNT(*)
                    FROM incidents i
                    JOIN categories c ON c.id = i.category_id
                    JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
                    LEFT JOIN (
                        SELECT incident_id, MAX(id) AS plan_id
                        FROM intervention_plans
                        GROUP BY incident_id
                    ) planned_lookup ON planned_lookup.incident_id = i.id
                    WHERE i.status IN ('submitted', 'acknowledged', 'in_progress')
                      " . ($agent_is_scoped ? "AND scm.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
                      AND planned_lookup.plan_id IS NULL
                ) AS unplanned_open
        ")->fetch(PDO::FETCH_ASSOC) ?: []);

        $serviceWorkload = $db->query("
            SELECT
                s.id,
                s.name,
                COUNT(DISTINCT CASE WHEN i.status IN ('submitted', 'acknowledged', 'in_progress') THEN i.id END) AS open_incidents_count,
                COUNT(DISTINCT CASE WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') THEN current_plan.id END) AS active_plans_count,
                COUNT(DISTINCT CASE WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') AND (current_plan.source_type = 'internal' OR current_plan.source_type IS NULL) THEN current_plan.id END) AS active_internal_plans_count,
                COUNT(DISTINCT CASE WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') AND current_plan.source_type = 'provider' THEN current_plan.id END) AS active_provider_plans_count,
                COUNT(DISTINCT CASE WHEN current_plan.status IN ('scheduled', 'rescheduled') AND current_plan.scheduled_date IS NOT NULL AND current_plan.scheduled_date < CURDATE() THEN current_plan.id END) AS overdue_plans_count
            FROM services s
            LEFT JOIN service_category_map scm ON scm.service_id = s.id
            LEFT JOIN categories c ON c.id = scm.category_id
            LEFT JOIN incidents i ON i.category_id = c.id
            LEFT JOIN ($latestPlansSql) current_plan ON current_plan.service_id = s.id
            WHERE s.is_active = 1
            " . ($agent_is_scoped ? "AND s.id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
            GROUP BY s.id
            ORDER BY overdue_plans_count DESC, open_incidents_count DESC, active_plans_count DESC, s.name ASC
            LIMIT 4
        ")->fetchAll(PDO::FETCH_ASSOC);

        $unplannedIncidentsList = $db->query("
            SELECT i.reference, c.name as category_name
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
            LEFT JOIN (
                SELECT incident_id, MAX(id) AS plan_id
                FROM intervention_plans
                GROUP BY incident_id
            ) planned_lookup ON planned_lookup.incident_id = i.id
            WHERE i.status IN ('submitted', 'acknowledged', 'in_progress')
              " . ($agent_is_scoped ? "AND scm.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
              AND planned_lookup.plan_id IS NULL
            ORDER BY i.created_at ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $activeServicesList = $db->query("
            SELECT name FROM services " . ($agent_is_scoped ? "WHERE id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ") AND is_active = 1" : "WHERE is_active = 1") . "
            ORDER BY name ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $plansTodayList = $db->query("
            SELECT i.reference, c.name as category_name
            FROM ($latestPlansSql) current_plan
            JOIN incidents i ON i.id = current_plan.incident_id
            JOIN categories c ON c.id = i.category_id
            WHERE current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') 
            AND current_plan.scheduled_date = CURDATE()
            " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $overduePlansList = $db->query("
            SELECT i.reference, c.name as category_name
            FROM ($latestPlansSql) current_plan
            JOIN incidents i ON i.id = current_plan.incident_id
            JOIN categories c ON c.id = i.category_id
            WHERE current_plan.status IN ('scheduled', 'rescheduled') 
            AND current_plan.scheduled_date IS NOT NULL 
            AND current_plan.scheduled_date < CURDATE()
            " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
            ORDER BY current_plan.scheduled_date ASC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $internalPlansList = $db->query("
            SELECT i.reference, c.name as category_name
            FROM ($latestPlansSql) current_plan
            JOIN incidents i ON i.id = current_plan.incident_id
            JOIN categories c ON c.id = i.category_id
            WHERE current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') 
            AND (current_plan.source_type = 'internal' OR current_plan.source_type IS NULL)
            " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $providerPlansList = $db->query("
            SELECT i.reference, c.name as category_name
            FROM ($latestPlansSql) current_plan
            JOIN incidents i ON i.id = current_plan.incident_id
            JOIN categories c ON c.id = i.category_id
            WHERE current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') 
            AND current_plan.source_type = 'provider'
            " . ($agent_is_scoped ? "AND current_plan.service_id IN (" . (!empty($agent_service_scope_ids) ? implode(',', array_map('intval', $agent_service_scope_ids)) : '0') . ")" : "") . "
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $serviceSignals['tooltip_unplanned'] = empty($unplannedIncidentsList) 
            ? "Aucun dossier en attente de planification calendaire." 
            : "À planifier :\n" . implode("\n", array_map(function($i) { return "• " . $i['reference'] . " (" . $i['category_name'] . ")"; }, $unplannedIncidentsList));
        
        $serviceSignals['tooltip_services'] = empty($activeServicesList) 
            ? "Aucun service actif de répertorié." 
            : "Services actifs :\n" . implode("\n", array_map(function($s) { return "• " . $s; }, $activeServicesList));

        $serviceSignals['tooltip_plans_today'] = empty($plansTodayList) 
            ? "Aucune intervention prévue aujourd'hui." 
            : "Activités du jour :\n" . implode("\n", array_map(function($i) { return "• " . $i['reference'] . " (" . $i['category_name'] . ")"; }, $plansTodayList));

        $serviceSignals['tooltip_overdue'] = empty($overduePlansList) 
            ? "Aucun retard constaté." 
            : "Plus urgents (En retard) :\n" . implode("\n", array_map(function($i) { return "• " . $i['reference'] . " (" . $i['category_name'] . ")"; }, $overduePlansList));

        $serviceSignals['tooltip_internal'] = empty($internalPlansList) 
            ? "Rien en cours pour l'équipe interne." 
            : "Exemple en régie :\n" . implode("\n", array_map(function($i) { return "• " . $i['reference'] . " (" . $i['category_name'] . ")"; }, $internalPlansList));

        $serviceSignals['tooltip_provider'] = empty($providerPlansList) 
            ? "Rien en mandat prestataire." 
            : "Exemple sous-traité :\n" . implode("\n", array_map(function($i) { return "• " . $i['reference'] . " (" . $i['category_name'] . ")"; }, $providerPlansList));

    } catch (Throwable $e) {
        $serviceSignals = [
            'services_active' => 0,
            'plans_today' => 0,
            'overdue_plans' => 0,
            'unplanned_open' => 0,
            'internal_active_plans' => 0,
            'provider_active_plans' => 0,
            'tooltip_unplanned' => 'Donnees non chargees',
            'tooltip_services' => 'Donnees non chargees',
        ];
        $serviceWorkload = [];
    }
}

foreach (array_slice($by_cat, 0, 4) as $cat) {
    $visual = category_visual_resolve($cat['icon'] ?? 'road', $cat['name'] ?? null);
    $categoryHighlights[] = [
        'name' => $cat['name'] ?? ($visual['label'] ?? 'Categorie'),
        'short_label' => $visual['short_label'] ?? ($cat['name'] ?? 'Categorie'),
        'description' => $visual['description'] ?? '',
        'icon' => $cat['icon'] ?? 'road',
        'color' => $cat['color'] ?? ($visual['accent'] ?? '#174b3a'),
        'count' => (int)($cat['cnt'] ?? 0),
    ];
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-hero <?= !empty($dashboardHeroPortraits) ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Pilotage local</div>
    <h2 class="page-hero-title">Coordonner la reponse de service et les priorites visibles</h2>
    <?php if ($isTrainingMode): ?>
    <p class="page-hero-text">
      Ce tableau de bord rassemble les besoins remontes du terrain afin d aider les equipes a arbitrer, traiter et rendre visible l execution de service.
    </p>
    <?php endif; ?>
    <div class="page-hero-actions">
      <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-primary btn-sm" data-async-link data-async-scope="admin-main">Ouvrir la file</a>
      <a href="/admin/?page=moderation" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir la modération</a>
      <a href="/admin/?page=notifications" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Publier une notification</a>
    </div>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['week'] ?></span>
      <span class="hero-chip-label">signalements sur 7 jours</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['submitted'] ?></span>
      <span class="hero-chip-label">à qualifier rapidement</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['resolved'] ?></span>
      <span class="hero-chip-label">situations résolues</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$pending_moderation_count ?></span>
      <span class="hero-chip-label">contenus à modérer</span>
    </div>
  </div>
  <?php if (!empty($dashboardHeroPortraits)): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <div class="dashboard-hero-portraits">
          <?php foreach ($dashboardHeroPortraits as $portrait): ?>
            <figure class="dashboard-hero-portrait-card">
              <?= generated_visual_html($portrait['asset'], ['class' => 'generated-visual generated-visual--portrait dashboard-hero-portrait', 'label' => $portrait['label']]) ?>
              <figcaption><?= e($portrait['title']) ?></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
        <?php if ($isTrainingMode): ?>
        <div class="generated-visual-caption">
          <strong>Duo agents stylise</strong>
          <span>Le tableau de bord remet en avant les portraits agents issus de la famille characters de reference.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($categoryHighlights)): ?>
<div class="admin-category-strip">
  <?php foreach ($categoryHighlights as $highlight): ?>
    <div class="admin-category-pill" style="--category-accent:<?= e($highlight['color']) ?>;">
      <?= category_visual_html($highlight['icon'], $highlight['name'], 'md', $highlight['color']) ?>
      <div class="admin-category-pill-copy">
        <strong><?= e($highlight['short_label']) ?></strong>
        <?php if ($isTrainingMode): ?>
        <span><?= e($highlight['description']) ?></span>
        <?php endif; ?>
      </div>
      <span class="admin-category-pill-count"><?= (int)$highlight['count'] ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($scope_notice): ?>
<div class="alert alert-info dashboard-scope-alert"><?= e($scope_notice) ?></div>
<?php endif; ?>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Lecture du jour</div>
    <h3>Commencer par les dossiers qui attendent un vrai signe de prise en charge.</h3>
    <p>
      <?= (int)$kpis['submitted'] ?> dossier(s) sont encore a qualifier et <?= (int)$kpis['in_progress'] ?> sont deja en cours. Le bon rythme consiste a faire baisser l attente visible avant d empiler de nouveaux traitements.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Actions rapides</div>
    <h3>Ouvrir, publier, rendre visible.</h3>
    <p>
      Le back-office doit servir a trois gestes simples : ouvrir la bonne fiche, publier une information utile, puis rendre l execution lisible.
    </p>
    <div class="admin-quick-links">
      <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-primary btn-sm" data-async-link data-async-scope="admin-main">Ouvrir la file</a>
      <a href="/admin/?page=polls" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Lancer une consultation</a>
      <a href="/admin/?page=events" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Publier un rendez-vous</a>
    </div>
  </div>
</div>

<?php if ($service_tables_ready): ?>
<div class="card dashboard-section-card">
  <div class="card-header">
    <span class="card-title">Cockpit d execution</span>
    <span class="text-muted text-small">Lire d abord ce qui doit etre planifie, relance ou execute aujourd hui.</span>
  </div>
  <div class="visual-admin-preview-grid" style="margin-top: 1.5rem; margin-bottom: 0.5rem;">
    <a href="/admin/?page=services" data-async-link data-async-scope="admin-main" class="visual-admin-preview-item" style="text-decoration:none; color:inherit; transform: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'" title="<?= e($serviceSignals['tooltip_services'] ?? "Services actifs") ?>">
      <div style="position:relative; width: 100%;">
        <img src="/admin/assets/img/kpi-icons/clay_icon_gear.png" alt="SRV" loading="lazy" class="visual-admin-preview-thumb" style="aspect-ratio: 1/1; object-fit: cover; background: #fafafa; border-radius: 10px; width: 100%;">
        <?php if((int)$serviceSignals['services_active'] > 0): ?><span class="badge" style="position:absolute; top:-6px; right:-6px; z-index:10; background:#3b82f6; color:white; font-size:11px; padding:3px 7px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= (int)$serviceSignals['services_active'] ?></span><?php endif; ?>
      </div>
      <div class="visual-admin-preview-meta" style="text-align: center; gap: 2px;">
        <strong style="font-size: 13px;">Supervision</strong>
        <span style="font-size: 11px; opacity: 0.7;">Actifs</span>
      </div>
    </a>

    <a href="/admin/?page=incidents&plan=scheduled" data-async-link data-async-scope="admin-main" class="visual-admin-preview-item" style="text-decoration:none; color:inherit; transform: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'" title="<?= e($serviceSignals['tooltip_plans_today'] ?? "Prévues aujourd'hui") ?>">
      <div style="position:relative; width: 100%;">
        <img src="/admin/assets/img/kpi-icons/clay_icon_calendar.png" alt="AGD" loading="lazy" class="visual-admin-preview-thumb" style="aspect-ratio: 1/1; object-fit: cover; background: rgba(16, 185, 129, 0.05); border-radius: 10px; width: 100%;">
        <?php if((int)$serviceSignals['plans_today'] > 0): ?><span class="badge" style="position:absolute; top:-6px; right:-6px; z-index:10; background:#10b981; color:white; font-size:11px; padding:3px 7px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= (int)$serviceSignals['plans_today'] ?></span><?php endif; ?>
      </div>
      <div class="visual-admin-preview-meta" style="text-align: center; gap: 2px;">
        <strong style="font-size: 13px;">Aujourd'hui</strong>
        <span style="font-size: 11px; opacity: 0.7;">Prévues</span>
      </div>
    </a>

    <a href="/admin/?page=incidents&plan=overdue" data-async-link data-async-scope="admin-main" class="visual-admin-preview-item" style="text-decoration:none; color:inherit; transform: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'" title="<?= e($serviceSignals['tooltip_overdue'] ?? "En retard") ?>">
      <div style="position:relative; width: 100%;">
        <img src="/admin/assets/img/kpi-icons/clay_icon_warning.png" alt="RET" loading="lazy" class="visual-admin-preview-thumb" style="aspect-ratio: 1/1; object-fit: cover; background: rgba(239, 68, 68, 0.05); border-radius: 10px; width: 100%;">
        <?php if((int)$serviceSignals['overdue_plans'] > 0): ?><span class="badge" style="position:absolute; top:-6px; right:-6px; z-index:10; background:#ef4444; color:white; font-size:11px; padding:3px 7px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= (int)$serviceSignals['overdue_plans'] ?></span><?php endif; ?>
      </div>
      <div class="visual-admin-preview-meta" style="text-align: center; gap: 2px;">
        <strong style="font-size: 13px;">En danger</strong>
        <span style="font-size: 11px; opacity: 0.7;">Retards</span>
      </div>
    </a>

    <a href="/admin/?page=incidents&plan=unplanned" data-async-link data-async-scope="admin-main" class="visual-admin-preview-item" style="text-decoration:none; color:inherit; transform: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'" title="<?= e($serviceSignals['tooltip_unplanned'] ?? "À planifier") ?>">
      <div style="position:relative; width: 100%;">
        <img src="/admin/assets/img/kpi-icons/clay_icon_empty_box.png" alt="FLX" loading="lazy" class="visual-admin-preview-thumb" style="aspect-ratio: 1/1; object-fit: cover; background: rgba(245, 158, 11, 0.05); border-radius: 10px; width: 100%;">
        <?php if((int)$serviceSignals['unplanned_open'] > 0): ?><span class="badge" style="position:absolute; top:-6px; right:-6px; z-index:10; background:#f59e0b; color:white; font-size:11px; padding:3px 7px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= (int)$serviceSignals['unplanned_open'] ?></span><?php endif; ?>
      </div>
      <div class="visual-admin-preview-meta" style="text-align: center; gap: 2px;">
        <strong style="font-size: 13px;">À planifier</strong>
        <span style="font-size: 11px; opacity: 0.7;">En attente</span>
      </div>
    </a>

    <a href="/admin/?page=incidents&executor=internal" data-async-link data-async-scope="admin-main" class="visual-admin-preview-item" style="text-decoration:none; color:inherit; transform: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'" title="<?= e($serviceSignals['tooltip_internal'] ?? "Équipe interne") ?>">
      <div style="position:relative; width: 100%;">
        <img src="/admin/assets/img/kpi-icons/clay_icon_hardhat.png" alt="INT" loading="lazy" class="visual-admin-preview-thumb" style="aspect-ratio: 1/1; object-fit: cover; background: rgba(59, 130, 246, 0.05); border-radius: 10px; width: 100%;">
        <?php if((int)$serviceSignals['internal_active_plans'] > 0): ?><span class="badge" style="position:absolute; top:-6px; right:-6px; z-index:10; background:#6b7280; color:white; font-size:11px; padding:3px 7px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= (int)$serviceSignals['internal_active_plans'] ?></span><?php endif; ?>
      </div>
      <div class="visual-admin-preview-meta" style="text-align: center; gap: 2px;">
        <strong style="font-size: 13px;">Interne</strong>
        <span style="font-size: 11px; opacity: 0.7;">Agents</span>
      </div>
    </a>

    <a href="/admin/?page=incidents&executor=provider" data-async-link data-async-scope="admin-main" class="visual-admin-preview-item" style="text-decoration:none; color:inherit; transform: none; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'" title="<?= e($serviceSignals['tooltip_provider'] ?? "Prestataire") ?>">
      <div style="position:relative; width: 100%;">
        <img src="/admin/assets/img/kpi-icons/clay_icon_truck.png" alt="EXT" loading="lazy" class="visual-admin-preview-thumb" style="aspect-ratio: 1/1; object-fit: cover; background: rgba(139, 92, 246, 0.05); border-radius: 10px; width: 100%;">
        <?php if((int)$serviceSignals['provider_active_plans'] > 0): ?><span class="badge" style="position:absolute; top:-6px; right:-6px; z-index:10; background:#8b5cf6; color:white; font-size:11px; padding:3px 7px; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= (int)$serviceSignals['provider_active_plans'] ?></span><?php endif; ?>
      </div>
      <div class="visual-admin-preview-meta" style="text-align: center; gap: 2px;">
        <strong style="font-size: 13px;">Externe</strong>
        <span style="font-size: 11px; opacity: 0.7;">Prestataires</span>
      </div>
    </a>
  </div>
</div>

<div class="card dashboard-section-card">
  <div class="card-header">
    <span class="card-title">Charge par service</span>
    <div class="dashboard-inline-actions">
      <a href="/admin/?page=services" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir les services</a>
      <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir la file</a>
    </div>
  </div>
  <?php if (empty($serviceWorkload)): ?>
    <p class="text-muted dashboard-empty-note">Aucune charge service visible pour l instant.</p>
  <?php else: ?>
    <div class="dashboard-service-grid">
      <?php foreach ($serviceWorkload as $service): ?>
        <div class="dashboard-service-card">
          <div class="dashboard-service-card-head">
            <div>
              <div class="dashboard-service-card-title"><?= e($service['name']) ?></div>
              <div class="text-small text-muted dashboard-service-card-subtitle">
                <?= (int)$service['open_incidents_count'] ?> dossier(s) ouverts
              </div>
            </div>
            <?php if ((int)$service['overdue_plans_count'] > 0): ?>
              <span class="badge badge-red"><?= (int)$service['overdue_plans_count'] ?> retard</span>
            <?php else: ?>
              <span class="badge badge-green"><?= (int)$service['active_plans_count'] ?> plan(s)</span>
            <?php endif; ?>
          </div>
          <div class="services-mode-band services-mode-band--spaced">
            <div class="services-mode-card">
              <strong><?= (int)$service['active_internal_plans_count'] ?></strong>
              <span>équipe interne</span>
            </div>
            <div class="services-mode-card">
              <strong><?= (int)$service['active_provider_plans_count'] ?></strong>
              <span>prestataire</span>
            </div>
            <div class="services-mode-card">
              <strong><?= (int)$service['open_incidents_count'] ?></strong>
              <span>dossiers ouverts</span>
            </div>
          </div>
          <div class="dashboard-inline-actions dashboard-inline-actions--spaced">
            <span class="badge badge-gray"><?= (int)$service['active_plans_count'] ?> plan(s) actif(s)</span>
            <span class="badge badge-blue"><?= (int)$service['active_internal_plans_count'] ?> équipe interne</span>
            <span class="badge badge-purple"><?= (int)$service['active_provider_plans_count'] ?> prestataire</span>
            <a href="/admin/?page=services&detail=<?= (int)$service['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Piloter</a>
            <a href="/admin/?page=incidents&service=<?= (int)$service['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Ouvrir la file</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="stats-grid dashboard-stats-grid">
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_document.png" alt="SIG" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= $kpis['total'] ?></div>
      <div class="stat-label">Signalements enregistrés</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_magnifier.png" alt="ATT" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= $kpis['submitted'] ?></div>
      <div class="stat-label">À qualifier</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_gear.png" alt="TRT" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= $kpis['in_progress'] ?></div>
      <div class="stat-label">En traitement</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_check.png" alt="OK" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= $kpis['resolved'] ?></div>
      <div class="stat-label">Résolus</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_sun.png" alt="JOU" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= $kpis['today'] ?></div>
      <div class="stat-label">Signalements aujourd'hui</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_id_badge.png" alt="USR" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= $user_count ?></div>
      <div class="stat-label">Comptes actifs</div>
    </div>
  </div>
</div>

<div class="stats-grid dashboard-stats-grid dashboard-stats-grid--community">
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_speech_bubble.png" alt="VOT" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= (int)$community['active_polls'] ?></div>
      <div class="stat-label">Consultations actives</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_ballot_box.png" alt="VOX" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= (int)$community['poll_votes'] ?></div>
      <div class="stat-label">Votes en cours</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_calendar.png" alt="AGD" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= (int)$community['upcoming_events'] ?></div>
      <div class="stat-label">Rendez-vous à venir</div>
    </div>
  </div>
  <div class="stat-card">
    <div style="flex: 0 0 54px; height: 54px; border-radius: 14px; overflow: hidden; background: #fff; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 4px 12px rgba(0,0,0,0.04);">
      <img src="/admin/assets/img/kpi-icons/clay_icon_raised_hand.png" alt="PRT" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transform: scale(1.15);">
    </div>
    <div>
      <div class="stat-value"><?= (int)$community['event_attendees'] ?></div>
      <div class="stat-label">Participations annoncées</div>
    </div>
  </div>
</div>

<!-- Graphiques -->
<div class="dashboard-split-grid">

  <!-- Évolution 14 jours -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Signalements — 14 derniers jours</span>
    </div>
    <div class="chart-container">
      <canvas id="chartEvolution"></canvas>
    </div>
  </div>

  <!-- Répartition par catégorie -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Par catégorie</span>
    </div>
    <div class="chart-container">
      <canvas id="chartCategories"></canvas>
    </div>
    <div class="dashboard-category-list">
      <?php foreach ($by_cat as $cat): ?>
        <div class="dashboard-category-row">
          <?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'md', $cat['color'] ?? null) ?>
          <div class="dashboard-category-copy">
            <div class="dashboard-category-name"><?= e($cat['name']) ?></div>
            <div class="text-small text-muted"><?= (int)$cat['cnt'] ?> signalement<?= ((int)$cat['cnt']) > 1 ? 's' : '' ?></div>
          </div>
          <span class="badge badge-tone" style="--badge-accent:<?= e($cat['color']) ?>"><?= (int)$cat['cnt'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<div class="dashboard-split-grid dashboard-split-grid--equal">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Concertation citoyenne</span>
      <a href="/admin/?page=polls" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Gérer →</a>
    </div>
    <?php if (empty($community_recent_polls)): ?>
      <p class="text-muted dashboard-empty-note">Aucune consultation active. Lancez une question flash pour sonder les priorités locales.</p>
    <?php else: ?>
      <div class="dashboard-stack-list">
        <?php foreach ($community_recent_polls as $poll): ?>
          <div class="dashboard-feed-card dashboard-feed-card--sand">
            <div class="dashboard-feed-card-head">
              <div>
                <div class="dashboard-feed-card-title"><?= e($poll['title']) ?></div>
                <div class="text-small text-muted dashboard-feed-card-meta">
                  <?= (int)$poll['total_votes'] ?> vote(s)
                  <?= !empty($poll['ends_at']) ? ' · jusqu’au ' . e(format_date_short($poll['ends_at'])) : ' · sans date de fin' ?>
                </div>
              </div>
              <span class="badge badge-green">En cours</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Rendez-vous communaux</span>
      <a href="/admin/?page=events" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Planifier →</a>
    </div>
    <?php if (empty($community_upcoming_events)): ?>
      <p class="text-muted dashboard-empty-note">Aucun événement programmé. Publiez un rendez-vous pour donner de la visibilité à l’action locale.</p>
    <?php else: ?>
      <div class="dashboard-stack-list">
        <?php foreach ($community_upcoming_events as $event): ?>
          <div class="dashboard-feed-card dashboard-feed-card--sky">
            <div class="dashboard-feed-card-head">
              <div>
                <div class="dashboard-feed-card-title"><?= e($event['title']) ?></div>
                <div class="text-small text-muted dashboard-feed-card-meta">
                  <?= e(format_date($event['event_date'])) ?> · <?= e($event['location'] ?: 'Lieu à confirmer') ?>
                </div>
              </div>
              <span class="badge badge-blue"><?= (int)$event['attendees_count'] ?> participant(s)</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($recentProofs)): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title">Dernières preuves citoyennes</span>
    <span class="text-muted text-small">Repères visuels comparables au mobile, sans masquer la file réelle.</span>
  </div>
  <div class="dashboard-proof-grid">
    <?php foreach ($recentProofs as $proofIncident): ?>
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
          <span class="dashboard-proof-card-description"><?= e($proofIncident['description']) ?></span>
          <span class="dashboard-proof-card-foot">
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

<!-- Derniers signalements -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Derniers signalements</span>
    <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir tout →</a>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Dossier & Catégorie</th>
          <th>Description</th>
          <th>Preuve</th>
          <th>État & Priorité</th>
          <th>Citoyen</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $inc): ?>
        <tr>
          <td style="min-width: 170px;">
            <div class="admin-category-cell" style="--category-accent:<?= e($inc['cat_color'] ?: ($themePalette['primary'] ?? '#355160')) ?>; margin-bottom: 6px;">
              <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'sm', $inc['cat_color'] ?? null) ?>
              <div class="admin-category-cell-copy">
                <span class="incidents-row-title" style="white-space: normal; line-height: 1.2;"><?= e($inc['cat_name']) ?></span>
              </div>
            </div>
            <div class="text-muted text-small incidents-nowrap">
              <code class="dashboard-ref" style="display:inline-block; margin-right: 4px;"><?= e($inc['reference']) ?></code>
              <?= format_date_short($inc['created_at']) ?>
            </div>
          </td>
          <td><span class="truncate-3-lines" title="<?= e($inc['description']) ?>"><?= e($inc['description']) ?></span></td>
          <td>
            <div class="admin-proof-cell admin-proof-cell--compact">
              <?php if (!empty($inc['lead_photo']['url'])): ?>
                <a href="/admin/?page=incident_detail&id=<?= (int)$inc['id'] ?>" class="admin-proof-thumb-link js-insta-preview" data-id="<?= (int)$inc['id'] ?>" data-async-link data-async-scope="admin-main" aria-label="Previsualiser la preuve citoyenne" title="Ouvrir la fenetre preuve">
                  <span class="admin-proof-thumb-wrap">
                    <img src="<?= e($inc['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb admin-proof-thumb--small">
                    <?php if ((int)$inc['photo_count'] > 1): ?>
                      <span class="admin-proof-thumb-badge">+<?= (int)$inc['photo_count'] - 1 ?></span>
                    <?php endif; ?>
                  </span>
                </a>
              <?php else: ?>
                <div class="admin-proof-thumb admin-proof-thumb--small admin-proof-thumb--empty">Aucune</div>
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
            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
              <span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span>
              <span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span>
            </div>
          </td>
          <td>
            <div class="incidents-row-title truncate" style="max-width: 140px;" title="<?= e($inc['reporter']) ?>"><?= e($inc['reporter']) ?></div>
          </td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <tr><td colspan="9" class="text-center text-muted dashboard-empty-row">Aucun signalement pour le moment.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
const evolutionCanvas = document.getElementById('chartEvolution');
const categoriesCanvas = document.getElementById('chartCategories');
if (!evolutionCanvas || !categoriesCanvas || typeof Chart === 'undefined') {
  return;
}

const themePalette = <?= json_encode($themePalette, JSON_UNESCAPED_SLASHES) ?>;

function withAlpha(hex, alpha) {
  const value = String(hex || '').replace('#', '');
  if (value.length !== 6) return hex;
  const r = parseInt(value.slice(0, 2), 16);
  const g = parseInt(value.slice(2, 4), 16);
  const b = parseInt(value.slice(4, 6), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

Chart.getChart(evolutionCanvas)?.destroy();
Chart.getChart(categoriesCanvas)?.destroy();

const evoData = <?= json_encode($evolution) ?>;
const evoLabels = evoData.map(d => {
  const dt = new Date(d.day);
  return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short' });
});
new Chart(evolutionCanvas, {
  type: 'line',
  data: {
    labels: evoLabels,
    datasets: [{
      label: 'Signalements',
      data: evoData.map(d => d.cnt),
      borderColor: themePalette.primary,
      backgroundColor: withAlpha(themePalette.primary, 0.12),
      tension: 0.4,
      fill: true,
      pointBackgroundColor: themePalette.primary,
      pointRadius: 4,
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

const catData = <?= json_encode($by_cat) ?>;
new Chart(categoriesCanvas, {
  type: 'doughnut',
  data: {
    labels: catData.map(c => c.name),
    datasets: [{
      data: catData.map(c => c.cnt),
      backgroundColor: catData.map(c => c.color),
      borderWidth: 2,
      borderColor: themePalette.surface,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 12 } }
    }
  }
});
})();
</script>

<?php require_once __DIR__ . '/../includes/insta_preview.php'; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
