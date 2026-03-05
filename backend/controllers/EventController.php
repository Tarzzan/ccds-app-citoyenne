<?php
/**
 * EventController — Événements communautaires (UX-12)
 *
 * Colonnes réelles (migration 20260304000007) :
 * - events     : id, title, description, location, event_date, created_by, created_at
 * - event_rsvps: id, event_id, user_id, status, created_at
 * - users      : full_name (pas 'name')
 */
class EventController extends BaseController
{
    /**
     * GET /events
     * Liste des événements à venir.
     */
    public function index(): void
    {
        $user = $this->requireAuth();
        $this->applyRateLimit('default', $user['id']);

        $stmt = $this->db->prepare("
            SELECT e.*,
                   u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'attending') AS attendees_count,
                   (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'interested') AS interested_count,
                   (SELECT status FROM event_rsvps WHERE event_id = e.id AND user_id = :uid LIMIT 1) AS user_rsvp
            FROM events e
            JOIN users u ON u.id = e.created_by
            WHERE e.event_date >= NOW()
            ORDER BY e.event_date ASC
        ");
        $stmt->execute([':uid' => $user['id']]);
        $this->success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * POST /events
     * Créer un événement (agent ou admin).
     */
    public function create(): void
    {
        $user = $this->requireAuth();
        if (!in_array($user['role'], ['agent', 'admin'])) {
            $this->error('Accès réservé aux agents et administrateurs.', 403);
        }
        $this->applyRateLimit('default', $user['id']);

        $input = json_decode(file_get_contents('php://input'), true);

        $errors = [];
        if (empty($input['title']))      $errors[] = 'Le titre est requis.';
        if (empty($input['event_date'])) $errors[] = 'La date est requise.';
        if (empty($input['location']))   $errors[] = 'Le lieu est requis.';
        if (!empty($errors)) $this->error(implode(' ', $errors), 422);

        $stmt = $this->db->prepare("
            INSERT INTO events (title, description, location, event_date, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            Security::sanitizeString($input['title']),
            Security::sanitizeString($input['description'] ?? ''),
            Security::sanitizeString($input['location']),
            $input['event_date'],
            $user['id'],
        ]);

        $eventId = (int) $this->db->lastInsertId();

        // Notifier tous les utilisateurs actifs
        $this->notifyAllUsers($eventId, $input['title']);

        $this->success(['id' => $eventId], 201, 'Événement créé avec succès.');
    }

    /**
     * POST /events/{id}/rsvp
     * S'inscrire ou modifier son inscription à un événement.
     */
    public function rsvp(int $eventId): void
    {
        $user  = $this->requireAuth();
        $this->applyRateLimit('default', $user['id']);

        $input  = json_decode(file_get_contents('php://input'), true);
        $status = $input['status'] ?? 'attending';

        if (!in_array($status, ['attending', 'interested', 'not_attending'])) {
            $this->error('Statut invalide. Valeurs acceptées : attending, interested, not_attending.', 400);
        }

        // Vérifier que l'événement existe et est à venir
        $eventStmt = $this->db->prepare("
            SELECT id, title FROM events WHERE id = ? AND event_date >= NOW()
        ");
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            $this->error('Événement introuvable ou déjà passé.', 404);
        }

        if ($status === 'not_attending') {
            // Supprimer l'inscription
            $this->db->prepare("DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?")
                ->execute([$eventId, $user['id']]);
            $this->success(null, 200, 'Inscription annulée.');
        } else {
            // Insérer ou mettre à jour
            $stmt = $this->db->prepare("
                INSERT INTO event_rsvps (event_id, user_id, status, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            $stmt->execute([$eventId, $user['id'], $status]);
            $this->success(['status' => $status], 200, 'Inscription enregistrée.');
        }
    }

    /**
     * GET /events/{id}
     * Détail d'un événement avec la liste des participants.
     */
    public function show(int $eventId): void
    {
        $user = $this->requireAuth();

        $eventStmt = $this->db->prepare("
            SELECT e.*, u.full_name AS created_by_name,
                   (SELECT status FROM event_rsvps WHERE event_id = e.id AND user_id = ? LIMIT 1) AS user_rsvp
            FROM events e
            JOIN users u ON u.id = e.created_by
            WHERE e.id = ?
        ");
        $eventStmt->execute([$user['id'], $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) $this->error('Événement introuvable.', 404);

        // Participants (limité à 20 pour la preview)
        $attendeesStmt = $this->db->prepare("
            SELECT u.id, u.full_name AS name, r.status, r.created_at
            FROM event_rsvps r
            JOIN users u ON u.id = r.user_id
            WHERE r.event_id = ? AND r.status = 'attending'
            ORDER BY r.created_at ASC
            LIMIT 20
        ");
        $attendeesStmt->execute([$eventId]);
        $event['attendees'] = $attendeesStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->success($event);
    }

    // ─── Méthodes privées ────────────────────────────────────────────────────

    private function notifyAllUsers(int $eventId, string $title): void
    {
        // Insérer une notification pour tous les utilisateurs actifs
        // incident_id est nullable — on passe NULL pour les notifications d'événements
        try {
            $this->db->prepare("
                INSERT INTO notifications (user_id, incident_id, title, body, type, created_at)
                SELECT id, NULL,
                       '📅 Nouvel événement communautaire',
                       :title,
                       'event',
                       NOW()
                FROM users
                WHERE is_active = 1
            ")->execute([':title' => $title]);
        } catch (\Exception $e) {
            // Ne pas bloquer la création de l'événement si les notifications échouent
            error_log('EventController::notifyAllUsers error: ' . $e->getMessage());
        }
    }
}
