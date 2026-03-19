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

$tablesReady = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'user_service_memberships');

if (!$tablesReady) {
    $_SESSION['flash_error'] = "Le socle services n'est pas encore disponible sur cet environnement.";
    header('Location: /admin/?page=dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin['role'] === 'admin') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_service') {
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $code === '') {
            $_SESSION['flash_error'] = 'Nom et code sont obligatoires.';
        } else {
            $exists = $db->prepare('SELECT id FROM services WHERE code = ? LIMIT 1');
            $exists->execute([$code]);

            if ($exists->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['flash_error'] = 'Ce code service existe deja.';
            } else {
                $stmt = $db->prepare('
                    INSERT INTO services (code, name, description, is_active, created_at)
                    VALUES (?, ?, ?, 1, NOW())
                ');
                $stmt->execute([$code, $name, $description !== '' ? $description : null]);
                $_SESSION['flash_success'] = 'Service cree avec succes.';
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

        if ($serviceId <= 0 || $name === '' || $code === '') {
            $_SESSION['flash_error'] = 'Donnees service invalides.';
        } else {
            $exists = $db->prepare('SELECT id FROM services WHERE code = ? AND id <> ? LIMIT 1');
            $exists->execute([$code, $serviceId]);

            if ($exists->fetch(PDO::FETCH_ASSOC)) {
                $_SESSION['flash_error'] = 'Ce code service est deja utilise.';
            } else {
                $stmt = $db->prepare('
                    UPDATE services
                    SET code = ?, name = ?, description = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$code, $name, $description !== '' ? $description : null, $serviceId]);
                $_SESSION['flash_success'] = 'Service mis a jour.';
            }
        }

        header('Location: /admin/?page=services&detail=' . $serviceId);
        exit;
    }

    if ($action === 'toggle_service') {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        if ($serviceId > 0) {
            $db->prepare('UPDATE services SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?')->execute([$serviceId]);
            $_SESSION['flash_success'] = 'Statut du service mis a jour.';
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
        COUNT(DISTINCT CASE WHEN p.status IN ('scheduled', 'rescheduled', 'in_progress') THEN p.id END) AS active_plans_count,
        COUNT(DISTINCT CASE
            WHEN p.status IN ('scheduled', 'rescheduled', 'in_progress')
             AND p.scheduled_date = CURDATE()
            THEN p.id END) AS plans_today_count,
        COUNT(DISTINCT CASE
            WHEN p.status IN ('scheduled', 'rescheduled')
             AND p.scheduled_date IS NOT NULL
             AND p.scheduled_date < CURDATE()
            THEN p.id END) AS overdue_plans_count,
        COUNT(DISTINCT CASE
            WHEN resolved_incident.status = 'submitted'
             AND planned_lookup.plan_id IS NULL
            THEN resolved_incident.id END) AS unplanned_submitted_count
    FROM services s
    LEFT JOIN service_category_map scm ON scm.service_id = s.id
    LEFT JOIN user_service_memberships usm ON usm.service_id = s.id
    LEFT JOIN intervention_plans p ON p.service_id = s.id
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

$detailService = null;
$serviceCategories = [];
$serviceMembers = [];
$servicePlans = [];
$serviceQueue = [];

if (isset($_GET['detail'])) {
    $detailId = (int)$_GET['detail'];
    foreach ($services as $service) {
        if ((int)$service['id'] === $detailId) {
            $detailService = $service;
            break;
        }
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
                i.id AS incident_id,
                i.reference,
                i.title,
                i.status AS incident_status,
                assignee.full_name AS assigned_user_name
            FROM intervention_plans p
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
                plan.id AS current_plan_id,
                plan.status AS current_plan_status,
                plan.scheduled_date,
                plan.time_window_start,
                plan.time_window_end,
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
    }
}

$kpis = [
    'total' => count($services),
    'active' => count(array_filter($services, static fn(array $s): bool => (int)$s['is_active'] === 1)),
    'open_incidents' => array_sum(array_map(static fn(array $s): int => (int)$s['open_incidents_count'], $services)),
    'active_plans' => array_sum(array_map(static fn(array $s): int => (int)$s['active_plans_count'], $services)),
    'plans_today' => array_sum(array_map(static fn(array $s): int => (int)$s['plans_today_count'], $services)),
    'overdue_plans' => array_sum(array_map(static fn(array $s): int => (int)$s['overdue_plans_count'], $services)),
];

require_once __DIR__ . '/../includes/layout.php';
?>
<style>
.services-hero {
  background: linear-gradient(135deg, #0e3127 0%, #174b3a 56%, #2d6f86 100%);
  color: #fff;
  border-radius: 28px;
  padding: 28px;
  margin-bottom: 24px;
  box-shadow: 0 18px 38px rgba(14,49,39,.16);
}
.services-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #f2d58c;
  margin-bottom: 10px;
}
.services-title {
  font-size: 30px;
  font-family: 'Merriweather', serif;
  font-weight: 900;
  line-height: 1.15;
  margin-bottom: 10px;
}
.services-text {
  color: rgba(255,255,255,.84);
  max-width: 720px;
}
.services-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.services-kpi {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 20px;
  padding: 18px;
  box-shadow: 0 10px 24px rgba(14,49,39,.06);
}
.services-kpi strong {
  display: block;
  font-size: 28px;
  color: #183229;
}
.services-kpi span {
  display: block;
  margin-top: 4px;
  color: #5e6c67;
}
.services-alert-band {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.services-alert {
  border-radius: 20px;
  padding: 18px;
  border: 1px solid #ece4d5;
  background: rgba(255,253,248,.94);
  box-shadow: 0 10px 24px rgba(14,49,39,.06);
}
.services-alert strong {
  display: block;
  font-size: 24px;
  margin-bottom: 6px;
}
.services-alert.is-warning strong {
  color: #a64b2a;
}
.services-alert.is-info strong {
  color: #2d6f86;
}
.services-layout {
  display: grid;
  grid-template-columns: 1.5fr .9fr;
  gap: 24px;
}
.services-card {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 22px;
  padding: 20px;
  box-shadow: 0 10px 24px rgba(14,49,39,.06);
}
.services-list {
  display: grid;
  gap: 12px;
}
.services-row {
  border: 1px solid #ece4d5;
  border-radius: 18px;
  padding: 16px;
  display: flex;
  justify-content: space-between;
  gap: 14px;
  background: #fffdf8;
}
.services-row-title {
  font-size: 17px;
  font-weight: 800;
  color: #183229;
}
.services-row-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 8px;
}
.services-mini-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 999px;
  background: #f3eee2;
  color: #355248;
  font-size: 12px;
  font-weight: 700;
}
.services-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 18px;
}
.services-detail-list {
  display: grid;
  gap: 10px;
}
.services-detail-item {
  padding: 12px 14px;
  border-radius: 16px;
  background: #f8f3e8;
  border: 1px solid #ece4d5;
}
.services-queue-table {
  width: 100%;
  border-collapse: collapse;
}
.services-queue-table th,
.services-queue-table td {
  padding: 12px 10px;
  border-bottom: 1px solid #ece4d5;
  vertical-align: top;
}
.services-queue-table th {
  text-align: left;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #5e6c67;
}
@media (max-width: 960px) {
  .services-layout,
  .services-detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<div class="services-hero">
  <div class="services-kicker">Chaîne d intervention</div>
  <div class="services-title">Relier les catégories, les agents et les opérations planifiées.</div>
  <div class="services-text">
    Cette page donne enfin une lecture exploitable du dispositif métier : qui porte quoi, quelle charge est ouverte et quels services structurent la réponse communale.
  </div>
</div>

<div class="services-grid">
  <div class="services-kpi"><strong><?= (int)$kpis['total'] ?></strong><span>services configurés</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['active'] ?></strong><span>services actifs</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['open_incidents'] ?></strong><span>dossiers ouverts via planification</span></div>
  <div class="services-kpi"><strong><?= (int)$kpis['active_plans'] ?></strong><span>interventions planifiées</span></div>
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
    <div class="card-header" style="padding:0 0 16px;border:none">
      <span class="card-title">Catalogue services</span>
    </div>

    <div class="services-list">
      <?php foreach ($services as $service): ?>
        <div class="services-row">
          <div>
            <div class="services-row-title"><?= e($service['name']) ?></div>
            <div class="text-muted text-small"><code><?= e($service['code']) ?></code></div>
            <?php if (!empty($service['description'])): ?>
              <div class="text-muted" style="margin-top:8px"><?= e($service['description']) ?></div>
            <?php endif; ?>
            <div class="services-row-meta">
              <span class="services-mini-badge"><?= (int)$service['categories_count'] ?> catégorie(s)</span>
              <span class="services-mini-badge"><?= (int)$service['members_count'] ?> membre(s)</span>
              <span class="services-mini-badge"><?= (int)$service['active_plans_count'] ?> plan(s) actif(s)</span>
              <span class="services-mini-badge"><?= (int)$service['open_incidents_count'] ?> dossier(s) ouvert(s)</span>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;min-width:140px">
            <span class="badge <?= (int)$service['is_active'] === 1 ? 'badge-green' : 'badge-gray' ?>">
              <?= (int)$service['is_active'] === 1 ? 'Actif' : 'Inactif' ?>
            </span>
            <a href="/admin/?page=services&detail=<?= (int)$service['id'] ?>" class="btn btn-outline btn-sm">Détail</a>
            <?php if ($admin['role'] === 'admin'): ?>
              <form method="POST">
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
    <div class="card-header" style="padding:0 0 16px;border:none">
      <span class="card-title"><?= $detailService ? 'Service en focus' : 'Nouveau service' ?></span>
    </div>

    <?php if ($detailService): ?>
      <form method="POST">
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
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a href="/admin/?page=services" class="btn btn-outline">Fermer le focus</a>
      </form>

      <div class="services-detail-grid">
        <div class="services-card" style="padding:16px">
          <h3 style="margin-bottom:12px;color:#183229">Catégories rattachées</h3>
          <div class="services-detail-list">
            <?php foreach ($serviceCategories as $category): ?>
              <div class="services-detail-item">
                <div style="display:flex;align-items:center;gap:10px">
                  <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'sm', $category['color'] ?? null) ?>
                  <strong><?= e($category['name']) ?></strong>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$serviceCategories): ?>
              <p class="text-muted">Aucune catégorie rattachée.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="services-card" style="padding:16px">
          <h3 style="margin-bottom:12px;color:#183229">Agents et responsables</h3>
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
              <p class="text-muted">Aucun agent rattaché.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="services-card" style="padding:16px;margin-top:18px">
        <h3 style="margin-bottom:12px;color:#183229">Dernières interventions planifiées</h3>
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
            </div>
          <?php endforeach; ?>
          <?php if (!$servicePlans): ?>
            <p class="text-muted">Aucune intervention planifiée sur ce service.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="services-card" style="padding:16px;margin-top:18px">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
          <h3 style="color:#183229">File active du service</h3>
          <a href="/admin/?page=incidents&service=<?= (int)$detailService['id'] ?>" class="btn btn-outline btn-sm">Ouvrir toute la file</a>
        </div>
        <?php if ($serviceQueue): ?>
          <div class="table-wrapper">
            <table class="services-queue-table">
              <thead>
                <tr>
                  <th>Dossier</th>
                  <th>Catégorie</th>
                  <th>Statut</th>
                  <th>Plan</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($serviceQueue as $queueItem): ?>
                  <tr>
                    <td>
                      <div style="font-weight:800;color:#183229"><?= e($queueItem['reference']) ?></div>
                      <div class="text-muted text-small"><?= e($queueItem['title'] ?: 'Sans titre') ?></div>
                      <div class="text-muted text-small"><?= e($queueItem['reporter_name']) ?></div>
                    </td>
                    <td>
                      <div style="display:flex;align-items:center;gap:8px">
                        <?= category_visual_html($queueItem['category_icon'] ?? 'road', $queueItem['category_name'], 'sm', $queueItem['category_color'] ?? null) ?>
                        <span><?= e($queueItem['category_name']) ?></span>
                      </div>
                    </td>
                    <td>
                      <span class="badge <?= status_class($queueItem['status']) ?>"><?= status_label($queueItem['status']) ?></span>
                      <div class="text-muted text-small" style="margin-top:6px">
                        <?= priority_label($queueItem['priority'] ?? 'medium') ?>
                      </div>
                    </td>
                    <td>
                      <?php if (!empty($queueItem['current_plan_id'])): ?>
                        <div style="font-weight:700;color:#183229">
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
                      <?php else: ?>
                        <span class="badge badge-yellow">Pas encore planifié</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a href="/admin/?page=incident_detail&id=<?= (int)$queueItem['id'] ?>" class="btn btn-primary btn-sm">Traiter</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted">Aucun dossier ouvert pour ce service actuellement.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <form method="POST">
        <input type="hidden" name="action" value="create_service">
        <div class="form-group">
          <label class="form-label">Nom</label>
          <input type="text" name="name" class="form-control" required placeholder="Ex: Voirie de proximité">
        </div>
        <div class="form-group">
          <label class="form-label">Code</label>
          <input type="text" name="code" class="form-control" required placeholder="Ex: voirie_proximite">
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="4" placeholder="Mission, périmètre, nature des interventions..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Créer le service</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
