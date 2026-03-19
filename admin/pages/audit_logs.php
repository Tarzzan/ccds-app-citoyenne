<?php
/**
 * Ma Commune Back-Office — Logs d'audit
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Logs d\'audit';
$active_nav = 'audit_logs';
$db         = Database::getInstance();

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
    echo '<div class="alert alert-warning">La table <strong>audit_logs</strong> n\'est pas disponible dans cet environnement.</div>';
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
    'incident_status_changed' => ['label' => 'Statut modifié', 'icon' => '🔄', 'color' => '#2D6F86'],
    'incident.deleted_via_admin' => ['label' => 'Signalement supprimé', 'icon' => '🗑️', 'color' => '#C94B3C'],
    'comment_report.dismissed' => ['label' => 'Signalement classé', 'icon' => '✅', 'color' => '#2F7D50'],
    'comment.deleted_via_admin' => ['label' => 'Commentaire supprimé', 'icon' => '🗑️', 'color' => '#C94B3C'],
    'comment.deleted_via_moderation' => ['label' => 'Commentaire supprimé', 'icon' => '🗑️', 'color' => '#C94B3C'],
    'user.suspended_via_moderation' => ['label' => 'Utilisateur suspendu', 'icon' => '🚫', 'color' => '#A64B2A'],
    'user_banned' => ['label' => 'Utilisateur suspendu', 'icon' => '🚫', 'color' => '#A64B2A'],
    'notification_sent' => ['label' => 'Notification envoyée', 'icon' => '🔔', 'color' => '#D48B2C'],
];

require_once __DIR__ . '/../includes/layout.php';
?>
<style>
.audit-hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 18px;
  background: linear-gradient(135deg, #0e3127 0%, #174b3a 52%, #2d6f86 100%);
  color: #fff;
  border-radius: 28px;
  padding: 28px;
  margin-bottom: 24px;
  box-shadow: 0 18px 38px rgba(14, 49, 39, .16);
}
.audit-hero--with-visual {
  grid-template-columns: minmax(0, 1fr) minmax(240px, 300px);
  align-items: center;
}
.audit-kicker {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #f2d58c;
  margin-bottom: 10px;
}
.audit-title {
  font-size: 30px;
  font-family: 'Merriweather', serif;
  font-weight: 900;
  line-height: 1.15;
  margin-bottom: 10px;
}
.audit-text {
  color: rgba(255,255,255,.82);
  max-width: 640px;
}
.audit-hero-copy {
  min-width: 0;
}
.audit-hero-visual {
  justify-self: end;
  width: 100%;
}
.audit-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.audit-stat {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 20px;
  padding: 18px;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.audit-stat strong {
  display: block;
  font-size: 28px;
  color: #183229;
}
.audit-stat span {
  font-size: 13px;
  color: #5e6c67;
}
.audit-filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}
.audit-filters input,
.audit-filters select {
  min-width: 150px;
}
.audit-table {
  width: 100%;
  border-collapse: collapse;
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 18px;
  overflow: hidden;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.audit-table th {
  background: #f8f3e8;
  padding: 12px 14px;
  text-align: left;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #5e6c67;
}
.audit-table td {
  padding: 12px 14px;
  border-top: 1px solid #f2ebde;
  vertical-align: middle;
}
.audit-table tr:hover td {
  background: #fbf7ef;
}
.audit-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}
.audit-entity {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 999px;
  background: #efe7d7;
  color: #5e6c67;
  font-size: 11px;
  font-weight: 700;
}
.audit-pagination {
  display: flex;
  gap: 6px;
  justify-content: center;
  margin-top: 20px;
  flex-wrap: wrap;
}
.audit-page {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 38px;
  height: 38px;
  padding: 0 12px;
  border-radius: 12px;
  border: 1px solid #d8d0c2;
  color: #183229;
  background: #fffdf8;
  text-decoration: none;
}
.audit-page.active {
  background: #174b3a;
  border-color: #174b3a;
  color: #fff;
}
.audit-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, .5);
  z-index: 1000;
  align-items: center;
  justify-content: center;
}
.audit-modal.open {
  display: flex;
}
.audit-modal-box {
  width: 760px;
  max-width: 94vw;
  max-height: 82vh;
  overflow: auto;
  background: #fffdf8;
  border-radius: 24px;
  padding: 24px;
  border: 1px solid #ece4d5;
  box-shadow: 0 18px 38px rgba(14, 49, 39, .16);
}
.audit-modal-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-top: 16px;
}
.audit-modal-col {
  background: #f8f3e8;
  border-radius: 16px;
  padding: 14px;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: monospace;
  font-size: 12px;
}
@media (max-width: 768px) {
  .audit-hero--with-visual {
    grid-template-columns: 1fr;
  }
  .audit-modal-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<?php $auditHeroVisual = generated_visual_url('CHAR-05'); ?>

<div class="audit-hero <?= $auditHeroVisual ? 'audit-hero--with-visual' : '' ?>">
  <div class="audit-hero-copy">
    <div class="audit-kicker">Traçabilité</div>
    <div class="audit-title">Suivre les actions sensibles du back-office</div>
    <div class="audit-text">
      Les logs d’audit permettent d’identifier qui a agi, sur quelle ressource, et à quel moment, afin de fiabiliser la gouvernance du service.
    </div>
  </div>
  <?php if ($auditHeroVisual): ?>
    <div class="audit-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <?= generated_visual_html('CHAR-05', ['class' => 'generated-visual generated-visual--portrait', 'label' => 'Traçabilité du back-office']) ?>
        <div class="generated-visual-caption">
          <strong>Gouvernance lisible</strong>
          <span>Les traces sensibles doivent rester consultables sans alourdir la lecture du controle interne.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="audit-stats">
  <div class="audit-stat"><strong><?= $total ?></strong><span>entrées sur la période</span></div>
  <div class="audit-stat"><strong><?= $stats['actors_7d'] ?></strong><span>agents actifs sur 7 jours</span></div>
  <div class="audit-stat"><strong><?= $stats['deletions_30d'] ?></strong><span>actions de suppression sur 30 jours</span></div>
  <div class="audit-stat"><strong><?= $stats['suspensions_30d'] ?></strong><span>suspensions sur 30 jours</span></div>
</div>

<form method="GET" action="/admin/" class="audit-filters">
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
  <button type="submit" class="btn btn-primary">Filtrer</button>
  <a href="/admin/?page=audit_logs" class="btn btn-outline">Réinitialiser</a>
</form>

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
        <tr><td colspan="7" class="text-center text-muted" style="padding:36px">Aucun log pour cette période.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log): ?>
        <?php
          $meta = $actionLabels[$log['action']] ?? ['label' => $log['action'], 'icon' => '⚙️', 'color' => '#5E6C67'];
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
            <span class="audit-action" style="background:<?= e($meta['color']) ?>22;color:<?= e($meta['color']) ?>">
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
        <a class="audit-page" href="/admin/?page=audit_logs&admin_id=<?= $filterAdmin ?>&entity=<?= urlencode($filterEntity) ?>&action=<?= urlencode($filterAction) ?>&from=<?= urlencode($filterFrom) ?>&to=<?= urlencode($filterTo) ?>&p=<?= $p ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>

<div class="audit-modal" id="auditModal" onclick="closeAuditModal(event)">
  <div class="audit-modal-box">
    <h3 style="font-size:24px;color:#183229">Détail de l’entrée d’audit</h3>
    <p class="text-muted" style="margin-top:6px">Affichage du contenu enregistré par l’action.</p>
    <div class="audit-modal-grid">
      <div>
        <div style="font-size:12px;font-weight:800;color:#5e6c67;margin-bottom:6px">DETAILS</div>
        <div class="audit-modal-col" id="auditDetails"></div>
      </div>
      <div>
        <div style="font-size:12px;font-weight:800;color:#5e6c67;margin-bottom:6px">AVANT / APRES</div>
        <div class="audit-modal-col" id="auditDiff"></div>
      </div>
    </div>
    <div style="margin-top:18px;display:flex;justify-content:flex-end">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('auditModal').classList.remove('open')">Fermer</button>
    </div>
  </div>
</div>

<script>
function auditFormat(value) {
  if (!value) return '(vide)';
  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch (error) {
    return String(value);
  }
}

function openAuditModal(details, oldValue, newValue) {
  document.getElementById('auditDetails').textContent = auditFormat(details);
  document.getElementById('auditDiff').textContent = 'Avant:\n' + auditFormat(oldValue) + '\n\nAprès:\n' + auditFormat(newValue);
  document.getElementById('auditModal').classList.add('open');
}

function closeAuditModal(event) {
  if (event.target.id === 'auditModal') {
    document.getElementById('auditModal').classList.remove('open');
  }
}
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
