<?php
/**
 * Service d'envoi de notifications push via l'API Expo Push
 * Documentation : https://docs.expo.dev/push-notifications/sending-notifications/
 * CCDS Citoyen v1.1
 */
class PushNotificationService
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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
    public function notifyStatusChange(int $incident_id, string $new_status): void
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

        // Enregistrer en base
        $this->saveNotification($incident['user_id'], $incident_id, 'status_change', $title, $body);

        // Envoyer la push
        $this->sendToUser($incident['user_id'], $title, $body, [
            'type'        => 'status_change',
            'incident_id' => $incident_id,
            'reference'   => $incident['reference'],
            'new_status'  => $new_status,
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

        $this->saveNotification($incident['user_id'], $incident_id, 'new_comment', $title, $body);
        $this->sendToUser($incident['user_id'], $title, $body, [
            'type'        => 'new_comment',
            'incident_id' => $incident_id,
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
            'channelId' => 'ccds-notifications',
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
    private function saveNotification(int $user_id, int $incident_id, string $type, string $title, string $body): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, incident_id, type, title, body)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $incident_id, $type, $title, $body]);
    }
}
