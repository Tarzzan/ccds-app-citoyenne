<?php
/**
 * CCDS v1.3 — Point d'entrée de l'API REST
 * Architecture OO complète — tous les endpoints passent par des contrôleurs.
 * TECH-02 : Suppression des anciens fichiers procéduraux backend/api/
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
        } elseif ($sub === 'stats' && $method === 'GET') {
            $ctrl->getStats(); // v1.5 — Tableau de bord citoyen (UX-07)
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
    // 2FA (v1.5 — SEC-03)
    // ----------------------------------------------------------------
    case 'auth':
        require_once __DIR__ . '/controllers/TwoFactorController.php';
        $authSub = $segments[1] ?? ''; // '2fa'
        $authAct = $segments[2] ?? ''; // 'setup', 'verify', 'disable', 'send-email', 'validate', 'status'
        if ($authSub === '2fa') {
            $ctrl = new TwoFactorController();
            match(true) {
                $method === 'GET'    && $authAct === 'status'     => $ctrl->getStatus(),
                $method === 'POST'   && $authAct === 'setup'      => $ctrl->setup(),
                $method === 'POST'   && $authAct === 'verify'     => $ctrl->verify(),
                $method === 'DELETE' && $authAct === 'disable'    => $ctrl->disable(),
                $method === 'POST'   && $authAct === 'send-email' => $ctrl->sendEmailCode(),
                $method === 'POST'   && $authAct === 'validate'   => $ctrl->validate(),
                default => (function() { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']); })()
            };
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Endpoint auth introuvable.']);
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
        require_once __DIR__ . '/controllers/IncidentController.php';
        require_once __DIR__ . '/controllers/CommentController.php';
        require_once __DIR__ . '/controllers/VoteController.php';

        if ($id && $sub === 'comments') {
            // GET /incidents/{id}/comments ou POST /incidents/{id}/comments
            $ctrl = new CommentController();
            match($method) {
                'GET'  => $ctrl->list((int)$id),
                'POST' => $ctrl->create((int)$id),
                default => http_response_code(405),
            };
        } elseif ($id && $sub === 'comments') {
            // Commentaires v1.4 (UX-05) : GET, POST, PUT /{cid}, DELETE /{cid}, POST /{cid}/reply
            require_once __DIR__ . '/controllers/CommentController.php';
            $ctrl = new CommentController();
            $cid  = (int)($segments[4] ?? 0);
            $csub = $segments[5] ?? '';
            match(true) {
                $method === 'GET'  && !$cid             => $ctrl->list((int)$id),
                $method === 'POST' && !$cid             => $ctrl->create((int)$id),
                $method === 'PUT'  && $cid > 0          => $ctrl->update((int)$id, $cid),
                $method === 'DELETE' && $cid > 0        => $ctrl->delete((int)$id, $cid),
                $method === 'POST' && $cid > 0 && $csub === 'reply' => $ctrl->create((int)$id), // parent_id dans le body
                default                                 => http_response_code(405),
            };
        } elseif ($id && $sub === 'photos') {
            // GET/POST /incidents/{id}/photos  et  DELETE /incidents/{id}/photos/{pid}
            require_once __DIR__ . '/controllers/PhotoController.php';
            $ctrl = new PhotoController();
            $pid  = (int)($segments[4] ?? 0); // /incidents/{id}/photos/{pid}
            match(true) {
                $method === 'GET'                  => $ctrl->list((int)$id),
                $method === 'POST'                 => $ctrl->upload((int)$id),
                $method === 'DELETE' && $pid > 0   => $ctrl->delete((int)$id, $pid),
                default                            => http_response_code(405),
            };
        } elseif ($id && $sub === 'report' && $method === 'GET') {
            // GET /incidents/{id}/report → Télécharger le PDF (ADMIN-05)
            require_once __DIR__ . '/controllers/ReportController.php';
            (new ReportController())->downloadPdf((int)$id);
        } elseif ($id && $sub === 'vote') {
            // POST /incidents/{id}/vote ou DELETE /incidents/{id}/vote ou GET /incidents/{id}/votes
            $ctrl = new VoteController();
            match($method) {
                'GET'    => $ctrl->getState((int)$id),
                'POST'   => $ctrl->vote((int)$id),
                'DELETE' => $ctrl->removeVote((int)$id),
                default  => http_response_code(405),
            };
        } elseif ($id && $sub === 'votes') {
            require_once __DIR__ . '/controllers/VoteController.php';
            (new VoteController())->getState((int)$id);
        } else {
            $ctrl = new IncidentController();
            if ($id) {
                match($method) {
                    'GET'    => $ctrl->show((int)$id),
                    'PUT'    => $ctrl->update((int)$id),
                    'PATCH'  => $ctrl->edit((int)$id),
                    'DELETE' => $ctrl->destroy((int)$id),
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
    // Commentaires (suppression directe)
    // ----------------------------------------------------------------
    case 'comments':
        require_once __DIR__ . '/controllers/CommentController.php';
        $ctrl = new CommentController();
        if ($id && $method === 'DELETE') {
            $ctrl->delete((int)$id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
        }
        break;

    // ----------------------------------------------------------------
    // Notifications
    // ----------------------------------------------------------------
    case 'notifications':
        require_once __DIR__ . '/controllers/NotificationController.php';
        $ctrl = new NotificationController();

        if ($sub === 'token' && $method === 'POST') {
            $ctrl->registerToken();
        } elseif ($id === 'read-all' && $method === 'PUT') {
            $ctrl->markAllRead();
        } elseif ($id === 'send' && $method === 'POST') {
            $ctrl->send();
        } elseif ($id && $sub === 'read' && $method === 'PUT') {
            $ctrl->markRead((int)$id);
        } elseif (!$id && $method === 'GET') {
            $ctrl->list();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
        }
        break;

    // ----------------------------------------------------------------
    // Gamification (v1.3 — GAMIF-01)
    // ----------------------------------------------------------------
    case 'gamification':
        require_once __DIR__ . '/controllers/GamificationController.php';
        $ctrl = new GamificationController();
        if ($sub === 'badges' && $method === 'GET') {
            $ctrl->badges();
        } elseif ($method === 'GET') {
            $ctrl->stats();
        } else {
            http_response_code(405);
        }
        break;

    // ----------------------------------------------------------------
    // Admin — Utilisateurs (v1.4 — ADMIN-04 + API-01)
    // GET    /api/admin/users
    // GET    /api/admin/users/{id}
    // PUT    /api/admin/users/{id}
    // GET    /api/admin/users/{id}/activity
    // GET    /api/admin/stats/users
    // ----------------------------------------------------------------
    case 'admin':
        require_once __DIR__ . '/controllers/UserController.php';
        $adminResource = $segments[1] ?? '';
        $adminId       = isset($segments[2]) ? Security::sanitizeId($segments[2]) : null;
        $adminSub      = $segments[3] ?? null;

        if ($adminResource === 'users') {
            $ctrl = new UserController();
            if ($adminId && $adminSub === 'activity') {
                $ctrl->activity((int)$adminId);
            } elseif ($adminId) {
                match($method) {
                    'GET' => $ctrl->show((int)$adminId),
                    'PUT' => $ctrl->update((int)$adminId),
                    default => http_response_code(405),
                };
            } else {
                match($method) {
                    'GET' => $ctrl->index(),
                    default => http_response_code(405),
                };
            }
        } elseif ($adminResource === 'stats' && ($segments[2] ?? '') === 'users') {
            (new UserController())->stats();
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => "Ressource admin '$adminResource' introuvable."]);
        }
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
