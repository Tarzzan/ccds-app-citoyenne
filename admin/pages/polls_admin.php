<?php
/**
 * CCDS Back-Office — Gestion des sondages
 */
require_admin_auth();
$db = Database::getInstance();

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'close' && !empty($_POST['poll_id'])) {
        $stmt = $db->prepare("UPDATE polls SET status = 'closed' WHERE id = ?");
        $stmt->execute([(int)$_POST['poll_id']]);
    } elseif ($action === 'delete' && !empty($_POST['poll_id'])) {
        $db->prepare("DELETE FROM poll_votes WHERE poll_id = ?")->execute([(int)$_POST['poll_id']]);
        $db->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([(int)$_POST['poll_id']]);
        $db->prepare("DELETE FROM polls WHERE id = ?")->execute([(int)$_POST['poll_id']]);
    }
    header('Location: /admin/?page=polls');
    exit;
}

// Récupérer les sondages
$polls = $db->query("
    SELECT p.*, u.full_name AS created_by_name,
           (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes,
           (SELECT COUNT(*) FROM poll_options WHERE poll_id = p.id) AS options_count
    FROM polls p
    JOIN users u ON u.id = p.created_by
    ORDER BY p.created_at DESC
")->fetchAll();

$page_title = 'Sondages';
$active_nav = 'polls';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-header">
    <h1>Sondages</h1>
    <span class="badge badge-blue"><?= count($polls) ?> sondage(s)</span>
</div>

<?php if (empty($polls)): ?>
<div class="empty-state">
    <p>Aucun sondage pour le moment.</p>
</div>
<?php else: ?>
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr>
                <th>Titre</th>
                <th>Statut</th>
                <th>Créé par</th>
                <th>Votes</th>
                <th>Options</th>
                <th>Fin</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($polls as $poll): ?>
            <tr>
                <td><?= e($poll['title']) ?></td>
                <td><span class="badge <?= $poll['status'] === 'open' ? 'badge-green' : 'badge-gray' ?>"><?= e($poll['status']) ?></span></td>
                <td><?= e($poll['created_by_name']) ?></td>
                <td><?= (int)$poll['total_votes'] ?></td>
                <td><?= (int)$poll['options_count'] ?></td>
                <td><?= $poll['ends_at'] ? e(format_date_short($poll['ends_at'])) : '—' ?></td>
                <td>
                    <?php if ($poll['status'] === 'open'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="close">
                        <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-warning">Fermer</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce sondage ?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
