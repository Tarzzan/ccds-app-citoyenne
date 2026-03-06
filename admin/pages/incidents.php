<?php
/**
 * CCDS v1.2 — Liste des signalements (ADMIN-03)
 * Filtres avancés : statut, catégorie, priorité, date, recherche textuelle, tri, votes.
 * Export CSV.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Signalements';
$active_nav = 'incidents';

$db = Database::getInstance();

// --- Paramètres de filtre et pagination ---
$per_page   = 20;
$page_num   = max(1, (int)($_GET['p']        ?? 1));
$offset     = ($page_num - 1) * $per_page;
$f_status   = $_GET['status']    ?? '';
$f_cat      = $_GET['cat']       ?? '';
$f_search   = trim($_GET['q']    ?? '');
$f_priority = $_GET['priority']  ?? '';
$f_date_from= trim($_GET['date_from'] ?? '');
$f_date_to  = trim($_GET['date_to']   ?? '');
$f_sort     = in_array($_GET['sort'] ?? '', ['votes_count','updated_at','created_at']) ? $_GET['sort'] : 'created_at';
$f_dir      = ($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$export_csv = isset($_GET['export']) && $_GET['export'] === 'csv';

// Construction de la clause WHERE
$where  = ['1=1'];
$params = [];
if ($f_status)    { $where[] = 'i.status = ?';                                    $params[] = $f_status; }
if ($f_cat)       { $where[] = 'i.category_id = ?';                               $params[] = $f_cat; }
if ($f_priority)  { $where[] = 'i.priority = ?';                                  $params[] = $f_priority; }
if ($f_date_from) { $where[] = 'DATE(i.created_at) >= ?';                         $params[] = $f_date_from; }
if ($f_date_to)   { $where[] = 'DATE(i.created_at) <= ?';                         $params[] = $f_date_to; }
if ($f_search)    {
    $where[] = '(i.reference LIKE ? OR i.title LIKE ? OR i.description LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
    $like = "%$f_search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$where_sql = implode(' AND ', $where);

// Compter le total
$count_stmt = $db->prepare("SELECT COUNT(*) FROM incidents i JOIN users u ON u.id = i.user_id WHERE $where_sql");
$count_stmt->execute($params);
$total       = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

// Récupérer les signalements
$sql = "
    SELECT i.id, i.reference, i.title, i.description, i.status, i.priority,
           i.votes_count, i.created_at, i.updated_at,
           c.name AS cat_name, c.color AS cat_color,
           u.full_name AS reporter, u.email AS reporter_email,
           (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
           (SELECT COUNT(*) FROM comments cm WHERE cm.incident_id = i.id AND cm.is_internal = 0) AS comment_count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    WHERE $where_sql
    ORDER BY i.$f_sort $f_dir
";

if (!$export_csv) {
    $sql .= " LIMIT $per_page OFFSET $offset";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Export CSV ---
if ($export_csv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="signalements_' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Référence','Titre','Statut','Priorité','Catégorie','Votes','Citoyen','Email','Date'], ';');
    foreach ($incidents as $inc) {
        fputcsv($out, [
            $inc['reference'], $inc['title'] ?: substr($inc['description'],0,60),
            status_label($inc['status']), priority_label($inc['priority'] ?? 'medium'),
            $inc['cat_name'], $inc['votes_count'],
            $inc['reporter'], $inc['reporter_email'],
            format_date_short($inc['created_at']),
        ], ';');
    }
    fclose($out);
    exit;
}

// Catégories pour le filtre
$categories = $db->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';

// Construire l'URL de base pour la pagination et le tri
$base_params = array_filter([
    'page'      => 'incidents',
    'status'    => $f_status,
    'cat'       => $f_cat,
    'priority'  => $f_priority,
    'q'         => $f_search,
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
    'sort'      => $f_sort,
    'dir'       => $f_dir,
]);
$base_url = '/admin/?' . http_build_query($base_params);

// Helper tri
function sort_url(string $field, string $current_sort, string $current_dir, string $base): string {
    $new_dir = ($current_sort === $field && $current_dir === 'DESC') ? 'ASC' : 'DESC';
    return $base . '&sort=' . $field . '&dir=' . $new_dir;
}
function sort_icon(string $field, string $current_sort, string $current_dir): string {
    if ($current_sort !== $field) return '<span style="opacity:.3">↕</span>';
    return $current_dir === 'DESC' ? '↓' : '↑';
}
?>

<!-- Filtres avancés v1.2 -->
<div class="card" style="padding:16px 24px;margin-bottom:16px;">
  <form method="GET" action="" id="filter-form">
    <input type="hidden" name="page" value="incidents">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
      <input type="text" name="q" class="form-control"
             placeholder="🔍 Réf, titre, description, citoyen…"
             value="<?= e($f_search) ?>" style="grid-column:span 2">
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
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <select name="priority" class="form-control" style="width:160px">
        <option value="">Toutes priorités</option>
        <option value="critical" <?= $f_priority==='critical'?'selected':'' ?>>🔴 Critique</option>
        <option value="high"     <?= $f_priority==='high'    ?'selected':'' ?>>🟠 Haute</option>
        <option value="medium"   <?= $f_priority==='medium'  ?'selected':'' ?>>🟡 Normale</option>
        <option value="low"      <?= $f_priority==='low'     ?'selected':'' ?>>🟢 Faible</option>
      </select>
      <input type="date" name="date_from" class="form-control" style="width:150px"
             value="<?= e($f_date_from) ?>" title="Date de début">
      <input type="date" name="date_to" class="form-control" style="width:150px"
             value="<?= e($f_date_to) ?>" title="Date de fin">
      <button type="submit" class="btn btn-primary">Filtrer</button>
      <a href="/admin/?page=incidents" class="btn btn-outline">Réinitialiser</a>
      <a href="<?= $base_url ?>&export=csv" class="btn btn-outline" style="margin-left:auto">
        📥 Export CSV
      </a>
    </div>
  </form>
</div>

<!-- Tableau -->
<div class="card">
  <div class="card-header">
    <span class="card-title">
      <?= $total ?> signalement<?= $total > 1 ? 's' : '' ?>
      <?php if ($f_status || $f_cat || $f_search || $f_priority || $f_date_from || $f_date_to): ?>
        <span class="badge badge-blue" style="margin-left:8px">Filtré</span>
      <?php endif; ?>
    </span>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th><a href="<?= sort_url('created_at', $f_sort, $f_dir, $base_url) ?>" style="color:inherit;text-decoration:none">
            Date <?= sort_icon('created_at', $f_sort, $f_dir) ?>
          </a></th>
          <th>Référence</th>
          <th>Titre / Description</th>
          <th>Catégorie</th>
          <th>Statut</th>
          <th>Priorité</th>
          <th><a href="<?= sort_url('votes_count', $f_sort, $f_dir, $base_url) ?>" style="color:inherit;text-decoration:none">
            👍 <?= sort_icon('votes_count', $f_sort, $f_dir) ?>
          </a></th>
          <th>Citoyen</th>
          <th>📷 💬</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidents as $inc): ?>
        <tr>
          <td class="text-muted text-small" style="white-space:nowrap"><?= format_date_short($inc['created_at']) ?></td>
          <td><code style="font-size:11px"><?= e($inc['reference']) ?></code></td>
          <td>
            <div style="font-weight:600;font-size:13px;margin-bottom:2px">
              <?= e($inc['title'] ?: 'Sans titre') ?>
            </div>
            <div class="text-muted text-small truncate" title="<?= e($inc['description']) ?>" style="max-width:220px">
              <?= e($inc['description']) ?>
            </div>
          </td>
          <td>
            <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
              <?= e($inc['cat_name']) ?>
            </span>
          </td>
          <td><span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span></td>
          <td><span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span></td>
          <td class="text-center">
            <?php if ($inc['votes_count'] > 0): ?>
              <span style="color:#f59e0b;font-weight:700">👍 <?= $inc['votes_count'] ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div style="font-size:13px"><?= e($inc['reporter']) ?></div>
            <div class="text-muted text-small"><?= e($inc['reporter_email']) ?></div>
          </td>
          <td class="text-center text-small">
            <?= $inc['photo_count'] > 0 ? '📷 '.$inc['photo_count'] : '' ?>
            <?= $inc['comment_count'] > 0 ? ' 💬 '.$inc['comment_count'] : '' ?>
            <?= $inc['photo_count'] == 0 && $inc['comment_count'] == 0 ? '<span class="text-muted">—</span>' : '' ?>
          </td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-primary btn-sm">Traiter</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($incidents)): ?>
        <tr><td colspan="10" class="text-center text-muted" style="padding:40px">
          <?= $f_search ? "Aucun résultat pour \"" . e($f_search) . "\"." : 'Aucun signalement trouvé.' ?>
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
  <div class="pagination" style="padding:16px 0 0;">
    <a href="<?= $base_url ?>&p=<?= max(1, $page_num-1) ?>"
       class="page-btn <?= $page_num <= 1 ? 'disabled' : '' ?>">← Préc.</a>
    <?php for ($i = max(1,$page_num-2); $i <= min($total_pages, $page_num+2); $i++): ?>
      <a href="<?= $base_url ?>&p=<?= $i ?>"
         class="page-btn <?= $i === $page_num ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <a href="<?= $base_url ?>&p=<?= min($total_pages, $page_num+1) ?>"
       class="page-btn <?= $page_num >= $total_pages ? 'disabled' : '' ?>">Suiv. →</a>
    <span class="text-muted text-small" style="margin-left:8px">
      Page <?= $page_num ?> / <?= $total_pages ?> (<?= $total ?> résultats)
    </span>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
