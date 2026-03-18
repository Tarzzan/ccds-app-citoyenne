<?php
/**
 * Ma Commune Back-Office — Gestion des utilisateurs
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Utilisateurs';
$active_nav = 'users';
$db         = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_agent' && $admin['role'] === 'admin') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['agent', 'admin'], true) ? $_POST['role'] : 'agent';

        if (!$fullName || !$email || !$password) {
            $_SESSION['flash_error'] = 'Tous les champs sont obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Adresse email invalide.';
        } else {
            $exists = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $exists->execute([$email]);

            if ($exists->fetch()) {
                $_SESSION['flash_error'] = 'Cet email est déjà utilisé.';
            } else {
                $db->prepare("
                    INSERT INTO users (full_name, email, password_hash, role, is_active, created_at)
                    VALUES (?, ?, ?, ?, 1, NOW())
                ")->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                $_SESSION['flash_success'] = "Compte de {$fullName} créé avec succès.";
            }
        }

        header('Location: /admin/?page=users');
        exit;
    }

    if ($action === 'toggle_active' && $admin['role'] === 'admin') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);

        if ($userId && $userId !== (int)$admin['id']) {
            $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$isActive, $userId]);
            $_SESSION['flash_success'] = $isActive ? 'Compte activé.' : 'Compte désactivé.';
        }

        $back = $_POST['back'] ?? '/admin/?page=users';
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'change_role' && $admin['role'] === 'admin') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', ['citizen', 'agent', 'admin'], true) ? $_POST['role'] : 'citizen';

        if ($userId && $userId !== (int)$admin['id']) {
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
            $_SESSION['flash_success'] = 'Rôle modifié.';
        }

        header('Location: /admin/?page=users');
        exit;
    }
}

$detailUser    = null;
$userIncidents = [];
$userActivity  = [];

if (isset($_GET['detail'])) {
    $detailId = (int)$_GET['detail'];

    $stmt = $db->prepare("
        SELECT u.*,
               COUNT(DISTINCT i.id)  AS incidents_count,
               COUNT(DISTINCT v.id)  AS votes_count,
               COUNT(DISTINCT c.id)  AS comments_count,
               COALESCE(g.points, 0) AS gamification_points
        FROM users u
        LEFT JOIN incidents i ON i.user_id = u.id
        LEFT JOIN votes v ON v.user_id = u.id
        LEFT JOIN comments c ON c.user_id = u.id
        LEFT JOIN user_gamification g ON g.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$detailId]);
    $detailUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detailUser) {
        $stmtInc = $db->prepare("
            SELECT i.id, i.reference, i.title, i.status, i.votes_count, i.created_at,
                   cat.name AS category_name, cat.icon AS category_icon
            FROM incidents i
            JOIN categories cat ON cat.id = i.category_id
            WHERE i.user_id = ?
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $stmtInc->execute([$detailId]);
        $userIncidents = $stmtInc->fetchAll(PDO::FETCH_ASSOC);

        $stmtAct = $db->prepare("
            (SELECT 'incident' AS type, i.reference AS ref, COALESCE(i.title, 'Sans titre') AS label, i.created_at AS date
             FROM incidents i WHERE i.user_id = ? ORDER BY i.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'comment', i.reference, LEFT(c.comment, 60), c.created_at
             FROM comments c JOIN incidents i ON i.id = c.incident_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'vote', i.reference, COALESCE(i.title, 'Sans titre'), v.created_at
             FROM votes v JOIN incidents i ON i.id = v.incident_id WHERE v.user_id = ? ORDER BY v.created_at DESC LIMIT 10)
            ORDER BY date DESC
            LIMIT 20
        ");
        $stmtAct->execute([$detailId, $detailId, $detailId]);
        $userActivity = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
    }
}

$search   = trim($_GET['q'] ?? '');
$roleFilt = $_GET['role'] ?? '';
$statFilt = $_GET['status'] ?? '';
$sort     = in_array($_GET['sort'] ?? '', ['full_name', 'email', 'created_at', 'incidents_count'], true) ? $_GET['sort'] : 'created_at';
$dir      = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$perPage  = 20;
$curPage  = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($curPage - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $like = '%' . $search . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    array_push($params, $like, $like, $like);
}

if ($roleFilt && in_array($roleFilt, ['citizen', 'agent', 'admin'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $roleFilt;
}

if ($statFilt === 'active') {
    $where[] = 'u.is_active = 1';
}
if ($statFilt === 'inactive') {
    $where[] = 'u.is_active = 0';
}

$whereSql = implode(' AND ', $where);

$stmtCount = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
$stmtCount->execute($params);
$total      = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$sortSql = match ($sort) {
    'full_name'       => 'u.full_name',
    'email'           => 'u.email',
    'incidents_count' => 'incidents_count',
    default           => 'u.created_at',
};

$stmtUsers = $db->prepare("
    SELECT u.id, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at,
           COUNT(DISTINCT i.id) AS incidents_count,
           COUNT(DISTINCT v.id) AS votes_count
    FROM users u
    LEFT JOIN incidents i ON i.user_id = u.id
    LEFT JOIN votes v ON v.user_id = u.id
    WHERE {$whereSql}
    GROUP BY u.id
    ORDER BY {$sortSql} {$dir}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$kpis = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(role = 'citizen') AS citizens,
        SUM(role = 'agent') AS agents,
        SUM(role = 'admin') AS admins,
        SUM(is_active = 1) AS active,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_30d
    FROM users
")->fetch(PDO::FETCH_ASSOC);

function users_role_badge(string $role): string
{
    return match ($role) {
        'admin' => '<span class="users-badge" style="background:#edd7cf;color:#8d3f23">Administrateur</span>',
        'agent' => '<span class="users-badge" style="background:#dce6ea;color:#2d6f86">Agent</span>',
        default => '<span class="users-badge" style="background:#efe7d7;color:#5e6c67">Citoyen</span>',
    };
}

function users_status_badge(bool $isActive): string
{
    return $isActive
        ? '<span class="users-badge" style="background:#dcebdd;color:#2f7d50">Actif</span>'
        : '<span class="users-badge" style="background:#efe7d7;color:#8d958f">Inactif</span>';
}

function users_activity_icon(string $type): string
{
    return match ($type) {
        'incident' => '📍',
        'comment'  => '💬',
        'vote'     => '👍',
        default    => '•',
    };
}

function users_sort_url(string $column, string $currentSort, string $currentDir, array $extra = []): string
{
    $nextDir = ($column === $currentSort && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($extra, ['sort' => $column, 'dir' => $nextDir]);
    return '/admin/?page=users&' . http_build_query($params);
}

require_once __DIR__ . '/../includes/layout.php';
?>
<style>
.users-hero {
  background: linear-gradient(135deg, #0e3127 0%, #174b3a 56%, #2d6f86 100%);
  color: #fff;
  border-radius: 28px;
  padding: 28px;
  margin-bottom: 24px;
  box-shadow: 0 18px 38px rgba(14, 49, 39, .16);
}
.users-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #f2d58c;
  margin-bottom: 10px;
}
.users-title {
  font-size: 30px;
  font-family: 'Merriweather', serif;
  font-weight: 900;
  line-height: 1.15;
  margin-bottom: 10px;
}
.users-text {
  color: rgba(255,255,255,.84);
  max-width: 640px;
}
.users-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.users-kpi {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 20px;
  padding: 18px;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.users-kpi-value {
  font-size: 28px;
  font-weight: 900;
  color: #183229;
}
.users-kpi-label {
  font-size: 13px;
  color: #5e6c67;
  margin-top: 4px;
}
.users-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.users-toolbar h2 {
  font-size: 20px;
  color: #183229;
}
.users-filters {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 18px;
}
.users-filters input,
.users-filters select {
  min-width: 160px;
}
.users-table {
  width: 100%;
  border-collapse: collapse;
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 18px;
  overflow: hidden;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.users-table th {
  background: #f8f3e8;
  padding: 12px 14px;
  text-align: left;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #5e6c67;
}
.users-table td {
  padding: 12px 14px;
  border-top: 1px solid #f2ebde;
  vertical-align: middle;
}
.users-table tr:hover td {
  background: #fbf7ef;
}
.users-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}
.users-pagination {
  display: flex;
  gap: 6px;
  justify-content: center;
  margin-top: 20px;
  flex-wrap: wrap;
}
.users-page {
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
.users-page.active {
  background: #174b3a;
  border-color: #174b3a;
  color: #fff;
}
.user-detail {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 22px;
  padding: 22px;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.user-detail-head {
  display: flex;
  gap: 16px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.user-avatar {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #dce6ea;
  color: #174b3a;
  font-size: 24px;
  font-weight: 800;
}
.user-detail-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 10px;
  margin: 16px 0 20px;
}
.user-detail-stat {
  background: #f8f3e8;
  border-radius: 16px;
  padding: 14px;
  text-align: center;
}
.user-detail-stat strong {
  display: block;
  font-size: 22px;
  color: #183229;
}
.user-detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}
.user-mini-table {
  width: 100%;
  border-collapse: collapse;
}
.user-mini-table td,
.user-mini-table th {
  padding: 6px 0;
  border-bottom: 1px solid #f2ebde;
  text-align: left;
}
.user-mini-table tr:last-child td,
.user-mini-table tr:last-child th {
  border-bottom: none;
}
.user-activity {
  list-style: none;
  padding: 0;
}
.user-activity li {
  display: flex;
  gap: 10px;
  padding: 8px 0;
  border-bottom: 1px solid #f2ebde;
}
.user-activity li:last-child {
  border-bottom: none;
}
.users-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, .45);
  z-index: 1000;
  align-items: center;
  justify-content: center;
}
.users-modal-box {
  width: 420px;
  max-width: 92vw;
  background: #fffdf8;
  border-radius: 22px;
  padding: 24px;
  border: 1px solid #ece4d5;
  box-shadow: 0 18px 38px rgba(14, 49, 39, .16);
}
@media (max-width: 768px) {
  .user-detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<div class="users-hero">
  <div class="users-kicker">Administration des comptes</div>
  <div class="users-title">Suivre les citoyens, agents et administrateurs</div>
  <div class="users-text">
    Cette page centralise les comptes actifs du dispositif afin de vérifier l’activité, suivre l’engagement et gérer les accès au service.
  </div>
</div>

<?php if ($detailUser): ?>
  <div class="user-detail">
    <div class="user-detail-head">
      <div class="user-avatar"><?= strtoupper(mb_substr($detailUser['full_name'], 0, 1)) ?></div>
      <div>
        <h2 style="font-size:24px;color:#183229"><?= e($detailUser['full_name']) ?></h2>
        <p style="color:#5e6c67;margin-top:4px"><?= e($detailUser['email']) ?></p>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          <?= users_role_badge($detailUser['role']) ?>
          <?= users_status_badge((bool)$detailUser['is_active']) ?>
        </div>
      </div>
      <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($admin['role'] === 'admin' && (int)$detailUser['id'] !== (int)$admin['id']): ?>
          <form method="POST">
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="user_id" value="<?= $detailUser['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $detailUser['is_active'] ? 0 : 1 ?>">
            <input type="hidden" name="back" value="/admin/?page=users&detail=<?= $detailUser['id'] ?>">
            <button type="submit" class="btn <?= $detailUser['is_active'] ? 'btn-danger' : 'btn-primary' ?>">
              <?= $detailUser['is_active'] ? 'Désactiver' : 'Activer' ?>
            </button>
          </form>
        <?php endif; ?>
        <a href="/admin/?page=users" class="btn btn-outline">Retour à la liste</a>
      </div>
    </div>

    <div class="user-detail-stats">
      <div class="user-detail-stat"><strong><?= (int)$detailUser['incidents_count'] ?></strong>Signalements</div>
      <div class="user-detail-stat"><strong><?= (int)$detailUser['votes_count'] ?></strong>Votes</div>
      <div class="user-detail-stat"><strong><?= (int)$detailUser['comments_count'] ?></strong>Commentaires</div>
      <div class="user-detail-stat"><strong><?= (int)$detailUser['gamification_points'] ?></strong>Points</div>
      <div class="user-detail-stat"><strong><?= format_date_short($detailUser['created_at']) ?></strong>Inscription</div>
    </div>

    <div class="user-detail-grid">
      <div>
        <h3 style="margin-bottom:10px;color:#183229">Derniers signalements</h3>
        <?php if ($userIncidents): ?>
          <table class="user-mini-table">
            <thead>
              <tr><th>Réf.</th><th>Titre</th><th>Votes</th></tr>
            </thead>
            <tbody>
              <?php foreach ($userIncidents as $incident): ?>
                <tr>
                  <td><a href="/admin/?page=incident_detail&id=<?= $incident['id'] ?>"><?= e($incident['reference']) ?></a></td>
                  <td><?= e(mb_strimwidth($incident['title'] ?: 'Sans titre', 0, 32, '...')) ?></td>
                  <td><?= (int)$incident['votes_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="text-muted">Aucun signalement.</p>
        <?php endif; ?>
      </div>
      <div>
        <h3 style="margin-bottom:10px;color:#183229">Activité récente</h3>
        <ul class="user-activity">
          <?php foreach ($userActivity as $activity): ?>
            <li>
              <span><?= users_activity_icon($activity['type']) ?></span>
              <div>
                <div style="font-weight:700;color:#183229"><?= e(mb_strimwidth($activity['label'], 0, 52, '...')) ?></div>
                <div class="text-muted text-small"><?= e($activity['ref']) ?> · <?= format_date($activity['date']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($userActivity)): ?>
            <li><span class="text-muted">Aucune activité récente.</span></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="users-grid">
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['total'] ?></div><div class="users-kpi-label">comptes total</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['citizens'] ?></div><div class="users-kpi-label">citoyens</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['agents'] ?></div><div class="users-kpi-label">agents</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['admins'] ?></div><div class="users-kpi-label">administrateurs</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['active'] ?></div><div class="users-kpi-label">actifs</div></div>
    <div class="users-kpi"><div class="users-kpi-value">+<?= (int)$kpis['new_30d'] ?></div><div class="users-kpi-label">nouveaux sur 30 jours</div></div>
  </div>

  <div class="users-toolbar">
    <h2>Gestion des utilisateurs</h2>
    <?php if ($admin['role'] === 'admin'): ?>
      <button type="button" onclick="document.getElementById('users-create-modal').style.display='flex'" class="btn btn-primary">Créer un agent</button>
    <?php endif; ?>
  </div>

  <form method="GET" action="/admin/" class="users-filters">
    <input type="hidden" name="page" value="users">
    <input type="text" name="q" class="form-control" placeholder="Nom, email, téléphone..." value="<?= e($search) ?>">
    <select name="role" class="form-control">
      <option value="">Tous les rôles</option>
      <option value="citizen" <?= $roleFilt === 'citizen' ? 'selected' : '' ?>>Citoyen</option>
      <option value="agent" <?= $roleFilt === 'agent' ? 'selected' : '' ?>>Agent</option>
      <option value="admin" <?= $roleFilt === 'admin' ? 'selected' : '' ?>>Administrateur</option>
    </select>
    <select name="status" class="form-control">
      <option value="">Tous les statuts</option>
      <option value="active" <?= $statFilt === 'active' ? 'selected' : '' ?>>Actifs</option>
      <option value="inactive" <?= $statFilt === 'inactive' ? 'selected' : '' ?>>Inactifs</option>
    </select>
    <button type="submit" class="btn btn-primary">Filtrer</button>
    <?php if ($search || $roleFilt || $statFilt): ?>
      <a href="/admin/?page=users" class="btn btn-outline">Réinitialiser</a>
    <?php endif; ?>
  </form>

  <?php $extra = ['q' => $search, 'role' => $roleFilt, 'status' => $statFilt, 'p' => $curPage]; ?>

  <div class="table-wrapper">
    <table class="users-table">
      <thead>
        <tr>
          <th><a href="<?= users_sort_url('full_name', $sort, $dir, $extra) ?>">Nom</a></th>
          <th><a href="<?= users_sort_url('email', $sort, $dir, $extra) ?>">Email</a></th>
          <th>Rôle</th>
          <th>Statut</th>
          <th><a href="<?= users_sort_url('incidents_count', $sort, $dir, $extra) ?>">Signalements</a></th>
          <th>Votes</th>
          <th><a href="<?= users_sort_url('created_at', $sort, $dir, $extra) ?>">Inscription</a></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td>
              <a href="/admin/?page=users&detail=<?= $user['id'] ?>" style="font-weight:700;color:#183229"><?= e($user['full_name']) ?></a>
              <?php if ($user['phone']): ?>
                <div class="text-muted text-small"><?= e($user['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($user['email']) ?></td>
            <td><?= users_role_badge($user['role']) ?></td>
            <td><?= users_status_badge((bool)$user['is_active']) ?></td>
            <td class="text-center"><?= (int)$user['incidents_count'] ?></td>
            <td class="text-center"><?= (int)$user['votes_count'] ?></td>
            <td class="text-muted text-small"><?= format_date_short($user['created_at']) ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a href="/admin/?page=users&detail=<?= $user['id'] ?>" class="btn btn-outline btn-sm">Détail</a>
                <?php if ($admin['role'] === 'admin' && (int)$user['id'] !== (int)$admin['id']): ?>
                  <form method="POST">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $user['is_active'] ? 0 : 1 ?>">
                    <button type="submit" class="btn <?= $user['is_active'] ? 'btn-danger' : 'btn-primary' ?> btn-sm">
                      <?= $user['is_active'] ? 'Désactiver' : 'Activer' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <tr><td colspan="8" class="text-center text-muted" style="padding:32px">Aucun utilisateur trouvé.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="users-pagination">
      <?php for ($page = 1; $page <= $totalPages; $page++): ?>
        <?php if ($page === $curPage): ?>
          <span class="users-page active"><?= $page ?></span>
        <?php else: ?>
          <a class="users-page" href="/admin/?page=users&q=<?= urlencode($search) ?>&role=<?= urlencode($roleFilt) ?>&status=<?= urlencode($statFilt) ?>&sort=<?= urlencode($sort) ?>&dir=<?= urlencode($dir) ?>&p=<?= $page ?>"><?= $page ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

  <?php if ($admin['role'] === 'admin'): ?>
    <div id="users-create-modal" class="users-modal">
      <div class="users-modal-box">
        <h3 style="font-size:22px;color:#183229;margin-bottom:14px">Créer un compte agent</h3>
        <form method="POST">
          <input type="hidden" name="action" value="create_agent">
          <div class="form-group">
            <label class="form-label">Nom complet</label>
            <input type="text" name="full_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control" required minlength="8">
          </div>
          <div class="form-group">
            <label class="form-label">Rôle</label>
            <select name="role" class="form-control">
              <option value="agent">Agent</option>
              <option value="admin">Administrateur</option>
            </select>
          </div>
          <div style="display:flex;justify-content:flex-end;gap:8px">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('users-create-modal').style.display='none'">Annuler</button>
            <button type="submit" class="btn btn-primary">Créer</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
