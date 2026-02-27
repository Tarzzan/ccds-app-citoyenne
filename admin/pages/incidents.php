<?php
/**
 * CCDS Back-Office — Liste des signalements
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Signalements';
$active_nav = 'incidents';

$db = Database::getInstance()->getConnection();

// --- Paramètres de filtre et pagination ---
$per_page  = 20;
$page_num  = max(1, (int)($_GET['p'] ?? 1));
$offset    = ($page_num - 1) * $per_page;
$f_status  = $_GET['status']   ?? '';
$f_cat     = $_GET['cat']      ?? '';
$f_search  = trim($_GET['q']   ?? '');
$f_priority= $_GET['priority'] ?? '';

// Construction de la clause WHERE
$where = ['1=1'];
$params = [];
if ($f_status)   { $where[] = 'i.status = ?';      $params[] = $f_status; }
if ($f_cat)      { $where[] = 'i.category_id = ?'; $params[] = $f_cat; }
if ($f_priority) { $where[] = 'i.priority = ?';    $params[] = $f_priority; }
if ($f_search)   {
    $where[] = '(i.reference LIKE ? OR i.description LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$f_search%"; $params[] = "%$f_search%"; $params[] = "%$f_search%";
}
$where_sql = implode(' AND ', $where);

// Compter le total
$count_stmt = $db->prepare("SELECT COUNT(*) FROM incidents i JOIN users u ON u.id = i.user_id WHERE $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

// Récupérer les signalements
$stmt = $db->prepare("
    SELECT i.id, i.reference, i.description, i.status, i.priority,
           i.created_at, i.updated_at,
           c.name AS cat_name, c.color AS cat_color,
           u.full_name AS reporter, u.email AS reporter_email,
           (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
           (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    WHERE $where_sql
    ORDER BY i.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Catégories pour le filtre
$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>

<!-- Filtres -->
<div class="card" style="padding:16px 24px;margin-bottom:16px;">
  <form method="GET" action="" class="filters-bar">
    <input type="hidden" name="page" value="incidents">
    <input type="text" name="q" class="form-control search-input"
           placeholder="🔍 Rechercher (réf, description, citoyen)…"
           value="<?= e($f_search) ?>">
    <select name="status" class="form-control">
      <option value="">Tous les statuts</option>
      <option value="submitted"    <?= $f_status==='submitted'    ?'selected':'' ?>>Soumis</option>
      <option value="acknowledged" <?= $f_status==='acknowledged' ?'selected':'' ?>>Pris en charge</option>
      <option value="in_progress"  <?= $f_status==='in_progress'  ?'selected':'' ?>>En cours</option>
      <option value="resolved"     <?= $f_status==='resolved'     ?'selected':'' ?>>Résolus</option>
      <option value="rejected"     <?= $f_status==='rejected'     ?'selected':'' ?>>Rejetés</option>
    </select>
    <select name="cat" class="form-control">
      <option value="">Toutes les catégories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= $f_cat==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="priority" class="form-control">
      <option value="">Toutes priorités</option>
      <option value="critical" <?= $f_priority==='critical'?'selected':'' ?>>Critique</option>
      <option value="high"     <?= $f_priority==='high'    ?'selected':'' ?>>Haute</option>
      <option value="medium"   <?= $f_priority==='medium'  ?'selected':'' ?>>Normale</option>
      <option value="low"      <?= $f_priority==='low'     ?'selected':'' ?>>Faible</option>
    </select>
    <button type="submit" class="btn btn-primary">Filtrer</button>
    <a href="/admin/?page=incidents" class="btn btn-outline">Réinitialiser</a>
  </form>
</div>

<!-- Tableau -->
<div class="card">
  <div class="card-header">
    <span class="card-title">
      <?= $total ?> signalement<?= $total > 1 ? 's' : '' ?>
      <?= $f_status || $f_cat || $f_search || $f_priority ? '<span class="badge badge-blue" style="margin-left:8px">Filtré</span>' : '' ?>
    </span>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Référence</th>
          <th>Description</th>
          <th>Catégorie</th>
          <th>Statut</th>
          <th>Priorité</th>
          <th>Citoyen</th>
          <th>📷</th>
          <th>💬</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidents as $inc): ?>
        <tr>
          <td><code style="font-size:11px"><?= e($inc['reference']) ?></code></td>
          <td>
            <span class="truncate" title="<?= e($inc['description']) ?>">
              <?= e($inc['description']) ?>
            </span>
          </td>
          <td>
            <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
              <?= e($inc['cat_name']) ?>
            </span>
          </td>
          <td><span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span></td>
          <td><span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span></td>
          <td>
            <div><?= e($inc['reporter']) ?></div>
            <div class="text-muted text-small"><?= e($inc['reporter_email']) ?></div>
          </td>
          <td class="text-center"><?= $inc['photo_count'] > 0 ? '📷 '.$inc['photo_count'] : '<span class="text-muted">—</span>' ?></td>
          <td class="text-center"><?= $inc['comment_count'] > 0 ? '💬 '.$inc['comment_count'] : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted text-small"><?= format_date_short($inc['created_at']) ?></td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-primary btn-sm">Traiter</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($incidents)): ?>
        <tr><td colspan="10" class="text-center text-muted" style="padding:40px">Aucun signalement trouvé.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination" style="padding:16px 0 0;">
    <?php
    $base_url = '/admin/?page=incidents' .
        ($f_status   ? '&status='   . urlencode($f_status)   : '') .
        ($f_cat      ? '&cat='      . urlencode($f_cat)      : '') .
        ($f_priority ? '&priority=' . urlencode($f_priority) : '') .
        ($f_search   ? '&q='        . urlencode($f_search)   : '');
    ?>
    <a href="<?= $base_url ?>&p=<?= max(1, $page_num-1) ?>"
       class="page-btn <?= $page_num <= 1 ? 'disabled' : '' ?>">← Préc.</a>
    <?php for ($i = max(1,$page_num-2); $i <= min($total_pages, $page_num+2); $i++): ?>
      <a href="<?= $base_url ?>&p=<?= $i ?>"
         class="page-btn <?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="<?= $base_url ?>&p=<?= min($total_pages, $page_num+1) ?>"
       class="page-btn <?= $page_num >= $total_pages ? 'disabled' : '' ?>">Suiv. →</a>
    <span class="text-muted text-small" style="margin-left:8px">
      Page <?= $page_num ?> / <?= $total_pages ?>
    </span>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
