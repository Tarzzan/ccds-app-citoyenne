<?php
/**
 * Page admin — Modération des commentaires (ADMIN-07)
 * Affiche les commentaires signalés et permet de les approuver ou supprimer.
 */

require_once __DIR__ . '/../includes/auth_check.php';
requireAdmin();

$db = Database::getInstance();

// Actions de modération
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action']     ?? '';
    $commentId = (int)($_POST['comment_id'] ?? 0);

    if ($commentId > 0) {
        switch ($action) {
            case 'approve':
                $db->execute('UPDATE comments SET is_flagged = 0 WHERE id = ?', [$commentId]);
                logAudit('comment_approved', 'comments', $commentId);
                $success = 'Commentaire approuvé — le signalement a été retiré.';
                break;

            case 'delete':
                $comment = $db->fetchOne('SELECT * FROM comments WHERE id = ?', [$commentId]);
                $db->execute('DELETE FROM comments WHERE id = ?', [$commentId]);
                logAudit('comment_deleted', 'comments', $commentId, json_encode($comment));
                $success = 'Commentaire supprimé.';
                break;

            case 'ban_user':
                $comment = $db->fetchOne('SELECT user_id FROM comments WHERE id = ?', [$commentId]);
                if ($comment) {
                    $db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$comment['user_id']]);
                    $db->execute('UPDATE comments SET is_flagged = 0 WHERE id = ?', [$commentId]);
                    logAudit('user_banned', 'users', $comment['user_id']);
                    $success = 'Utilisateur suspendu et commentaire retiré.';
                }
                break;
        }
    }
}

// Récupérer les commentaires signalés
$flaggedComments = $db->fetchAll(
    'SELECT c.*, u.full_name AS author_name, u.email AS author_email, u.role AS author_role,
            i.reference AS incident_ref, i.title AS incident_title
     FROM comments c
     JOIN users u     ON u.id = c.user_id
     JOIN incidents i ON i.id = c.incident_id
     WHERE c.is_flagged = 1
     ORDER BY c.created_at DESC'
);

$totalFlagged = count($flaggedComments);

function logAudit(string $action, string $entity, int $entityId, string $oldValue = null): void
{
    global $db;
    $adminId = $_SESSION['user_id'] ?? 0;
    $db->execute(
        'INSERT INTO audit_logs (admin_id, action, entity, entity_id, old_value, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
        [$adminId, $action, $entity, $entityId, $oldValue, $_SERVER['REMOTE_ADDR'] ?? null]
    );
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modération — CCDS Admin</title>
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <style>
        .comment-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            border-left: 4px solid #E53935;
        }
        .comment-meta { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
        .badge-role   { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-citizen { background: #E3F2FD; color: #1565C0; }
        .badge-agent   { background: #E8F5E9; color: #2E7D32; }
        .badge-admin   { background: #FFF3E0; color: #E65100; }
        .comment-body  { background: #FFF8F8; border-radius: 8px; padding: 14px; margin-bottom: 14px; font-size: 14px; color: #333; line-height: 1.6; border: 1px solid #FFCDD2; }
        .incident-link { font-size: 12px; color: #666; margin-bottom: 12px; }
        .incident-link a { color: #1565C0; text-decoration: none; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-approve { background: #2E7D32; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .btn-delete  { background: #C62828; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .btn-ban     { background: #6A1B9A; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state .icon { font-size: 48px; margin-bottom: 16px; }
        .stats-bar { display: flex; gap: 20px; margin-bottom: 28px; }
        .stat-card { background: #fff; border-radius: 12px; padding: 16px 24px; box-shadow: 0 2px 8px rgba(0,0,0,.06); flex: 1; }
        .stat-number { font-size: 28px; font-weight: 700; color: #E53935; }
        .stat-label  { font-size: 13px; color: #888; margin-top: 4px; }
        .alert-success { background: #E8F5E9; color: #2E7D32; padding: 14px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/layout.php'; ?>

<main class="admin-main">
    <div class="page-header">
        <h1>🚩 Modération des commentaires</h1>
        <p class="page-subtitle">Commentaires signalés par les utilisateurs</p>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert-success">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="stats-bar">
        <div class="stat-card">
            <div class="stat-number"><?= $totalFlagged ?></div>
            <div class="stat-label">Commentaires signalés</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#2E7D32"><?= $db->fetchOne('SELECT COUNT(*) AS n FROM comments WHERE is_flagged = 0')['n'] ?? 0 ?></div>
            <div class="stat-label">Commentaires approuvés</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color:#1565C0"><?= $db->fetchOne('SELECT COUNT(*) AS n FROM comments')['n'] ?? 0 ?></div>
            <div class="stat-label">Total commentaires</div>
        </div>
    </div>

    <?php if (empty($flaggedComments)): ?>
        <div class="empty-state">
            <div class="icon">✅</div>
            <h3>Aucun commentaire signalé</h3>
            <p>Tous les commentaires sont conformes.</p>
        </div>
    <?php else: ?>
        <?php foreach ($flaggedComments as $comment): ?>
            <div class="comment-card">
                <div class="comment-meta">
                    <strong><?= htmlspecialchars($comment['author_name']) ?></strong>
                    <span class="badge-role badge-<?= $comment['author_role'] ?>"><?= ucfirst($comment['author_role']) ?></span>
                    <span style="color:#999;font-size:12px"><?= $comment['author_email'] ?></span>
                    <span style="color:#999;font-size:12px;margin-left:auto"><?= date('d/m/Y H:i', strtotime($comment['created_at'])) ?></span>
                </div>

                <div class="incident-link">
                    📌 Signalement : <a href="/admin/?page=incident_detail&id=<?= $comment['incident_id'] ?>">
                        <?= htmlspecialchars($comment['incident_ref']) ?> — <?= htmlspecialchars($comment['incident_title']) ?>
                    </a>
                </div>

                <div class="comment-body">
                    <?= nl2br(htmlspecialchars($comment['comment'])) ?>
                    <?php if ($comment['is_edited']): ?>
                        <span style="font-size:11px;color:#999;margin-left:8px">(modifié)</span>
                    <?php endif; ?>
                </div>

                <div class="actions">
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-approve">✅ Approuver</button>
                    </form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce commentaire ?')">
                        <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn-delete">🗑️ Supprimer</button>
                    </form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Suspendre cet utilisateur et supprimer le commentaire ?')">
                        <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                        <input type="hidden" name="action" value="ban_user">
                        <button type="submit" class="btn-ban">🚫 Suspendre l'utilisateur</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
