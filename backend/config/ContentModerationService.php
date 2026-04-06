<?php

require_once __DIR__ . '/../controllers/AuditLogController.php';

final class ContentModerationService
{
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_REPORTED = 'reported';
    public const STATUS_AUTO_HIDDEN = 'auto_hidden';
    public const STATUS_HIDDEN_BY_ADMIN = 'hidden_by_admin';
    public const STATUS_RESTORED = 'restored';

    public const PHOTO_REASON_UNDER_18 = 'photo_under_18';
    public const PHOTO_REASON_SENSITIVE = 'photo_sensitive';
    public const PHOTO_REASON_INAPPROPRIATE = 'photo_inappropriate';

    public const COMMENT_REASON_AGGRESSIVE = 'insulting_or_aggressive';
    public const COMMENT_REASON_SPAM = 'spam_or_advertising';
    public const COMMENT_REASON_INAPPROPRIATE = 'inappropriate';
    public const COMMENT_REASON_PERSONAL_INFO = 'personal_information';

    private const DEFAULT_SETTINGS = [
        'photo_auto_hide_threshold' => '5',
        'comment_auto_hide_threshold' => '5',
        'photo_auto_hide_enabled' => '1',
        'comment_auto_hide_enabled' => '1',
        'photo_tie_breaker_order' => self::PHOTO_REASON_UNDER_18 . ',' . self::PHOTO_REASON_SENSITIVE . ',' . self::PHOTO_REASON_INAPPROPRIATE,
        'photo_public_message_under_18' => 'Image retirée car réservée aux adultes.',
        'photo_public_message_sensitive' => 'Image retirée car sensible.',
        'photo_public_message_inappropriate' => 'Image retirée car non appropriée.',
        'comment_public_message' => 'Commentaire masqué par modération.',
    ];

    private const PHOTO_PLACEHOLDER_FILES = [
        self::PHOTO_REASON_UNDER_18 => 'photo-under-18.png',
        self::PHOTO_REASON_SENSITIVE => 'photo-sensitive.png',
        self::PHOTO_REASON_INAPPROPRIATE => 'photo-inappropriate.png',
    ];

    private const PHOTO_REASON_LABELS = [
        self::PHOTO_REASON_UNDER_18 => 'Photo -18',
        self::PHOTO_REASON_SENSITIVE => 'Photo pouvant heurter la sensibilité',
        self::PHOTO_REASON_INAPPROPRIATE => 'Photo non appropriée',
    ];

    private const COMMENT_REASON_LABELS = [
        self::COMMENT_REASON_AGGRESSIVE => 'Insultant ou agressif',
        self::COMMENT_REASON_SPAM => 'Spam ou publicité',
        self::COMMENT_REASON_INAPPROPRIATE => 'Contenu inapproprié',
        self::COMMENT_REASON_PERSONAL_INFO => 'Informations personnelles',
    ];

    public function __construct(
        private readonly PDO $db
    ) {}

    public static function photoReasons(): array
    {
        return self::PHOTO_REASON_LABELS;
    }

    public static function commentReasons(): array
    {
        return self::COMMENT_REASON_LABELS;
    }

    public static function moderationStatuses(): array
    {
        return [
            self::STATUS_VISIBLE,
            self::STATUS_REPORTED,
            self::STATUS_AUTO_HIDDEN,
            self::STATUS_HIDDEN_BY_ADMIN,
            self::STATUS_RESTORED,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->hasTable('moderation_settings')
            && $this->hasTable('photo_reports')
            && $this->hasTable('comment_reports');
    }

    public function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;

        if (!$this->hasTable('moderation_settings')) {
            return $settings;
        }

        $rows = $this->db->query('SELECT setting_key, setting_value FROM moderation_settings')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (isset($row['setting_key'], $row['setting_value'])) {
                $settings[$row['setting_key']] = (string)$row['setting_value'];
            }
        }

