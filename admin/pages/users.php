<?php
/**
 * CCDS Back-Office — Gestion avancée des utilisateurs (v1.4 — ADMIN-04)
 * Recherche, filtres par rôle/statut, pagination, tri, vue détail avec activité.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Utilisateurs';
$active_nav = 'users';
$db = Database::getInstance()->getConnection();

// ── Actions POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_agent' && $admin['role'] === 'admin') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $password  = trim($_POST['password']  ?? '');
        $role      = in_array($_POST['role'] ?? '', ['agent','admin']) ? $_POST['role'] : 'agent';
        if (!$full_name || !$email || !$password) {
            $_SESSION['flash_error'] = 'Tous les champs sont obligatoires.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Email invalide.';
        } else {
            $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
            $exists->execute([$email]);
            if ($exists->fetch()) {
                $_SESSION['flash_error'] = 'Cet email est déjà utilisé.';
            } else {
                $db->prepare("INSERT INTO users (full_name, email, password, role, is_active, created_at)
                              VALUES (?, ?, ?, ?, 1, NOW())")
                   ->execute([$full_name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                $_SESSION['flash_success'] = "Compte de $full_name créé avec succès.";
            }
        }
        header('Location: /admin/?page=users');
        exit;
    }

    if ($action === 'toggle_active' && $admin['role'] === 'admin') {
        $uid    = (int)($_POST['user_id']   ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        if ($uid && $uid !== (int)$admin['id']) {
            $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$active, $uid]);
            $_SESSION['flash_success'] = $active ? 'Compte activé.' : 'Compte désactivé.';
        }
        $back = $_POST['back'] ?? '/admin/?page=users';
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'change_role' && $admin['role'] === 'admin') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['citizen','agent','admin']) ? $_POST['role'] : 'citizen';
        if ($uid && $uid !== (int)$admin['id']) {
            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $uid]);
            $_SESSION['flash_success'] = 'Rôle modifié.';
        }
        header('Location: /admin/?page=users');
        exit;
    }
}

// ── Vue détail d'un utilisateur ──────────────────────────────
$detail_user    = null;
$user_incidents = [];
$user_activity  = [];

if (isset($_GET['detail'])) {
    $uid = (int)$_GET['detail'];
    $stmt = $db->prepare("
        SELECT u.*,
               COUNT(DISTINCT i.id)  AS incidents_count,
               COUNT(DISTINCT v.id)  AS votes_count,
               COUNT(DISTINCT c.id)  AS comments_count,
               COALESCE(g.points, 0) AS gamification_points
        FROM users u
        LEFT JOIN incidents i ON i.user_id = u.id
        LEFT JOIN votes     v ON v.user_id = u.id
        LEFT JOIN comments  c ON c.user_id = u.id
        LEFT JOIN user_gamification g ON g.user_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$uid]);
    $detail_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detail_user) {
        $stmtInc = $db->prepare("
            SELECT i.id, i.reference, i.title, i.status, i.votes_count, i.created_at,
                   cat.name AS category_name, cat.icon AS category_icon
            FROM incidents i JOIN categories cat ON cat.id = i.category_id
            WHERE i.user_id = ? ORDER BY i.created_at DESC LIMIT 10
        ");
        $stmtInc->execute([$uid]);
        $user_incidents = $stmtInc->fetchAll(PDO::FETCH_ASSOC);

        $stmtAct = $db->prepare("
            (SELECT 'incident' AS type, i.reference AS ref, i.title AS label, i.created_at AS date
             FROM incidents i WHERE i.user_id = ? ORDER BY i.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'comment', i.reference, LEFT(c.comment, 60), c.created_at
             FROM comments c JOIN incidents i ON i.id = c.incident_id WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'vote', i.reference, i.title, v.created_at
             FROM votes v JOIN incidents i ON i.id = v.incident_id WHERE v.user_id = ? ORDER BY v.created_at DESC LIMIT 10)
            ORDER BY date DESC LIMIT 20
        ");
        $stmtAct->execute([$uid, $uid, $uid]);
        $user_activity = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ── Filtres et pagination ────────────────────────────────────
$search   = trim($_GET['q']      ?? '');
$role_f   = $_GET['role']        ?? '';
$status_f = $_GET['status']      ?? '';
$sort     = in_array($_GET['sort'] ?? '', ['full_name','email','created_at','incidents_count']) ? $_GET['sort'] : 'created_at';
$dir      = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$per_page = 20;
$cur_page = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($cur_page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
if ($search) {
    $like = '%' . $search . '%';
    $where[]  = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($role_f && in_array($role_f, ['citizen','agent','admin'])) {
    $where[] = 'u.role = ?'; $params[] = $role_f;
}
if ($status_f === 'active')   { $where[] = 'u.is_active = 1'; }
if ($status_f === 'inactive') { $where[] = 'u.is_active = 0'; }

$whereSQL = implode(' AND ', $where);

$stmtCount = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSQL");
$stmtCount->execute($params);
$total       = (int)$stmtCount->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));

$sortSQL = match($sort) {
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
    LEFT JOIN votes     v ON v.user_id = u.id
    WHERE $whereSQL
    GROUP BY u.id
    ORDER BY $sortSQL $dir
    LIMIT $per_page OFFSET $offset
");
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs globaux ─────────────────────────────────────────────
$kpis = $db->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(role='citizen')                                   AS citizens,
        SUM(role='agent')                                     AS agents,
        SUM(role='admin')                                     AS admins,
        SUM(is_active=1)                                      AS active,
        SUM(created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY))   AS new_30d
    FROM users
")->fetch(PDO::FETCH_ASSOC);

function role_badge_v14(string $role): string {
    return match($role) {
        'admin'   => '<span class="badge14 badge14-red">Admin</span>',
        'agent'   => '<span class="badge14 badge14-blue">Agent</span>',
        default   => '<span class="badge14 badge14-gray">Citoyen</span>',
    };
}
function status_badge_v14(bool $active): string {
    return $active
        ? '<span class="badge14 badge14-green">Actif</span>'
        : '<span class="badge14 badge14-gray">Inactif</span>';
}
function activity_icon_v14(string $type): string {
    return match($type) { 'incident' => '📍', 'comment' => '💬', 'vote' => '👍', default => '•' };
}
function sort_url(string $col, string $cur, string $curDir, array $extra = []): string {
    $newDir = ($col === $cur && $curDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($extra, ['sort' => $col, 'dir' => $newDir]);
    return '/admin/?page=users&' . http_build_query($params);
}

require_once __DIR__ . '/../includes/layout.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Utilisateurs — CCDS Admin</title>
<style>
.kpi-row14{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:24px}
.kpi14{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center}
.kpi14 .v{font-size:2rem;font-weight:700;color:#1d4ed8}
.kpi14 .l{font-size:.72rem;color:#6b7280;margin-top:3px}
.filters14{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:center}
.filters14 input,.filters14 select{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.875rem}
.filters14 .btn-f{padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:.875rem}
.tbl14{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.tbl14 th{background:#f9fafb;padding:11px 14px;text-align:left;font-size:.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.tbl14 td{padding:11px 14px;border-top:1px solid #f3f4f6;font-size:.875rem;vertical-align:middle}
.tbl14 tr:hover td{background:#f9fafb}
.badge14{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.68rem;font-weight:600}
.badge14-red{background:#fee2e2;color:#b91c1c}
.badge14-blue{background:#dbeafe;color:#1d4ed8}
.badge14-green{background:#dcfce7;color:#15803d}
.badge14-gray{background:#f3f4f6;color:#6b7280}
.btn14{padding:5px 11px;border-radius:6px;font-size:.75rem;cursor:pointer;border:1px solid #d1d5db;background:#fff;text-decoration:none;color:#374151;display:inline-block}
.btn14-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn14-danger{background:#ef4444;color:#fff;border-color:#ef4444}
.btn14-success{background:#16a34a;color:#fff;border-color:#16a34a}
.pag14{display:flex;gap:6px;justify-content:center;margin-top:20px;flex-wrap:wrap}
.pag14 a,.pag14 span{padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;text-decoration:none;color:#374151}
.pag14 .cur{background:#2563eb;color:#fff;border-color:#2563eb}
/* Détail */
.detail14{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:24px}
.dstats14{display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:10px;margin:16px 0}
.dstat14{background:#f9fafb;border-radius:8px;padding:12px;text-align:center}
.dstat14 .v{font-size:1.5rem;font-weight:700;color:#1d4ed8}
.dstat14 .l{font-size:.68rem;color:#6b7280}
.act-list{list-style:none;padding:0;margin:0}
.act-list li{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid #f3f4f6;font-size:.8rem}
.act-list li:last-child{border-bottom:none}
.sort-a{color:#374151;text-decoration:none}
.sort-a:hover{color:#2563eb}
.users-header14{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px}
/* Modal */
.modal14{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.modal14-box{background:#fff;border-radius:12px;padding:28px;width:400px;max-width:90vw}
.form14-group{margin-bottom:14px}
.form14-group label{display:block;font-size:.875rem;margin-bottom:4px;font-weight:500}
.form14-group input,.form14-group select{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:.875rem}
</style>
</head>
<body>
<?php render_layout_header($admin, $page_title); ?>
<div class="admin-content">

<?php if ($detail_user): ?>
<!-- ────────────────── VUE DÉTAIL ────────────────── -->
<div class="detail14">
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;flex-wrap:wrap">
        <div style="width:52px;height:52px;border-radius:50%;background:#dbeafe;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;color:#1d4ed8;flex-shrink:0">
            <?= strtoupper(mb_substr($detail_user['full_name'],0,1)) ?>
        </div>
        <div>
            <h2 style="margin:0;font-size:1.2rem"><?= e($detail_user['full_name']) ?></h2>
            <p style="margin:3px 0;color:#6b7280;font-size:.875rem"><?= e($detail_user['email']) ?></p>
            <div style="display:flex;gap:6px;margin-top:5px">
                <?= role_badge_v14($detail_user['role']) ?>
                <?= status_badge_v14((bool)$detail_user['is_active']) ?>
            </div>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($admin['role']==='admin' && $detail_user['id']!==$admin['id']): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= $detail_user['id'] ?>">
                <input type="hidden" name="is_active" value="<?= $detail_user['is_active']?0:1 ?>">
                <input type="hidden" name="back" value="/admin/?page=users&detail=<?= $detail_user['id'] ?>">
                <button type="submit" class="btn14 <?= $detail_user['is_active']?'btn14-danger':'btn14-success' ?>">
                    <?= $detail_user['is_active']?'Désactiver':'Activer' ?>
                </button>
            </form>
            <?php endif; ?>
            <a href="/admin/?page=users" class="btn14">← Retour à la liste</a>
        </div>
    </div>

    <div class="dstats14">
        <div class="dstat14"><div class="v"><?= $detail_user['incidents_count'] ?></div><div class="l">Signalements</div></div>
        <div class="dstat14"><div class="v"><?= $detail_user['votes_count'] ?></div><div class="l">Votes</div></div>
        <div class="dstat14"><div class="v"><?= $detail_user['comments_count'] ?></div><div class="l">Commentaires</div></div>
        <div class="dstat14"><div class="v"><?= $detail_user['gamification_points'] ?></div><div class="l">Points</div></div>
        <div class="dstat14"><div class="v"><?= date('d/m/y',strtotime($detail_user['created_at'])) ?></div><div class="l">Inscrit le</div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
        <div>
            <h4 style="font-size:.875rem;margin-bottom:10px">📍 Derniers signalements</h4>
            <?php if ($user_incidents): ?>
            <table style="width:100%;font-size:.78rem;border-collapse:collapse">
                <tr style="color:#9ca3af"><th style="text-align:left;padding:3px 0">Réf.</th><th style="text-align:left;padding:3px 6px">Titre</th><th>Votes</th></tr>
                <?php foreach ($user_incidents as $inc): ?>
                <tr>
                    <td style="padding:4px 0"><a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" style="color:#2563eb;text-decoration:none"><?= e($inc['reference']) ?></a></td>
                    <td style="padding:4px 6px"><?= e(mb_strimwidth($inc['title'],0,28,'…')) ?></td>
                    <td style="text-align:center"><?= $inc['votes_count'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?><p style="color:#9ca3af;font-size:.8rem">Aucun signalement.</p><?php endif; ?>
        </div>
        <div>
            <h4 style="font-size:.875rem;margin-bottom:10px">🕐 Activité récente</h4>
            <ul class="act-list">
                <?php foreach ($user_activity as $act): ?>
                <li>
                    <span><?= activity_icon_v14($act['type']) ?></span>
                    <div>
                        <div style="font-weight:500"><?= e(mb_strimwidth($act['label'],0,45,'…')) ?></div>
                        <div style="color:#9ca3af"><?= e($act['ref']) ?> · <?= date('d/m/Y H:i',strtotime($act['date'])) ?></div>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if (!$user_activity): ?><li><span style="color:#9ca3af">Aucune activité.</span></li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ────────────────── LISTE ────────────────── -->

<!-- KPIs -->
<div class="kpi-row14">
    <div class="kpi14"><div class="v"><?= $kpis['total'] ?></div><div class="l">Total</div></div>
    <div class="kpi14"><div class="v"><?= $kpis['citizens'] ?></div><div class="l">Citoyens</div></div>
    <div class="kpi14"><div class="v"><?= $kpis['agents'] ?></div><div class="l">Agents</div></div>
    <div class="kpi14"><div class="v"><?= $kpis['active'] ?></div><div class="l">Actifs</div></div>
    <div class="kpi14"><div class="v">+<?= $kpis['new_30d'] ?></div><div class="l">Nouveaux (30j)</div></div>
</div>

<!-- En-tête -->
<div class="users-header14">
    <h2 style="margin:0;font-size:1.2rem">Gestion des utilisateurs</h2>
    <?php if ($admin['role']==='admin'): ?>
    <button onclick="document.getElementById('modal-create').style.display='flex'" class="btn14 btn14-primary">+ Créer un agent</button>
    <?php endif; ?>
</div>

<!-- Filtres -->
<form method="GET" action="/admin/" class="filters14">
    <input type="hidden" name="page" value="users">
    <input type="text" name="q" placeholder="🔍 Nom, email, téléphone…" value="<?= e($search) ?>">
    <select name="role">
        <option value="">Tous les rôles</option>
        <option value="citizen" <?= $role_f==='citizen'?'selected':'' ?>>Citoyen</option>
        <option value="agent"   <?= $role_f==='agent'  ?'selected':'' ?>>Agent</option>
        <option value="admin"   <?= $role_f==='admin'  ?'selected':'' ?>>Admin</option>
    </select>
    <select name="status">
        <option value="">Tous les statuts</option>
        <option value="active"   <?= $status_f==='active'  ?'selected':'' ?>>Actifs</option>
        <option value="inactive" <?= $status_f==='inactive'?'selected':'' ?>>Inactifs</option>
    </select>
    <button type="submit" class="btn-f">Filtrer</button>
    <?php if ($search||$role_f||$status_f): ?>
    <a href="/admin/?page=users" style="padding:8px 12px;color:#6b7280;font-size:.875rem;text-decoration:none">✕ Réinitialiser</a>
    <?php endif; ?>
    <span style="margin-left:auto;color:#6b7280;font-size:.8rem"><?= $total ?> résultat<?= $total>1?'s':'' ?></span>
</form>

<!-- Tableau -->
<?php
$extra = ['q'=>$search,'role'=>$role_f,'status'=>$status_f,'p'=>$cur_page];
?>
<table class="tbl14">
    <thead>
        <tr>
            <th><a href="<?= sort_url('full_name',$sort,$dir,$extra) ?>" class="sort-a">Nom <?= $sort==='full_name'?($dir==='ASC'?'↑':'↓'):'' ?></a></th>
            <th><a href="<?= sort_url('email',$sort,$dir,$extra) ?>" class="sort-a">Email <?= $sort==='email'?($dir==='ASC'?'↑':'↓'):'' ?></a></th>
            <th>Rôle</th><th>Statut</th>
            <th><a href="<?= sort_url('incidents_count',$sort,$dir,$extra) ?>" class="sort-a">Signalements <?= $sort==='incidents_count'?($dir==='ASC'?'↑':'↓'):'' ?></a></th>
            <th>Votes</th>
            <th><a href="<?= sort_url('created_at',$sort,$dir,$extra) ?>" class="sort-a">Inscrit le <?= $sort==='created_at'?($dir==='ASC'?'↑':'↓'):'' ?></a></th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td>
                <a href="/admin/?page=users&detail=<?= $u['id'] ?>" style="font-weight:600;color:#111827;text-decoration:none"><?= e($u['full_name']) ?></a>
                <?php if ($u['phone']): ?><div style="font-size:.7rem;color:#9ca3af"><?= e($u['phone']) ?></div><?php endif; ?>
            </td>
            <td style="color:#6b7280"><?= e($u['email']) ?></td>
            <td><?= role_badge_v14($u['role']) ?></td>
            <td><?= status_badge_v14((bool)$u['is_active']) ?></td>
            <td style="text-align:center"><?= $u['incidents_count'] ?></td>
            <td style="text-align:center"><?= $u['votes_count'] ?></td>
            <td style="color:#9ca3af;font-size:.8rem"><?= date('d/m/Y',strtotime($u['created_at'])) ?></td>
            <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                    <a href="/admin/?page=users&detail=<?= $u['id'] ?>" class="btn14">Détail</a>
                    <?php if ($admin['role']==='admin' && $u['id']!==$admin['id']): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="is_active" value="<?= $u['is_active']?0:1 ?>">
                        <button type="submit" class="btn14 <?= $u['is_active']?'btn14-danger':'btn14-success' ?>" style="font-size:.7rem">
                            <?= $u['is_active']?'Désactiver':'Activer' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
        <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:32px">Aucun utilisateur trouvé.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages>1): ?>
<div class="pag14">
    <?php for ($p=1;$p<=$total_pages;$p++): ?>
    <?php if ($p===$cur_page): ?>
    <span class="cur"><?= $p ?></span>
    <?php else: ?>
    <a href="/admin/?page=users&q=<?= urlencode($search) ?>&role=<?= $role_f ?>&status=<?= $status_f ?>&sort=<?= $sort ?>&dir=<?= $dir ?>&p=<?= $p ?>"><?= $p ?></a>
    <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
</div>

<!-- Modal Créer un agent -->
<?php if ($admin['role']==='admin'): ?>
<div id="modal-create" class="modal14">
    <div class="modal14-box">
        <h3 style="margin:0 0 18px">Créer un compte agent</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_agent">
            <div class="form14-group"><label>Nom complet</label><input type="text" name="full_name" required></div>
            <div class="form14-group"><label>Email</label><input type="email" name="email" required></div>
            <div class="form14-group"><label>Mot de passe</label><input type="password" name="password" required minlength="8"></div>
            <div class="form14-group"><label>Rôle</label>
                <select name="role"><option value="agent">Agent</option><option value="admin">Admin</option></select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
                <button type="button" onclick="document.getElementById('modal-create').style.display='none'" class="btn14">Annuler</button>
                <button type="submit" class="btn14 btn14-primary">Créer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php render_layout_footer(); ?>
</body>
</html>
