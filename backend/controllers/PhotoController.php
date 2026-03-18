<?php

/**
 * Ma Commune — PhotoController
 * Gestion des photos multiples pour un incident (v1.4 — UX-04).
 *
 * Routes :
 *   GET    /incidents/{id}/photos         → Liste des photos
 *   POST   /incidents/{id}/photos         → Uploader une photo
 *   DELETE /incidents/{id}/photos/{pid}   → Supprimer une photo
 */
class PhotoController extends BaseController
{
    private const MAX_PHOTOS     = 5;
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 Mo
    private const ALLOWED_TYPES  = ['image/jpeg', 'image/png', 'image/webp'];
    private const UPLOAD_DIR     = __DIR__ . '/../uploads/incidents/';
    private ?bool $hasSortOrderColumn = null;

    // ── GET /incidents/{id}/photos ────────────────────────────
    public function list(int $incidentId): void
    {
        $auth = $this->requireAuth();
        $this->assertIncidentAccess($incidentId, $auth);

        if ($this->hasSortOrderColumn()) {
            $stmt = $this->db->prepare("
                SELECT id, file_path, file_name, mime_type, file_size, sort_order, uploaded_at AS created_at
                FROM photos WHERE incident_id = ? ORDER BY sort_order, id
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT id, file_path, file_name, mime_type, file_size, 0 AS sort_order, uploaded_at AS created_at
                FROM photos WHERE incident_id = ? ORDER BY id
            ");
        }
        $stmt->execute([$incidentId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $base = rtrim($_ENV['APP_URL'] ?? (defined('APP_URL') ? APP_URL : 'https://api.macommune.netetfix.com'), '/');
        foreach ($photos as &$p) {
            $p['url'] = $base . '/uploads/incidents/' . basename($p['file_path']);
        }

        $this->json(['success' => true, 'photos' => $photos]);
    }

    // ── POST /incidents/{id}/photos ───────────────────────────
    public function upload(int $incidentId): void
    {
        $auth = $this->requireAuth();
        $this->assertIncidentAccess($incidentId, $auth, true); // owner ou admin/agent

        // Compter via requête préparée
        $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM photos WHERE incident_id = ?");
        $stmtCount->execute([$incidentId]);
        $count = (int)$stmtCount->fetchColumn();

        if ($count >= self::MAX_PHOTOS) {
            $this->error('Nombre maximum de photos atteint (' . self::MAX_PHOTOS . ').', 422);
        }

        // Valider le fichier uploadé
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $this->error('Aucun fichier reçu ou erreur d\'upload.', 400);
        }

        $file     = $_FILES['photo'];
        $mimeType = mime_content_type($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_TYPES)) {
            $this->error('Type de fichier non autorisé. Formats acceptés : JPEG, PNG, WebP.', 422);
        }

        if ($file['size'] > self::MAX_SIZE_BYTES) {
            $this->error('Fichier trop volumineux (max 10 Mo).', 422);
        }

        // Créer le dossier si nécessaire
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        // Générer un nom de fichier unique
        $ext      = match($mimeType) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
        $fileName = 'INC' . $incidentId . '_' . uniqid() . '.' . $ext;
        $filePath = self::UPLOAD_DIR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $this->error('Impossible de sauvegarder le fichier.', 500);
        }

        // Optimiser l'image avec GD si disponible
        if (extension_loaded('gd')) {
            $this->optimizeImage($filePath, $mimeType);
        }

        $origName  = basename($file['name'] ?? $fileName);

        if ($this->hasSortOrderColumn()) {
            $sortOrder = (int)($_POST['sort_order'] ?? $count);
            $stmt = $this->db->prepare("
                INSERT INTO photos (incident_id, file_path, file_name, mime_type, file_size, sort_order, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$incidentId, $fileName, $origName, $mimeType, filesize($filePath), $sortOrder]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO photos (incident_id, file_path, file_name, mime_type, file_size, uploaded_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$incidentId, $fileName, $origName, $mimeType, filesize($filePath)]);
        }
        $photoId = (int)$this->db->lastInsertId();

        $base = rtrim($_ENV['APP_URL'] ?? (defined('APP_URL') ? APP_URL : 'https://api.macommune.netetfix.com'), '/');
        $this->json([
            'success'  => true,
            'photo_id' => $photoId,
            'url'      => $base . '/uploads/incidents/' . $fileName,
        ], 201);
    }

    // ── DELETE /incidents/{id}/photos/{pid} ───────────────────
    public function delete(int $incidentId, int $photoId): void
    {
        $auth = $this->requireAuth();
        $this->assertIncidentAccess($incidentId, $auth, true);

        $stmt = $this->db->prepare("SELECT * FROM photos WHERE id = ? AND incident_id = ?");
        $stmt->execute([$photoId, $incidentId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$photo) {
            $this->error('Photo introuvable.', 404);
        }

        // Supprimer le fichier physique
        $filePath = self::UPLOAD_DIR . basename($photo['file_path']);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->db->prepare("DELETE FROM photos WHERE id = ?")->execute([$photoId]);

        $this->json(['success' => true, 'message' => 'Photo supprimée.']);
    }

    // ── Helpers ───────────────────────────────────────────────

    /**
     * Vérifie que l'utilisateur a accès à l'incident.
     * Si $requireOwnerOrStaff = true, seul le propriétaire ou un agent/admin peut agir.
     */
    private function assertIncidentAccess(int $incidentId, array $auth, bool $requireOwnerOrStaff = false): void
    {
        $stmt = $this->db->prepare("SELECT user_id, status FROM incidents WHERE id = ?");
        $stmt->execute([$incidentId]);
        $incident = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incident) {
            $this->error('Incident introuvable.', 404);
        }

        if ($requireOwnerOrStaff) {
            $isOwner = (int) $incident['user_id'] === (int) ($auth['sub'] ?? 0);
            $isStaff = in_array($auth['role'] ?? '', ['agent', 'admin'], true);
            if (!$isOwner && !$isStaff) {
                $this->error('Accès refusé.', 403);
            }
        }
    }

    /**
     * Redimensionne l'image si elle dépasse 1920px de large.
     */
    private function optimizeImage(string $filePath, string $mimeType): void
    {
        try {
            [$w, $h] = getimagesize($filePath);
            if ($w <= 1920) return;

            $ratio  = 1920 / $w;
            $newW   = 1920;
            $newH   = (int)round($h * $ratio);

            $src = match($mimeType) {
                'image/png'  => imagecreatefrompng($filePath),
                'image/webp' => imagecreatefromwebp($filePath),
                default      => imagecreatefromjpeg($filePath),
            };

            $dst = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

            match($mimeType) {
                'image/png'  => imagepng($dst, $filePath, 8),
                'image/webp' => imagewebp($dst, $filePath, 82),
                default      => imagejpeg($dst, $filePath, 85),
            };

            imagedestroy($src);
            imagedestroy($dst);
        } catch (\Throwable $e) {
            // Silencieux — l'image originale est conservée
        }
    }

    private function hasSortOrderColumn(): bool
    {
        if ($this->hasSortOrderColumn !== null) {
            return $this->hasSortOrderColumn;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(['photos', 'sort_order']);
            $this->hasSortOrderColumn = (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $this->hasSortOrderColumn = false;
        }

        return $this->hasSortOrderColumn;
    }
}
