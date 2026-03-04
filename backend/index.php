<?php
/**
 * CCDS v1.2 — Point d'entrée de l'API REST
 * Architecture OO avec contrôleurs, RBAC et sécurité renforcée (TECH-01 + SEC-01)
 */

// --- Chargement de la configuration ---
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/helpers.php';

// --- Noyau v1.2 ---
require_once __DIR__ . '/core/Security.php';
require_once __DIR__ . '/core/Permissions.php';
require_once __DIR__ . '/core/BaseController.php';

// --- En-têtes globaux ---
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: '  . CORS_ORIGINS);
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Appliquer les en-têtes de sécurité HTTP
Security::applySecurityHeaders();

// Répondre aux pre-flight CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Rate limiting (protection contre les abus)
Security::checkRateLimit();

// --- Routeur ---
$method   = $_SERVER['REQUEST_METHOD'];
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri      = preg_replace('#^/api#', '', $uri);
$uri      = rtrim($uri, '/') ?: '/';
$segments = array_values(array_filter(explode('/', $uri)));
$resource = $segments[0] ?? '';
$id       = isset($segments[1]) ? Security::sanitizeId($segments[1]) : null;
$sub      = $segments[2] ?? null;

// --- Dispatch ---
switch ($resource) {

    // ----------------------------------------------------------------
    // Auth
    // ----------------------------------------------------------------
    case 'register':
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController())->register();
        break;

    case 'login':
        require_once __DIR__ . '/controllers/AuthController.php';
        (new AuthController())->login();
        break;

    case 'profile':
        require_once __DIR__ . '/controllers/AuthController.php';
        $ctrl = new AuthController();
        if ($sub === 'password' && $method === 'PUT') {
            $ctrl->changePassword();
        } elseif ($method === 'GET') {
            $ctrl->getProfile();
        } elseif ($method === 'PUT') {
            $ctrl->updateProfile();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
        }
        break;

    // ----------------------------------------------------------------
    // Catégories
    // ----------------------------------------------------------------
    case 'categories':
        require_once __DIR__ . '/controllers/CategoryController.php';
        $ctrl = new CategoryController();
        match($method) {
            'GET'    => $ctrl->index(),
            'POST'   => $ctrl->store(),
            'PUT'    => $id ? $ctrl->update($id) : http_response_code(400),
            'DELETE' => $id ? $ctrl->destroy($id) : http_response_code(400),
            default  => http_response_code(405),
        };
        break;

    // ----------------------------------------------------------------
    // Incidents
    // ----------------------------------------------------------------
    case 'incidents':
        if ($id && $sub === 'comments') {
            // Commentaires — on garde l'ancien fichier procédural (compatible)
            require_once __DIR__ . '/api/comments.php';
            handle_comments($method, $id);
        } elseif ($id && $sub === 'vote') {
            require_once __DIR__ . '/api/votes.php';
            handle_votes($method, $id);
        } else {
            require_once __DIR__ . '/controllers/IncidentController.php';
            $ctrl = new IncidentController();
            if ($id) {
                match($method) {
                    'GET'    => $ctrl->show($id),
                    'PUT'    => $ctrl->update($id),
                    'PATCH'  => $ctrl->edit($id),
                    'DELETE' => $ctrl->destroy($id),
                    default  => http_response_code(405),
                };
            } else {
                match($method) {
                    'GET'  => $ctrl->index(),
                    'POST' => $ctrl->store(),
                    default => http_response_code(405),
                };
            }
        }
        break;

    // ----------------------------------------------------------------
    // Notifications
    // ----------------------------------------------------------------
    case 'notifications':
        require_once __DIR__ . '/api/notifications.php';
        handle_notifications($method, $id, $sub);
        break;

    // ----------------------------------------------------------------
    // 404
    // ----------------------------------------------------------------
    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Endpoint '$resource' introuvable.",
        ], JSON_UNESCAPED_UNICODE);
}
