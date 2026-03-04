<?php
/**
 * CCDS Back-Office — Point d'entrée et routeur principal
 * Toutes les requêtes passent par ce fichier via .htaccess
 */

// Charger le bootstrap (config, session, helpers)
require_once __DIR__ . '/includes/bootstrap.php';

// Récupérer la page demandée (paramètre GET ou réécriture URL)
$page = $_GET['page'] ?? 'dashboard';

// Pages publiques (sans authentification)
$public_pages = ['login'];

// Vérifier l'authentification pour les pages protégées
if (!in_array($page, $public_pages) && empty($_SESSION['admin_user'])) {
    header('Location: /admin/?page=login');
    exit;
}

// Déconnexion
if ($page === 'logout') {
    session_destroy();
    header('Location: /admin/?page=login');
    exit;
}

// Mapper les pages vers les fichiers
$page_map = [
    'login'           => 'pages/login.php',
    'dashboard'       => 'pages/dashboard.php',
    'incidents'       => 'pages/incidents.php',
    'incident_detail' => 'pages/incident_detail.php',
    'users'           => 'pages/users.php',
    'stats'           => 'pages/stats.php',
    'categories'      => 'pages/categories.php',
    'map'             => 'pages/map.php',
    'notifications'   => 'pages/notifications.php',
    'search'          => 'pages/search.php',
];

$file = $page_map[$page] ?? null;

if ($file && file_exists(__DIR__ . '/' . $file)) {
    require_once __DIR__ . '/' . $file;
} else {
    // Page 404
    require_admin_auth();
    $page_title = 'Page introuvable';
    $active_nav = '';
    require_once __DIR__ . '/includes/layout.php';
    echo '<div style="text-align:center;padding:80px 20px">
            <div style="font-size:64px">🔍</div>
            <h2 style="font-size:24px;font-weight:800;margin:16px 0 8px">Page introuvable</h2>
            <p style="color:#94a3b8">La page <strong>' . e($page) . '</strong> n\'existe pas.</p>
            <a href="/admin/?page=dashboard" class="btn btn-primary" style="margin-top:20px">← Retour au tableau de bord</a>
          </div>';
    require_once __DIR__ . '/includes/layout_footer.php';
}
