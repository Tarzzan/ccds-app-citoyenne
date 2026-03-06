<?php
/**
 * CCDS Back-Office — Gestion des événements
 */
require_admin_auth();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete' && !empty($_POST['event_id'])) {
        $db->prepare("DELETE FROM event_rsvps WHERE event_id = ?")->execute([(int)$_POST['event_id']]);
        $db->prepare("DELETE FROM events WHERE id = ?")->execute([(int)$_POST['event_id']]);
    }
    header('Location: /admin/?page=events');
    exit;
}

$events = $db->query("
    SELECT e.*, u.full_name AS created_by_name,
           (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'attending') AS attendees_count
    FROM events e
    JOIN users u ON u.id = e.created_by
    ORDER BY e.event_date DESC
")->fetchAll();

$page_title = 'Événements';
$active_nav = 'events';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-header">
    <h1>Événements</h1>
    <span class="badge badge-blue"><?= count($events) ?> événement(s)</span>
</div>

<?php if (empty($events)): ?>
<div class="empty-state"><p>Aucun événement pour le moment.</p></div>
<?php else: ?>
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr><th>Titre</th><th>Lieu</th><th>Date</th><th>Créé par</th><th>Participants</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
            <tr>
                <td><?= e($ev['title']) ?></td>
                <td><?= e($ev['location'] ?? '—') ?></td>
                <td><?= e(format_date_short($ev['event_date'])) ?></td>
                <td><?= e($ev['created_by_name']) ?></td>
                <td><?= (int)$ev['attendees_count'] ?></td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cet événement ?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
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
