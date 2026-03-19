<?php
/**
 * Ma Commune Back-Office — Tableau de bord
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Tableau de bord';
$active_nav = 'dashboard';

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
           u.full_name AS reporter
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
$dashboardHeroVisual = generated_visual_url('HERO-01');
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

try {
    $community = array_merge($community, $db->query("
        SELECT
            (SELECT COUNT(*) FROM polls WHERE status = 'active' AND (ends_at IS NULL OR ends_at >= NOW())) AS active_polls,
            (SELECT COUNT(*) FROM poll_votes pv JOIN polls p ON p.id = pv.poll_id WHERE p.status = 'active' AND (p.ends_at IS NULL OR p.ends_at >= NOW())) AS poll_votes,
            (SELECT COUNT(*) FROM events WHERE event_date >= NOW()) AS upcoming_events,
            (SELECT COUNT(*) FROM event_rsvps r JOIN events e ON e.id = r.event_id WHERE e.event_date >= NOW() AND r.status = 'attending') AS event_attendees
    ")->fetch(PDO::FETCH_ASSOC) ?: []);

    $community_recent_polls = $db->query("
        SELECT
            p.id, p.title, p.ends_at, p.status,
            (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes
        FROM polls p
        WHERE p.status = 'active' AND (p.ends_at IS NULL OR p.ends_at >= NOW())
        ORDER BY COALESCE(p.ends_at, '9999-12-31 23:59:59') ASC, p.created_at DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $community_upcoming_events = $db->query("
        SELECT
            e.id, e.title, e.location, e.event_date,
            (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.status = 'attending') AS attendees_count
        FROM events e
        WHERE e.event_date >= NOW()
        ORDER BY e.event_date ASC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);
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
    } catch (Throwable $e) {
        $serviceSignals = [
            'services_active' => 0,
            'plans_today' => 0,
            'overdue_plans' => 0,
            'unplanned_open' => 0,
            'internal_active_plans' => 0,
            'provider_active_plans' => 0,
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

<div class="page-hero <?= $dashboardHeroVisual ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Pilotage territorial</div>
    <h2 class="page-hero-title">Coordonner la réponse publique locale</h2>
    <p class="page-hero-text">
      Ce tableau de bord rassemble les besoins remontés du terrain afin d'aider les agents à arbitrer, traiter et rendre visible l'action communale.
    </p>
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
  </div>
  <?php if ($dashboardHeroVisual): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <?= generated_visual_html('HERO-01', ['class' => 'generated-visual generated-visual--contain', 'label' => 'Hero Ma Commune']) ?>
        <div class="generated-visual-caption">
          <strong>Visuel hero installe</strong>
          <span>Le back-office peut maintenant accueillir le duo premium sans changer la structure de pilotage.</span>
        </div>
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
        <span><?= e($highlight['description']) ?></span>
      </div>
      <span class="admin-category-pill-count"><?= (int)$highlight['count'] ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($scope_notice): ?>
<div class="alert alert-info" style="margin-bottom:16px;"><?= e($scope_notice) ?></div>
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
      Le back-office doit servir a trois gestes simples : ouvrir la bonne fiche, publier une information communale utile, puis rendre l execution lisible.
    </p>
    <div class="admin-quick-links">
      <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-primary btn-sm">Ouvrir la file</a>
      <a href="/admin/?page=polls" class="btn btn-outline btn-sm">Lancer une consultation</a>
      <a href="/admin/?page=events" class="btn btn-outline btn-sm">Publier un rendez-vous</a>
    </div>
  </div>
</div>

<?php if ($service_tables_ready): ?>
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon canopy">🧭</div>
    <div>
      <div class="stat-value"><?= (int)$serviceSignals['services_active'] ?></div>
      <div class="stat-label">Services actifs</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon awara">📅</div>
    <div>
      <div class="stat-value"><?= (int)$serviceSignals['plans_today'] ?></div>
      <div class="stat-label">Interventions prévues aujourd'hui</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon laterite">⏳</div>
    <div>
      <div class="stat-value"><?= (int)$serviceSignals['overdue_plans'] ?></div>
      <div class="stat-label">Plans possiblement en retard</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon river">🗂️</div>
    <div>
      <div class="stat-value"><?= (int)$serviceSignals['unplanned_open'] ?></div>
      <div class="stat-label">Dossiers ouverts sans plan</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon leaf">🛠️</div>
    <div>
      <div class="stat-value"><?= (int)$serviceSignals['internal_active_plans'] ?></div>
      <div class="stat-label">Interventions équipe interne</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon sand">🤝</div>
    <div>
      <div class="stat-value"><?= (int)$serviceSignals['provider_active_plans'] ?></div>
      <div class="stat-label">Interventions prestataire</div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:24px;">
  <div class="card-header">
    <span class="card-title">🧭 Charge par service</span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="/admin/?page=services" class="btn btn-outline btn-sm">Voir les services</a>
      <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-outline btn-sm">Voir la file</a>
    </div>
  </div>
  <?php if (empty($serviceWorkload)): ?>
    <p class="text-muted" style="padding:8px 4px 4px;">Aucune charge service visible pour l instant.</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
      <?php foreach ($serviceWorkload as $service): ?>
        <div style="padding:16px;border:1px solid #ece4d5;border-radius:18px;background:#fffdf8;">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
            <div>
              <div style="font-weight:800;color:#183229;"><?= e($service['name']) ?></div>
              <div class="text-small text-muted" style="margin-top:6px;">
                <?= (int)$service['open_incidents_count'] ?> dossier(s) ouverts
              </div>
            </div>
            <?php if ((int)$service['overdue_plans_count'] > 0): ?>
              <span class="badge badge-red"><?= (int)$service['overdue_plans_count'] ?> retard</span>
            <?php else: ?>
              <span class="badge badge-green"><?= (int)$service['active_plans_count'] ?> plan(s)</span>
            <?php endif; ?>
          </div>
          <div class="services-mode-band" style="margin-top:12px">
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
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <span class="badge badge-gray"><?= (int)$service['active_plans_count'] ?> plan(s) actif(s)</span>
            <span class="badge badge-blue"><?= (int)$service['active_internal_plans_count'] ?> équipe interne</span>
            <span class="badge badge-purple"><?= (int)$service['active_provider_plans_count'] ?> prestataire</span>
            <a href="/admin/?page=services&detail=<?= (int)$service['id'] ?>" class="btn btn-outline btn-sm">Piloter</a>
            <a href="/admin/?page=incidents&service=<?= (int)$service['id'] ?>" class="btn btn-outline btn-sm">Ouvrir la file</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon canopy">📋</div>
    <div>
      <div class="stat-value"><?= $kpis['total'] ?></div>
      <div class="stat-label">Signalements enregistrés</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon awara">🕐</div>
    <div>
      <div class="stat-value"><?= $kpis['submitted'] ?></div>
      <div class="stat-label">À qualifier</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon river">⚙️</div>
    <div>
      <div class="stat-value"><?= $kpis['in_progress'] ?></div>
      <div class="stat-label">En traitement</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon leaf">✅</div>
    <div>
      <div class="stat-value"><?= $kpis['resolved'] ?></div>
      <div class="stat-label">Résolus</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon sand">📅</div>
    <div>
      <div class="stat-value"><?= $kpis['today'] ?></div>
      <div class="stat-label">Signalements aujourd'hui</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon laterite">👥</div>
    <div>
      <div class="stat-value"><?= $user_count ?></div>
      <div class="stat-label">Comptes actifs</div>
    </div>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon river">🗳️</div>
    <div>
      <div class="stat-value"><?= (int)$community['active_polls'] ?></div>
      <div class="stat-label">Consultations actives</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon leaf">🙋</div>
    <div>
      <div class="stat-value"><?= (int)$community['poll_votes'] ?></div>
      <div class="stat-label">Votes en cours</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon canopy">📅</div>
    <div>
      <div class="stat-value"><?= (int)$community['upcoming_events'] ?></div>
      <div class="stat-label">Rendez-vous à venir</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon awara">🤝</div>
    <div>
      <div class="stat-value"><?= (int)$community['event_attendees'] ?></div>
      <div class="stat-label">Participations annoncées</div>
    </div>
  </div>
</div>

<!-- Graphiques -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px;">

  <!-- Évolution 14 jours -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">📈 Signalements — 14 derniers jours</span>
    </div>
    <div class="chart-container">
      <canvas id="chartEvolution"></canvas>
    </div>
  </div>

  <!-- Répartition par catégorie -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">🏷️ Par catégorie</span>
    </div>
    <div class="chart-container">
      <canvas id="chartCategories"></canvas>
    </div>
    <div style="display:grid;gap:10px;padding:4px 6px 0;">
      <?php foreach ($by_cat as $cat): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #f1ece0;">
          <?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'sm', $cat['color'] ?? null) ?>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:700;color:#183229;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($cat['name']) ?></div>
            <div class="text-small text-muted"><?= (int)$cat['cnt'] ?> signalement<?= ((int)$cat['cnt']) > 1 ? 's' : '' ?></div>
          </div>
          <span class="badge" style="background:<?= e($cat['color']) ?>22;color:<?= e($cat['color']) ?>"><?= (int)$cat['cnt'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">🗳️ Concertation citoyenne</span>
      <a href="/admin/?page=polls" class="btn btn-outline btn-sm">Gérer →</a>
    </div>
    <?php if (empty($community_recent_polls)): ?>
      <p class="text-muted" style="padding:8px 4px 4px;">Aucune consultation active. Lancez une question flash pour sonder les priorités du territoire.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($community_recent_polls as $poll): ?>
          <div style="padding:14px;border:1px solid #ece4d5;border-radius:16px;background:#fffaf2;">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
              <div>
                <div style="font-weight:800;color:#1f2937;"><?= e($poll['title']) ?></div>
                <div class="text-small text-muted" style="margin-top:6px;">
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
      <span class="card-title">📅 Rendez-vous communaux</span>
      <a href="/admin/?page=events" class="btn btn-outline btn-sm">Planifier →</a>
    </div>
    <?php if (empty($community_upcoming_events)): ?>
      <p class="text-muted" style="padding:8px 4px 4px;">Aucun événement programmé. Publiez un rendez-vous pour donner de la visibilité à l’action communale.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($community_upcoming_events as $event): ?>
          <div style="padding:14px;border:1px solid #dce6ea;border-radius:16px;background:#f8fbfc;">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
              <div>
                <div style="font-weight:800;color:#1f2937;"><?= e($event['title']) ?></div>
                <div class="text-small text-muted" style="margin-top:6px;">
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

<!-- Derniers signalements -->
<div class="card">
  <div class="card-header">
    <span class="card-title">🕐 Derniers signalements</span>
    <a href="<?= e($scoped_incidents_link) ?>" class="btn btn-outline btn-sm">Voir tout →</a>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Référence</th>
          <th>Description</th>
          <th>Catégorie</th>
          <th>Statut</th>
          <th>Priorité</th>
          <th>Citoyen</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $inc): ?>
        <tr>
          <td><code style="font-size:11px"><?= e($inc['reference']) ?></code></td>
          <td><span class="truncate"><?= e($inc['description']) ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'sm', $inc['cat_color'] ?? null) ?>
              <?php $recentVisual = category_visual_resolve($inc['cat_icon'] ?? 'road', $inc['cat_name'] ?? null); ?>
              <div class="admin-category-cell-copy">
                <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
                  <?= e($inc['cat_name']) ?>
                </span>
                <div class="text-muted text-small"><?= e($recentVisual['description'] ?? '') ?></div>
              </div>
            </div>
          </td>
          <td><span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span></td>
          <td><span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span></td>
          <td><?= e($inc['reporter']) ?></td>
          <td class="text-muted text-small"><?= format_date_short($inc['created_at']) ?></td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-outline btn-sm">Voir</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <tr><td colspan="8" class="text-center text-muted" style="padding:32px">Aucun signalement pour le moment.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Graphique évolution
const evoData = <?= json_encode($evolution) ?>;
const evoLabels = evoData.map(d => {
  const dt = new Date(d.day);
  return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short' });
});
new Chart(document.getElementById('chartEvolution'), {
  type: 'line',
  data: {
    labels: evoLabels,
    datasets: [{
      label: 'Signalements',
      data: evoData.map(d => d.cnt),
      borderColor: '#2d6f86',
      backgroundColor: 'rgba(45,111,134,.10)',
      tension: 0.4,
      fill: true,
      pointBackgroundColor: '#2d6f86',
      pointRadius: 4,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
      x: { grid: { display: false } }
    }
  }
});

// Graphique catégories
const catData = <?= json_encode($by_cat) ?>;
new Chart(document.getElementById('chartCategories'), {
  type: 'doughnut',
  data: {
    labels: catData.map(c => c.name),
    datasets: [{
      data: catData.map(c => c.cnt),
      backgroundColor: catData.map(c => c.color),
      borderWidth: 2,
      borderColor: '#fff',
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 12 } }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
