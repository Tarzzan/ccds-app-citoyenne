<?php
/**
 * Ma Commune Back-Office — Logs d'audit
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Logs d\'audit';
$active_nav = 'audit_logs';
$db         = Database::getInstance();
$themePalette = visual_admin_data_palette();
$statusPalette = visual_admin_status_palette();

if ($admin['role'] !== 'admin') {
    render_error(403, 'Accès réservé aux administrateurs.');
}

$columns = [];
try {
    $columns = $db->query('SHOW COLUMNS FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $columns = [];
}

if (empty($columns)) {
    require_once __DIR__ . '/../includes/layout.php';
    echo '<div class="alert alert-warning">La table <strong>audit_logs</strong> n\'est pas disponible dans cet environnement. La traçabilité avancée reste donc indisponible tant que la migration correspondante n a pas été appliquée.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    return;
}

$userField       = in_array('user_id', $columns, true) ? 'user_id' : 'admin_id';
$targetTypeField = in_array('target_type', $columns, true) ? 'target_type' : 'entity';
$targetIdField   = in_array('target_id', $columns, true) ? 'target_id' : 'entity_id';
$detailsField    = in_array('details', $columns, true) ? 'details' : null;
$oldValueField   = in_array('old_value', $columns, true) ? 'old_value' : null;
$newValueField   = in_array('new_value', $columns, true) ? 'new_value' : null;

$filterAdmin  = (int)($_GET['admin_id'] ?? 0);
$filterEntity = trim($_GET['entity'] ?? '');
$filterAction = trim($_GET['action'] ?? '');
$filterFrom   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$filterTo     = $_GET['to'] ?? date('Y-m-d');
$pageNum      = max(1, (int)($_GET['p'] ?? 1));
$perPage      = 25;
$offset       = ($pageNum - 1) * $perPage;

$where  = ['al.created_at BETWEEN ? AND ?'];
$params = [$filterFrom . ' 00:00:00', $filterTo . ' 23:59:59'];

if ($filterAdmin > 0) {
    $where[] = "al.{$userField} = ?";
    $params[] = $filterAdmin;
}
if ($filterEntity !== '') {
    $where[] = "al.{$targetTypeField} = ?";
    $params[] = $filterEntity;
}
if ($filterAction !== '') {
    $where[] = 'al.action LIKE ?';
    $params[] = '%' . $filterAction . '%';
}

$whereSql = implode(' AND ', $where);

$stmtCount = $db->prepare("SELECT COUNT(*) FROM audit_logs al WHERE {$whereSql}");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();

$selectDetails = [];
if ($detailsField) {
    $selectDetails[] = "al.{$detailsField} AS log_details";
}
if ($oldValueField) {
    $selectDetails[] = "al.{$oldValueField} AS log_old_value";
}
if ($newValueField) {
    $selectDetails[] = "al.{$newValueField} AS log_new_value";
}
$detailsSql = $selectDetails ? ', ' . implode(', ', $selectDetails) : '';

$stmtLogs = $db->prepare("
    SELECT al.action, al.created_at, al.ip_address,
           al.{$targetTypeField} AS log_target_type,
           al.{$targetIdField} AS log_target_id,
           u.full_name AS admin_name,
           u.email AS admin_email
           {$detailsSql}
    FROM audit_logs al
    JOIN users u ON u.id = al.{$userField}
    WHERE {$whereSql}
    ORDER BY al.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

$admins = $db->query("SELECT id, full_name FROM users WHERE role IN ('admin', 'agent') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, (int)ceil($total / $perPage));

$stats = ['actors_7d' => 0, 'deletions_30d' => 0, 'suspensions_30d' => 0];
try {
    $stats['actors_7d'] = (int)$db->query("SELECT COUNT(DISTINCT {$userField}) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $stats['deletions_30d'] = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%deleted%' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    $stats['suspensions_30d'] = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%banned%' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
} catch (Throwable $e) {
}

$actionLabels = [
    'incident_status_changed' => ['label' => 'Statut modifié', 'icon' => 'STA', 'color' => $statusPalette['acknowledged']],
    'incident.deleted_via_admin' => ['label' => 'Signalement supprimé', 'icon' => 'SUP', 'color' => $themePalette['danger']],
    'comment_report.dismissed' => ['label' => 'Signalement classé', 'icon' => 'OK', 'color' => $statusPalette['resolved']],
    'comment.deleted_via_admin' => ['label' => 'Commentaire supprimé', 'icon' => 'SUP', 'color' => $themePalette['danger']],
    'comment.deleted_via_moderation' => ['label' => 'Commentaire supprimé', 'icon' => 'SUP', 'color' => $themePalette['danger']],
    'user.suspended_via_moderation' => ['label' => 'Utilisateur suspendu', 'icon' => 'BLQ', 'color' => $statusPalette['in_progress']],
    'user_banned' => ['label' => 'Utilisateur suspendu', 'icon' => 'BLQ', 'color' => $statusPalette['in_progress']],
    'notification_sent' => ['label' => 'Notification envoyée', 'icon' => 'INF', 'color' => $themePalette['accent']],
];

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="audit-admin">
<div class="page-hero page-hero--with-visual">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Traçabilité</div>
    <div class="page-hero-title">Suivre les actions sensibles du back-office</div>
    <div class="page-hero-text">
      Les logs d’audit permettent d’identifier qui a agi, sur quelle ressource, et à quel moment, afin de fiabiliser la gouvernance du service.
    </div>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $total ?></span>
      <span class="hero-chip-label">entree(s) sur la période</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $stats['actors_7d'] ?></span>
      <span class="hero-chip-label">agents actifs sur 7 jours</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $stats['deletions_30d'] ?></span>
      <span class="hero-chip-label">actions de suppression</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $stats['suspensions_30d'] ?></span>
      <span class="hero-chip-label">suspensions récentes</span>
    </div>
  </div>
  <div class="page-hero-visual">
    <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
      <?= generated_visual_html('ILL-01', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Lecture de traces']) ?>
      <?= generated_visual_html('ILL-05', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Cadre de gouvernance']) ?>
      <?= generated_visual_html('CHAR-05', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais audit']) ?>
      <div class="generated-visual-caption hero-visual-stack-copy">
        <strong>Memoire d exploitation</strong>
        <span>Qui a agi, sur quoi, et avec quel niveau de sensibilité pour le service.</span>
      </div>
    </div>
  </div>
</div>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Bonne lecture</div>
    <h3>Lire les traces comme une aide a la gouvernance, pas comme une punition.</h3>
    <p>
      Les logs servent d abord a comprendre la chaine d action, a retrouver un contexte et a fiabiliser les décisions sensibles prises dans le backoffice.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Reflexe utile</div>
    <h3>Filtrer vite, puis ouvrir le bon détail.</h3>
    <p>
      L enjeu n est pas de lire toute la table, mais d isoler la bonne période, le bon agent et la bonne action pour rejouer proprement un incident d exploitation.
    </p>
  </div>
</div>

<div class="audit-stats">
  <div class="audit-stat"><strong><?= $total ?></strong><span>entrées sur la période</span></div>
  <div class="audit-stat"><strong><?= $stats['actors_7d'] ?></strong><span>agents actifs sur 7 jours</span></div>
  <div class="audit-stat"><strong><?= $stats['deletions_30d'] ?></strong><span>actions de suppression sur 30 jours</span></div>
  <div class="audit-stat"><strong><?= $stats['suspensions_30d'] ?></strong><span>suspensions sur 30 jours</span></div>
</div>

<div class="card audit-filters-card">
  <div class="card-header">
    <span class="card-title">Filtrer les traces</span>
    <span class="text-small text-muted">Réduire la lecture aux agents, entités et fenêtres de temps utiles.</span>
  </div>
  <form method="GET" action="/admin/" class="audit-filters" data-async-form>
    <input type="hidden" name="page" value="audit_logs">
    <select name="admin_id" class="form-control">
      <option value="">Tous les agents</option>
      <?php foreach ($admins as $actor): ?>
        <option value="<?= $actor['id'] ?>" <?= $filterAdmin === (int)$actor['id'] ? 'selected' : '' ?>><?= e($actor['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="entity" class="form-control" value="<?= e($filterEntity) ?>" placeholder="Entité">
    <input type="text" name="action" class="form-control" value="<?= e($filterAction) ?>" placeholder="Action">
    <input type="date" name="from" class="form-control" value="<?= e($filterFrom) ?>">
    <input type="date" name="to" class="form-control" value="<?= e($filterTo) ?>">
    <div class="audit-filter-actions">
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <a href="/admin/?page=audit_logs" class="btn btn-outline" data-async-link>Réinitialiser</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header card-header--split">
    <div>
      <span class="card-title">Historique d’audit</span>
      <p class="admin-section-note">Conserver une lecture compacte : acteur, action, cible et charge utile avant d’ouvrir le détail JSON.</p>
    </div>
    <span class="text-small text-muted"><?= $total ?> entrée(s) trouvée(s)</span>
  </div>
  <div class="table-wrapper">
    <table class="audit-table">
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
        <tr><td colspan="7" class="text-center text-muted audit-empty">Aucun log pour cette période. Élargir la fenêtre, retirer un filtre ou attendre un nouvel événement d exploitation.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log): ?>
        <?php
          $meta = $actionLabels[$log['action']] ?? ['label' => $log['action'], 'icon' => 'LOG', 'color' => '#5E6C67'];
          $detailsPayload = $log['log_details'] ?? null;
          $oldPayload = $log['log_old_value'] ?? null;
          $newPayload = $log['log_new_value'] ?? null;
        ?>
        <tr>
          <td class="text-small text-muted">
            <?= format_date_short($log['created_at']) ?><br>
            <strong><?= date('H:i:s', strtotime($log['created_at'])) ?></strong>
          </td>
          <td>
            <strong><?= e($log['admin_name']) ?></strong><br>
            <span class="text-small text-muted"><?= e($log['admin_email']) ?></span>
          </td>
          <td>
            <span class="audit-action" style="--audit-accent:<?= e($meta['color']) ?>">
              <?= e($meta['icon']) ?> <?= e($meta['label']) ?>
            </span>
          </td>
          <td><span class="audit-entity"><?= e((string)$log['log_target_type']) ?></span></td>
          <td><?= $log['log_target_id'] !== null ? (int)$log['log_target_id'] : '—' ?></td>
          <td class="text-small text-muted"><?= e($log['ip_address'] ?? '—') ?></td>
          <td>
            <?php if ($detailsPayload || $oldPayload || $newPayload): ?>
              <button
                type="button"
                class="btn btn-outline btn-sm"
                onclick='openAuditModal(<?= json_encode($detailsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($oldPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($newPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
              >
                Voir
              </button>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="audit-pagination">
      <?php for ($p = 1; $p <= min($totalPages, 10); $p++): ?>
        <?php if ($p === $pageNum): ?>
          <span class="audit-page active"><?= $p ?></span>
        <?php else: ?>
          <a class="audit-page" data-async-link href="/admin/?page=audit_logs&admin_id=<?= $filterAdmin ?>&entity=<?= urlencode($filterEntity) ?>&action=<?= urlencode($filterAction) ?>&from=<?= urlencode($filterFrom) ?>&to=<?= urlencode($filterTo) ?>&p=<?= $p ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<div class="audit-modal" id="auditModal" onclick="closeAuditModal(event)">
  <div class="audit-modal-box">
    <h3 class="audit-modal-title">Détail de l’entrée d’audit</h3>
    <p class="text-muted audit-modal-text">Affichage du contenu enregistré par l’action.</p>
    <div class="audit-modal-grid">
      <div>
        <div class="audit-modal-label">DETAILS</div>
        <div class="audit-modal-col" id="auditDetails"></div>
      </div>
      <div>
        <div class="audit-modal-label">AVANT / APRES</div>
        <div class="audit-modal-col" id="auditDiff"></div>
      </div>
    </div>
    <div class="audit-modal-actions">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('auditModal').classList.remove('open')">Fermer</button>
    </div>
  </div>
</div>

<script>
(() => {
function auditFormat(value) {
  if (!value) return '(vide)';
  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch (error) {
    return String(value);
  }
}

window.openAuditModal = function openAuditModal(details, oldValue, newValue) {
  document.getElementById('auditDetails').textContent = auditFormat(details);
  document.getElementById('auditDiff').textContent = 'Avant:\n' + auditFormat(oldValue) + '\n\nAprès:\n' + auditFormat(newValue);
  document.getElementById('auditModal').classList.add('open');
};

window.closeAuditModal = function closeAuditModal(event) {
  if (event.target.id === 'auditModal') {
    document.getElementById('auditModal').classList.remove('open');
  }
};
})();
</script>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
