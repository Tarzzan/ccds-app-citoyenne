<?php
/**
 * Ma Commune Back-Office — Recherche globale
 * Recherche simultanée dans incidents, utilisateurs et catégories.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Recherche';
$active_nav = 'search';
$db         = Database::getInstance();
$service_tables_ready = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'intervention_plans');
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;

$query   = trim($_GET['q'] ?? '');
$results = ['incidents' => [], 'users' => [], 'categories' => []];
$total   = 0;

if (strlen($query) >= 2) {
    $like = '%' . $query . '%';
    $incidentScopeJoin = $service_tables_ready ? "
        LEFT JOIN service_category_map scoped_scm ON scoped_scm.category_id = cat.id AND scoped_scm.is_default = 1
        LEFT JOIN services mapped_service ON mapped_service.id = scoped_scm.service_id
        LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
            SELECT p2.id
            FROM intervention_plans p2
            WHERE p2.incident_id = i.id
            ORDER BY p2.created_at DESC, p2.id DESC
            LIMIT 1
        )
        LEFT JOIN services plan_service ON plan_service.id = latest_plan.service_id
    " : '';
    $incidentScopeWhere = '';
    $categoryScopeJoin = '';
    $categoryScopeWhere = '';

    if ($agent_is_scoped) {
        if (!empty($agent_service_scope_ids)) {
            $safeServiceIds = implode(',', array_map('intval', $agent_service_scope_ids));
            $incidentScopeWhere = " AND COALESCE(latest_plan.service_id, scoped_scm.service_id) IN ($safeServiceIds)";
            $categoryScopeJoin = "JOIN service_category_map scoped_scm ON scoped_scm.category_id = c.id AND scoped_scm.is_default = 1";
            $categoryScopeWhere = " AND scoped_scm.service_id IN ($safeServiceIds)";
            $scope_notice = $admin['primary_service_name']
                ? 'La recherche est limitee au service ' . $admin['primary_service_name'] . '.'
                : 'La recherche est limitee a vos services rattaches.';
        } else {
            $incidentScopeWhere = ' AND 1 = 0';
            $categoryScopeWhere = ' AND 1 = 0';
            $scope_notice = 'Aucun service ne vous est encore attribue. Les resultats incidents resteront vides tant que le rattachement n est pas renseigne.';
        }
    }

    $stmtInc = $db->prepare("
        SELECT i.id, i.reference, i.title, i.status, i.votes_count, i.created_at,
               cat.name AS category_name, cat.icon AS category_icon,
               u.full_name AS reporter_name,
               " . ($service_tables_ready ? "
               COALESCE(plan_service.name, mapped_service.name) AS service_name,
               latest_plan.status AS current_plan_status,
               latest_plan.scheduled_date AS current_plan_date,
               latest_plan.time_window_start AS current_plan_time_start,
               latest_plan.time_window_end AS current_plan_time_end,
               latest_plan.source_type AS current_plan_source_type,
               latest_plan.provider_name AS current_plan_provider_name
               " : "
               NULL AS service_name,
               NULL AS current_plan_status,
               NULL AS current_plan_date,
               NULL AS current_plan_time_start,
               NULL AS current_plan_time_end,
               NULL AS current_plan_source_type,
               NULL AS current_plan_provider_name
               ") . "
        FROM incidents i
        JOIN categories cat ON cat.id = i.category_id
        JOIN users u ON u.id = i.user_id
        $incidentScopeJoin
        WHERE (i.reference LIKE ? OR i.title LIKE ? OR i.description LIKE ? OR i.address LIKE ?)
        $incidentScopeWhere
        ORDER BY i.created_at DESC
        LIMIT 10
    ");
    $stmtInc->execute([$like, $like, $like, $like]);
    $results['incidents'] = $stmtInc->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results['incidents'] as &$incident) {
        $visual = category_visual_resolve($incident['category_icon'] ?? null, $incident['category_name'] ?? null);
        $incident['category_description'] = $visual['description'] ?? '';
    }
    unset($incident);

    if (!$agent_is_scoped) {
        $stmtUsr = $db->prepare("
            SELECT id, full_name, email, phone, role, is_active, created_at,
                   (SELECT COUNT(*) FROM incidents WHERE user_id = users.id) AS incidents_count
            FROM users
            WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmtUsr->execute([$like, $like, $like]);
        $results['users'] = $stmtUsr->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmtCat = $db->prepare("
        SELECT c.id, c.name, c.icon, c.color, c.is_active,
               COUNT(i.id) AS incidents_count
        FROM categories c
        $categoryScopeJoin
        LEFT JOIN incidents i ON i.category_id = c.id
        WHERE c.name LIKE ?
        $categoryScopeWhere
        GROUP BY c.id
        ORDER BY incidents_count DESC
        LIMIT 5
    ");
    $stmtCat->execute([$like]);
    $results['categories'] = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results['categories'] as &$category) {
        $visual = category_visual_resolve($category['icon'] ?? null, $category['name'] ?? null);
        $category['visual_description'] = $visual['description'] ?? '';
    }
    unset($category);

    $total = count($results['incidents']) + count($results['users']) + count($results['categories']);
}

function search_status_label(string $status): string
{
    return [
        'submitted'    => 'Soumis',
        'acknowledged' => 'Pris en charge',
        'in_progress'  => 'En cours',
        'resolved'     => 'Résolu',
        'rejected'     => 'Rejeté',
    ][$status] ?? $status;
}

function search_status_color(string $status): string
{
    return [
        'submitted'    => '#D48B2C',
        'acknowledged' => '#2D6F86',
        'in_progress'  => '#A64B2A',
        'resolved'     => '#2F7D50',
        'rejected'     => '#C94B3C',
    ][$status] ?? '#5E6C67';
}

function search_plan_label(?string $status): string
{
    return [
        'scheduled'   => 'Prévue',
        'rescheduled' => 'Reprogrammée',
        'in_progress' => 'En intervention',
        'completed'   => 'Terminée',
        'cancelled'   => 'Annulée',
    ][$status ?? ''] ?? 'A planifier';
}

require_once __DIR__ . '/../includes/layout.php';
?>
<style>
.search-hero {
  background: linear-gradient(135deg, #0e3127 0%, #174b3a 55%, #2d6f86 100%);
  border-radius: 28px;
  padding: 28px;
  margin-bottom: 24px;
  color: #fff;
  box-shadow: 0 18px 38px rgba(14, 49, 39, .16);
}
.search-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #f2d58c;
  margin-bottom: 10px;
}
.search-title {
  font-size: 30px;
  line-height: 1.15;
  font-family: 'Merriweather', serif;
  font-weight: 900;
  margin-bottom: 10px;
}
.search-text {
  color: rgba(255,255,255,.82);
  max-width: 640px;
  margin-bottom: 18px;
}
.search-form {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.search-form input {
  flex: 1;
  min-width: 240px;
  padding: 14px 16px;
  border: 1px solid rgba(255,255,255,.16);
  border-radius: 16px;
  font-size: 15px;
  background: rgba(255,252,246,.94);
}
.search-form button {
  padding: 14px 20px;
  border: none;
  border-radius: 16px;
  font-weight: 800;
  background: #d2a13a;
  color: #0e3127;
  cursor: pointer;
}
.results-meta {
  color: #5e6c67;
  font-size: 14px;
  margin-bottom: 18px;
}
.search-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 18px;
  font-weight: 800;
  color: #183229;
  margin: 24px 0 12px;
}
.search-section-count {
  background: #efe7d7;
  color: #5e6c67;
  border-radius: 999px;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 700;
}
.result-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  margin-bottom: 12px;
  border-radius: 18px;
  border: 1px solid #ece4d5;
  background: rgba(255,253,248,.94);
  color: inherit;
  text-decoration: none;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.result-card:hover {
  text-decoration: none;
  border-color: #d2a13a;
  background: #fffdf8;
}
.result-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 18px;
}
.result-main {
  flex: 1;
  min-width: 0;
}
.result-title {
  color: #183229;
  font-size: 15px;
  font-weight: 800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.result-sub {
  color: #5e6c67;
  font-size: 13px;
  margin-top: 4px;
}
.result-sub-strong {
  color: #355248;
  font-weight: 700;
}
.result-sub-soft {
  color: #7b857f;
}
.result-badges {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-left: auto;
}
.result-badge {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  white-space: nowrap;
}
.search-empty {
  text-align: center;
  padding: 54px 20px;
  color: #8d958f;
}
.search-empty-icon {
  font-size: 46px;
  margin-bottom: 12px;
}
</style>

<div class="search-hero">
  <div class="search-kicker">Recherche transversale</div>
  <div class="search-title">Retrouver une situation, un citoyen ou une catégorie</div>
  <div class="search-text">
    La recherche globale aide les agents à relier rapidement un dossier, une personne et le contexte territorial associé.
  </div>
  <form method="GET" action="/admin/" class="search-form">
    <input type="hidden" name="page" value="search">
    <input type="text" name="q" placeholder="Référence, titre, email, nom..." value="<?= e($query) ?>" autocomplete="off">
    <button type="submit">Rechercher</button>
  </form>
</div>

<?php if ($scope_notice): ?>
  <div class="alert alert-info"><?= e($scope_notice) ?></div>
<?php endif; ?>

<?php if ($query && strlen($query) < 2): ?>
  <div class="alert alert-warning">Saisissez au moins 2 caractères.</div>
<?php elseif ($query && $total === 0): ?>
  <div class="search-empty">
    <div class="search-empty-icon">🔎</div>
    <p>Aucun résultat pour <strong>"<?= e($query) ?>"</strong>.</p>
    <p>Essayez une référence, un email ou un titre partiel.</p>
  </div>
<?php elseif ($query): ?>
  <p class="results-meta"><?= $total ?> résultat<?= $total > 1 ? 's' : '' ?> pour <strong>"<?= e($query) ?>"</strong></p>

  <?php if ($results['incidents']): ?>
    <div class="search-section-title">📍 Signalements <span class="search-section-count"><?= count($results['incidents']) ?></span></div>
    <?php foreach ($results['incidents'] as $incident): ?>
      <a href="/admin/?page=incident_detail&id=<?= $incident['id'] ?>" class="result-card">
        <?= category_visual_html($incident['category_icon'] ?? 'road', $incident['category_name'], 'sm') ?>
        <div class="result-main">
          <div class="result-title"><?= e($incident['reference']) ?> — <?= e($incident['title'] ?: 'Sans titre') ?></div>
          <div class="result-sub">
            <span class="result-sub-strong"><?= e($incident['category_name']) ?></span>
            <?php if (!empty($incident['category_description'])): ?>
              · <span class="result-sub-soft"><?= e($incident['category_description']) ?></span>
            <?php endif; ?>
          </div>
          <div class="result-sub">
            <?= e($incident['reporter_name']) ?> · <?= format_date_short($incident['created_at']) ?>
            <?php if (!empty($incident['service_name'])): ?>
              · <span class="result-sub-strong"><?= e($incident['service_name']) ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($incident['current_plan_status']) || !empty($incident['service_name'])): ?>
            <div class="result-sub">
              <span class="result-sub-soft">
                <?= e(search_plan_label($incident['current_plan_status'] ?? null)) ?>
                <?php if (!empty($incident['current_plan_source_type'])): ?>
                  · <?= $incident['current_plan_source_type'] === 'provider'
                    ? 'Prestataire' . (!empty($incident['current_plan_provider_name']) ? ' ' . e($incident['current_plan_provider_name']) : '')
                    : 'Equipe interne' ?>
                <?php endif; ?>
                <?php if (!empty($incident['current_plan_date'])): ?>
                  · <?= e($incident['current_plan_date']) ?>
                <?php endif; ?>
                <?php if (!empty($incident['current_plan_time_start']) || !empty($incident['current_plan_time_end'])): ?>
                  · <?= e(trim(implode(' - ', array_filter([$incident['current_plan_time_start'] ?? null, $incident['current_plan_time_end'] ?? null])))) ?>
                <?php endif; ?>
              </span>
            </div>
          <?php endif; ?>
        </div>
        <div class="result-badges">
          <span class="result-badge" style="background:<?= search_status_color($incident['status']) ?>22;color:<?= search_status_color($incident['status']) ?>">
            <?= e(search_status_label($incident['status'])) ?>
          </span>
          <span class="result-badge" style="background:#eef3f1;color:#355248">
            <?= e(search_plan_label($incident['current_plan_status'] ?? null)) ?>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($results['users']): ?>
    <div class="search-section-title">👤 Utilisateurs <span class="search-section-count"><?= count($results['users']) ?></span></div>
    <?php foreach ($results['users'] as $user): ?>
      <a href="/admin/?page=users&detail=<?= $user['id'] ?>" class="result-card">
        <div class="result-icon" style="background:#dcebdd;color:#174b3a;font-weight:800">
          <?= strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
        </div>
        <div class="result-main">
          <div class="result-title"><?= e($user['full_name']) ?></div>
          <div class="result-sub">
            <?= e($user['email']) ?>
            <?= $user['phone'] ? ' · ' . e($user['phone']) : '' ?>
            · <?= (int)$user['incidents_count'] ?> signalement<?= ((int)$user['incidents_count']) > 1 ? 's' : '' ?>
          </div>
        </div>
        <span class="result-badge" style="background:#efe7d7;color:#5e6c67"><?= e(role_label($user['role'])) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($results['categories']): ?>
    <div class="search-section-title">🏷️ Catégories <span class="search-section-count"><?= count($results['categories']) ?></span></div>
    <?php foreach ($results['categories'] as $category): ?>
      <a href="/admin/?page=categories" class="result-card">
        <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'sm', $category['color'] ?? null) ?>
        <div class="result-main">
          <div class="result-title"><?= e($category['name']) ?></div>
          <div class="result-sub">
            <?= (int)$category['incidents_count'] ?> signalement<?= ((int)$category['incidents_count']) > 1 ? 's' : '' ?>
            <?php if (!empty($category['visual_description'])): ?>
              · <span class="result-sub-soft"><?= e($category['visual_description']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <span class="result-badge" style="background:<?= $category['is_active'] ? '#dcebdd' : '#efe7d7' ?>;color:<?= $category['is_active'] ? '#2f7d50' : '#8d958f' ?>">
          <?= $category['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
<?php else: ?>
  <div class="search-empty">
    <div class="search-empty-icon">🔍</div>
    <p>Saisissez un terme pour rechercher dans les signalements, utilisateurs et catégories.</p>
    <p>Exemples : `MC-2026-00003`, `admin@macommune.local`, `Voirie`</p>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
