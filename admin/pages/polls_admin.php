<?php
/**
 * Ma Commune Admin — Gestion des consultations
 */
$admin = require_admin_auth();
$db = Database::getInstance();
$isAdmin = ($admin['role'] ?? '') === 'admin';
$pollHasType = admin_db_has_column($db, 'polls', 'type');
$pollHasStatus = admin_db_has_column($db, 'polls', 'status');
$pollHasIsActive = admin_db_has_column($db, 'polls', 'is_active');

function normalize_poll_options(array $rawOptions): array
{
    $options = [];
    foreach ($rawOptions as $option) {
        $label = trim((string) $option);
        if ($label !== '') {
            $options[] = Security::sanitizeString($label);
        }
    }

    return array_values(array_unique($options));
}

function poll_status_label(string $status): string
{
    return [
        'active' => 'En cours',
        'closed' => 'Clôturé',
    ][$status] ?? $status;
}

function poll_status_class(string $status): string
{
    return $status === 'active' ? 'badge-green' : 'badge-gray';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!$isAdmin) {
            throw new RuntimeException('Seuls les administrateurs peuvent modifier les consultations.');
        }

        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $endsAt = trim($_POST['ends_at'] ?? '');
            $options = preg_split('/\r\n|\r|\n/', $_POST['options'] ?? '') ?: [];
            $options = normalize_poll_options($options);

            if ($title === '' || count($options) < 2) {
                throw new RuntimeException('Un titre et au moins deux options sont requis.');
            }

            $normalizedEndsAt = $endsAt !== '' ? str_replace('T', ' ', $endsAt) . ':00' : null;

            $db->beginTransaction();
            $columns = ['title', 'description'];
            $values = ['?', '?'];
            $params = [
                Security::sanitizeString($title),
                Security::sanitizeString($description),
            ];

            if ($pollHasType) {
                $columns[] = 'type';
                $values[] = '?';
                $params[] = 'single';
            }

            if ($pollHasStatus) {
                $columns[] = 'status';
                $values[] = "'active'";
            }

            if ($pollHasIsActive) {
                $columns[] = 'is_active';
                $values[] = '1';
            }

            $columns[] = 'created_by';
            $values[] = '?';
            $params[] = (int)($admin['id'] ?? 0);

            $columns[] = 'ends_at';
            $values[] = '?';
            $params[] = $normalizedEndsAt;

            $columns[] = 'created_at';
            $values[] = 'NOW()';

            $stmt = $db->prepare(sprintf(
                'INSERT INTO polls (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $values)
            ));
            $stmt->execute($params);

            $pollId = (int) $db->lastInsertId();
            $optStmt = $db->prepare("INSERT INTO poll_options (poll_id, label, sort_order) VALUES (?, ?, ?)");
            foreach ($options as $index => $label) {
                $optStmt->execute([$pollId, $label, $index]);
            }
            $db->commit();
            $_SESSION['flash_success'] = 'Consultation créée avec succès.';
        } elseif ($action === 'close' && !empty($_POST['poll_id'])) {
            $updates = [];
            if ($pollHasStatus) {
                $updates[] = "status = 'closed'";
            }
            if ($pollHasIsActive) {
                $updates[] = 'is_active = 0';
            }

            if ($updates) {
                $stmt = $db->prepare('UPDATE polls SET ' . implode(', ', $updates) . ' WHERE id = ?');
                $stmt->execute([(int)$_POST['poll_id']]);
            }
            $_SESSION['flash_success'] = 'Consultation clôturée.';
        } elseif ($action === 'delete' && !empty($_POST['poll_id'])) {
            $pollId = (int) $_POST['poll_id'];
            $db->beginTransaction();
            $db->prepare("DELETE FROM poll_votes WHERE poll_id = ?")->execute([$pollId]);
            $db->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([$pollId]);
            $db->prepare("DELETE FROM polls WHERE id = ?")->execute([$pollId]);
            $db->commit();
            $_SESSION['flash_success'] = 'Consultation supprimée.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_error'] = $e->getMessage() ?: 'Impossible de traiter la demande.';
    }

    header('Location: /admin/?page=polls');
    exit;
}

