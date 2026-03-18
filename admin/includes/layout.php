<?php
/**
 * Ma Commune Back-Office — Layout HTML partagé
 * Inclure ce fichier en début de chaque page avec les variables :
 *   $page_title  : titre de la page
 *   $active_nav  : clé du lien actif dans la sidebar
 */

$admin = current_admin();
$page_title  = $page_title  ?? (defined('APP_NAME') ? APP_NAME . ' Admin' : 'Ma Commune Admin');
$active_nav  = $active_nav  ?? '';

// Compter les signalements en attente pour le badge sidebar
$db = Database::getInstance();
$pending_count = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM incidents WHERE status = 'submitted'");
    $pending_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Compter les notifications non lues (v1.1)
$unread_notifs_count = 0;
try {
    $unread_notifs_count = (int)$db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
} catch (Exception $e) {}
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
  <link rel="stylesheet" href="/admin/assets/css/admin.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="admin-shell">
<div class="layout">

  <!-- ===================== SIDEBAR ===================== -->
  <aside class="sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <img src="/admin/assets/img/ma-commune-guyane-mark.png" alt="Ma Commune Guyane" class="brand-icon">
      <div>
        <div class="brand-name"><?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?></div>
        <div class="brand-sub"><?= defined('APP_SUBTITLE') ? e(APP_SUBTITLE) : 'Administration territoriale' ?></div>
        <div class="brand-territory">Guyane · Veille communale</div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-title">Navigation</div>

      <a href="/admin/?page=dashboard" class="nav-item <?= $active_nav === 'dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">📊</span>
        <span>Tableau de bord</span>
      </a>

      <a href="/admin/?page=incidents" class="nav-item <?= $active_nav === 'incidents' ? 'active' : '' ?>">
        <span class="nav-icon">📋</span>
        <span>Signalements</span>
        <?php if ($pending_count > 0): ?>
          <span class="nav-badge"><?= $pending_count ?></span>
        <?php endif; ?>
      </a>

      <a href="/admin/?page=map" class="nav-item <?= $active_nav === 'map' ? 'active' : '' ?>">
        <span class="nav-icon">🗺️</span>
        <span>Carte</span>
      </a>

      <div class="nav-section-title" style="margin-top:8px">Gestion</div>

      <a href="/admin/?page=users" class="nav-item <?= $active_nav === 'users' ? 'active' : '' ?>">
        <span class="nav-icon">👥</span>
        <span>Utilisateurs</span>
      </a>

      <a href="/admin/?page=categories" class="nav-item <?= $active_nav === 'categories' ? 'active' : '' ?>">
        <span class="nav-icon">🏷️</span>
        <span>Catégories</span>
      </a>

      <a href="/admin/?page=notifications" class="nav-item <?= $active_nav === 'notifications' ? 'active' : '' ?>">
        <span class="nav-icon">🔔</span>
        <span>Notifications</span>
        <?php if ($unread_notifs_count > 0): ?>
          <span class="nav-badge" style="background:#f59e0b"><?= $unread_notifs_count ?></span>
        <?php endif; ?>
      </a>

      <a href="/admin/?page=search" class="nav-item <?= $active_nav === 'search' ? 'active' : '' ?>">
        <span class="nav-icon">🔍</span>
        <span>Recherche globale</span>
      </a>

      <div class="nav-section-title" style="margin-top:8px">Modération</div>

      <a href="/admin/?page=moderation" class="nav-item <?= $active_nav === 'moderation' ? 'active' : '' ?>">
        <span class="nav-icon">🚩</span>
        <span>Commentaires signalés</span>
        <?php
        $flagged_count = 0;
        try { $flagged_count = (int)$db->query("SELECT COUNT(*) FROM comments WHERE is_flagged = 1")->fetchColumn(); } catch (Exception $e) {}
        if ($flagged_count > 0): ?>
          <span class="nav-badge" style="background:#E53935"><?= $flagged_count ?></span>
        <?php endif; ?>
      </a>

      <a href="/admin/?page=audit_logs" class="nav-item <?= $active_nav === 'audit_logs' ? 'active' : '' ?>">
        <span class="nav-icon">📋</span>
        <span>Logs d'audit</span>
      </a>

      <div class="nav-section-title" style="margin-top:8px">Analyse</div>

      <a href="/admin/?page=stats" class="nav-item <?= $active_nav === 'stats' ? 'active' : '' ?>">
        <span class="nav-icon">📈</span>
        <span>Statistiques</span>
      </a>

      <a href="/admin/?page=realtime_dashboard" class="nav-item <?= $active_nav === 'realtime_dashboard' ? 'active' : '' ?>">
        <span class="nav-icon">📡</span>
        <span>Temps réel</span>
        <span class="nav-badge" style="background:#10B981;animation:pulse 1.5s infinite">LIVE</span>
      </a>

      <a href="/admin/?page=predictive_analysis" class="nav-item <?= $active_nav === 'predictive_analysis' ? 'active' : '' ?>">
        <span class="nav-icon">🔮</span>
        <span>Analyse prédictive</span>
      </a>

      <div class="nav-section-title" style="margin-top:8px">Communauté</div>

      <a href="/admin/?page=polls" class="nav-item <?= $active_nav === 'polls' ? 'active' : '' ?>">
        <span class="nav-icon">🗳️</span>
        <span>Sondages</span>
      </a>

      <a href="/admin/?page=events" class="nav-item <?= $active_nav === 'events' ? 'active' : '' ?>">
        <span class="nav-icon">📅</span>
        <span>Événements</span>
      </a>

    </nav>

    <div class="sidebar-footer">
      <?php if ($admin): ?>
      <div class="sidebar-user">
        <div class="avatar"><?= strtoupper(substr($admin['full_name'] ?? 'A', 0, 1)) ?></div>
        <div>
          <div class="user-name"><?= e($admin['full_name'] ?? 'Admin') ?></div>
          <div class="user-role"><?= role_label($admin['role'] ?? 'admin') ?></div>
        </div>
        <a href="/admin/?page=logout" class="logout-btn" title="Déconnexion" data-confirm="Voulez-vous vous déconnecter ?">🚪</a>
      </div>
      <?php endif; ?>
    </div>
  </aside>

  <!-- ===================== MAIN ===================== -->
  <main class="main">
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
          ☰
        </button>
        <div class="topbar-heading">
          <div class="topbar-kicker">Service public local</div>
          <h1 class="topbar-title"><?= e($page_title) ?></h1>
        </div>
      </div>
      <div class="topbar-actions">
        <form method="GET" action="/admin/" class="quick-search-form">
          <input type="hidden" name="page" value="search">
          <input type="text" name="q" placeholder="Recherche rapide..."
                 class="quick-search-input" autocomplete="off">
        </form>
        <div class="topbar-meta">
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
      <?php
      // Afficher les messages flash de session
      if (!empty($_SESSION['flash_success'])) {
          echo '<div class="alert alert-success" data-auto-dismiss>✅ ' . e($_SESSION['flash_success']) . '</div>';
          unset($_SESSION['flash_success']);
      }
      if (!empty($_SESSION['flash_error'])) {
          echo '<div class="alert alert-danger" data-auto-dismiss>❌ ' . e($_SESSION['flash_error']) . '</div>';
          unset($_SESSION['flash_error']);
      }
      ?>
