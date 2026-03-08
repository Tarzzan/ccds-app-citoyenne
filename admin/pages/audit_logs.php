<?php
/**
 * Page admin — Logs d'audit (ADMIN-08)
 * Traçabilité complète des actions sensibles des administrateurs.
 */

require_once __DIR__ . '/../includes/auth_check.php';
requireAdmin();

$db = Database::getInstance();

// Filtres
$filterAdmin  = (int)($_GET['admin_id'] ?? 0);
$filterEntity = $_GET['entity'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterFrom   = $_GET['from']   ?? date('Y-m-d', strtotime('-30 days'));
$filterTo     = $_GET['to']     ?? date('Y-m-d');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

// Construction de la requête
$where  = ['al.created_at BETWEEN ? AND ?'];
$params = [$filterFrom . ' 00:00:00', $filterTo . ' 23:59:59'];

if ($filterAdmin > 0) {
    $where[]  = 'al.admin_id = ?';
    $params[] = $filterAdmin;
}
if ($filterEntity) {
    $where[]  = 'al.entity = ?';
    $params[] = $filterEntity;
}
if ($filterAction) {
    $where[]  = 'al.action LIKE ?';
    $params[] = '%' . $filterAction . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$total = $db->fetchOne(
    "SELECT COUNT(*) AS n FROM audit_logs al $whereClause",
    $params
)['n'] ?? 0;

$logs = $db->fetchAll(
    "SELECT al.*, u.full_name AS admin_name, u.email AS admin_email
     FROM audit_logs al
     JOIN users u ON u.id = al.admin_id
     $whereClause
     ORDER BY al.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$totalPages = max(1, (int) ceil($total / $perPage));
$admins     = $db->fetchAll("SELECT id, full_name FROM users WHERE role IN ('admin', 'agent') ORDER BY full_name");

// Libellés des actions
$actionLabels = [
    'incident_status_changed' => ['label' => 'Statut modifié',        'icon' => '🔄', 'color' => '#1565C0'],
    'incident_deleted'        => ['label' => 'Signalement supprimé',  'icon' => '🗑️', 'color' => '#C62828'],
    'comment_approved'        => ['label' => 'Commentaire approuvé',  'icon' => '✅', 'color' => '#2E7D32'],
    'comment_deleted'         => ['label' => 'Commentaire supprimé',  'icon' => '🗑️', 'color' => '#C62828'],
    'user_role_changed'       => ['label' => 'Rôle modifié',          'icon' => '👤', 'color' => '#E65100'],
    'user_banned'             => ['label' => 'Utilisateur suspendu',  'icon' => '🚫', 'color' => '#6A1B9A'],
    'user_unbanned'           => ['label' => 'Suspension levée',      'icon' => '✅', 'color' => '#2E7D32'],
    'notification_sent'       => ['label' => 'Notification envoyée',  'icon' => '🔔', 'color' => '#F57F17'],
    'category_created'        => ['label' => 'Catégorie créée',       'icon' => '➕', 'color' => '#2E7D32'],
    'category_deleted'        => ['label' => 'Catégorie supprimée',   'icon' => '🗑️', 'color' => '#C62828'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs d'audit — <?= defined('APP_SHORT_NAME') ? e(APP_SHORT_NAME) : 'MaCommune' ?> Admin</title>
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <style>
        .filters-bar { background: #fff; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,.06); display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; color: #666; font-weight: 600; }
        .filter-group select, .filter-group input { padding: 8px 12px; border: 1px solid #DDD; border-radius: 8px; font-size: 14px; }
        .btn-filter { background: #1B5E20; color: #fff; border: none; padding: 9px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; align-self: flex-end; }
        .btn-reset  { background: #EEE; color: #333; border: none; padding: 9px 16px; border-radius: 8px; cursor: pointer; align-self: flex-end; }
        .log-table  { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .log-table th { background: #F5F5F5; padding: 12px 16px; text-align: left; font-size: 13px; color: #666; border-bottom: 1px solid #EEE; }
        .log-table td { padding: 12px 16px; font-size: 13px; border-bottom: 1px solid #F5F5F5; vertical-align: middle; }
        .log-table tr:hover td { background: #FAFAFA; }
        .action-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .entity-badge { padding: 3px 8px; border-radius: 6px; font-size: 11px; background: #EEE; color: #555; }
        .diff-btn { background: none; border: 1px solid #DDD; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 12px; color: #666; }
        .diff-btn:hover { background: #F5F5F5; }
        .pagination { display: flex; gap: 8px; justify-content: center; margin-top: 24px; }
        .page-btn { padding: 8px 14px; border: 1px solid #DDD; border-radius: 8px; background: #fff; cursor: pointer; font-size: 14px; text-decoration: none; color: #333; }
        .page-btn.active { background: #1B5E20; color: #fff; border-color: #1B5E20; }
        .stats-row { display: flex; gap: 16px; margin-bottom: 24px; }
        .stat-mini { background: #fff; border-radius: 10px; padding: 14px 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); flex: 1; text-align: center; }
        .stat-mini .n { font-size: 24px; font-weight: 700; color: #1B5E20; }
        .stat-mini .l { font-size: 12px; color: #888; margin-top: 2px; }
        /* Modal diff */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #fff; border-radius: 16px; padding: 28px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .diff-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        .diff-col { background: #F5F5F5; border-radius: 8px; padding: 12px; font-size: 13px; font-family: monospace; white-space: pre-wrap; word-break: break-all; }
        .diff-col.old { background: #FFEBEE; }
        .diff-col.new { background: #E8F5E9; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/layout.php'; ?>

<main class="admin-main">
    <div class="page-header">
        <h1>📋 Logs d'audit</h1>
        <p class="page-subtitle">Traçabilité complète des actions administrateurs</p>
    </div>

    <!-- Statistiques rapides -->
    <div class="stats-row">
        <div class="stat-mini">
            <div class="n"><?= $total ?></div>
            <div class="l">Entrées (période)</div>
        </div>
        <div class="stat-mini">
            <div class="n"><?= $db->fetchOne("SELECT COUNT(DISTINCT admin_id) AS n FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['n'] ?? 0 ?></div>
            <div class="l">Admins actifs (7j)</div>
        </div>
        <div class="stat-mini">
            <div class="n"><?= $db->fetchOne("SELECT COUNT(*) AS n FROM audit_logs WHERE action LIKE '%deleted%' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['n'] ?? 0 ?></div>
            <div class="l">Suppressions (30j)</div>
        </div>
        <div class="stat-mini">
            <div class="n"><?= $db->fetchOne("SELECT COUNT(*) AS n FROM audit_logs WHERE action LIKE '%banned%' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['n'] ?? 0 ?></div>
            <div class="l">Suspensions (30j)</div>
        </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="filters-bar">
        <input type="hidden" name="page" value="audit_logs">
        <div class="filter-group">
            <label>Administrateur</label>
            <select name="admin_id">
                <option value="">Tous</option>
                <?php foreach ($admins as $admin): ?>
                    <option value="<?= $admin['id'] ?>" <?= $filterAdmin == $admin['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($admin['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Entité</label>
            <select name="entity">
                <option value="">Toutes</option>
                <option value="incidents" <?= $filterEntity === 'incidents' ? 'selected' : '' ?>>Signalements</option>
                <option value="comments"  <?= $filterEntity === 'comments'  ? 'selected' : '' ?>>Commentaires</option>
                <option value="users"     <?= $filterEntity === 'users'     ? 'selected' : '' ?>>Utilisateurs</option>
                <option value="categories" <?= $filterEntity === 'categories' ? 'selected' : '' ?>>Catégories</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Recherche action</label>
            <input type="text" name="action" value="<?= htmlspecialchars($filterAction) ?>" placeholder="ex: deleted">
        </div>
        <div class="filter-group">
            <label>Du</label>
            <input type="date" name="from" value="<?= $filterFrom ?>">
        </div>
        <div class="filter-group">
            <label>Au</label>
            <input type="date" name="to" value="<?= $filterTo ?>">
        </div>
        <button type="submit" class="btn-filter">🔍 Filtrer</button>
        <a href="?page=audit_logs" class="btn-reset">Réinitialiser</a>
    </form>

    <!-- Tableau des logs -->
    <table class="log-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Administrateur</th>
                <th>Action</th>
                <th>Entité</th>
                <th>ID</th>
                <th>IP</th>
                <th>Détails</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" style="text-align:center;padding:40px;color:#999">Aucun log pour cette période.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log):
                    $meta = $actionLabels[$log['action']] ?? ['label' => $log['action'], 'icon' => '⚙️', 'color' => '#666'];
                ?>
                <tr>
                    <td style="white-space:nowrap;color:#666;font-size:12px">
                        <?= date('d/m/Y', strtotime($log['created_at'])) ?><br>
                        <strong><?= date('H:i:s', strtotime($log['created_at'])) ?></strong>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($log['admin_name']) ?></strong><br>
                        <span style="font-size:11px;color:#999"><?= htmlspecialchars($log['admin_email']) ?></span>
                    </td>
                    <td>
                        <span class="action-badge" style="background:<?= $meta['color'] ?>22;color:<?= $meta['color'] ?>">
                            <?= $meta['icon'] ?> <?= $meta['label'] ?>
                        </span>
                    </td>
                    <td><span class="entity-badge"><?= htmlspecialchars($log['entity']) ?></span></td>
                    <td style="color:#666"><?= $log['entity_id'] ?? '—' ?></td>
                    <td style="font-size:12px;color:#999"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
                    <td>
                        <?php if ($log['old_value'] || $log['new_value']): ?>
                            <button class="diff-btn" onclick="showDiff(<?= htmlspecialchars(json_encode($log['old_value'])) ?>, <?= htmlspecialchars(json_encode($log['new_value'])) ?>)">
                                Voir diff
                            </button>
                        <?php else: ?>
                            <span style="color:#CCC">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= min($totalPages, 10); $p++): ?>
                <a href="?page=audit_logs&admin_id=<?= $filterAdmin ?>&entity=<?= urlencode($filterEntity) ?>&action=<?= urlencode($filterAction) ?>&from=<?= $filterFrom ?>&to=<?= $filterTo ?>&page=<?= $p ?>"
                   class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Modal diff -->
<div class="modal-overlay" id="diffModal" onclick="closeDiff(event)">
    <div class="modal-box">
        <h3 style="margin:0 0 8px">Détail de la modification</h3>
        <p style="font-size:13px;color:#888;margin:0 0 16px">Comparaison avant / après</p>
        <div class="diff-grid">
            <div>
                <div style="font-size:12px;font-weight:700;color:#C62828;margin-bottom:6px">AVANT</div>
                <div class="diff-col old" id="diffOld"></div>
            </div>
            <div>
                <div style="font-size:12px;font-weight:700;color:#2E7D32;margin-bottom:6px">APRÈS</div>
                <div class="diff-col new" id="diffNew"></div>
            </div>
        </div>
        <button onclick="document.getElementById('diffModal').classList.remove('open')" style="margin-top:20px;background:#EEE;border:none;padding:10px 20px;border-radius:8px;cursor:pointer;font-size:14px">Fermer</button>
    </div>
</div>

<script>
function showDiff(oldVal, newVal) {
    const fmt = v => {
        if (!v) return '(vide)';
        try { return JSON.stringify(JSON.parse(v), null, 2); }
        catch { return v; }
    };
    document.getElementById('diffOld').textContent = fmt(oldVal);
    document.getElementById('diffNew').textContent = fmt(newVal);
    document.getElementById('diffModal').classList.add('open');
}
function closeDiff(e) {
    if (e.target.id === 'diffModal') document.getElementById('diffModal').classList.remove('open');
}
</script>
</body>
</html>
