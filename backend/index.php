<?php
/**
 * Ma Commune v1.6 — Point d'entrée de l'API REST
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

$corsOriginsRaw = defined('CORS_ORIGINS') ? CORS_ORIGINS : '*';
$corsOriginHeader = '*';

if ($corsOriginsRaw !== '*') {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $corsOriginsRaw))));
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
        $corsOriginHeader = $requestOrigin;
        header('Vary: Origin');
    } elseif (count($allowedOrigins) === 1) {
        $corsOriginHeader = $allowedOrigins[0];
    } else {
        $corsOriginHeader = $allowedOrigins[0] ?? '';
        if ($corsOriginHeader !== '') {
            header('Vary: Origin');
        }
    }
}

if ($corsOriginHeader !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOriginHeader);
}

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
$uri      = preg_replace('#^/(mc-api|api)#', '', $uri);
$uri      = rtrim($uri, '/') ?: '/';
$segments = array_values(array_filter(explode('/', $uri)));
$resource = $segments[0] ?? '';
$idRaw    = $segments[1] ?? null;
$id       = $idRaw !== null ? Security::sanitizeId($idRaw) : null;
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
        $profileSub = $segments[1] ?? null;  // sous-route textuelle (/profile/{sub})
        if ($profileSub === 'password' && $method === 'PUT') {
            $ctrl->changePassword();
        } elseif ($profileSub === 'stats' && $method === 'GET') {
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
        $authSub = $segments[1] ?? '';
        $authAct = $segments[2] ?? '';
        if ($authSub === '2fa') {
            require_once __DIR__ . '/controllers/TwoFactorController.php';
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
        } elseif ($authSub === 'google') {
            require_once __DIR__ . '/controllers/AuthController.php';
            $ctrl = new AuthController();
            match(true) {
                $method === 'POST' => $ctrl->googleLogin(),
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
        require_once __DIR__ . '/controllers/ModerationController.php';

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
            $psub = $segments[4] ?? '';
            match(true) {
                $method === 'GET'                                    => $ctrl->list((int)$id),
                $method === 'POST' && $pid === 0                     => $ctrl->upload((int)$id),
                $method === 'DELETE' && $pid > 0                     => $ctrl->delete((int)$id, $pid),
                $method === 'POST' && $pid > 0 && $psub === 'report' => (new ModerationController())->reportPhoto((int)$id, $pid),
                default                                              => http_response_code(405),
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
        require_once __DIR__ . '/controllers/ModerationController.php';
        if ($id && $sub === 'report' && $method === 'POST') {
            (new ModerationController())->reportComment((int) $id);
        } elseif ($id && $method === 'DELETE') {
            $ctrl = new CommentController();
            $ctrl->deleteStandalone((int)$id);
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

        if ($idRaw === 'token' && $method === 'POST') {
            $ctrl->registerToken();
        } elseif ($idRaw === 'read-all' && $method === 'PUT') {
            $ctrl->markAllRead();
        } elseif ($idRaw === 'send' && $method === 'POST') {
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
        if ($idRaw === 'badges' && $method === 'GET') {
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
    // Config publique (sans auth) — companion skin, etc.
    // GET  /api/config/companion
    // ----------------------------------------------------------------
    case 'config':
        $configSub = $segments[1] ?? '';
        if ($configSub === 'companion' && $method === 'GET') {
            // Lecture du companion actif depuis les settings visuels admin
            $adminIncludesDir = dirname(__DIR__) . '/admin/includes';
            if (!defined('UPLOAD_DIR') && is_file($adminIncludesDir . '/bootstrap.php')) {
                // Chargement minimal : juste les helpers visuels
                $configPhp = dirname(__DIR__) . '/backend/config/config.php';
                if (is_file($configPhp)) {
                    require_once $configPhp;
                }
            }
            if (is_file($adminIncludesDir . '/generated_visuals.php')) {
                require_once $adminIncludesDir . '/generated_visuals.php';
            }

            $companionAssetId = null;
            $companionUrl     = null;

            if (function_exists('visual_admin_settings')) {
                $settings = visual_admin_settings();
                $companionAssetId = $settings['slots']['companion'] ?? 'CHAR-05';
            }

            if ($companionAssetId && function_exists('generated_visual_url')) {
                $companionUrl = generated_visual_url($companionAssetId);
            }

            // Fallback : URL directe sur le serveur de production
            if (!$companionUrl && $companionAssetId) {
                $charMap = [
                    'CHAR-01' => 'char-01-mascot-m1-portrait-4x5.png',
                    'CHAR-02' => 'char-02-mascot-m2-portrait-4x5.png',
                    'CHAR-03' => 'char-03-mascot-m3-portrait-4x5.png',
                    'CHAR-04' => 'char-04-agent-a1-bust-4x5.png',
                    'CHAR-05' => 'char-05-agent-a2-bust-4x5.png',
                    'CHAR-06' => 'char-06-duo-m1-a2-hero-16x9.png',
                ];
                if (isset($charMap[$companionAssetId])) {
                    $companionUrl = '/admin/assets/img/generated-visuals/characters/' . $charMap[$companionAssetId];
                }
            }

            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

            echo json_encode([
                'success'      => true,
                'companion'    => [
                    'asset_id' => $companionAssetId ?? 'CHAR-05',
                    'url'      => $companionUrl ? $baseUrl . $companionUrl . '?v=' . time() : null,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Endpoint config introuvable.']);
        }
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
        $adminIdRaw    = $segments[2] ?? null;
        $adminId       = $adminIdRaw !== null ? Security::sanitizeId($adminIdRaw) : null;
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
        // ----------------------------------------------------------------
        // Admin — Logs d'audit (ADMIN-08)
        // GET  /api/admin/audit-logs
        // GET  /api/admin/audit-logs/export
        // ----------------------------------------------------------------
        } elseif ($adminResource === 'audit-logs') {
            require_once __DIR__ . '/controllers/AuditLogController.php';
            $auditCtrl = new AuditLogController();
            if ($adminIdRaw === 'export' && $method === 'GET') {
                $auditCtrl->exportCsv();
            } elseif ($method === 'GET') {
                $auditCtrl->index();
            } else {
                http_response_code(405);
            }
        // ----------------------------------------------------------------
        // Admin — Modération des commentaires (ADMIN-07)
        // GET  /api/admin/moderation/reports
        // PUT  /api/admin/moderation/reports/{id}
        // GET  /api/admin/moderation/stats
        // ----------------------------------------------------------------
        } elseif ($adminResource === 'moderation') {
            require_once __DIR__ . '/controllers/ModerationController.php';
            $modCtrl = new ModerationController();
            $modSub  = $segments[2] ?? '';
            $modId   = isset($segments[3]) ? Security::sanitizeId($segments[3]) : null;
            if ($modSub === 'stats' && $method === 'GET') {
                $modCtrl->getStats();
            } elseif ($modSub === 'reports' && $modId && $method === 'PUT') {
                $modCtrl->reviewReport((int)$modId);
            } elseif ($modSub === 'reports' && $method === 'GET') {
                $modCtrl->getReports();
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint moderation introuvable.']);
            }
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
