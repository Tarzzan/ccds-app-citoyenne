<?php
/**
 * CCDS v1.6 — Point d'entrée de l'API REST
 * Architecture OO complète — tous les endpoints passent par des contrôleurs.
 * TECH-02 : Suppression des anciens fichiers procéduraux backend/api/
 */

// --- Chargement de la configuration ---
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/PushNotificationService.php';
require_once __DIR__ . '/config/PdfReportService.php';

// --- Autoload Composer (FPDF, Phinx, etc.) ---
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// --- Noyau ---
require_once __DIR__ . '/core/Security.php';
require_once __DIR__ . '/core/Permissions.php';
require_once __DIR__ . '/core/RateLimiter.php';
require_once __DIR__ . '/core/BaseController.php';

// --- En-têtes globaux ---
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: '  . CORS_ORIGINS);
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

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
            $ctrl->getStats();
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
    // 2FA (SEC-03)
    // ----------------------------------------------------------------
    case 'auth':
        require_once __DIR__ . '/controllers/TwoFactorController.php';
        $authSub = $segments[1] ?? '';
        $authAct = $segments[2] ?? '';
        if ($authSub === '2fa') {
            $ctrl = new TwoFactorController();
            match(true) {
                $method === 'GET'    && $authAct === 'status'     => $ctrl->getStatus(),
                $method === 'POST'   && $authAct === 'setup'      => $ctrl->setup(),
                $method === 'POST'   && $authAct === 'verify'     => $ctrl->verify(),
                $method === 'DELETE' && $authAct === 'disable'    => $ctrl->disable(),
                $method === 'POST'   && $authAct === 'send-email' => $ctrl->sendEmailCode(),
                $method === 'POST'   && $authAct === 'validate'   => $ctrl->validateCode(),
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
        require_once __DIR__ . '/controllers/PhotoController.php';
        require_once __DIR__ . '/controllers/ReportController.php';

        if ($id && $sub === 'comments') {
            $ctrl = new CommentController();
            $cid  = (int)($segments[3] ?? 0);
            $csub = $segments[4] ?? '';
            match(true) {
                $method === 'GET'    && !$cid                           => $ctrl->list((int)$id),
                $method === 'POST'   && !$cid                           => $ctrl->create((int)$id),
                $method === 'PUT'    && $cid > 0                        => $ctrl->update((int)$id, $cid),
                $method === 'DELETE' && $cid > 0                        => $ctrl->delete((int)$id, $cid),
                $method === 'POST'   && $cid > 0 && $csub === 'reply'   => $ctrl->create((int)$id),
                default                                                  => http_response_code(405),
            };
        } elseif ($id && $sub === 'photos') {
            $ctrl = new PhotoController();
            $pid  = (int)($segments[3] ?? 0);
            match(true) {
                $method === 'GET'                  => $ctrl->list((int)$id),
                $method === 'POST'                 => $ctrl->upload((int)$id),
                $method === 'DELETE' && $pid > 0   => $ctrl->delete((int)$id, $pid),
                default                            => http_response_code(405),
            };
        } elseif ($id && $sub === 'report' && $method === 'GET') {
            (new ReportController())->downloadPdf((int)$id);
        } elseif ($id && ($sub === 'vote' || $sub === 'votes')) {
            $ctrl = new VoteController();
            match($method) {
                'GET'    => $ctrl->getState((int)$id),
                'POST'   => $ctrl->vote((int)$id),
                'DELETE' => $ctrl->removeVote((int)$id),
                default  => http_response_code(405),
            };
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
    // Gamification (GAMIF-01)
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
    // Sondages (v1.6)
    // GET    /api/polls
    // POST   /api/polls
    // POST   /api/polls/{id}/vote
    // GET    /api/polls/{id}/results
    // ----------------------------------------------------------------
    case 'polls':
        require_once __DIR__ . '/controllers/PollController.php';
        $ctrl = new PollController();
        if ($id && $sub === 'vote' && $method === 'POST') {
            $ctrl->vote((int)$id);
        } elseif ($id && $sub === 'results' && $method === 'GET') {
            $ctrl->results((int)$id);
        } elseif (!$id) {
            match($method) {
                'GET'  => $ctrl->index(),
                'POST' => $ctrl->create(),
                default => http_response_code(405),
            };
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Endpoint polls introuvable.']);
        }
        break;

    // ----------------------------------------------------------------
    // Événements (v1.6)
    // GET    /api/events
    // POST   /api/events
    // GET    /api/events/{id}
    // POST   /api/events/{id}/rsvp
    // ----------------------------------------------------------------
    case 'events':
        require_once __DIR__ . '/controllers/EventController.php';
        $ctrl = new EventController();
        if ($id && $sub === 'rsvp' && $method === 'POST') {
            $ctrl->rsvp((int)$id);
        } elseif ($id && $method === 'GET') {
            $ctrl->show((int)$id);
        } elseif (!$id) {
            match($method) {
                'GET'  => $ctrl->index(),
                'POST' => $ctrl->create(),
                default => http_response_code(405),
            };
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Endpoint events introuvable.']);
        }
        break;

    // ----------------------------------------------------------------
    // Webhooks (v1.6)
    // GET    /api/webhooks
    // POST   /api/webhooks
    // PUT    /api/webhooks/{id}
    // DELETE /api/webhooks/{id}
    // POST   /api/webhooks/{id}/test
    // ----------------------------------------------------------------
    case 'webhooks':
        require_once __DIR__ . '/controllers/WebhookController.php';
        $ctrl = new WebhookController();
        if ($id && $sub === 'test' && $method === 'POST') {
            $ctrl->test((int)$id);
        } elseif ($id) {
            match($method) {
                'PUT'    => $ctrl->update((int)$id),
                'DELETE' => $ctrl->delete((int)$id),
                default  => http_response_code(405),
            };
        } else {
            match($method) {
                'GET'  => $ctrl->index(),
                'POST' => $ctrl->create(),
                default => http_response_code(405),
            };
        }
        break;

    // ----------------------------------------------------------------
    // API Publique (clé API)
    // GET    /api/public/incidents
    // GET    /api/public/stats
    // GET    /api/public/categories
    // ----------------------------------------------------------------
    case 'public':
        require_once __DIR__ . '/controllers/PublicApiController.php';
        $ctrl      = new PublicApiController();
        $publicSub = $segments[1] ?? '';
        match($publicSub) {
            'incidents'  => $ctrl->incidents(),
            'stats'      => $ctrl->stats(),
            'categories' => $ctrl->categories(),
            default      => (function() { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Endpoint public introuvable.']); })(),
        };
        break;

    // ----------------------------------------------------------------
    // RGPD (v1.6)
    // POST   /api/gdpr/export
    // GET    /api/gdpr/download/{filename}
    // DELETE /api/gdpr/account
    // ----------------------------------------------------------------
    case 'gdpr':
        require_once __DIR__ . '/controllers/GdprController.php';
        $ctrl    = new GdprController();
        $gdprSub = $segments[1] ?? '';
        if ($gdprSub === 'export' && $method === 'POST') {
            $ctrl->requestExport();
        } elseif ($gdprSub === 'download' && $method === 'GET') {
            $filename = $segments[2] ?? '';
            $ctrl->download($filename);
        } elseif ($gdprSub === 'account' && $method === 'DELETE') {
            $ctrl->deleteAccount();
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Endpoint RGPD introuvable.']);
        }
        break;

    // ----------------------------------------------------------------
    // Admin — Utilisateurs (ADMIN-04 + API-01)
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
