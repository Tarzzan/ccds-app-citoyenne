<?php
/**
 * EventController — Événements communautaires (UX-12)
 *
 * Colonnes réelles (migration 20260304000007) :
 * - events     : id, title, description, location, event_date, created_by, created_at
 * - event_rsvps: id, event_id, user_id, status, created_at
 * - users      : full_name (pas 'name')
 */
require_once __DIR__ . '/../config/NotificationStore.php';

class EventController extends BaseController
{
    private ?bool $legacyRsvpStatuses = null;

    /**
     * GET /events
     * Liste des événements à venir.
     */
    public function index(): void
    {
        $user   = $this->requireAuth();
        $userId = (int)($user['sub'] ?? 0);
        $this->applyRateLimit('default', $userId);

        $eventDateColumn = $this->eventDateColumn();
        $stmt = $this->db->prepare("
            SELECT e.*,
                   e.{$eventDateColumn} AS event_date,
                   u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'attending') AS attendees_count,
                   (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status IN ('interested', 'maybe')) AS interested_count,
                   (SELECT status FROM event_rsvps WHERE event_id = e.id AND user_id = :uid LIMIT 1) AS user_rsvp_raw
            FROM events e
            JOIN users u ON u.id = e.created_by
            WHERE e.{$eventDateColumn} >= NOW()
            ORDER BY e.{$eventDateColumn} ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->success(array_map([$this, 'normalizeEventRecord'], $events));
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
        $userId = (int)($user['sub'] ?? 0);
        $this->applyRateLimit('default', $userId);

        $input = json_decode(file_get_contents('php://input'), true);

        $errors = [];
        if (empty($input['title']))      $errors[] = 'Le titre est requis.';
        if (empty($input['event_date'])) $errors[] = 'La date est requise.';
        if (empty($input['location']))   $errors[] = 'Le lieu est requis.';
        if (!empty($errors)) $this->error(implode(' ', $errors), 422);

        $columns = ['title', 'description', 'location'];
        $placeholders = ['?', '?', '?'];
        $params = [
            Security::sanitizeString($input['title']),
            Security::sanitizeString($input['description'] ?? ''),
            Security::sanitizeString($input['location']),
        ];

        $eventDateColumn = $this->eventDateColumn();
        $columns[] = $eventDateColumn;
        $placeholders[] = '?';
        $params[] = $input['event_date'];

        if ($this->dbHasColumn('events', 'ends_at')) {
            $columns[] = 'ends_at';
            $placeholders[] = '?';
            $params[] = null;
        }

        if ($this->dbHasColumn('events', 'is_published')) {
            $columns[] = 'is_published';
            $placeholders[] = '1';
        }

        $columns[] = 'created_by';
        $placeholders[] = '?';
        $params[] = $userId;

        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';

        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO events (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        ));
        $stmt->execute($params);

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
        $user   = $this->requireAuth();
        $userId = (int)($user['sub'] ?? 0);
        $this->applyRateLimit('default', $userId);

        $input  = json_decode(file_get_contents('php://input'), true);
        $status = $this->normalizeRequestedRsvpStatus($input['status'] ?? 'attending');

        if (!in_array($status, ['attending', 'interested', 'not_attending'])) {
            $this->error('Statut invalide. Valeurs acceptées : attending, interested, not_attending.', 400);
        }

        // Vérifier que l'événement existe et est à venir
        $eventDateColumn = $this->eventDateColumn();
        $eventStmt = $this->db->prepare("
            SELECT id, title FROM events WHERE id = ? AND {$eventDateColumn} >= NOW()
        ");
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) {
            $this->error('Événement introuvable ou déjà passé.', 404);
        }

        if ($status === 'not_attending') {
            // Supprimer l'inscription
            $this->db->prepare("DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?")
                ->execute([$eventId, $userId]);
            $this->success(null, 200, 'Inscription annulée.');
        } else {
            // Insérer ou mettre à jour
            $stmt = $this->db->prepare("
                INSERT INTO event_rsvps (event_id, user_id, status, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            $stmt->execute([$eventId, $userId, $this->dbRsvpStatus($status)]);
            $this->success(['status' => $status], 200, 'Inscription enregistrée.');
        }
    }

    /**
     * GET /events/{id}
     * Détail d'un événement avec la liste des participants.
     */
    public function show(int $eventId): void
    {
        $user   = $this->requireAuth();
        $userId = (int)($user['sub'] ?? 0);

        $eventDateColumn = $this->eventDateColumn();
        $eventStmt = $this->db->prepare("
            SELECT e.*, e.{$eventDateColumn} AS event_date, u.full_name AS created_by_name,
                   (SELECT status FROM event_rsvps WHERE event_id = e.id AND user_id = ? LIMIT 1) AS user_rsvp_raw
            FROM events e
            JOIN users u ON u.id = e.created_by
            WHERE e.id = ?
        ");
        $eventStmt->execute([$userId, $eventId]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) $this->error('Événement introuvable.', 404);
        $event = $this->normalizeEventRecord($event);

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

    private function eventDateColumn(): string
    {
        return $this->dbHasColumn('events', 'event_date') ? 'event_date' : 'starts_at';
    }

    private function usesLegacyRsvpStatuses(): bool
    {
        if ($this->legacyRsvpStatuses !== null) {
            return $this->legacyRsvpStatuses;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM event_rsvps LIKE 'status'");
            $column = $stmt->fetch(PDO::FETCH_ASSOC);
            $type = (string) ($column['Type'] ?? '');
            $this->legacyRsvpStatuses = str_contains($type, "'maybe'") || str_contains($type, "'declined'");
        } catch (Throwable $e) {
            $this->legacyRsvpStatuses = false;
        }

        return $this->legacyRsvpStatuses;
    }

    private function normalizeRequestedRsvpStatus(string $status): string
    {
        return match ($status) {
            'maybe' => 'interested',
            'declined' => 'not_attending',
            default => $status,
        };
    }

    private function dbRsvpStatus(string $status): string
    {
        if (!$this->usesLegacyRsvpStatuses()) {
            return $status;
        }

        return match ($status) {
            'interested' => 'maybe',
            'not_attending' => 'declined',
            default => $status,
        };
    }

    private function normalizeEventRecord(array $event): array
    {
        $event['user_rsvp'] = match ($event['user_rsvp_raw'] ?? null) {
            'maybe' => 'interested',
            'declined' => 'not_attending',
            default => $event['user_rsvp_raw'] ?? null,
        };
        unset($event['user_rsvp_raw']);

        return $event;
    }

    // ─── Méthodes privées ────────────────────────────────────────────────────

    private function notifyAllUsers(int $eventId, string $title): void
    {
        try {
            $stmt = $this->db->query("SELECT id FROM users WHERE is_active = 1");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                NotificationStore::insert(
                    $this->db,
                    (int) $userId,
                    null,
                    'event',
                    '📅 Nouvel événement communautaire',
                    'Un nouveau rendez-vous est proposé : ' . $title,
                    ['event_id' => $eventId],
                    0
                );
            }
        } catch (\Exception $e) {
            // Ne pas bloquer la création de l'événement si les notifications échouent
            error_log('EventController::notifyAllUsers error: ' . $e->getMessage());
        }
    }
}
