<?php
/**
 * AuditLogController — Logs d'audit administrateur (ADMIN-08)
 *
 * Endpoints :
 *   GET  /admin/audit-logs          — Liste paginée des logs (admin seulement)
 *   GET  /admin/audit-logs/export   — Export CSV des logs
 *
 * La méthode statique log() est appelée depuis les autres contrôleurs
 * pour enregistrer chaque action sensible.
 */
class AuditLogController extends BaseController
{
    // ─────────────────────────────────────────────────────────────────────────
    // Méthode statique — à appeler depuis n'importe quel contrôleur
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enregistre une action dans les logs d'audit.
     *
     * @param \PDO   $db
     * @param int    $userId     ID de l'utilisateur qui effectue l'action
     * @param string $action     Ex: 'user.blocked', 'incident.deleted', 'comment.moderated'
     * @param string $targetType Ex: 'user', 'incident', 'comment'
     * @param int    $targetId   ID de la ressource cible
     * @param array  $details    Données supplémentaires (avant/après)
     */
    public static function log(
        \PDO   $db,
        int    $userId,
        string $action,
        ?string $targetType = null,
        ?int    $targetId   = null,
        array  $details    = []
    ): void {
        try {
            $detailsJson = !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

            if (self::hasColumn($db, 'audit_logs', 'user_id')) {
                $stmt = $db->prepare("
                    INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId,
                    $action,
                    $targetType,
                    $targetId,
                    $detailsJson,
                    $_SERVER['REMOTE_ADDR']     ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO audit_logs (admin_id, action, entity, entity_id, old_value, new_value, ip_address, created_at)
                    VALUES (?, ?, ?, ?, NULL, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId,
                    $action,
                    $targetType ?? 'system',
                    $targetId,
                    $detailsJson,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            // Ne jamais bloquer l'action principale à cause d'un log raté
            error_log('[AuditLog] Erreur lors de l\'enregistrement : ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/audit-logs
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $user = $this->requireAuth();
        $this->requireAdmin($user);
        $legacySchema = $this->isLegacySchema();

        $page    = max(1, (int) ($_GET['page']    ?? 1));
        $limit   = min(100, max(10, (int) ($_GET['limit'] ?? 50)));
        $offset  = ($page - 1) * $limit;
        $action  = Security::sanitizeString($_GET['action']  ?? '');
        $userId  = (int) ($_GET['user_id'] ?? 0);
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to']   ?? '';

        // Construction de la requête avec filtres dynamiques
        $where  = ['1=1'];
        $params = [];

        if ($action) {
            $where[]  = 'al.action LIKE ?';
            $params[] = '%' . $action . '%';
        }
        if ($userId > 0) {
            $where[]  = $legacySchema ? 'al.admin_id = ?' : 'al.user_id = ?';
            $params[] = $userId;
        }
        if ($dateFrom) {
            $where[]  = 'al.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where[]  = 'al.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        // Total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Données
        $selectSql = $legacySchema
            ? "
                SELECT
                    al.id,
                    al.admin_id AS user_id,
                    al.action,
                    al.entity AS target_type,
                    al.entity_id AS target_id,
                    al.new_value AS details,
                    al.ip_address,
                    NULL AS user_agent,
                    al.created_at,
                    u.full_name AS user_name,
                    u.email     AS user_email,
                    u.role      AS user_role
                FROM audit_logs al
                JOIN users u ON u.id = al.admin_id
                WHERE {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT {$limit} OFFSET {$offset}
            "
            : "
                SELECT al.*,
                       u.full_name AS user_name,
                       u.email     AS user_email,
                       u.role      AS user_role
                FROM audit_logs al
                JOIN users u ON u.id = al.user_id
                WHERE {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT {$limit} OFFSET {$offset}
            ";
        $stmt = $this->db->prepare($selectSql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Décoder le JSON des détails
        foreach ($logs as &$log) {
            if ($log['details']) {
                $log['details'] = json_decode($log['details'], true);
            }
        }

        $this->success([
            'data' => $logs,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $limit,
                'pages'    => (int) ceil($total / $limit),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /admin/audit-logs/export
    // ─────────────────────────────────────────────────────────────────────────

    public function exportCsv(): void
    {
        $user = $this->requireAuth();
        $userId = $this->getAuthUserId($user);
        $this->requireAdmin($user);
        $legacySchema = $this->isLegacySchema();

        $stmt = $this->db->prepare($legacySchema
            ? "
                SELECT al.created_at, u.full_name AS admin_name, u.email AS admin_email,
                       al.action, al.entity AS target_type, al.entity_id AS target_id, al.ip_address
                FROM audit_logs al
                JOIN users u ON u.id = al.admin_id
                ORDER BY al.created_at DESC
                LIMIT 10000
            "
            : "
                SELECT al.created_at, u.full_name AS admin_name, u.email AS admin_email,
                       al.action, al.target_type, al.target_id, al.ip_address
                FROM audit_logs al
                JOIN users u ON u.id = al.user_id
                ORDER BY al.created_at DESC
                LIMIT 10000
            "
        );
        $stmt->execute();
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Log de l'export lui-même
        self::log($this->db, $userId, 'audit_logs.exported', 'audit_logs', null, ['count' => count($logs)]);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Admin', 'Email', 'Action', 'Cible', 'ID Cible', 'IP'], ',', '"', '\\');
        foreach ($logs as $log) {
            fputcsv($out, [
                $log['created_at'],
                $log['admin_name'],
                $log['admin_email'],
                $log['action'],
                $log['target_type'] ?? '',
                $log['target_id']   ?? '',
                $log['ip_address']  ?? '',
            ], ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    private function isLegacySchema(): bool
    {
        return self::hasColumn($this->db, 'audit_logs', 'admin_id');
    }

    private static function hasColumn(\PDO $db, string $table, string $column): bool
    {
        try {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
