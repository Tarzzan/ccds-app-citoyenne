<?php
/**
 * Ma Commune — Pilotage des services
 *
 * Surface légère pour :
 * - créer/éditer les services
 * - lire les catégories rattachées
 * - lire les agents rattachés
 * - visualiser la charge courante
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Services';
$active_nav = 'services';
$db         = Database::getInstance();
$lead_photo_select = admin_incident_first_photo_select($db, 'i');
$themePalette = visual_admin_data_palette();
$themePresets = visual_admin_theme_presets();

$tablesReady = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'user_service_memberships');
$latestPlansSql = "
    SELECT latest_plan.*
    FROM intervention_plans latest_plan
    INNER JOIN (
        SELECT incident_id, MAX(id) AS latest_id
        FROM intervention_plans
        GROUP BY incident_id
    ) latest_lookup ON latest_lookup.latest_id = latest_plan.id
";
$agent_service_scope_ids = admin_allowed_service_ids($admin);
$agent_is_scoped = admin_is_service_scoped_agent($admin);
$scope_notice = null;

if ($tablesReady && !admin_db_has_column($db, 'services', 'theme_variant')) {
    $db->exec("ALTER TABLE services ADD COLUMN theme_variant VARCHAR(255) DEFAULT NULL AFTER description");
}

if (!$tablesReady) {
    $_SESSION['flash_error'] = "Le socle services n'est pas encore disponible sur cet environnement. La lecture des files et des rattachements reste bloquée tant que cette base n est pas prête.";
    header('Location: /admin/?page=dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin['role'] === 'admin') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_service') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $themeVariant = trim($_POST['theme_variant'] ?? '');

        if ($name === '' || $code === '') {
            $_SESSION['flash_error'] = 'Nom et code sont obligatoires. Le service doit être identifiable à la fois par son libellé et par son code stable.';
        } else {
            $exists = $db->prepare('SELECT id FROM services WHERE code = ? LIMIT 1');
            $exists->execute([$code]);

            if ($exists->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['flash_error'] = 'Ce code service existe déjà. Réutiliser un code unique évite de brouiller les branchements internes.';
            } else {
                $stmt = $db->prepare('
                    INSERT INTO services (code, name, description, theme_variant, is_active, created_at)
                    VALUES (?, ?, ?, ?, 1, NOW())
                ');
                $stmt->execute([$code, $name, $description !== '' ? $description : null, $themeVariant !== '' ? $themeVariant : null]);
                $_SESSION['flash_success'] = 'Service créé. Il peut maintenant recevoir des rattachements, des catégories et une file dédiée.';
            }
        }

        header('Location: /admin/?page=services');
        exit;
    }

    if ($action === 'update_service') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $themeVariant = trim($_POST['theme_variant'] ?? '');

        if ($serviceId <= 0 || $name === '' || $code === '') {
            $_SESSION['flash_error'] = 'Données service invalides. Vérifier le nom, le code et le service ciblé avant d enregistrer.';
        } else {
            $exists = $db->prepare('SELECT id FROM services WHERE code = ? AND id <> ? LIMIT 1');
            $exists->execute([$code, $serviceId]);

            if ($exists->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['flash_error'] = 'Ce code service est déjà utilisé. Garder un code distinct reste nécessaire pour la lecture technique et métier.';
            } else {
                $stmt = $db->prepare('
                    UPDATE services
                    SET code = ?, name = ?, description = ?, theme_variant = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$code, $name, $description !== '' ? $description : null, $themeVariant !== '' ? $themeVariant : null, $serviceId]);
                $_SESSION['flash_success'] = 'Service mis à jour. La lecture du poste, des files et des rattachements est désormais alignée sur cette nouvelle version.';
            }
        }

        header('Location: /admin/?page=services&detail=' . $serviceId);
        exit;
    }

    if ($action === 'toggle_service') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($serviceId > 0) {
            $db->prepare('UPDATE services SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?')->execute([$serviceId]);
            $_SESSION['flash_success'] = 'Statut du service mis à jour. Vérifier ensuite l impact sur les files et les rattachements visibles.';
        }

        header('Location: /admin/?page=services');
        exit;
    }
}

$services = $db->query("
    SELECT
        s.*,
        COUNT(DISTINCT scm.category_id) AS categories_count,
        COUNT(DISTINCT usm.user_id) AS members_count,
        COUNT(DISTINCT CASE WHEN resolved_incident.status IN ('submitted', 'acknowledged', 'in_progress') THEN resolved_incident.id END) AS open_incidents_count,
        COUNT(DISTINCT CASE WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress') THEN current_plan.id END) AS active_plans_count,
        COUNT(DISTINCT CASE
            WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress')
             AND (current_plan.source_type = 'internal' OR current_plan.source_type IS NULL)
            THEN current_plan.id END) AS active_internal_plans_count,
        COUNT(DISTINCT CASE
            WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress')
             AND current_plan.source_type = 'provider'
            THEN current_plan.id END) AS active_provider_plans_count,
        COUNT(DISTINCT CASE
            WHEN current_plan.status IN ('scheduled', 'rescheduled', 'in_progress')
             AND current_plan.scheduled_date = CURDATE()
            THEN current_plan.id END) AS plans_today_count,
        COUNT(DISTINCT CASE
            WHEN current_plan.status IN ('scheduled', 'rescheduled')
             AND current_plan.scheduled_date IS NOT NULL
             AND current_plan.scheduled_date < CURDATE()
            THEN current_plan.id END) AS overdue_plans_count,
        COUNT(DISTINCT CASE
            WHEN resolved_incident.status = 'submitted'
             AND planned_lookup.plan_id IS NULL
            THEN resolved_incident.id END) AS unplanned_submitted_count
    FROM services s
    LEFT JOIN service_category_map scm ON scm.service_id = s.id
    LEFT JOIN user_service_memberships usm ON usm.service_id = s.id
    LEFT JOIN ($latestPlansSql) current_plan ON current_plan.service_id = s.id
    LEFT JOIN categories resolved_category ON resolved_category.id = scm.category_id
    LEFT JOIN incidents resolved_incident ON resolved_incident.category_id = resolved_category.id
    LEFT JOIN (
        SELECT incident_id, MAX(id) AS plan_id
        FROM intervention_plans
        GROUP BY incident_id
    ) planned_lookup ON planned_lookup.incident_id = resolved_incident.id
    GROUP BY s.id
    ORDER BY s.is_active DESC, s.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($agent_is_scoped) {
    if (!empty($agent_service_scope_ids)) {
        $services = array_values(array_filter(
            $services,
            static fn(array $service): bool => in_array((int)$service['id'], $agent_service_scope_ids, true)
        ));
        $scope_notice = $admin['primary_service_name']
            ? 'Votre pilotage est limite au service ' . $admin['primary_service_name'] . '.'
            : 'Votre pilotage est limite a vos services rattaches.';
    } else {
        $services = [];
        $scope_notice = 'Aucun service ne vous est encore attribue. Cette vue restera vide tant que le rattachement n est pas renseigne.';
    }
}

$detailService = null;
$serviceCategories = [];
$serviceMembers = [];
$servicePlans = [];
$serviceQueue = [];
$serviceCategoryHighlights = [];
$serviceRecentProofs = [];

if (isset($_GET['detail'])) {
    $detailId = (int)$_GET['detail'];
    foreach ($services as $service) {
        if ((int)$service['id'] === $detailId) {
            $detailService = $service;
            break;
        }
    }

    if ($detailId > 0 && $agent_is_scoped && !$detailService) {
        render_error(403, 'Ce service ne fait pas partie de votre perimetre.');
    }

    if ($detailService) {
        $stmt = $db->prepare("
            SELECT c.id, c.name, c.icon, c.color
            FROM service_category_map scm
            JOIN categories c ON c.id = scm.category_id
            WHERE scm.service_id = ?
            ORDER BY scm.is_default DESC, scm.priority_order ASC, c.name ASC
        ");
        $stmt->execute([$detailId]);
        $serviceCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($serviceCategories as $category) {
            $visual = category_visual_resolve($category['icon'] ?? 'road', $category['name'] ?? null);
            $serviceCategoryHighlights[] = [
                'name' => $category['name'],
                'icon' => $category['icon'] ?? 'road',
                'color' => $category['color'] ?? ($visual['accent'] ?? ($themePalette['primary'] ?? '#355160')),
                'short_label' => $visual['short_label'] ?? $category['name'],
                'description' => $visual['description'] ?? '',
            ];
        }

        $stmt = $db->prepare("
            SELECT
                u.id,
                u.full_name,
                u.email,
                u.role,
                usm.role_in_service,
                usm.is_primary
            FROM user_service_memberships usm
            JOIN users u ON u.id = usm.user_id
            WHERE usm.service_id = ?
            ORDER BY usm.is_primary DESC, u.full_name ASC
        ");
        $stmt->execute([$detailId]);
        $serviceMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            SELECT
                p.id,
                p.status,
                p.scheduled_date,
                p.time_window_start,
                p.time_window_end,
                p.source_type,
                p.provider_name,
                i.id AS incident_id,
                i.reference,
                i.title,
                i.status AS incident_status,
                assignee.full_name AS assigned_user_name
            FROM ($latestPlansSql) p
            JOIN incidents i ON i.id = p.incident_id
            LEFT JOIN users assignee ON assignee.id = p.assigned_user_id
            WHERE p.service_id = ?
            ORDER BY p.created_at DESC
            LIMIT 12
        ");
        $stmt->execute([$detailId]);
        $servicePlans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            SELECT
                i.id,
                i.reference,
                i.title,
                i.status,
                i.priority,
                i.created_at,
                i.updated_at,
                c.name AS category_name,
                c.icon AS category_icon,
                c.color AS category_color,
                reporter.full_name AS reporter_name,
                {$lead_photo_select},
                (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
                plan.id AS current_plan_id,
                plan.status AS current_plan_status,
                plan.scheduled_date,
                plan.time_window_start,
                plan.time_window_end,
                plan.source_type AS current_plan_source_type,
                plan.provider_name AS current_plan_provider_name,
                assignee.full_name AS assigned_user_name
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
            JOIN users reporter ON reporter.id = i.user_id
            LEFT JOIN intervention_plans plan
                ON plan.id = (
                    SELECT p2.id
                    FROM intervention_plans p2
                    WHERE p2.incident_id = i.id
                    ORDER BY p2.created_at DESC, p2.id DESC
                    LIMIT 1
                )
            LEFT JOIN users assignee ON assignee.id = plan.assigned_user_id
            WHERE scm.service_id = ?
              AND i.status IN ('submitted', 'acknowledged', 'in_progress')
            ORDER BY
              CASE i.priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                ELSE 4
              END,
              i.created_at ASC
            LIMIT 20
        ");
        $stmt->execute([$detailId]);
        $serviceQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($serviceQueue as &$queueItem) {
            $queueItem['lead_photo'] = admin_incident_preview_photo($db, $queueItem);
        }
        unset($queueItem);

        $serviceRecentProofs = admin_fetch_recent_proof_incidents($db, [
            'limit' => 4,
            'service_ids' => [$detailId],
            'only_open' => true,
        ]);
    }
}

$kpis = [
    'total' => count($services),
    'active' => count(array_filter($services, static fn(array $s): bool => (int)$s['is_active'] === 1)),
    'open_incidents' => array_sum(array_map(static fn(array $s): int => (int)$s['open_incidents_count'], $services)),
    'active_plans' => array_sum(array_map(static fn(array $s): int => (int)$s['active_plans_count'], $services)),
    'active_internal_plans' => array_sum(array_map(static fn(array $s): int => (int)$s['active_internal_plans_count'], $services)),
    'active_provider_plans' => array_sum(array_map(static fn(array $s): int => (int)$s['active_provider_plans_count'], $services)),
    'plans_today' => array_sum(array_map(static fn(array $s): int => (int)$s['plans_today_count'], $services)),
    'overdue_plans' => array_sum(array_map(static fn(array $s): int => (int)$s['overdue_plans_count'], $services)),
];
$servicesHeroPortraits = [
    ['asset' => visual_admin_slot_asset('services_primary', 'CHAR-04'), 'label' => 'Coordination service'],
    ['asset' => visual_admin_slot_asset('services_secondary', 'CHAR-05'), 'label' => 'Equipe terrain'],
];
$servicesHeroHasPortraits = false;
foreach ($servicesHeroPortraits as $heroPortrait) {
    if (generated_visual_url($heroPortrait['asset'])) {
        $servicesHeroHasPortraits = true;
        break;
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-async-scope" data-async-scope="services-admin">
<div class="page-hero <?= $servicesHeroHasPortraits ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Chaîne d intervention</div>
    <div class="page-hero-title">Relier les catégories, les agents et les opérations planifiées.</div>
    <?php if ($isTrainingMode): ?>
    <div class="page-hero-text">
      Cette page donne une lecture exploitable du dispositif metier : qui porte quoi, quelle charge est ouverte et quels services structurent la reponse de terrain.
    </div>
    <?php endif; ?>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['total'] ?></span>
      <span class="hero-chip-label">services configurés</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['active_plans'] ?></span>
      <span class="hero-chip-label">plans actifs</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['open_incidents'] ?></span>
      <span class="hero-chip-label">dossiers ouverts</span>
    </div>
  </div>
  <?php if ($servicesHeroHasPortraits): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <div class="dashboard-hero-portraits">
          <?php foreach ($servicesHeroPortraits as $heroPortrait): ?>
            <?php if (!generated_visual_url($heroPortrait['asset'])) { continue; } ?>
            <figure class="dashboard-hero-portrait-card">
              <?= generated_visual_html($heroPortrait['asset'], ['class' => 'generated-visual generated-visual--portrait dashboard-hero-portrait', 'label' => $heroPortrait['label']]) ?>
              <figcaption><?= e($heroPortrait['label']) ?></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
        <?php if ($isTrainingMode): ?>
        <div class="generated-visual-caption">
          <strong>Duo service terrain</strong>
          <span>Le pilotage des services revient vers un duo agents stylise pour lire la charge, les categories et les interventions sans rupture visuelle.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($scope_notice): ?>
  <div class="alert alert-info services-scope-alert"><?= e($scope_notice) ?></div>
<?php endif; ?>

<div class="services-grid">
  <div class="services-kpi"><strong><?= (int)$kpis['total'] ?></strong><span>services configurés</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['active'] ?></strong><span>services actifs</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['open_incidents'] ?></strong><span>dossiers ouverts via planification</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['active_plans'] ?></strong><span>interventions planifiées</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['active_internal_plans'] ?></strong><span>prises en charge en équipe interne</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['active_provider_plans'] ?></strong><span>missions prestataire en cours</span></div>
</div>

<div class="services-alert-band">
  <div class="services-alert is-info">
    <strong><?= (int)$kpis['plans_today'] ?></strong>
    <div>intervention(s) prévues aujourd hui</div>
  </div>
  <div class="services-alert is-warning">
    <strong><?= (int)$kpis['overdue_plans'] ?></strong>
    <div>planification(s) potentiellement en retard ou à reprogrammer</div>
  </div>
</div>

<div class="services-layout">
  <div class="services-card">
    <div class="card-header services-card-header-clean">
      <span class="card-title">Catalogue services</span>
    </div>

    <div class="services-list">
      <?php foreach ($services as $service): ?>
        <div class="services-row">
          <div>
            <div class="services-row-title"><?= e($service['name']) ?></div>
            <div class="text-muted text-small"><code><?= e($service['code']) ?></code></div>
            <?php if (!empty($service['description'])): ?>
              <div class="text-muted services-copy-gap"><?= e($service['description']) ?></div>
            <?php endif; ?>
            <div class="services-row-meta">
              <span class="services-mini-badge"><?= (int)$service['categories_count'] ?> catégorie(s)</span>
              <span class="services-mini-badge"><?= (int)$service['members_count'] ?> membre(s)</span>
              <span class="services-mini-badge"><?= (int)$service['active_plans_count'] ?> plan(s) actif(s)</span>
              <span class="services-mini-badge"><?= (int)$service['active_internal_plans_count'] ?> équipe interne</span>
              <span class="services-mini-badge"><?= (int)$service['active_provider_plans_count'] ?> prestataire</span>
              <span class="services-mini-badge"><?= (int)$service['open_incidents_count'] ?> dossier(s) ouvert(s)</span>
            </div>
          </div>
          <div class="services-row-actions">
            <span class="badge <?= (int)$service['is_active'] === 1 ? 'badge-green' : 'badge-gray' ?>">
              <?= (int)$service['is_active'] === 1 ? 'Actif' : 'Inactif' ?>
            </span>
            <a href="/admin/?page=services&detail=<?= (int)$service['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Détail</a>
            <?php if ($admin['role'] === 'admin'): ?>
              <form method="POST" data-async-form>
                <input type="hidden" name="action" value="toggle_service">
                <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                <button type="submit" class="btn <?= (int)$service['is_active'] === 1 ? 'btn-danger' : 'btn-primary' ?> btn-sm">
                  <?= (int)$service['is_active'] === 1 ? 'Désactiver' : 'Activer' ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="services-card">
    <div class="card-header services-card-header-clean">
      <span class="card-title"><?= $detailService ? 'Service en focus' : 'Nouveau service' ?></span>
    </div>

    <?php if ($detailService): ?>
      <div class="card services-focus-card">
        <div class="card-header services-card-header-tight">
          <span class="card-title">Cockpit d execution du service</span>
          <span class="text-muted text-small">Lire ici ce qui doit etre planifie, execute ou relance avant d editer le service.</span>
        </div>
        <div class="services-mode-band services-mode-band--tight">
          <div class="services-mode-card">
            <strong><?= (int)$detailService['unplanned_submitted_count'] ?></strong>
            <span>a planifier</span>
          </div>
          <div class="services-mode-card">
            <strong><?= (int)$detailService['plans_today_count'] ?></strong>
            <span>prevues aujourd hui</span>
          </div>
          <div class="services-mode-card">
            <strong><?= (int)$detailService['overdue_plans_count'] ?></strong>
            <span>en retard</span>
          </div>
        </div>
        <div class="services-mode-band services-mode-band--spaced">
          <div class="services-mode-card">
            <strong><?= (int)$detailService['active_internal_plans_count'] ?></strong>
            <span>equipe interne</span>
          </div>
          <div class="services-mode-card">
            <strong><?= (int)$detailService['active_provider_plans_count'] ?></strong>
            <span>prestataire</span>
          </div>
          <div class="services-mode-card">
            <strong><?= (int)$detailService['open_incidents_count'] ?></strong>
            <span>dossiers ouverts</span>
          </div>
        </div>
      </div>

      <?php if ($isTrainingMode): ?>
      <div class="admin-form-guide">
        <strong>Edition du service</strong>
        <div class="admin-form-guide-list">
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">01</span>
            <div>
              <strong>Stabiliser le nom et le code</strong>
              <span>Ces deux champs servent de repere dans les vues admin et dans les rattachements de categories ou d agents.</span>
            </div>
          </div>
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">02</span>
            <div>
              <strong>Documenter le perimetre</strong>
              <span>La description doit dire ce que le service prend en charge, pas simplement reformuler son nom.</span>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <form method="POST" data-async-form>
        <input type="hidden" name="action" value="update_service">
        <input type="hidden" name="service_id" value="<?= (int)$detailService['id'] ?>">
        <div class="form-group">
          <label class="form-label">Nom</label>
          <input type="text" name="name" class="form-control" required value="<?= e($detailService['name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Code</label>
          <input type="text" name="code" class="form-control" required value="<?= e($detailService['code']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="4"><?= e((string)($detailService['description'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Thème visuel exclusif (Optionnel)</label>
          <select name="theme_variant" class="form-control">
            <option value="">Par défaut (Socle commun)</option>
            <?php foreach ($themePresets as $presetId => $preset): ?>
              <option value="<?= e($presetId) ?>" <?= ($detailService['theme_variant'] ?? '') === $presetId ? 'selected' : '' ?>><?= e((string)$preset['label']) ?> <?= ($preset['is_custom'] ?? false) ? '(Perso)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a href="/admin/?page=services" class="btn btn-outline" data-async-link data-async-scope="admin-main">Fermer le focus</a>
      </form>

      <div class="services-mode-band">
        <div class="services-mode-card">
          <strong><?= (int)$detailService['active_internal_plans_count'] ?></strong>
          <span>intervention(s) en equipe interne</span>
        </div>
        <div class="services-mode-card">
          <strong><?= (int)$detailService['active_provider_plans_count'] ?></strong>
          <span>mission(s) prestataire en cours</span>
        </div>
        <div class="services-mode-card">
          <strong><?= (int)$detailService['unplanned_submitted_count'] ?></strong>
          <span>dossier(s) encore sans plan visible</span>
        </div>
      </div>

      <?php if ($serviceCategoryHighlights): ?>
        <div class="services-category-strip">
          <?php foreach ($serviceCategoryHighlights as $highlight): ?>
            <div class="services-category-pill" style="--category-accent:<?= e($highlight['color']) ?>;">
              <?= category_visual_html($highlight['icon'], $highlight['name'], 'md', $highlight['color']) ?>
              <div>
                <strong><?= e($highlight['short_label']) ?></strong>
                <span><?= e($highlight['description']) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($serviceRecentProofs): ?>
        <div class="card dashboard-section-card services-detail-card services-detail-card--spaced">
          <div class="card-header card-header--split">
            <div>
              <span class="card-title">Dernières preuves citoyennes du service</span>
              <p class="admin-section-note">Lecture directe des dernières pièces terrain sans repasser par toute la file du service.</p>
            </div>
            <a href="/admin/?page=incidents&service=<?= (int)$detailService['id'] ?>&proof=with_photo" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Ouvrir la file avec preuves</a>
          </div>
          <div class="dashboard-proof-grid">
            <?php foreach ($serviceRecentProofs as $proofIncident): ?>
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

      <div class="services-detail-grid">
        <div class="services-card services-detail-card">
          <div class="services-section-head">
            <div>
              <h3 class="services-section-title">Catégories rattachées</h3>
              <p class="admin-section-note">Socle métier porté par le service dans la chaîne de traitement.</p>
            </div>
            <span class="badge badge-gray"><?= count($serviceCategories) ?> categorie<?= count($serviceCategories) > 1 ? 's' : '' ?></span>
          </div>
          <div class="services-detail-list">
            <?php foreach ($serviceCategories as $category): ?>
              <div class="services-detail-item">
                <div class="services-queue-category">
                  <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'md', $category['color'] ?? null) ?>
                  <strong><?= e($category['name']) ?></strong>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$serviceCategories): ?>
              <p class="text-muted">Aucune catégorie rattachée. Le service reste donc sans porte d entrée métier claire pour les prochains dossiers.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="services-card services-detail-card">
          <div class="services-section-head">
            <div>
              <h3 class="services-section-title">Agents et responsables</h3>
              <p class="admin-section-note">Qui agit sur ce service et avec quel niveau de responsabilité.</p>
            </div>
            <span class="badge badge-gray"><?= count($serviceMembers) ?> membre<?= count($serviceMembers) > 1 ? 's' : '' ?></span>
          </div>
          <div class="services-detail-list">
            <?php foreach ($serviceMembers as $member): ?>
              <div class="services-detail-item">
                <strong><?= e($member['full_name']) ?></strong>
                <div class="text-muted text-small"><?= e($member['email']) ?></div>
                <div class="text-muted text-small">
                  <?= e(ucfirst($member['role_in_service'])) ?>
                  <?= !empty($member['is_primary']) ? ' · principal' : '' ?>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$serviceMembers): ?>
              <p class="text-muted">Aucun agent rattaché. Ce service n a pas encore de relais humain visible dans le backoffice.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="services-card services-detail-card services-detail-card--spaced">
        <div class="services-section-head">
          <div>
            <h3 class="services-section-title">Dernières interventions planifiées</h3>
            <p class="admin-section-note">Derniers dossiers déjà portés par une planification visible côté service.</p>
          </div>
          <span class="badge badge-gray"><?= count($servicePlans) ?> plan<?= count($servicePlans) > 1 ? 's' : '' ?></span>
        </div>
        <div class="services-detail-list">
          <?php foreach ($servicePlans as $plan): ?>
            <div class="services-detail-item">
              <strong><?= e($plan['reference']) ?> · <?= e($plan['title'] ?: 'Sans titre') ?></strong>
              <div class="text-muted text-small">
                <?= e(ucfirst(str_replace('_', ' ', $plan['status']))) ?>
                · <?= e($plan['scheduled_date'] ?: 'date non définie') ?>
                <?php if (!empty($plan['time_window_start']) || !empty($plan['time_window_end'])): ?>
                  · <?= e(trim(implode(' - ', array_filter([$plan['time_window_start'], $plan['time_window_end']])))) ?>
                <?php endif; ?>
              </div>
              <div class="text-muted text-small">
                Dossier <?= e($plan['incident_status']) ?>
                <?= !empty($plan['assigned_user_name']) ? ' · ' . e($plan['assigned_user_name']) : '' ?>
              </div>
              <div class="services-plan-mode">
                <?php if (($plan['source_type'] ?? '') === 'provider'): ?>
                  Prestataire missionné<?= !empty($plan['provider_name']) ? ' · ' . e($plan['provider_name']) : '' ?>
                <?php else: ?>
                  Équipe interne
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$servicePlans): ?>
            <p class="text-muted">Aucune intervention planifiée sur ce service. La file reste lisible, mais aucune date d exécution n est encore portée ici.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="services-card services-detail-card services-detail-card--spaced">
        <div class="services-queue-head">
          <div>
            <h3 class="services-section-title">File active du service</h3>
            <p class="admin-section-note">Prioriser ici les dossiers à traiter, planifier ou relancer avec preuve et plan visibles.</p>
          </div>
          <div class="services-queue-head-actions">
            <span class="badge badge-gray"><?= count($serviceQueue) ?> dossier<?= count($serviceQueue) > 1 ? 's' : '' ?></span>
            <a href="/admin/?page=incidents&service=<?= (int)$detailService['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Ouvrir toute la file</a>
          </div>
        </div>
        <?php if ($serviceQueue): ?>
          <div class="table-wrapper">
            <table class="services-queue-table">
              <thead>
                <tr>
                  <th>Dossier</th>
                  <th>Catégorie</th>
                  <th>Preuve</th>
                  <th>État & Priorité</th>
                  <th>Plan</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($serviceQueue as $queueItem): ?>
                  <tr>
                    <td>
                      <div class="services-queue-ref"><?= e($queueItem['reference']) ?></div>
                      <div class="text-muted text-small"><?= e($queueItem['title'] ?: 'Sans titre') ?></div>
                      <div class="text-muted text-small"><?= e($queueItem['reporter_name']) ?></div>
                    </td>
                    <td>
                      <div class="services-queue-category">
                        <?= category_visual_html($queueItem['category_icon'] ?? 'road', $queueItem['category_name'], 'sm', $queueItem['category_color'] ?? null) ?>
                        <div class="services-queue-category-copy">
                          <strong><?= e($queueItem['category_name']) ?></strong>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="admin-proof-cell admin-proof-cell--compact">
                        <?php if (!empty($queueItem['lead_photo']['url'])): ?>
                          <a href="/admin/?page=incident_detail&id=<?= (int)$queueItem['id'] ?>" class="admin-proof-thumb-link" data-async-link data-async-scope="admin-main" aria-label="Voir la preuve citoyenne">
                            <span class="admin-proof-thumb-wrap">
                              <img src="<?= e($queueItem['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb admin-proof-thumb--small">
                              <?php if ((int)($queueItem['photo_count'] ?? 0) > 1): ?>
                                <span class="admin-proof-thumb-badge">+<?= (int)$queueItem['photo_count'] - 1 ?></span>
                              <?php endif; ?>
                            </span>
                          </a>
                        <?php else: ?>
                          <div class="admin-proof-thumb admin-proof-thumb--small admin-proof-thumb--empty">Aucune photo</div>
                        <?php endif; ?>
                        <div class="admin-proof-copy">
                          <?php if ((int)($queueItem['photo_count'] ?? 0) > 0): ?>
                            <div class="admin-proof-counts">
                              <span class="badge badge-gray"><?= (int)$queueItem['photo_count'] ?> photo<?= (int)$queueItem['photo_count'] > 1 ? 's' : '' ?></span>
                            </div>
                          <?php endif; ?>
                          <?php if (!empty($queueItem['lead_photo']['moderation_message'])): ?>
                            <div class="admin-proof-note"><?= e($queueItem['lead_photo']['moderation_message']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                        <span class="badge <?= status_class($queueItem['status']) ?>"><?= status_label($queueItem['status']) ?></span>
                        <span class="badge <?= priority_class($queueItem['priority'] ?? 'medium') ?>"><?= priority_label($queueItem['priority'] ?? 'medium') ?></span>
                      </div>
                    </td>
                    <td>
                      <?php if (!empty($queueItem['current_plan_id'])): ?>
                        <div class="services-queue-plan-title">
                          <?= e(ucfirst(str_replace('_', ' ', (string)$queueItem['current_plan_status']))) ?>
                        </div>
                        <div class="text-muted text-small">
                          <?= e($queueItem['scheduled_date'] ?: 'date non definie') ?>
                          <?php if (!empty($queueItem['time_window_start']) || !empty($queueItem['time_window_end'])): ?>
                            · <?= e(trim(implode(' - ', array_filter([$queueItem['time_window_start'], $queueItem['time_window_end']])))) ?>
                          <?php endif; ?>
                        </div>
                        <?php if (!empty($queueItem['assigned_user_name'])): ?>
                          <div class="text-muted text-small"><?= e($queueItem['assigned_user_name']) ?></div>
                        <?php endif; ?>
                        <div class="services-plan-mode">
                          <?php if (($queueItem['current_plan_source_type'] ?? '') === 'provider'): ?>
                            Prestataire missionné<?= !empty($queueItem['current_plan_provider_name']) ? ' · ' . e($queueItem['current_plan_provider_name']) : '' ?>
                          <?php else: ?>
                            Équipe interne
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="badge badge-yellow">Pas encore planifié</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a href="/admin/?page=incident_detail&id=<?= (int)$queueItem['id'] ?>" class="btn btn-primary btn-sm" data-async-link data-async-scope="admin-main">Traiter</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted">Aucun dossier ouvert pour ce service actuellement. C est un état sain tant qu aucune charge nouvelle n est remontée.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php if ($isTrainingMode): ?>
      <div class="admin-form-guide">
        <strong>Creation d un service</strong>
        <div class="admin-form-guide-list">
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">01</span>
            <div>
              <strong>Nommer le service comme une equipe exploitable</strong>
              <span>Le nom doit rester clair dans les files, les categories et les details utilisateur.</span>
            </div>
          </div>
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">02</span>
            <div>
              <strong>Utiliser un code stable</strong>
              <span>Le code doit rester court, lisible et durable pour les branchements internes.</span>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <form method="POST" data-async-form>
        <input type="hidden" name="action" value="create_service">
        <div class="form-group">
          <label class="form-label">Nom</label>
          <input type="text" name="name" class="form-control" required placeholder="Ex: Voirie de proximité">
        </div>
        <div class="form-group">
          <label class="form-label">Code</label>
          <input type="text" name="code" class="form-control" required placeholder="Ex: voirie_proximite">
          <?php if ($isTrainingMode): ?>
          <div class="admin-section-note">Preferer un code simple, sans ambiguite, qui pourra rester stable meme si le libelle evolue.</div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="4" placeholder="Mission, périmètre, nature des interventions..."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Thème visuel exclusif (Optionnel)</label>
          <select name="theme_variant" class="form-control">
            <option value="">Par défaut (Socle commun)</option>
            <?php foreach ($themePresets as $presetId => $preset): ?>
              <option value="<?= e($presetId) ?>"><?= e((string)$preset['label']) ?> <?= ($preset['is_custom'] ?? false) ? '(Perso)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Créer le service</button>
      </form>
    <?php endif; ?>
  </div>
</div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
