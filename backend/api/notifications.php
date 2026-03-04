<?php
/**
 * API Notifications Push — CCDS Citoyen v1.1
 * Endpoints :
 *   POST /api/notifications/token       — Enregistrer un token Expo Push
 *   GET  /api/notifications             — Lister les notifications de l'utilisateur
 *   PUT  /api/notifications/{id}/read   — Marquer une notification comme lue
 *   PUT  /api/notifications/read-all    — Tout marquer comme lu
 */

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/Database.php';

$db     = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$user   = require_auth(); // Toutes les routes nécessitent une authentification

// Sous-route : /api/notifications/token ou /api/notifications/{id}/read
$sub = $urlParts[2] ?? '';

switch (true) {

    // ── POST /api/notifications/token : Enregistrer token Expo Push ────────
    case ($method === 'POST' && $sub === 'token'):
        $data = json_decode(file_get_contents('php://input'), true);
        $token    = sanitize($data['token'] ?? '');
        $platform = sanitize($data['platform'] ?? 'android');

        if (empty($token) || !str_starts_with($token, 'ExponentPushToken[')) {
            json_response(['error' => 'Token Expo Push invalide'], 400);
        }

        // Upsert : insérer ou mettre à jour si le token existe déjà
        $stmt = $db->prepare("
            INSERT INTO push_tokens (user_id, token, platform)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), updated_at = NOW()
        ");
        $stmt->execute([$user['user_id'], $token, $platform]);

        json_response(['success' => true, 'message' => 'Token enregistré'], 201);
        break;

    // ── GET /api/notifications : Lister les notifications ─────────────────
    case ($method === 'GET' && empty($sub)):
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare("
            SELECT n.id, n.type, n.title, n.body, n.is_read, n.sent_at,
                   i.reference AS incident_reference, i.title AS incident_title
            FROM notifications n
            LEFT JOIN incidents i ON n.incident_id = i.id
            WHERE n.user_id = ?
            ORDER BY n.sent_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user['user_id'], $limit, $offset]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compteur non lus
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['user_id']]);
        $unread_count = (int)$stmt->fetchColumn();

        json_response([
            'notifications' => $notifications,
            'unread_count'  => $unread_count,
            'page'          => $page,
        ]);
        break;

    // ── PUT /api/notifications/read-all : Tout marquer comme lu ───────────
    case ($method === 'PUT' && $sub === 'read-all'):
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        json_response(['success' => true, 'updated' => $stmt->rowCount()]);
        break;

    // ── PUT /api/notifications/{id}/read : Marquer une notif comme lue ────
    case ($method === 'PUT' && is_numeric($sub)):
        $notif_id = (int)$sub;
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notif_id, $user['user_id']]);

        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'Notification introuvable'], 404);
        }
        json_response(['success' => true]);
        break;

    default:
        json_response(['error' => 'Route non trouvée'], 404);
}