        return $settings;
    }

    public function saveSettings(array $input, int $adminId): void
    {
        if (!$this->hasTable('moderation_settings')) {
            return;
        }

        $current = $this->getSettings();
        $next = [
            'photo_auto_hide_threshold' => (string)max(1, min(99, (int)($input['photo_auto_hide_threshold'] ?? $current['photo_auto_hide_threshold']))),
            'comment_auto_hide_threshold' => (string)max(1, min(99, (int)($input['comment_auto_hide_threshold'] ?? $current['comment_auto_hide_threshold']))),
            'photo_auto_hide_enabled' => !empty($input['photo_auto_hide_enabled']) ? '1' : '0',
            'comment_auto_hide_enabled' => !empty($input['comment_auto_hide_enabled']) ? '1' : '0',
            'photo_tie_breaker_order' => $this->sanitizePhotoTieBreakerOrder((string)($input['photo_tie_breaker_order'] ?? $current['photo_tie_breaker_order'])),
            'photo_public_message_under_18' => $this->sanitizePublicMessage((string)($input['photo_public_message_under_18'] ?? $current['photo_public_message_under_18'])),
            'photo_public_message_sensitive' => $this->sanitizePublicMessage((string)($input['photo_public_message_sensitive'] ?? $current['photo_public_message_sensitive'])),
            'photo_public_message_inappropriate' => $this->sanitizePublicMessage((string)($input['photo_public_message_inappropriate'] ?? $current['photo_public_message_inappropriate'])),
            'comment_public_message' => $this->sanitizePublicMessage((string)($input['comment_public_message'] ?? $current['comment_public_message'])),
        ];

        $stmt = $this->db->prepare("
            INSERT INTO moderation_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");

        foreach ($next as $key => $value) {
            $stmt->execute([$key, $value, $adminId]);
        }

        AuditLogController::log($this->db, $adminId, 'moderation.settings_updated', 'moderation_settings', null, $next);
    }

    public function reportPhoto(int $photoId, int $reporterId, string $reason, string $description = ''): array
    {
        $this->guardAvailable();

        if (!isset(self::PHOTO_REASON_LABELS[$reason])) {
            throw new RuntimeException('Motif de signalement photo invalide.');
        }

        $photo = $this->loadPhotoForReporting($photoId);
        if ((int)$photo['incident_author_id'] === $reporterId) {
            throw new RuntimeException('Vous ne pouvez pas signaler votre propre photo.');
        }

        $existing = $this->db->prepare('SELECT id FROM photo_reports WHERE photo_id = ? AND reporter_id = ? LIMIT 1');
        $existing->execute([$photoId, $reporterId]);
        if ($existing->fetchColumn()) {
            throw new RuntimeException('Vous avez déjà signalé cette photo.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO photo_reports (photo_id, incident_id, reporter_id, reason, description, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $photoId,
            (int)$photo['incident_id'],
            $reporterId,
            $reason,
            $this->trimDescription($description),
        ]);

        return $this->refreshPhotoModerationState($photoId);
    }

    public function reportComment(int $commentId, int $reporterId, string $reason, string $description = ''): array
    {
        $this->guardAvailable();

        if (!isset(self::COMMENT_REASON_LABELS[$reason])) {
            throw new RuntimeException('Motif de signalement commentaire invalide.');
        }

        $comment = $this->loadCommentForReporting($commentId);
        if ((int)$comment['author_id'] === $reporterId) {
            throw new RuntimeException('Vous ne pouvez pas signaler votre propre commentaire.');
        }

        $existing = $this->db->prepare('SELECT id FROM comment_reports WHERE comment_id = ? AND reporter_id = ? LIMIT 1');
        $existing->execute([$commentId, $reporterId]);
        if ($existing->fetchColumn()) {
            throw new RuntimeException('Vous avez déjà signalé ce commentaire.');
        }

        $stmt = $this->db->prepare("
            INSERT INTO comment_reports (comment_id, reporter_id, reason, description, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $commentId,
            $reporterId,
            $reason,
            $this->trimDescription($description),
        ]);

        return $this->refreshCommentModerationState($commentId);
    }

    public function hidePhotoByAdmin(int $photoId, string $reason, int $adminId, string $source = 'manual'): void
    {
        $this->guardAvailable();
        $this->applyPhotoModerationState($photoId, self::STATUS_HIDDEN_BY_ADMIN, $reason, $adminId);
        $this->markPhotoReportsReviewed($photoId, 'actioned', $adminId);
        AuditLogController::log($this->db, $adminId, 'photo.hidden_via_moderation', 'photo', $photoId, [
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    public function restorePhoto(int $photoId, int $adminId): void
    {
        $this->guardAvailable();
        $stmt = $this->db->prepare("
            UPDATE photos
            SET moderation_status = ?, moderation_reason = NULL, moderation_placeholder_key = NULL,
                moderation_hidden_at = NULL, moderation_decided_by = ?, moderation_decided_at = NOW(),
                moderation_report_count = 0
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_RESTORED, $adminId, $photoId]);
        $this->markPhotoReportsReviewed($photoId, 'dismissed', $adminId);
        AuditLogController::log($this->db, $adminId, 'photo.restored_via_moderation', 'photo', $photoId, []);
    }

    public function dismissPhotoReports(int $photoId, int $adminId): void
    {
        $this->guardAvailable();
        $stmt = $this->db->prepare("
            UPDATE photos
            SET moderation_status = ?, moderation_reason = NULL, moderation_placeholder_key = NULL,
                moderation_reported_at = NULL, moderation_hidden_at = NULL,
                moderation_decided_by = ?, moderation_decided_at = NOW(),
                moderation_report_count = 0
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_VISIBLE, $adminId, $photoId]);
        $this->markPhotoReportsReviewed($photoId, 'dismissed', $adminId);
        AuditLogController::log($this->db, $adminId, 'photo_reports.dismissed', 'photo', $photoId, []);
    }

    public function hideCommentByAdmin(int $commentId, string $reason, int $adminId, string $source = 'manual'): void
    {
        $this->guardAvailable();
        $this->applyCommentModerationState($commentId, self::STATUS_HIDDEN_BY_ADMIN, $reason, $adminId);
        $this->markCommentReportsReviewed($commentId, 'actioned', $adminId);
        AuditLogController::log($this->db, $adminId, 'comment.hidden_via_moderation', 'comment', $commentId, [
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    public function restoreComment(int $commentId, int $adminId): void
    {
        $this->guardAvailable();
        $stmt = $this->db->prepare("
            UPDATE comments
            SET moderation_status = ?, moderation_reason = NULL,
                moderation_hidden_at = NULL, moderation_decided_by = ?, moderation_decided_at = NOW(),
                moderation_report_count = 0
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_RESTORED, $adminId, $commentId]);
        $this->markCommentReportsReviewed($commentId, 'dismissed', $adminId);
        AuditLogController::log($this->db, $adminId, 'comment.restored_via_moderation', 'comment', $commentId, []);
    }

    public function dismissCommentReports(int $commentId, int $adminId): void
    {
        $this->guardAvailable();
        $stmt = $this->db->prepare("
            UPDATE comments
            SET moderation_status = ?, moderation_reason = NULL, moderation_reported_at = NULL,
                moderation_hidden_at = NULL, moderation_decided_by = ?, moderation_decided_at = NOW(),
                moderation_report_count = 0
            WHERE id = ?
        ");
        $stmt->execute([self::STATUS_VISIBLE, $adminId, $commentId]);
        $this->markCommentReportsReviewed($commentId, 'dismissed', $adminId);
        AuditLogController::log($this->db, $adminId, 'comment_reports.dismissed', 'comment', $commentId, []);
    }

    public function publicPhotoPayload(array $photo): array
    {
        $status = (string)($photo['moderation_status'] ?? self::STATUS_VISIBLE);
        $reason = (string)($photo['moderation_reason'] ?? '');
        $placeholderKey = (string)($photo['moderation_placeholder_key'] ?? '');

        if (in_array($status, [self::STATUS_AUTO_HIDDEN, self::STATUS_HIDDEN_BY_ADMIN], true) && $placeholderKey !== '') {
            $photo['url'] = $this->placeholderUrl($placeholderKey);
            $photo['is_moderated'] = true;
            $photo['moderation_message'] = $this->publicPhotoMessage($placeholderKey);
            $photo['moderation_reason_label'] = self::PHOTO_REASON_LABELS[$reason] ?? self::PHOTO_REASON_LABELS[$placeholderKey] ?? 'Photo retirée';
            return $photo;
        }

        $photo['is_moderated'] = false;
        $photo['moderation_message'] = null;
        $photo['moderation_reason_label'] = null;
        return $photo;
    }

    public function uploadUrl(?string $path, string $defaultDirectory = 'incidents'): ?string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $normalized = ltrim($path, '/');
        if (str_starts_with($normalized, 'uploads/')) {
            $normalized = substr($normalized, 8);
        }

        if (!str_contains($normalized, '/')) {
            $normalized = trim($defaultDirectory, '/') . '/' . $normalized;
        }

        return rtrim(UPLOAD_BASE_URL, '/') . '/' . $normalized;
    }

    public function publicCommentPayload(array $comment): array
    {
        $status = (string)($comment['moderation_status'] ?? self::STATUS_VISIBLE);
        if (!in_array($status, [self::STATUS_AUTO_HIDDEN, self::STATUS_HIDDEN_BY_ADMIN], true)) {
            $comment['is_moderated'] = false;
            $comment['moderation_message'] = null;
            return $comment;
        }

        $comment['comment'] = $this->publicCommentMessage();
        $comment['author_name'] = 'Modération communale';
        $comment['user_name'] = 'Modération communale';
        $comment['author_role'] = 'admin';
        $comment['user_role'] = 'admin';
        $comment['is_moderated'] = true;
        $comment['moderation_message'] = $this->publicCommentMessage();
        return $comment;
    }

    public function placeholderUrl(string $placeholderKey): string
    {
        $file = self::PHOTO_PLACEHOLDER_FILES[$placeholderKey] ?? self::PHOTO_PLACEHOLDER_FILES[self::PHOTO_REASON_INAPPROPRIATE];
        return rtrim(UPLOAD_BASE_URL, '/') . '/moderation/' . $file;
    }

    public function publicPhotoMessage(string $placeholderKey): string
    {
        $settings = $this->getSettings();
        return match ($placeholderKey) {
            self::PHOTO_REASON_UNDER_18 => $settings['photo_public_message_under_18'],
            self::PHOTO_REASON_SENSITIVE => $settings['photo_public_message_sensitive'],
            default => $settings['photo_public_message_inappropriate'],
        };
    }

    public function publicCommentMessage(): string
    {
        $settings = $this->getSettings();
        return $settings['comment_public_message'] ?? self::DEFAULT_SETTINGS['comment_public_message'];
    }

    public function photoTieBreakerOrder(): array
    {
        $settings = $this->getSettings();
        return array_values(array_filter(
            explode(',', $settings['photo_tie_breaker_order'] ?? self::DEFAULT_SETTINGS['photo_tie_breaker_order']),
            static fn(string $reason): bool => isset(self::PHOTO_REASON_LABELS[$reason])
        ));
    }

    private function refreshPhotoModerationState(int $photoId): array
    {
        $countStmt = $this->db->prepare('SELECT COUNT(DISTINCT reporter_id) FROM photo_reports WHERE photo_id = ?');
        $countStmt->execute([$photoId]);
        $count = (int)$countStmt->fetchColumn();

        $update = $this->db->prepare("
            UPDATE photos
            SET moderation_report_count = ?, moderation_status = ?, moderation_reported_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$count, $count > 0 ? self::STATUS_REPORTED : self::STATUS_VISIBLE, $photoId]);

        $threshold = $this->photoAutoHideThreshold();
        if ($this->photoAutoHideEnabled() && $count >= $threshold) {
            $majority = $this->majorityPhotoReason($photoId);
            $this->applyPhotoModerationState($photoId, self::STATUS_AUTO_HIDDEN, $majority, null);
        }

        return [
            'report_count' => $count,
            'auto_hidden' => $this->photoAutoHideEnabled() && $count >= $threshold,
            'threshold' => $threshold,
        ];
    }

    private function refreshCommentModerationState(int $commentId): array
    {
        $countStmt = $this->db->prepare('SELECT COUNT(DISTINCT reporter_id) FROM comment_reports WHERE comment_id = ?');
        $countStmt->execute([$commentId]);
        $count = (int)$countStmt->fetchColumn();

        $update = $this->db->prepare("
            UPDATE comments
            SET moderation_report_count = ?, moderation_status = ?, moderation_reported_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$count, $count > 0 ? self::STATUS_REPORTED : self::STATUS_VISIBLE, $commentId]);

        $threshold = $this->commentAutoHideThreshold();
        if ($this->commentAutoHideEnabled() && $count >= $threshold) {
            $majority = $this->majorityCommentReason($commentId);
            $this->applyCommentModerationState($commentId, self::STATUS_AUTO_HIDDEN, $majority, null);
        }

        return [
            'report_count' => $count,
            'auto_hidden' => $this->commentAutoHideEnabled() && $count >= $threshold,
            'threshold' => $threshold,
        ];
    }

    private function majorityPhotoReason(int $photoId): string
    {
        $stmt = $this->db->prepare("
            SELECT reason, COUNT(*) AS total
            FROM photo_reports
            WHERE photo_id = ?
            GROUP BY reason
        ");
        $stmt->execute([$photoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return self::PHOTO_REASON_INAPPROPRIATE;
        }

        $max = max(array_map(static fn(array $row): int => (int)$row['total'], $rows));
        $candidates = array_values(array_map(
            static fn(array $row): string => (string)$row['reason'],
            array_filter($rows, static fn(array $row): bool => (int)$row['total'] === $max)
        ));

        foreach ($this->photoTieBreakerOrder() as $preferred) {
            if (in_array($preferred, $candidates, true)) {
                return $preferred;
            }
        }

        return $candidates[0];
    }

    private function majorityCommentReason(int $commentId): string
    {
        $stmt = $this->db->prepare("
            SELECT reason, COUNT(*) AS total
            FROM comment_reports
            WHERE comment_id = ?
            GROUP BY reason
            ORDER BY total DESC, reason ASC
        ");
        $stmt->execute([$commentId]);
        $reason = $stmt->fetchColumn();
        return $reason ? (string)$reason : self::COMMENT_REASON_INAPPROPRIATE;
    }

    private function applyPhotoModerationState(int $photoId, string $status, string $reason, ?int $adminId): void
    {
        $placeholderKey = array_key_exists($reason, self::PHOTO_PLACEHOLDER_FILES) ? $reason : self::PHOTO_REASON_INAPPROPRIATE;
        $stmt = $this->db->prepare("
            UPDATE photos
            SET moderation_status = ?, moderation_reason = ?, moderation_placeholder_key = ?,
                moderation_hidden_at = NOW(), moderation_decided_by = ?, moderation_decided_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $reason, $placeholderKey, $adminId, $photoId]);
    }

    private function applyCommentModerationState(int $commentId, string $status, string $reason, ?int $adminId): void
    {
        $stmt = $this->db->prepare("
            UPDATE comments
            SET moderation_status = ?, moderation_reason = ?, moderation_hidden_at = NOW(),
                moderation_decided_by = ?, moderation_decided_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $reason, $adminId, $commentId]);
    }

    private function markPhotoReportsReviewed(int $photoId, string $status, int $adminId): void
    {
        $stmt = $this->db->prepare("
            UPDATE photo_reports
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE photo_id = ? AND status = 'pending'
        ");
        $stmt->execute([$status, $adminId, $photoId]);
    }

    private function markCommentReportsReviewed(int $commentId, string $status, int $adminId): void
    {
        $stmt = $this->db->prepare("
            UPDATE comment_reports
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE comment_id = ? AND status = 'pending'
        ");
        $stmt->execute([$status, $adminId, $commentId]);
    }

    private function loadPhotoForReporting(int $photoId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, i.id AS incident_id, i.user_id AS incident_author_id
            FROM photos p
            JOIN incidents i ON i.id = p.incident_id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$photo) {
            throw new RuntimeException('Photo introuvable.');
        }

        return $photo;
    }

    private function loadCommentForReporting(int $commentId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, c.user_id AS author_id
            FROM comments c
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$comment) {
            throw new RuntimeException('Commentaire introuvable.');
        }

        return $comment;
    }

    private function photoAutoHideThreshold(): int
    {
        return max(1, (int)($this->getSettings()['photo_auto_hide_threshold'] ?? self::DEFAULT_SETTINGS['photo_auto_hide_threshold']));
    }

    private function commentAutoHideThreshold(): int
    {
        return max(1, (int)($this->getSettings()['comment_auto_hide_threshold'] ?? self::DEFAULT_SETTINGS['comment_auto_hide_threshold']));
    }

    private function photoAutoHideEnabled(): bool
    {
        return ($this->getSettings()['photo_auto_hide_enabled'] ?? '1') === '1';
    }

    private function commentAutoHideEnabled(): bool
    {
        return ($this->getSettings()['comment_auto_hide_enabled'] ?? '1') === '1';
    }

    private function sanitizePhotoTieBreakerOrder(string $value): string
    {
        $seen = [];
        $reasons = array_filter(array_map('trim', explode(',', $value)));
        foreach ($reasons as $reason) {
            if (isset(self::PHOTO_REASON_LABELS[$reason]) && !isset($seen[$reason])) {
                $seen[$reason] = true;
            }
        }

        foreach (array_keys(self::PHOTO_REASON_LABELS) as $reason) {
            if (!isset($seen[$reason])) {
                $seen[$reason] = true;
            }
        }

        return implode(',', array_keys($seen));
    }

    private function sanitizePublicMessage(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Contenu retiré par modération.';
        }

        return mb_substr($value, 0, 140);
    }

    private function trimDescription(string $value): string
    {
        return mb_substr(trim($value), 0, 1000);
    }

    private function guardAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('La modération de contenu n’est pas disponible sur cet environnement.');
        }
    }

    private function hasTable(string $tableName): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
