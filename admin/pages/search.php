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
$lead_photo_select = admin_incident_first_photo_select($db, 'i');
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;
$statusPalette = visual_admin_status_palette();

$query   = trim($_GET['q'] ?? '');
$results = ['incidents' => [], 'users' => [], 'categories' => []];
$total   = 0;
$searchExecution = [
    'unplanned' => 0,
    'scheduled' => 0,
    'in_progress' => 0,
    'provider' => 0,
];

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
               {$lead_photo_select},
               (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
               (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count,
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
        $incident['lead_photo'] = admin_incident_preview_photo($db, $incident);
    }
    unset($incident);
    foreach ($results['incidents'] as $incident) {
        $planStatus = (string)($incident['current_plan_status'] ?? '');
        if ($planStatus === 'in_progress') {
            $searchExecution['in_progress']++;
        } elseif (in_array($planStatus, ['scheduled', 'rescheduled'], true)) {
            $searchExecution['scheduled']++;
        } else {
            $searchExecution['unplanned']++;
        }

        if (($incident['current_plan_source_type'] ?? '') === 'provider') {
            $searchExecution['provider']++;
        }
    }

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
    global $statusPalette;

    return [
        'submitted'    => $statusPalette['submitted'],
        'acknowledged' => $statusPalette['acknowledged'],
        'in_progress'  => $statusPalette['in_progress'],
        'resolved'     => $statusPalette['resolved'],
        'rejected'     => $statusPalette['rejected'],
    ][$status] ?? $statusPalette['default'];
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

$searchHeroPortraits = [
    ['asset' => visual_admin_slot_asset('search_primary', 'CHAR-05'), 'label' => 'Relais recherche'],
    ['asset' => visual_admin_slot_asset('search_secondary', 'CHAR-04'), 'label' => 'Lecture dossier'],
];
$searchHeroHasPortraits = false;
foreach ($searchHeroPortraits as $heroPortrait) {
    if (generated_visual_url($heroPortrait['asset'])) {
        $searchHeroHasPortraits = true;
        break;
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="search-admin">
<div class="page-hero <?= $searchHeroHasPortraits ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Recherche transversale</div>
    <div class="page-hero-title">Retrouver une situation, un citoyen ou une catégorie</div>
    <div class="page-hero-text">
      La recherche globale aide les agents a relier rapidement un dossier, une personne et le contexte de service associe.
    </div>
    <form method="GET" action="/admin/" class="search-form" data-async-form>
      <input type="hidden" name="page" value="search">
      <input type="text" name="q" class="form-control" placeholder="Référence, titre, email, nom..." value="<?= e($query) ?>" autocomplete="off">
      <button type="submit" class="btn btn-primary">Rechercher</button>
    </form>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($results['incidents']) ?></span>
      <span class="hero-chip-label">signalements trouvés</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($results['users']) ?></span>
      <span class="hero-chip-label">utilisateurs trouvés</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($results['categories']) ?></span>
      <span class="hero-chip-label">catégories trouvées</span>
    </div>
  </div>
  <?php if ($searchHeroHasPortraits): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <div class="dashboard-hero-portraits">
          <?php foreach ($searchHeroPortraits as $heroPortrait): ?>
            <?php if (!generated_visual_url($heroPortrait['asset'])) { continue; } ?>
            <figure class="dashboard-hero-portrait-card">
              <?= generated_visual_html($heroPortrait['asset'], ['class' => 'generated-visual generated-visual--portrait dashboard-hero-portrait', 'label' => $heroPortrait['label']]) ?>
              <figcaption><?= e($heroPortrait['label']) ?></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
        <div class="generated-visual-caption">
          <strong>Recherche guidee par agents</strong>
          <span>La recherche globale retrouve la meme famille stylisee que le tableau de bord et la file terrain, sans revenir vers un hero trop decoratif.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($scope_notice): ?>
  <div class="alert alert-info"><?= e($scope_notice) ?></div>
<?php endif; ?>

<?php if ($query && strlen($query) < 2): ?>
  <div class="alert alert-warning">Saisissez au moins 2 caractères. Une recherche trop courte remonte trop de bruit pour rester utile.</div>
<?php elseif ($query && $total === 0): ?>
  <div class="search-empty">
    <div class="search-empty-icon">Recherche</div>
    <p>Aucun résultat pour <strong>"<?= e($query) ?>"</strong>.</p>
    <p>Essayez une référence, un email, un titre partiel ou élargissez le périmètre si vous êtes limité à un service.</p>
  </div>
<?php elseif ($query): ?>
  <p class="search-results-meta"><?= $total ?> résultat<?= $total > 1 ? 's' : '' ?> pour <strong>"<?= e($query) ?>"</strong></p>
  <p class="search-results-note">Priorité de lecture : ouvrir d abord les dossiers avec preuve, commentaires ou plan déjà visible.</p>

  <?php if ($results['incidents']): ?>
    <div class="card search-summary-card">
      <div class="card-header">
        <span class="card-title">Lecture d execution de la recherche</span>
        <span class="text-muted text-small">Avant d ouvrir les fiches, verifier si les resultats remontent surtout des dossiers a planifier, deja programmes ou en cours.</span>
      </div>
      <div class="services-mode-band services-mode-band--tight">
        <div class="services-mode-card">
          <strong><?= (int)$searchExecution['unplanned'] ?></strong>
          <span>a planifier</span>
        </div>
        <div class="services-mode-card">
          <strong><?= (int)$searchExecution['scheduled'] ?></strong>
          <span>prevues</span>
        </div>
        <div class="services-mode-card">
          <strong><?= (int)$searchExecution['in_progress'] ?></strong>
          <span>en intervention</span>
        </div>
      </div>
      <div class="services-mode-band services-mode-band--tight search-section-gap">
        <div class="services-mode-card">
          <strong><?= (int)$searchExecution['provider'] ?></strong>
          <span>prestataire</span>
        </div>
        <div class="services-mode-card">
          <strong><?= count($results['incidents']) ?></strong>
          <span>signalements trouves</span>
        </div>
        <div class="services-mode-card">
          <strong><?= (int)$total ?></strong>
          <span>resultats tous types</span>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($results['incidents']): ?>
    <div class="search-section-title">Signalements <span class="search-section-count"><?= count($results['incidents']) ?></span></div>
    <p class="search-section-note">Les cartes regroupent categorie, preuve, plan et statut pour éviter d ouvrir chaque fiche à l aveugle.</p>
    <?php foreach ($results['incidents'] as $incident): ?>
      <a href="/admin/?page=incident_detail&id=<?= $incident['id'] ?>" class="result-card" data-async-link data-async-scope="admin-main">
        <div class="result-card-visuals">
          <?= category_visual_html($incident['category_icon'] ?? 'road', $incident['category_name'], 'md') ?>
          <?php if (!empty($incident['lead_photo']['url'])): ?>
            <span class="admin-proof-thumb-wrap">
              <img src="<?= e($incident['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb admin-proof-thumb--small">
              <?php if ((int)$incident['photo_count'] > 1): ?>
                <span class="admin-proof-thumb-badge">+<?= (int)$incident['photo_count'] - 1 ?></span>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
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
          <div class="result-sub">
            <?php if ((int)$incident['photo_count'] > 0): ?>
              <span class="result-sub-strong"><?= (int)$incident['photo_count'] ?> photo<?= (int)$incident['photo_count'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if ((int)$incident['comment_count'] > 0): ?>
              <?= (int)$incident['photo_count'] > 0 ? ' · ' : '' ?><span class="result-sub-soft"><?= (int)$incident['comment_count'] ?> commentaire<?= (int)$incident['comment_count'] > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <?php if (!empty($incident['lead_photo']['moderation_message'])): ?>
              <?= ((int)$incident['photo_count'] > 0 || (int)$incident['comment_count'] > 0) ? ' · ' : '' ?><span class="result-sub-soft"><?= e($incident['lead_photo']['moderation_message']) ?></span>
            <?php elseif ((int)$incident['photo_count'] > 0): ?>
              <?= ((int)$incident['comment_count'] > 0) ? ' · ' : '' ?><span class="result-sub-soft"><?= (int)$incident['photo_count'] > 1 ? 'Serie photo visible' : 'Preuve citoyenne visible' ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="result-badges">
          <span class="result-badge badge-tone" style="--badge-accent:<?= search_status_color($incident['status']) ?>">
            <?= e(search_status_label($incident['status'])) ?>
          </span>
          <span class="result-badge result-badge--plan">
            <?= e(search_plan_label($incident['current_plan_status'] ?? null)) ?>
          </span>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($results['users']): ?>
    <div class="search-section-title">Utilisateurs <span class="search-section-count"><?= count($results['users']) ?></span></div>
    <p class="search-section-note">Lecture rapide des comptes retrouvés avec rôle, volume de signalements et contact principal.</p>
    <?php foreach ($results['users'] as $user): ?>
      <a href="/admin/?page=users&detail=<?= $user['id'] ?>" class="result-card" data-async-link data-async-scope="admin-main">
        <div class="result-icon result-icon--user">
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
        <span class="result-badge result-badge--role"><?= e(role_label($user['role'])) ?></span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($results['categories']): ?>
    <div class="search-section-title">Catégories <span class="search-section-count"><?= count($results['categories']) ?></span></div>
    <p class="search-section-note">Les catégories restent compactes pour juger vite si la requête remonte surtout une famille métier.</p>
    <?php foreach ($results['categories'] as $category): ?>
      <a href="/admin/?page=categories" class="result-card" data-async-link data-async-scope="admin-main">
        <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'md', $category['color'] ?? null) ?>
        <div class="result-main">
          <div class="result-title"><?= e($category['name']) ?></div>
          <div class="result-sub">
            <?= (int)$category['incidents_count'] ?> signalement<?= ((int)$category['incidents_count']) > 1 ? 's' : '' ?>
            <?php if (!empty($category['visual_description'])): ?>
              · <span class="result-sub-soft"><?= e($category['visual_description']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <span class="result-badge <?= $category['is_active'] ? 'result-badge--active' : 'result-badge--inactive' ?>">
          <?= $category['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
<?php else: ?>
  <div class="search-empty">
    <div class="search-empty-icon">Trouver</div>
    <p>Saisissez un terme pour rechercher dans les signalements, utilisateurs et catégories.</p>
    <p>Exemples : `MC-2026-00003`, `admin@collectivite.local`, `Voirie`</p>
  </div>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
