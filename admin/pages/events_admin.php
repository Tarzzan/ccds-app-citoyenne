<?php
/**
 * Ma Commune Admin — Gestion des événements
 */
$admin = require_admin_auth();
$db = Database::getInstance();

function normalize_admin_datetime(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return strlen($value) === 16 ? str_replace('T', ' ', $value) . ':00' : str_replace('T', ' ', $value);
}

function notify_event_users(PDO $db, string $title): void
{
    $stmt = $db->prepare("
        INSERT INTO notifications (user_id, incident_id, title, body, type, sent_at)
        SELECT id, NULL,
               '📅 Nouvel événement communautaire',
               CONCAT('Un nouveau rendez-vous est proposé : ', :title),
               'event',
               NOW()
        FROM users
        WHERE is_active = 1
    ");
    $stmt->execute([':title' => $title]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $eventDate = normalize_admin_datetime($_POST['event_date'] ?? null);

            if ($title === '' || $location === '' || !$eventDate) {
                throw new RuntimeException('Titre, lieu et date sont obligatoires pour créer un événement.');
            }

            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO events (title, description, location, event_date, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                Security::sanitizeString($title),
                Security::sanitizeString($description),
                Security::sanitizeString($location),
                $eventDate,
                (int)($admin['id'] ?? 0),
            ]);

            notify_event_users($db, $title);
            $db->commit();
            $_SESSION['flash_success'] = 'Événement créé et notification citoyenne envoyée.';
        } elseif ($action === 'delete' && !empty($_POST['event_id'])) {
            $eventId = (int) $_POST['event_id'];
            $db->beginTransaction();
            $db->prepare("DELETE FROM event_rsvps WHERE event_id = ?")->execute([$eventId]);
            $db->prepare("DELETE FROM events WHERE id = ?")->execute([$eventId]);
            $db->commit();
            $_SESSION['flash_success'] = 'Événement supprimé.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_error'] = $e->getMessage() ?: 'Impossible de traiter la demande.';
    }

    header('Location: /admin/?page=events');
    exit;
}

$events = $db->query("
    SELECT e.*, u.full_name AS created_by_name,
           (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'attending') AS attendees_count,
           (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'interested') AS interested_count
    FROM events e
    JOIN users u ON u.id = e.created_by
    ORDER BY (e.event_date >= NOW()) DESC, e.event_date ASC
")->fetchAll();

$upcomingCount = 0;
$attendeesTotal = 0;
foreach ($events as $event) {
    if (!empty($event['event_date']) && strtotime($event['event_date']) >= time()) {
        $upcomingCount++;
    }
    $attendeesTotal += (int)($event['attendees_count'] ?? 0);
}

$page_title = 'Événements';
$active_nav = 'events';
require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-header">
    <h1>Événements</h1>
    <span class="badge badge-blue"><?= $upcomingCount ?> à venir</span>
    <span class="badge badge-green"><?= $attendeesTotal ?> participation(s)</span>
</div>

<div class="card" style="padding:20px;margin-bottom:18px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div>
            <h2 style="margin:0 0 6px;">Programmer un rendez-vous communal</h2>
            <p class="text-muted" style="margin:0;">Chaque création publiée ici est visible dans l’application mobile et déclenche une notification citoyenne.</p>
        </div>
        <span class="badge badge-gray"><?= count($events) ?> événement(s) au total</span>
    </div>

    <form method="post">
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:12px;">
            <label>
                <div class="text-small" style="margin-bottom:6px;font-weight:700;">Titre</div>
                <input type="text" name="title" class="form-control" required maxlength="160" placeholder="Réunion de quartier, permanence, atelier...">
            </label>
            <label>
                <div class="text-small" style="margin-bottom:6px;font-weight:700;">Lieu</div>
                <input type="text" name="location" class="form-control" required maxlength="160" placeholder="Hôtel de ville, maison de quartier...">
            </label>
            <label>
                <div class="text-small" style="margin-bottom:6px;font-weight:700;">Date et heure</div>
                <input type="datetime-local" name="event_date" class="form-control" required>
            </label>
        </div>
        <label style="display:block;margin-bottom:12px;">
            <div class="text-small" style="margin-bottom:6px;font-weight:700;">Description</div>
            <textarea name="description" class="form-control" rows="4" maxlength="1200" placeholder="Précisez l’objectif, le public concerné et les informations utiles."></textarea>
        </label>
        <button type="submit" class="btn btn-primary">Publier l’événement</button>
    </form>
</div>

<?php if (empty($events)): ?>
<div class="empty-state"><p>Aucun événement pour le moment.</p></div>
<?php else: ?>
<div class="table-container">
    <table class="data-table">
        <thead>
            <tr><th>Titre</th><th>Lieu</th><th>Date</th><th>Créé par</th><th>Mobilisation</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($events as $ev): ?>
            <tr>
                <td><?= e($ev['title']) ?></td>
                <td><?= e($ev['location'] ?? '—') ?></td>
                <td>
                    <?= e(format_date($ev['event_date'])) ?><br>
                    <span class="text-small text-muted">
                        <?= strtotime($ev['event_date']) >= time() ? 'À venir' : 'Archivé' ?>
                    </span>
                </td>
                <td><?= e($ev['created_by_name']) ?></td>
                <td>
                    <?= (int)$ev['attendees_count'] ?> participant(s)<br>
                    <span class="text-small text-muted"><?= (int)$ev['interested_count'] ?> intéressé(s)</span>
                </td>
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