$pollStatusSelect = $pollHasStatus
    ? 'p.status'
    : "CASE WHEN COALESCE(p.is_active, 0) = 1 THEN 'active' ELSE 'closed' END";

$polls = $db->query("
    SELECT p.*, {$pollStatusSelect} AS effective_status, u.full_name AS created_by_name,
           (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes,
           (SELECT COUNT(*) FROM poll_options WHERE poll_id = p.id) AS options_count
    FROM polls p
    JOIN users u ON u.id = p.created_by
    ORDER BY (effective_status = 'active') DESC, COALESCE(p.ends_at, '9999-12-31 23:59:59') ASC, p.created_at DESC
")->fetchAll();

$activePolls = 0;
$totalVotes = 0;
foreach ($polls as &$poll) {
    $poll['status'] = $poll['status'] ?? $poll['effective_status'] ?? 'closed';
    if (($poll['status'] ?? '') === 'active') {
        $activePolls++;
    }
    $totalVotes += (int)($poll['total_votes'] ?? 0);
}
unset($poll);

$page_title = 'Sondages';
$active_nav = 'polls';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-header">
    <h1>Consultations citoyennes</h1>
    <span class="badge badge-blue"><?= $activePolls ?> active(s)</span>
    <span class="badge badge-green"><?= $totalVotes ?> vote(s)</span>
</div>

<?php if (!$isAdmin): ?>
<div class="alert alert-warning">Cette page est en lecture seule pour votre rôle. La création et la clôture des consultations sont réservées aux administrateurs.</div>
<?php else: ?>
<div class="card" style="padding:20px;margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div>
            <h2 style="margin:0 0 6px;">Lancer une consultation</h2>
            <p class="text-muted" style="margin:0;">Publiez rapidement une question a choix unique, des options claires et une echeance pour consulter les habitants.</p>
        </div>
        <span class="badge badge-gray"><?= count($polls) ?> consultation(s) au total</span>
    </div>

    <form method="post">
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px;">
            <label>
                <div class="text-small" style="margin-bottom:6px;font-weight:700;">Titre</div>
                <input type="text" name="title" class="form-control" required maxlength="160" placeholder="Quel projet prioriser ce trimestre ?">
            </label>
            <label>
                <div class="text-small" style="margin-bottom:6px;font-weight:700;">Mode</div>
                <input type="text" class="form-control" value="Consultation a choix unique" disabled>
            </label>
            <label>
                <div class="text-small" style="margin-bottom:6px;font-weight:700;">Clôture</div>
                <input type="datetime-local" name="ends_at" class="form-control">
            </label>
        </div>
        <label style="display:block;margin-bottom:12px;">
            <div class="text-small" style="margin-bottom:6px;font-weight:700;">Description</div>
            <textarea name="description" class="form-control" rows="3" maxlength="1200" placeholder="Expliquez l’objectif de la consultation et les critères de décision."></textarea>
        </label>
        <label style="display:block;margin-bottom:12px;">
            <div class="text-small" style="margin-bottom:6px;font-weight:700;">Options de réponse</div>
            <textarea name="options" class="form-control" rows="5" required placeholder="Une option par ligne&#10;Rénover l’éclairage public&#10;Créer une aire de jeux&#10;Étendre les horaires de médiathèque"></textarea>
        </label>
        <div class="text-small text-muted" style="margin-bottom:12px;">La V1 permet un seul vote par habitant et par consultation.</div>
        <button type="submit" class="btn btn-primary">Publier la consultation</button>
    </form>
</div>
<?php endif; ?>

<?php if (empty($polls)): ?>
<div class="empty-state">
    <p>Aucune consultation pour le moment.</p>
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
                <td><span class="badge <?= poll_status_class($poll['status']) ?>"><?= e(poll_status_label($poll['status'])) ?></span></td>
                <td><?= e($poll['created_by_name']) ?></td>
                <td><?= (int)$poll['total_votes'] ?></td>
                <td><?= (int)$poll['options_count'] ?></td>
                <td><?= $poll['ends_at'] ? e(format_date_short($poll['ends_at'])) : '—' ?></td>
                <td>
                    <?php if ($poll['status'] === 'active' && $isAdmin): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="close">
                        <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-warning">Clôturer</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce sondage ?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
