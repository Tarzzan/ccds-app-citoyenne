<?php
/**
 * Service d'envoi de notifications push via l'API Expo Push
 * Documentation : https://docs.expo.dev/push-notifications/sending-notifications/
 * Ma Commune — service de notifications push
 */
class PushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Envoyer une notification à un utilisateur spécifique
     */
    public function sendToUser(int $user_id, string $title, string $body, array $data = []): bool
    {
        // Récupérer tous les tokens de l'utilisateur
        $stmt = $this->db->prepare("SELECT token FROM push_tokens WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tokens)) {
            return false;
        }

        return $this->sendBatch($tokens, $title, $body, $data);
    }

    /**
     * Notifier le citoyen d'un changement de statut de son signalement
     */
    public function notifyStatusChange(int $incident_id, string $new_status, ?string $note = null): void
    {
        // Récupérer le signalement et son auteur
        $stmt = $this->db->prepare("
            SELECT i.user_id, i.title, i.reference, u.full_name
            FROM incidents i
            JOIN users u ON i.user_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$incident_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) return;

        $statusLabels = [
            'pending'     => 'En attente de traitement',
            'acknowledged'=> 'Pris en compte',
            'in_progress' => 'En cours de traitement',
            'resolved'    => 'Résolu ✅',
            'rejected'    => 'Refusé',
            'closed'      => 'Clôturé',
        ];

        $label = $statusLabels[$new_status] ?? $new_status;
        $title = "Mise à jour de votre signalement";
        $body  = "Bonjour {$incident['full_name']}, votre signalement \"{$incident['title']}\" est maintenant : {$label}.";
        if ($note) {
            $body .= "\n" . trim($note);
        }

        // Enregistrer en base
        if (!$this->saveNotification($incident['user_id'], $incident_id, 'status_change', $title, $body)) {
            return;
        }

        // Envoyer la push
        $this->sendToUser($incident['user_id'], $title, $body, [
            'type'        => 'status_change',
            'incident_id' => $incident_id,
            'reference'   => $incident['reference'],
            'new_status'  => $new_status,
            'note'        => $note,
        ]);
    }

    /**
     * Notifier le citoyen d'un nouveau commentaire public sur son signalement
     */
    public function notifyNewComment(int $incident_id, string $commenter_name): void
    {
        $stmt = $this->db->prepare("SELECT user_id, title FROM incidents WHERE id = ?");
        $stmt->execute([$incident_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) return;

        $title = "Nouveau commentaire sur votre signalement";
        $body  = "{$commenter_name} a commenté votre signalement \"{$incident['title']}\".";

        if (!$this->saveNotification($incident['user_id'], $incident_id, 'new_comment', $title, $body)) {
            return;
        }
        $this->sendToUser($incident['user_id'], $title, $body, [
            'type'        => 'new_comment',
            'incident_id' => $incident_id,
        ]);
    }

    /**
     * Notifier le citoyen qu'une intervention a ete planifiee ou replanifiee.
     */
    public function notifyInterventionPlanned(int $incident_id, array $plan, bool $isReschedule = false): void
    {
        $stmt = $this->db->prepare("
            SELECT i.user_id, i.title, i.reference, u.full_name
            FROM incidents i
            JOIN users u ON i.user_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$incident_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            return;
        }

        $serviceName = trim((string)($plan['service_name'] ?? 'service communal'));
        $scheduledDate = $this->formatFrenchDate($plan['scheduled_date'] ?? null);
        $timeWindow = $this->formatTimeWindow($plan['time_window_start'] ?? null, $plan['time_window_end'] ?? null);
        $providerLabel = !empty($plan['provider_name'])
            ? 'Prestataire missionne : ' . trim((string)$plan['provider_name']) . '.'
            : null;

        $title = $isReschedule
            ? "Intervention reprogrammee"
            : "Intervention planifiee";

        $bodyParts = [
            "Bonjour {$incident['full_name']},",
            $isReschedule
                ? "le {$serviceName} a mis a jour l intervention prevue pour votre signalement \"{$incident['title']}\"."
                : "le {$serviceName} a planifie une intervention pour votre signalement \"{$incident['title']}\".",
        ];

        if ($scheduledDate !== null) {
            $bodyParts[] = $timeWindow !== null
                ? "Passage prevu le {$scheduledDate}, {$timeWindow}."
                : "Passage prevu le {$scheduledDate}.";
        }

        if (!empty($plan['citizen_message'])) {
            $bodyParts[] = trim((string)$plan['citizen_message']);
        }

        if ($providerLabel !== null) {
            $bodyParts[] = $providerLabel;
        }

        $body = implode(' ', array_filter($bodyParts));

        if (!$this->saveNotification($incident['user_id'], $incident_id, 'intervention_plan', $title, $body)) {
            return;
        }
        $this->sendToUser($incident['user_id'], $title, $body, [
            'type' => 'intervention_plan',
            'incident_id' => $incident_id,
            'reference' => $incident['reference'],
            'service_name' => $serviceName,
            'scheduled_date' => $plan['scheduled_date'] ?? null,
            'time_window_start' => $plan['time_window_start'] ?? null,
            'time_window_end' => $plan['time_window_end'] ?? null,
            'citizen_message' => $plan['citizen_message'] ?? null,
            'provider_name' => $plan['provider_name'] ?? null,
            'is_reschedule' => $isReschedule,
        ]);
    }

    /**
     * Notifier le citoyen qu'une intervention change d'etat.
     */
    public function notifyInterventionUpdated(int $incident_id, array $plan, string $state): void
    {
        $stmt = $this->db->prepare("
            SELECT i.user_id, i.title, i.reference, u.full_name
            FROM incidents i
            JOIN users u ON i.user_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$incident_id]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            return;
        }

        $serviceName = trim((string)($plan['service_name'] ?? 'service communal'));
        $providerName = trim((string)($plan['provider_name'] ?? ''));
        $scheduledDate = $this->formatFrenchDate($plan['scheduled_date'] ?? null);
        $timeWindow = $this->formatTimeWindow($plan['time_window_start'] ?? null, $plan['time_window_end'] ?? null);
        $providerLabel = $providerName !== '' ? 'Prestataire missionne : ' . $providerName . '.' : null;

        $title = match ($state) {
            'in_progress' => 'Intervention en cours',
            'completed' => 'Intervention terminee',
            'cancelled' => 'Intervention annulee',
            default => 'Mise a jour de l intervention',
        };

        $bodyParts = match ($state) {
            'in_progress' => [
                "Bonjour {$incident['full_name']},",
                "le {$serviceName} est maintenant en intervention sur votre signalement \"{$incident['title']}\".",
                $scheduledDate !== null
                    ? ($timeWindow !== null ? "Creneau annonce : {$scheduledDate}, {$timeWindow}." : "Passage annonce : {$scheduledDate}.")
                    : null,
            ],
            'completed' => [
                "Bonjour {$incident['full_name']},",
                "l intervention du {$serviceName} sur votre signalement \"{$incident['title']}\" est terminee.",
                !empty($plan['citizen_message']) ? trim((string)$plan['citizen_message']) : null,
            ],
            'cancelled' => [
                "Bonjour {$incident['full_name']},",
                "l intervention prevue pour votre signalement \"{$incident['title']}\" a ete annulee par le {$serviceName}.",
                "Une nouvelle planification pourra vous etre transmise si besoin.",
            ],
            default => [
                "Bonjour {$incident['full_name']},",
                "le {$serviceName} a mis a jour l intervention liee a votre signalement \"{$incident['title']}\".",
            ],
        };

        if ($providerLabel !== null) {
            $bodyParts[] = $providerLabel;
        }

        $body = implode(' ', array_filter($bodyParts));

        if (!$this->saveNotification($incident['user_id'], $incident_id, 'intervention_update', $title, $body)) {
            return;
        }
        $this->sendToUser($incident['user_id'], $title, $body, [
            'type' => 'intervention_update',
            'incident_id' => $incident_id,
            'reference' => $incident['reference'],
            'service_name' => $serviceName,
            'plan_status' => $state,
            'scheduled_date' => $plan['scheduled_date'] ?? null,
            'time_window_start' => $plan['time_window_start'] ?? null,
            'time_window_end' => $plan['time_window_end'] ?? null,
            'provider_name' => $providerName !== '' ? $providerName : null,
        ]);
    }

    /**
     * Envoyer un lot de notifications via l'API Expo
     */
    private function sendBatch(array $tokens, string $title, string $body, array $data = []): bool
    {
        $messages = array_map(fn($token) => [
            'to'    => $token,
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'sound' => 'default',
            'badge' => 1,
            'channelId' => (defined('APP_SLUG') ? str_replace('_', '-', APP_SLUG) : 'ma-commune') . '-notifications',
        ], $tokens);

        $ch = curl_init(self::EXPO_PUSH_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
            ],
            CURLOPT_POSTFIELDS     => json_encode($messages),
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 200;
    }

    /**
     * Sauvegarder une notification en base de données
     */
    private function saveNotification(int $user_id, int $incident_id, string $type, string $title, string $body): bool
    {
        if ($this->notificationExistsRecently($user_id, $incident_id, $type, $title, $body)) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, incident_id, type, title, body)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $incident_id, $type, $title, $body]);
        return true;
    }

    private function notificationExistsRecently(int $user_id, int $incident_id, string $type, string $title, string $body): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM notifications
            WHERE user_id = ?
              AND incident_id = ?
              AND type = ?
              AND title = ?
              AND body = ?
              AND sent_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            LIMIT 1
        ");
        $stmt->execute([$user_id, $incident_id, $type, $title, $body]);

        return (bool)$stmt->fetchColumn();
    }

    private function formatFrenchDate(?string $isoDate): ?string
    {
        if (!$isoDate) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $isoDate);
        if (!$date) {
            return $isoDate;
        }

        $months = [
            1 => 'janvier',
            2 => 'fevrier',
            3 => 'mars',
            4 => 'avril',
            5 => 'mai',
            6 => 'juin',
            7 => 'juillet',
            8 => 'aout',
            9 => 'septembre',
            10 => 'octobre',
            11 => 'novembre',
            12 => 'decembre',
        ];

        $month = $months[(int)$date->format('n')] ?? $date->format('m');
        return $date->format('j') . ' ' . $month . ' ' . $date->format('Y');
    }

    private function formatTimeWindow(?string $start, ?string $end): ?string
    {
        $parts = array_filter([
            $this->formatHourMinute($start),
            $this->formatHourMinute($end),
        ]);
        if (empty($parts)) {
            return null;
        }

        return count($parts) === 2
            ? 'entre ' . $parts[0] . ' et ' . $parts[1]
            : 'autour de ' . $parts[0];
    }

    private function formatHourMinute(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return substr($value, 0, 5);
        }

        return $value;
    }
}
