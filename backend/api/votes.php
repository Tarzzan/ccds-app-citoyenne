<?php
/**
 * API Votes "Moi aussi" — CCDS Citoyen v1.1
 * Endpoints :
 *   POST   /api/incidents/{id}/vote    — Voter pour un signalement
 *   DELETE /api/incidents/{id}/vote    — Retirer son vote
 *   GET    /api/incidents/{id}/votes   — Obtenir le nombre de votes
 */

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/Database.php';

$db     = Database::getInstance()->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Récupérer l'incident_id depuis l'URL (passé par le routeur)
$incident_id = isset($urlParts[2]) ? (int)$urlParts[2] : 0;

if ($incident_id <= 0) {
    json_response(['error' => 'ID de signalement invalide'], 400);
}

// Vérifier que le signalement existe
$stmt = $db->prepare("SELECT id, votes_count FROM incidents WHERE id = ?");
$stmt->execute([$incident_id]);
$incident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$incident) {
    json_response(['error' => 'Signalement introuvable'], 404);
}

// Obtenir l'IP du client
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = explode(',', $ip)[0]; // Prendre la première IP si multiple

// Obtenir l'utilisateur connecté (optionnel)
$user_id = null;
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth_header) {
    try {
        $payload = jwt_decode(str_replace('Bearer ', '', $auth_header));
        $user_id = $payload['user_id'] ?? null;
    } catch (Exception $e) {
        // Token invalide, on continue en mode anonyme
    }
}

switch ($method) {

    // ── GET : Nombre de votes ──────────────────────────────────────────────
    case 'GET':
        $has_voted = false;
        if ($user_id) {
            $stmt = $db->prepare("SELECT id FROM votes WHERE incident_id = ? AND user_id = ?");
            $stmt->execute([$incident_id, $user_id]);
            $has_voted = (bool)$stmt->fetch();
        } else {
            $stmt = $db->prepare("SELECT id FROM votes WHERE incident_id = ? AND ip_address = ?");
            $stmt->execute([$incident_id, $ip]);
            $has_voted = (bool)$stmt->fetch();
        }

        json_response([
            'incident_id' => $incident_id,
            'votes_count' => (int)$incident['votes_count'],
            'has_voted'   => $has_voted,
        ]);
        break;

    // ── POST : Voter ───────────────────────────────────────────────────────
    case 'POST':
        // Vérifier si déjà voté
        if ($user_id) {
            $stmt = $db->prepare("SELECT id FROM votes WHERE incident_id = ? AND user_id = ?");
            $stmt->execute([$incident_id, $user_id]);
        } else {
            $stmt = $db->prepare("SELECT id FROM votes WHERE incident_id = ? AND ip_address = ?");
            $stmt->execute([$incident_id, $ip]);
        }

        if ($stmt->fetch()) {
            json_response(['error' => 'Vous avez déjà voté pour ce signalement'], 409);
        }

        // Insérer le vote
        $stmt = $db->prepare("INSERT INTO votes (incident_id, user_id, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$incident_id, $user_id, $ip]);

        // Incrémenter le compteur dénormalisé
        $db->prepare("UPDATE incidents SET votes_count = votes_count + 1 WHERE id = ?")->execute([$incident_id]);

        // Récupérer le nouveau compteur
        $stmt = $db->prepare("SELECT votes_count FROM incidents WHERE id = ?");
        $stmt->execute([$incident_id]);
        $new_count = (int)$stmt->fetchColumn();

        // Envoyer une notification si le signalement atteint un palier (5, 10, 25, 50, 100)
        $milestones = [5, 10, 25, 50, 100];
        if (in_array($new_count, $milestones)) {
            // Récupérer l'auteur du signalement
            $stmt = $db->prepare("SELECT user_id, title FROM incidents WHERE id = ?");
            $stmt->execute([$incident_id]);
            $inc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($inc && $inc['user_id']) {
                $stmt = $db->prepare("
                    INSERT INTO notifications (user_id, incident_id, type, title, body)
                    VALUES (?, ?, 'vote_milestone', ?, ?)
                ");
                $stmt->execute([
                    $inc['user_id'],
                    $incident_id,
                    "🎉 Votre signalement est populaire !",
                    "\"" . substr($inc['title'], 0, 50) . "\" a atteint {$new_count} votes \"Moi aussi\"."
                ]);
            }
        }

        json_response([
            'success'     => true,
            'votes_count' => $new_count,
            'has_voted'   => true,
            'message'     => 'Vote enregistré avec succès',
        ], 201);
        break;

    // ── DELETE : Retirer son vote ──────────────────────────────────────────
    case 'DELETE':
        if ($user_id) {
            $stmt = $db->prepare("DELETE FROM votes WHERE incident_id = ? AND user_id = ?");
            $stmt->execute([$incident_id, $user_id]);
        } else {
            $stmt = $db->prepare("DELETE FROM votes WHERE incident_id = ? AND ip_address = ?");
            $stmt->execute([$incident_id, $ip]);
        }

        $deleted = $stmt->rowCount();

        if ($deleted > 0) {
            // Décrémenter le compteur
            $db->prepare("UPDATE incidents SET votes_count = GREATEST(votes_count - 1, 0) WHERE id = ?")->execute([$incident_id]);
        }

        $stmt = $db->prepare("SELECT votes_count FROM incidents WHERE id = ?");
        $stmt->execute([$incident_id]);
        $new_count = (int)$stmt->fetchColumn();

        json_response([
            'success'     => $deleted > 0,
            'votes_count' => $new_count,
            'has_voted'   => false,
            'message'     => $deleted > 0 ? 'Vote retiré' : 'Aucun vote à retirer',
        ]);
        break;

    default:
        json_response(['error' => 'Méthode non autorisée'], 405);
}
