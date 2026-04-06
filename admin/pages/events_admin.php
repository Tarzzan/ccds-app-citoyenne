<?php
/**
 * Ma Commune Admin — Gestion des événements
 */
$admin = require_admin_auth();
$db = Database::getInstance();
require_once __DIR__ . '/../../backend/config/NotificationStore.php';
$isAdmin = ($admin['role'] ?? '') === 'admin';
$eventHasEventDate = admin_db_has_column($db, 'events', 'event_date');
$eventHasStartsAt = admin_db_has_column($db, 'events', 'starts_at');
$eventHasEndsAt = admin_db_has_column($db, 'events', 'ends_at');
$eventHasPublished = admin_db_has_column($db, 'events', 'is_published');

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
    $stmt = $db->query('SELECT id FROM users WHERE is_active = 1');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        NotificationStore::insert(
            $db,
            (int) $userId,
            null,
            'event',
            'Nouvel evenement communautaire',
            'Un nouveau rendez-vous est proposé : ' . $title,
            [],
            0
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!$isAdmin) {
            throw new RuntimeException('Seuls les administrateurs peuvent modifier les evenements.');
        }

        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $eventDate = normalize_admin_datetime($_POST['event_date'] ?? null);

            if ($title === '' || $location === '' || !$eventDate) {
                throw new RuntimeException('Titre, lieu et date sont obligatoires pour créer un événement.');
            }

            $db->beginTransaction();
            $columns = ['title', 'description', 'location'];
            $values = ['?', '?', '?'];
            $params = [
                Security::sanitizeString($title),
                Security::sanitizeString($description),
                Security::sanitizeString($location),
            ];

            if ($eventHasEventDate) {
                $columns[] = 'event_date';
                $values[] = '?';
                $params[] = $eventDate;
            } elseif ($eventHasStartsAt) {
                $columns[] = 'starts_at';
                $values[] = '?';
                $params[] = $eventDate;
            }

            if ($eventHasEndsAt) {
                $columns[] = 'ends_at';
                $values[] = '?';
                $params[] = null;
            }

            if ($eventHasPublished) {
                $columns[] = 'is_published';
                $values[] = '1';
            }

            $columns[] = 'created_by';
            $values[] = '?';
            $params[] = (int)($admin['id'] ?? 0);

            $columns[] = 'created_at';
            $values[] = 'NOW()';

            $stmt = $db->prepare(sprintf(
                'INSERT INTO events (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $values)
            ));
            $stmt->execute($params);

            notify_event_users($db, $title);
            $db->commit();
            $_SESSION['flash_success'] = 'Événement créé et notification citoyenne envoyée. Vérifier ensuite le libellé, la date et la mobilisation remontée dans la liste.';
        } elseif ($action === 'delete' && !empty($_POST['event_id'])) {
            $eventId = (int) $_POST['event_id'];
            $db->beginTransaction();
            $db->prepare("DELETE FROM event_rsvps WHERE event_id = ?")->execute([$eventId]);
            $db->prepare("DELETE FROM events WHERE id = ?")->execute([$eventId]);
            $db->commit();
            $_SESSION['flash_success'] = 'Événement supprimé. Il ne remontera plus dans l application citoyenne ni dans la file locale.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_error'] = $e->getMessage() ?: 'Impossible de traiter la demande. Vérifier les champs de publication et la validité du rendez-vous.';
    }

    header('Location: /admin/?page=events');
    exit;
}

$eventDateSelect = $eventHasEventDate
    ? 'e.event_date'
    : 'e.starts_at AS event_date';

