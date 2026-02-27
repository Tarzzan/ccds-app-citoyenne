<?php
/**
 * CCDS — Point d'entrée de l'API REST
 * Toutes les requêtes sont redirigées ici via .htaccess
 */

// --- Chargement de la configuration ---
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/helpers.php';

// --- En-têtes globaux ---
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: '  . CORS_ORIGINS);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Répondre aux pre-flight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Routeur simple ---
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Supprimer le préfixe /api si présent
$uri    = preg_replace('#^/api#', '', $uri);
$uri    = rtrim($uri, '/') ?: '/';

// Découper l'URI en segments : /incidents/42 => ['incidents', '42']
$segments = array_values(array_filter(explode('/', $uri)));
$resource = $segments[0] ?? '';
$id       = isset($segments[1]) ? (int)$segments[1] : null;
$sub      = $segments[2] ?? null; // ex: /incidents/42/comments

// --- Dispatch ---
switch ($resource) {

    case 'register':
        require __DIR__ . '/api/auth.php';
        handle_register();
        break;

    case 'login':
        require __DIR__ . '/api/auth.php';
        handle_login();
        break;

    case 'categories':
        require __DIR__ . '/api/categories.php';
        handle_categories($method, $id);
        break;

    case 'incidents':
        if ($id && $sub === 'comments') {
            require __DIR__ . '/api/comments.php';
            handle_comments($method, $id);
        } else {
            require __DIR__ . '/api/incidents.php';
            handle_incidents($method, $id);
        }
        break;

    default:
        json_error('Endpoint introuvable.', 404);
}
