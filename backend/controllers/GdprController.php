<?php
/**
 * GdprController — Export RGPD des données citoyen (ADMIN-11)
 *
 * Conformité RGPD — Article 20 : Droit à la portabilité des données.
 * Permet à un citoyen de télécharger une archive JSON complète de ses données.
 */
class GdprController extends BaseController
{
    /**
     * POST /auth/gdpr/request
     * Demande d'export — génère l'archive et envoie un email avec le lien.
     */
    public function requestExport(): void
    {
        $user = $this->requireAuth();
        $this->applyRateLimit('gdpr_export', $user['id']);

        $userId = (int) $user['id'];

        // Collecter toutes les données de l'utilisateur
        $data = $this->collectUserData($userId);

        // Générer l'archive JSON
        $exportDir  = __DIR__ . '/../exports/';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0700, true);
        }

        $filename   = "ccds_export_user_{$userId}_" . date('Ymd_His') . '.json';
        $filepath   = $exportDir . $filename;
        $exportData = [
            'generated_at'  => date('c'),
            'user_id'       => $userId,
            'ccds_version'  => '1.6.0',
            'data'          => $data,
        ];

        file_put_contents($filepath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Enregistrer la demande d'export en base
        $stmt = $this->db->prepare("
            INSERT INTO gdpr_export_requests (user_id, status, file_path, requested_at)
            VALUES (?, 'completed', ?, NOW())
        ");
        $stmt->execute([$userId, $filename]);

        // En production : envoyer un email avec le lien de téléchargement
        // mail($user['email'], 'Votre export RGPD CCDS', "Votre archive est disponible...");

        $this->success([
            'message'    => 'Votre archive de données a été générée.',
            'filename'   => $filename,
            'expires_at' => date('c', strtotime('+7 days')),
        ], 200, 'Export RGPD généré avec succès');
    }

    /**
     * GET /auth/gdpr/download/{filename}
     * Téléchargement de l'archive (lien sécurisé, valable 7 jours).
     */
    public function download(string $filename): void
    {
        $user   = $this->requireAuth();
        $userId = (int) $user['id'];

        // Vérifier que le fichier appartient à cet utilisateur
        $stmt = $this->db->prepare("
            SELECT * FROM gdpr_export_requests
            WHERE user_id = ? AND file_path = ?
            AND requested_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$userId, $filename]);
        $export = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$export) {
            $this->error('Fichier introuvable ou lien expiré.', 404);
        }

        $filepath = __DIR__ . '/../exports/' . basename($filename);
        if (!file_exists($filepath)) {
            $this->error('Fichier introuvable.', 404);
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }

    /**
     * DELETE /auth/gdpr/delete-account
     * Suppression complète du compte et de toutes les données (droit à l'oubli).
     */
    public function deleteAccount(): void
    {
        $user   = $this->requireAuth();
        $userId = (int) $user['id'];

        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['password'])) {
            $this->error('Mot de passe requis pour confirmer la suppression.', 400);
        }

        // Vérifier le mot de passe
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($input['password'], $row['password'])) {
            $this->error('Mot de passe incorrect.', 401);
        }

        // Anonymiser plutôt que supprimer pour conserver l'intégrité des signalements
        $stmt = $this->db->prepare("
            UPDATE users SET
                full_name = 'Utilisateur supprimé',
                email     = CONCAT('deleted_', id, '@ccds.deleted'),
                password  = '',
                phone     = NULL,
                is_active = 0
            WHERE id = ?
        ");
        $stmt->execute([$userId]);

        // Supprimer les tokens push (données personnelles)
        $this->db->prepare("DELETE FROM push_tokens WHERE user_id = ?")->execute([$userId]);

        // Supprimer les exports RGPD
        $exports = $this->db->prepare("SELECT file_path FROM gdpr_export_requests WHERE user_id = ?");
        $exports->execute([$userId]);
        foreach ($exports->fetchAll(PDO::FETCH_COLUMN) as $filename) {
            $filepath = __DIR__ . '/../exports/' . basename($filename);
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
        $this->db->prepare("DELETE FROM gdpr_export_requests WHERE user_id = ?")->execute([$userId]);

        $this->success(null, 200, 'Votre compte a été supprimé avec succès.');
    }

    // ─── Collecte des données ────────────────────────────────────────────────

    private function collectUserData(int $userId): array
    {
        return [
            'profile'       => $this->getProfile($userId),
            'incidents'     => $this->getIncidents($userId),
            'votes'         => $this->getVotes($userId),
            'comments'      => $this->getComments($userId),
            'notifications' => $this->getNotifications($userId),
            'gamification'  => $this->getGamification($userId),
        ];
    }

    private function getProfile(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, full_name AS name, email, phone, created_at
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function getIncidents(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, title, description, address, latitude, longitude,
                   status, votes_count, created_at, updated_at
            FROM incidents WHERE user_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getVotes(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT v.incident_id, i.title AS incident_title, v.created_at
            FROM votes v
            JOIN incidents i ON i.id = v.incident_id
            WHERE v.user_id = ? ORDER BY v.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getComments(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id, c.content, c.incident_id, i.title AS incident_title,
                   c.created_at, c.updated_at
            FROM comments c
            JOIN incidents i ON i.id = c.incident_id
            WHERE c.user_id = ? ORDER BY c.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getNotifications(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, title, body, type, is_read, created_at
            FROM notifications WHERE user_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getGamification(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT points, last_action_at FROM user_gamification WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $gamif = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['points' => 0];

        $stmt2 = $this->db->prepare("
            SELECT badge_key, awarded_at FROM user_badges WHERE user_id = ?
        ");
        $stmt2->execute([$userId]);
        $gamif['badges'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        return $gamif;
    }
}