$events = $db->query("
    SELECT e.*, {$eventDateSelect}, u.full_name AS created_by_name,
           (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status = 'attending') AS attendees_count,
           (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND status IN ('interested', 'maybe')) AS interested_count
    FROM events e
    JOIN users u ON u.id = e.created_by
    ORDER BY (event_date >= NOW()) DESC, event_date ASC
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

<div class="page-hero page-hero--with-visual">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Vie locale</div>
    <h2 class="page-hero-title">Donner de la visibilite aux rendez-vous utiles.</h2>
    <p class="page-hero-text">
      Cette vue doit permettre de publier un evenement clair, bien situe et immediatement comprehensible par les habitants.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$upcomingCount ?></span>
      <span class="hero-chip-label">rendez-vous a venir</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$attendeesTotal ?></span>
      <span class="hero-chip-label">participation(s) annoncee(s)</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($events) ?></span>
      <span class="hero-chip-label">evenement(s) au total</span>
    </div>
  </div>
  <div class="page-hero-visual">
    <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
      <?= generated_visual_html('ILL-02', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Rendez-vous local']) ?>
      <?= generated_visual_html('ILL-05', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Mobilisation locale']) ?>
      <?= generated_visual_html('CHAR-05', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais evenement']) ?>
      <div class="generated-visual-caption hero-visual-stack-copy">
        <strong>Rendez-vous lisibles</strong>
        <span>Chaque evenement doit etre compris vite, situe clairement et relie a une mobilisation reelle.</span>
      </div>
    </div>
  </div>
</div>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Bonne pratique</div>
    <h3>Un rendez-vous utile doit etre compris en quelques secondes.</h3>
    <p>
      Titre explicite, lieu net, date fiable et description courte suffisent souvent a rendre l information beaucoup plus actionnable pour les habitants.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Objectif produit</div>
    <h3>Passer d une annonce brute a une mobilisation lisible.</h3>
    <p>
      La valeur de cette surface ne vient pas du volume publie, mais de la clarte des rendez-vous qui comptent vraiment pour la vie locale.
    </p>
    <div class="admin-guidance-visual">
      <?= generated_visual_html('CHAR-04', ['class' => 'generated-visual generated-visual--portrait admin-proof-thumb admin-proof-thumb--small', 'label' => 'Relais rendez-vous']) ?>
      <div class="admin-guidance-visual-copy">
        <strong>Publier peu, rendre visible ce qui compte</strong>
        <span>Le backoffice doit aider a transformer une annonce brute en rendez-vous vraiment exploitable pour les habitants.</span>
      </div>
    </div>
  </div>
</div>

<div class="page-async-scope" data-async-scope="events-admin">
<div class="page-header">
    <h1>Événements</h1>
    <span class="badge badge-blue"><?= $upcomingCount ?> à venir</span>
    <span class="badge badge-green"><?= $attendeesTotal ?> participation(s)</span>
</div>

<?php if (!$isAdmin): ?>
<div class="alert alert-warning">Cette page est en lecture seule pour votre rôle. La publication et la suppression des evenements sont reservees aux administrateurs.</div>
<?php else: ?>
<div class="card support-create-card">
    <div class="events-admin-create-head">
        <div>
            <h2 class="support-section-title">Programmer un rendez-vous local</h2>
            <p class="text-muted support-section-copy">Chaque création publiée ici est visible dans l’application mobile et déclenche une notification citoyenne.</p>
        </div>
        <span class="badge badge-gray"><?= count($events) ?> événement(s) au total</span>
    </div>
    <div class="admin-form-guide">
        <strong>Avant publication</strong>
        <div class="admin-form-guide-list">
            <div class="admin-form-guide-item">
                <span class="admin-form-guide-step">01</span>
                <div>
                    <strong>Nommer le rendez-vous</strong>
                    <span>Le titre doit indiquer ce qui se passe, pas seulement l intention generale.</span>
                </div>
            </div>
            <div class="admin-form-guide-item">
                <span class="admin-form-guide-step">02</span>
                <div>
                    <strong>Verifier lieu et date</strong>
                    <span>Ces deux champs portent l information la plus critique pour l habitant.</span>
                </div>
            </div>
        </div>
    </div>

    <form method="post" data-async-form>
        <input type="hidden" name="action" value="create">
        <div class="events-admin-form-grid">
            <label class="events-admin-label">
                <div class="text-small support-field-kicker">Titre</div>
                <input type="text" name="title" class="form-control" required maxlength="160" placeholder="Réunion de quartier, permanence, atelier...">
            </label>
            <label class="events-admin-label">
                <div class="text-small support-field-kicker">Lieu</div>
                <input type="text" name="location" class="form-control" required maxlength="160" placeholder="Hôtel de ville, maison de quartier...">
            </label>
            <label class="events-admin-label">
                <div class="text-small support-field-kicker">Date et heure</div>
                <input type="datetime-local" name="event_date" class="form-control" required>
            </label>
        </div>
        <label class="events-admin-label support-form-block">
            <div class="text-small support-field-kicker">Description</div>
            <textarea name="description" class="form-control" rows="4" maxlength="1200" placeholder="Précisez l’objectif, le public concerné et les informations utiles."></textarea>
        </label>
        <div class="admin-section-note">La description sert a rassurer et orienter. Garder un texte utile : objet, public, ce qu il faut apporter ou comprendre.</div>
        <button type="submit" class="btn btn-primary">Publier l’événement</button>
    </form>
</div>
<?php endif; ?>

<?php if (empty($events)): ?>
<div class="empty-state"><p>Aucun événement pour le moment. Publier un premier rendez-vous permettra d amorcer la lecture locale et la notification citoyenne.</p></div>
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
                    <?php if ($isAdmin): ?>
                        <div class="events-admin-table-actions">
                          <form method="post" class="support-inline-form" onsubmit="return confirm('Supprimer cet événement ?')" data-async-form>
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                              <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                          </form>
                        </div>
                    <?php else: ?>
                        <span class="text-muted text-small">Lecture seule</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
