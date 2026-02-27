<?php
/**
 * CCDS — API Commentaires
 *
 * GET  /api/incidents/{id}/comments  → Liste les commentaires publics d'un signalement
 * POST /api/incidents/{id}/comments  → Ajouter un commentaire (authentifié)
 */

function handle_comments(string $method, int $incidentId): void
{
    $db   = Database::getInstance();
    $auth = require_auth();

    // Vérifier que le signalement existe
    $stmt = $db->prepare('SELECT id FROM incidents WHERE id = ? LIMIT 1');
    $stmt->execute([$incidentId]);
    if (!$stmt->fetch()) {
        json_error('Signalement introuvable.', 404);
    }

    switch ($method) {

        // ----------------------------------------------------------
        // GET — Lister les commentaires
        // ----------------------------------------------------------
        case 'GET':
            // Les citoyens ne voient que les commentaires publics
            // Les agents et admins voient aussi les notes internes
            $isStaff = in_array($auth['role'], ['agent', 'admin'], true);
            $where   = $isStaff ? '' : 'AND c.is_internal = 0';

            $stmt = $db->prepare("
                SELECT
                    c.id, c.comment, c.is_internal, c.created_at,
                    u.id        AS user_id,
                    u.full_name AS user_name,
                    u.role      AS user_role
                FROM comments c
                JOIN users u ON u.id = c.user_id
                WHERE c.incident_id = ? $where
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$incidentId]);
            json_success($stmt->fetchAll());
            break;

        // ----------------------------------------------------------
        // POST — Ajouter un commentaire
        // ----------------------------------------------------------
        case 'POST':
            $body = get_json_body();

            $errors = validate($body, [
                'comment' => 'required|min:2|max:2000',
            ]);
            if (!empty($errors)) {
                json_error('Données invalides.', 422, $errors);
            }

            // Seuls les agents/admins peuvent poster des notes internes
            $isInternal = false;
            if (isset($body['is_internal']) && $body['is_internal']) {
                if (!in_array($auth['role'], ['agent', 'admin'], true)) {
                    json_error('Accès refusé : seuls les agents peuvent poster des notes internes.', 403);
                }
                $isInternal = true;
            }

            $stmt = $db->prepare(
                'INSERT INTO comments (incident_id, user_id, comment, is_internal) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $incidentId,
                $auth['sub'],
                trim($body['comment']),
                $isInternal ? 1 : 0,
            ]);
            $commentId = (int)$db->lastInsertId();

            json_success(['id' => $commentId], 201, 'Commentaire ajouté.');
            break;

        default:
            json_error('Méthode non autorisée.', 405);
    }
}
