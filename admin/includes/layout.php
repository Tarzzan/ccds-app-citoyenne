<?php
/**
 * Ma Commune Back-Office — Layout HTML partagé
 * Inclure ce fichier en début de chaque page avec les variables :
 *   $page_title  : titre de la page
 *   $active_nav  : clé du lien actif dans la sidebar
 */

$admin = current_admin();
$is_admin_role = ($admin['role'] ?? null) === 'admin';
$agent_is_scoped = !$is_admin_role && admin_is_service_scoped_agent($admin ?? []);
$agent_service_scope_ids = $agent_is_scoped ? admin_allowed_service_ids($admin ?? []) : [];
$page_title  = $page_title  ?? (defined('APP_NAME') ? APP_NAME . ' Admin' : 'Ma Commune Admin');
$active_nav  = $active_nav  ?? '';

$isTrainingMode = true;
if (isset($_GET['mode'])) {
    $isTrainingMode = $_GET['mode'] === 'training';
    setcookie('app_ui_mode', $isTrainingMode ? 'training' : 'production', time() + 86400 * 365, '/');
} elseif (isset($_COOKIE['app_ui_mode'])) {
    $isTrainingMode = $_COOKIE['app_ui_mode'] === 'training';
}

// Compter les signalements en attente pour le badge sidebar
$db = Database::getInstance();
$pending_count = 0;
try {
    if ($agent_is_scoped && !empty($agent_service_scope_ids)) {
        $safeServiceIds = implode(',', array_map('intval', $agent_service_scope_ids));
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM incidents i
            JOIN categories c ON c.id = i.category_id
            JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
            WHERE i.status = 'submitted'
              AND scm.service_id IN ($safeServiceIds)
        ");
        $pending_count = (int)$stmt->fetchColumn();
    } elseif ($agent_is_scoped) {
        $pending_count = 0;
    } else {
        $stmt = $db->query("SELECT COUNT(*) FROM incidents WHERE status = 'submitted'");
        $pending_count = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {}

// Compter les notifications non lues (v1.1)
$unread_notifs_count = 0;
try {
    $unread_notifs_count = $is_admin_role
        ? (int)$db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn()
        : 0;
} catch (Exception $e) {}

$pending_moderation_count = 0;
try {
    if ($is_admin_role && admin_db_has_table($db, 'photo_reports')) {
        $pendingPhotoReports = (int)$db->query("SELECT COUNT(DISTINCT photo_id) FROM photo_reports WHERE status = 'pending'")->fetchColumn();
        $pendingCommentReports = admin_db_has_table($db, 'comment_reports')
            ? (int)$db->query("SELECT COUNT(DISTINCT comment_id) FROM comment_reports WHERE status = 'pending'")->fetchColumn()
            : 0;
        $pending_moderation_count = $pendingPhotoReports + $pendingCommentReports;
    } elseif ($is_admin_role && admin_db_has_column($db, 'comments', 'is_flagged')) {
        $pending_moderation_count = (int)$db->query("SELECT COUNT(*) FROM comments WHERE is_flagged = 1")->fetchColumn();
    }
} catch (Throwable $e) {}

$workspace_role_label = $is_admin_role ? 'Supervision centrale' : 'Pilotage service';
$workspace_scope_label = $agent_is_scoped && !empty($admin['primary_service_name'])
    ? $admin['primary_service_name']
    : 'Perimetre general';
$workspace_env_label = defined('APP_ENV') ? strtoupper((string)APP_ENV) : 'PRODUCTION';
$territory_label = defined('APP_TERRITORY_LABEL') && trim((string) APP_TERRITORY_LABEL) !== ''
    ? trim((string) APP_TERRITORY_LABEL)
    : 'Territoire';
$nav_context_map = [
    'dashboard' => ['section' => 'Pilotage', 'hint' => 'Vue d ensemble du poste', 'marker' => '01'],
    'incidents' => ['section' => 'Pilotage', 'hint' => 'Flux des signalements', 'marker' => '02'],
    'map' => ['section' => 'Pilotage', 'hint' => 'Lecture cartographique', 'marker' => '03'],
    'users' => ['section' => 'Gestion', 'hint' => 'Profils et roles', 'marker' => '04'],
    'services' => ['section' => 'Gestion', 'hint' => 'Services et files', 'marker' => '05'],
    'categories' => ['section' => 'Gestion', 'hint' => 'Taxonomie et reperes', 'marker' => '06'],
    'notifications' => ['section' => 'Gestion', 'hint' => 'Messages et relais', 'marker' => '07'],
    'search' => ['section' => 'Gestion', 'hint' => 'Recherche transversale', 'marker' => '08'],
    'visual_admin' => ['section' => 'Gestion', 'hint' => 'Studio visuel central', 'marker' => '09'],
    'moderation' => ['section' => 'Controle', 'hint' => 'Surfaces a verifier', 'marker' => '10'],
    'audit_logs' => ['section' => 'Controle', 'hint' => 'Traite des actions', 'marker' => '11'],
    'gdpr'       => ['section' => 'Controle', 'hint' => 'Droits RGPD', 'marker' => '11b'],
    'stats' => ['section' => 'Analyse', 'hint' => 'Tendances et volumes', 'marker' => '12'],
    'realtime_dashboard' => ['section' => 'Analyse', 'hint' => 'Lecture temps reel', 'marker' => '13'],
    'predictive_analysis' => ['section' => 'Analyse', 'hint' => 'Projection et signaux', 'marker' => '14'],
    'polls' => ['section' => 'Communaute', 'hint' => 'Consultations et retours', 'marker' => '15'],
    'events' => ['section' => 'Communaute', 'hint' => 'Agenda et rendez-vous', 'marker' => '16'],
];
$current_nav_context = $nav_context_map[$active_nav] ?? [
    'section' => $agent_is_scoped ? 'Exploitation' : 'Pilotage',
    'hint' => 'Lecture en cours',
    'marker' => '00',
];
$brand_subtitle = defined('APP_SUBTITLE') && trim((string) APP_SUBTITLE) !== ''
    ? trim((string) APP_SUBTITLE)
    : 'Administration locale';
$brand_territory_line = $territory_label . ' · Interface adaptable';
$sidebar_summary_title = $is_admin_role ? 'Poste d administration mutualisable' : 'Poste d exploitation de service';
$sidebar_summary_copy = $is_admin_role
    ? 'Suivi, coordination et qualite de service sur une base neutre adaptable a chaque commune.'
    : 'Priorisation locale, suivi de file et pilotage de service sur une base mutualisee.';
$brand_mark_url = visual_admin_brand_asset_url('brand_mark_url', '/admin/assets/img/ma-commune-guyane-mark.png');
$theme_settings = visual_admin_theme_settings();
$theme_inline_style = visual_admin_theme_inline_style();
$sidebar_scene_url = visual_admin_slot_url('sidebar_scene', 'ILL-02') ?? visual_admin_slot_url('sidebar_scene', 'ILL-01');
$sidebar_scene_alt_url = visual_admin_slot_url('sidebar_inset', 'ILL-05');
$sidebar_agent_url = visual_admin_slot_url('sidebar_agent', 'CHAR-04') ?? visual_admin_slot_url('sidebar_agent', 'CHAR-05');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($page_title) ?> — <?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/admin/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="/admin/assets/js/admin.js" defer></script>
  <script src="/admin/assets/js/atelier.js" defer></script>
</head>
<body class="admin-shell" data-theme-variant="<?= e((string)$theme_settings['variant']) ?>" style="<?= e($theme_inline_style) ?>">
<div class="layout">

  <!-- ===================== SIDEBAR ===================== -->
  <aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <img src="<?= e($brand_mark_url) ?>" alt="<?= e((defined('APP_NAME') ? APP_NAME : 'Ma Commune') . ' marque') ?>" class="brand-icon">
      <div>
        <div class="brand-name"><?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?></div>
        <div class="brand-sub"><?= e($brand_subtitle) ?></div>
        <div class="brand-territory"><?= e($brand_territory_line) ?></div>
      </div>
    </div>

    <div class="sidebar-ambience">
      <?php if ($sidebar_scene_url): ?>
        <div class="sidebar-ambience-visual">
          <img src="<?= e($sidebar_scene_url) ?>" alt="Repere visuel de navigation" class="sidebar-ambience-scene" loading="lazy">
          <?php if ($sidebar_scene_alt_url): ?>
            <img src="<?= e($sidebar_scene_alt_url) ?>" alt="Lecture de contexte" class="sidebar-ambience-inset" loading="lazy">
          <?php endif; ?>
          <?php if ($sidebar_agent_url): ?>
            <img src="<?= e($sidebar_agent_url) ?>" alt="Repere de pilotage" class="sidebar-ambience-agent" loading="lazy">
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="sidebar-workspace-meta" aria-hidden="true">
        <span class="meta-dot" title="Environnement actif"></span>
        <span class="meta-env"><?= e($workspace_env_label) ?></span>
        <span class="meta-sep">&bull;</span>
        <span class="meta-role"><?= e($workspace_role_label) ?></span>
        <span class="meta-sep">&bull;</span>
        <span class="meta-scope"><?= e($workspace_scope_label) ?></span>
      </div>
      <div class="sidebar-workspace-stats">
        <div class="sidebar-workspace-stat">
          <strong><?= $pending_count ?></strong>
          <span>Signalements à traiter</span>
        </div>
        <div class="sidebar-workspace-stat">
          <strong><?= $pending_moderation_count ?></strong>
          <span>Contenus à modérer</span>
        </div>
        <div class="sidebar-workspace-stat">
          <strong><?= $unread_notifs_count ?></strong>
          <span>Notifications non lues</span>
        </div>
      </div>
    </div>

    <?php
    // Sidebar icon pack: use active pack images
    $sidebarPack = function_exists('category_visual_active_pack') ? category_visual_active_pack() : 'kourou';
    $sidebarIconBase = '/admin/assets/img/icon-packs/' . rawurlencode($sidebarPack) . '/';
    ?>
    <nav class="sidebar-nav">
      <details class="nav-section" open>
        <summary class="nav-section-title">Navigation</summary>
        <div class="nav-items-group">
          <a href="/admin/?page=dashboard" class="nav-item <?= $active_nav === 'dashboard' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('dashboard')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Tableau de bord</strong>
              <small>Vue d'ensemble du poste</small>
            </span>
          </a>

          <a href="/admin/?page=incidents" class="nav-item <?= $active_nav === 'incidents' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('incidents')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Signalements</strong>
              <small>Flux et file de traitement</small>
            </span>
            <?php if ($pending_count > 0): ?>
              <span class="nav-badge"><?= $pending_count ?></span>
            <?php endif; ?>
          </a>

          <a href="/admin/?page=map" class="nav-item <?= $active_nav === 'map' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('map')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Carte</strong>
              <small>Lecture cartographique</small>
            </span>
          </a>
        </div>
      </details>

      <details class="nav-section" open>
        <summary class="nav-section-title">Gestion</summary>
        <div class="nav-items-group">
          <a href="/admin/?page=users" class="nav-item <?= $active_nav === 'users' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('users')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Utilisateurs</strong>
              <small>Profils, rôles et accès</small>
            </span>
          </a>

          <a href="/admin/?page=services" class="nav-item <?= $active_nav === 'services' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('services')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Services</strong>
              <small>Périmètres et files</small>
            </span>
          </a>

          <?php if ($is_admin_role): ?>
          <a href="/admin/?page=categories" class="nav-item <?= $active_nav === 'categories' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('categories')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Catégories</strong>
              <small>Taxonomie et repères</small>
            </span>
          </a>
          <?php endif; ?>

          <a href="/admin/?page=notifications" class="nav-item <?= $active_nav === 'notifications' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('notifications')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Notifications</strong>
              <small>Messages et relais</small>
            </span>
            <?php if ($unread_notifs_count > 0): ?>
              <span class="nav-badge nav-badge--warning"><?= $unread_notifs_count ?></span>
            <?php endif; ?>
          </a>
        </div>
      </details>

      <?php if ($is_admin_role): ?>
      <details class="nav-section" open>
        <summary class="nav-section-title">Contrôle</summary>
        <div class="nav-items-group">
          <a href="/admin/?page=moderation" class="nav-item <?= $active_nav === 'moderation' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('moderation')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Modération</strong>
              <small>Surfaces à vérifier</small>
            </span>
            <?php if ($pending_moderation_count > 0): ?>
              <span class="nav-badge"><?= $pending_moderation_count ?></span>
            <?php endif; ?>
          </a>

          <a href="/admin/?page=audit_logs" class="nav-item <?= $active_nav === 'audit_logs' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('audit_logs')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Logs d'audit</strong>
              <small>Tracé des actions</small>
            </span>
          </a>

          <a href="/admin/?page=gdpr" class="nav-item <?= $active_nav === 'gdpr' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('audit_logs')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Conformité RGPD</strong>
              <small>Exports et suppressions</small>
            </span>
          </a>

          <a href="/admin/?page=stats" class="nav-item <?= $active_nav === 'stats' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('stats')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Statistiques</strong>
              <small>Tendances et volumes</small>
            </span>
          </a>
        </div>
      </details>
      <?php endif; ?>

      <details class="nav-section" open>
        <summary class="nav-section-title">Options</summary>
        <div class="nav-items-group">
          <a href="/admin/?page=search" class="nav-item <?= $active_nav === 'search' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('search')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Recherche</strong>
              <small>Recherche transversale</small>
            </span>
          </a>

          <?php if ($is_admin_role): ?>
          <a href="/admin/?page=visual_admin" class="nav-item <?= $active_nav === 'visual_admin' ? 'active' : '' ?>" data-async-link data-async-scope="admin-main">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('visual_admin')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Paramètres</strong>
              <small>Thème et personnalisation</small>
            </span>
          </a>

          <a href="/admin/pages/icon_packs.php" class="nav-item <?= $active_nav === 'icon_packs' ? 'active' : '' ?>">
            <span class="nav-icon"><img src="<?= e(visual_admin_nav_icon_url('icon_packs')) ?>" alt=""></span>
            <span class="nav-copy">
              <strong>Identité visuelle</strong>
              <small>Packs d'icônes</small>
            </span>
          </a>
          <?php endif; ?>
        </div>
      </details>
    </nav>

    <div class="sidebar-footer">
      <?php if ($admin): ?>
      <div class="sidebar-user">
        <div class="avatar"><?= strtoupper(substr($admin['full_name'] ?? 'A', 0, 1)) ?></div>
        <div class="sidebar-user-info">
          <div class="user-name"><?= e($admin['full_name'] ?? 'Admin') ?></div>
          <div class="user-role"><?= role_label($admin['role'] ?? 'admin') ?></div>
        </div>
        <a href="/admin/?page=logout&bypass=<?= time() ?>" class="logout-btn" title="Déconnexion">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </aside>


  <!-- ===================== MAIN ===================== -->
  <main class="main">
    <div class="main-shell" data-async-scope="admin-main" aria-busy="false">
    <header class="topbar">
      <div class="topbar-left">
        <button
          type="button"
          class="topbar-menu-btn"
          id="sidebarToggle"
          aria-controls="appSidebar"
          aria-expanded="false"
          aria-label="Ouvrir la navigation"
        >
          Menu
        </button>
        <div class="topbar-heading">
          <div class="topbar-kicker">Service public local</div>
          <h1 class="topbar-title" id="adminPageTitle" tabindex="-1" data-async-focus><?= e($page_title) ?></h1>
          <div class="topbar-context" aria-hidden="true">
            <span>Kourou</span>
            <span>Terrain</span>
            <span>Interventions</span>
          </div>
        </div>
      </div>
      <div class="topbar-actions">
        <div class="topbar-workspace-card" aria-label="Espace de travail">
          <div class="topbar-workspace-head">
            <strong><?= e($workspace_role_label) ?></strong>
            <span><?= e($workspace_scope_label) ?></span>
          </div>
          <div class="topbar-workspace-metrics">
            <span><?= $pending_count ?> à traiter</span>
            <span><?= $pending_moderation_count ?> modération</span>
          </div>
        </div>
        <form method="GET" action="/admin/" class="quick-search-form" data-async-form data-async-scope="admin-main">
          <input type="hidden" name="page" value="search">
          <input type="text" name="q" placeholder="Recherche rapide..."
                 class="quick-search-input" autocomplete="off">
        </form>
        <div class="topbar-meta" style="display:flex; align-items:center; gap:12px;">
          <!-- UI Mode Toggle -->
          <div class="ui-mode-toggle" style="background: rgba(0,0,0,0.05); padding: 4px; border-radius: 20px; display: inline-flex; align-items: center; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);">
            <a href="?<?= e(preg_replace('/&?mode=[^&]*/', '', $_SERVER['QUERY_STRING'] ?? '')) ?>&mode=production" style="padding: 4px 10px; border-radius: 16px; font-size: 10px; text-transform: uppercase; letter-spacing:0.5px; font-weight: 800; text-decoration:none; transition:0.2s; color: <?= !$isTrainingMode ? '#fff' : 'var(--gray-600)' ?>; background: <?= !$isTrainingMode ? 'var(--primary)' : 'transparent' ?>; box-shadow: <?= !$isTrainingMode ? '0 4px 10px rgba(0,0,0,0.1)' : 'none' ?>;" title="Interface epuree et dense pour agents expert">Prod</a>
            <a href="?<?= e(preg_replace('/&?mode=[^&]*/', '', $_SERVER['QUERY_STRING'] ?? '')) ?>&mode=training" style="padding: 4px 10px; border-radius: 16px; font-size: 10px; text-transform: uppercase; letter-spacing:0.5px; font-weight: 800; text-decoration:none; transition:0.2s; color: <?= $isTrainingMode ? '#fff' : 'var(--gray-600)' ?>; background: <?= $isTrainingMode ? 'var(--primary)' : 'transparent' ?>; box-shadow: <?= $isTrainingMode ? '0 4px 10px rgba(0,0,0,0.1)' : 'none' ?>;" title="Interface tutorielle pour la formation">Train</a>
          </div>
          <span class="text-small"><?= date('d/m/Y H:i') ?></span>
          <span class="topbar-separator">•</span>
          <span class="text-small">Pilotage communal</span>
        </div>
      </div>
    </header>

    <div class="page-content">
      <button
        type="button"
        class="sidebar-overlay"
        id="sidebarOverlay"
        aria-label="Fermer la navigation"
      ></button>
      <div id="pageFlashStack">
        <?php
        // Afficher les messages flash de session
        if (!empty($_SESSION['flash_success'])) {
            echo '<div class="alert alert-success" data-auto-dismiss>OK · ' . e($_SESSION['flash_success']) . '</div>';
            unset($_SESSION['flash_success']);
        }
        if (!empty($_SESSION['flash_error'])) {
            echo '<div class="alert alert-danger" data-auto-dismiss>Erreur · ' . e($_SESSION['flash_error']) . '</div>';
            unset($_SESSION['flash_error']);
        }
        ?>
      </div>
