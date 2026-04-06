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
$pollOptionHasLabel = admin_db_has_column($db, 'poll_options', 'label');
$pollOptionHasSortOrder = admin_db_has_column($db, 'poll_options', 'sort_order');
$pollOptionHasVotes = admin_db_has_column($db, 'poll_options', 'votes');

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
            foreach ($options as $index => $label) {
                $columns = ['poll_id'];
                $values = ['?'];
                $params = [$pollId];

                if ($pollOptionHasLabel) {
                    $columns[] = 'label';
                    $values[] = '?';
                    $params[] = $label;
                } else {
                    $columns[] = 'text';
                    $values[] = '?';
                    $params[] = $label;
                }

                if ($pollOptionHasSortOrder) {
                    $columns[] = 'sort_order';
                    $values[] = '?';
                    $params[] = $index;
                }

                if ($pollOptionHasVotes) {
                    $columns[] = 'votes';
                    $values[] = '0';
                }

                $optStmt = $db->prepare(sprintf(
                    'INSERT INTO poll_options (%s) VALUES (%s)',
                    implode(', ', $columns),
                    implode(', ', $values)
                ));
                $optStmt->execute($params);
            }
            $db->commit();
            $_SESSION['flash_success'] = 'Consultation créée. Le signal citoyen peut maintenant commencer à remonter sur cette question.';
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
            $_SESSION['flash_success'] = 'Consultation clôturée. Le résultat est figé et peut désormais être relu comme un arbitrage terminé.';
        } elseif ($action === 'delete' && !empty($_POST['poll_id'])) {
            $pollId = (int) $_POST['poll_id'];
            $db->beginTransaction();
            $db->prepare("DELETE FROM poll_votes WHERE poll_id = ?")->execute([$pollId]);
            $db->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([$pollId]);
            $db->prepare("DELETE FROM polls WHERE id = ?")->execute([$pollId]);
            $db->commit();
            $_SESSION['flash_success'] = 'Consultation supprimée. Elle ne remontera plus dans le parcours citoyen.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['flash_error'] = $e->getMessage() ?: 'Impossible de traiter la demande. Vérifier la question, les options et la date de clôture avant de republier.';
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

<div class="page-hero page-hero--with-visual">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Concertation citoyenne</div>
    <h2 class="page-hero-title">Poser une question simple, lire un signal clair.</h2>
    <p class="page-hero-text">
      Cette surface doit aider a publier une consultation rapide, comprehensible et exploitable, sans promesse de participation plus complexe que la V1.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$activePolls ?></span>
      <span class="hero-chip-label">consultation(s) active(s)</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$totalVotes ?></span>
      <span class="hero-chip-label">vote(s) deja exprime(s)</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($polls) ?></span>
      <span class="hero-chip-label">consultation(s) au total</span>
    </div>
  </div>
  <div class="page-hero-visual">
    <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
      <?= generated_visual_html('ILL-01', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Question locale']) ?>
      <?= generated_visual_html('ILL-05', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Lecture du signal']) ?>
      <?= generated_visual_html('CHAR-04', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais concertation']) ?>
      <div class="generated-visual-caption hero-visual-stack-copy">
        <strong>Consulter pour arbitrer</strong>
        <span>Le bon resultat est un signal net, pas une consultation floue qui restera ouverte trop longtemps.</span>
      </div>
    </div>
  </div>
</div>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Bonne pratique</div>
    <h3>Moins d options, plus de lisibilite.</h3>
    <p>
      Une question claire, quelques options distinctes et une date de cloture explicite donnent un resultat plus utilisable qu une consultation trop large.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Objectif produit</div>
    <h3>Faire remonter un arbitrage, pas ouvrir un debat flou.</h3>
    <p>
      La V1 doit aider a prioriser une decision locale rapidement. Le bon usage est de publier, lire un signal net, puis cloturer proprement.
    </p>
    <div class="admin-guidance-visual">
      <?= generated_visual_html('CHAR-05', ['class' => 'generated-visual generated-visual--portrait admin-proof-thumb admin-proof-thumb--small', 'label' => 'Relais consultation']) ?>
      <div class="admin-guidance-visual-copy">
        <strong>Publier peu, cloturer proprement</strong>
        <span>Une consultation courte et lisible produit souvent un meilleur arbitrage qu un dispositif plus lourd.</span>
      </div>
    </div>
  </div>
</div>

<div class="page-async-scope" data-async-scope="polls-admin">
<div class="page-header">
    <h1>Consultations citoyennes</h1>
    <span class="badge badge-blue"><?= $activePolls ?> active(s)</span>
    <span class="badge badge-green"><?= $totalVotes ?> vote(s)</span>
</div>

<?php if (!$isAdmin): ?>
<div class="alert alert-warning">Cette page est en lecture seule pour votre rôle. La création et la clôture des consultations sont réservées aux administrateurs.</div>
<?php else: ?>
<div class="card support-create-card">
    <div class="polls-admin-create-head">
        <div>
            <h2 class="support-section-title">Lancer une consultation</h2>
            <p class="text-muted support-section-copy">Publiez rapidement une question a choix unique, des options claires et une echeance pour consulter les habitants.</p>
        </div>
        <span class="badge badge-gray"><?= count($polls) ?> consultation(s) au total</span>
    </div>
    <div class="admin-form-guide">
        <strong>Avant publication</strong>
        <div class="admin-form-guide-list">
            <div class="admin-form-guide-item">
                <span class="admin-form-guide-step">01</span>
                <div>
                    <strong>Poser une question arbitrable</strong>
                    <span>Le titre doit permettre un choix simple, sans ambiguite, entre plusieurs options lisibles.</span>
                </div>
            </div>
            <div class="admin-form-guide-item">
                <span class="admin-form-guide-step">02</span>
                <div>
                    <strong>Rediger des options distinctes</strong>
                    <span>Chaque ligne doit exprimer un vrai choix, pas deux variantes quasi identiques.</span>
                </div>
            </div>
        </div>
    </div>

    <form method="post" data-async-form>
        <input type="hidden" name="action" value="create">
        <div class="polls-admin-form-grid">
            <label class="polls-admin-label">
                <div class="text-small support-field-kicker">Titre</div>
                <input type="text" name="title" class="form-control" required maxlength="160" placeholder="Quel projet prioriser ce trimestre ?">
            </label>
            <label class="polls-admin-label">
                <div class="text-small support-field-kicker">Mode</div>
                <input type="text" class="form-control" value="Consultation a choix unique" disabled>
            </label>
            <label class="polls-admin-label">
                <div class="text-small support-field-kicker">Clôture</div>
                <input type="datetime-local" name="ends_at" class="form-control">
            </label>
        </div>
        <label class="polls-admin-label support-form-block">
            <div class="text-small support-field-kicker">Description</div>
            <textarea name="description" class="form-control" rows="3" maxlength="1200" placeholder="Expliquez l’objectif de la consultation et les critères de décision."></textarea>
        </label>
        <label class="polls-admin-label support-form-block">
            <div class="text-small support-field-kicker">Options de réponse</div>
            <textarea name="options" class="form-control" rows="5" required placeholder="Une option par ligne&#10;Rénover l’éclairage public&#10;Créer une aire de jeux&#10;Étendre les horaires de médiathèque"></textarea>
        </label>
        <div class="text-small text-muted polls-admin-help support-inline-note">La V1 permet un seul vote par habitant et par consultation.</div>
        <div class="admin-section-note">Si deux options semblent se chevaucher, il vaut mieux les fusionner avant publication que diluer le vote citoyen.</div>
        <button type="submit" class="btn btn-primary">Publier la consultation</button>
    </form>
</div>
<?php endif; ?>

<?php if (empty($polls)): ?>
<div class="empty-state">
    <p>Aucune consultation pour le moment. Une première question claire suffit à lancer la lecture citoyenne sans surcharger la gouvernance.</p>
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
                    <div class="polls-admin-table-actions">
                      <?php if ($poll['status'] === 'active' && $isAdmin): ?>
                      <form method="post" class="support-inline-form" data-async-form>
                          <input type="hidden" name="action" value="close">
                          <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-warning">Clôturer</button>
                      </form>
                      <?php endif; ?>
                      <?php if ($isAdmin): ?>
                      <form method="post" class="support-inline-form" onsubmit="return confirm('Supprimer ce sondage ?')" data-async-form>
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="poll_id" value="<?= (int)$poll['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                      </form>
                      <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
