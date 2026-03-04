<?php
/**
 * CCDS Back-Office — Recherche globale (v1.4 — ADMIN-06)
 * Recherche simultanée dans incidents, utilisateurs et catégories.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Recherche';
$active_nav = 'search';
$db = Database::getInstance()->getConnection();

$query   = trim($_GET['q'] ?? '');
$results = ['incidents' => [], 'users' => [], 'categories' => []];
$total   = 0;

if (strlen($query) >= 2) {
    $like = '%' . $query . '%';

    // ── Incidents ─────────────────────────────────────────────
    $stmtInc = $db->prepare("
        SELECT i.id, i.reference, i.title, i.status, i.votes_count, i.created_at,
               cat.name AS category_name, cat.icon AS category_icon,
               u.full_name AS reporter_name
        FROM incidents i
        JOIN categories cat ON cat.id = i.category_id
        JOIN users u ON u.id = i.user_id
        WHERE i.reference LIKE ? OR i.title LIKE ? OR i.description LIKE ? OR i.address LIKE ?
        ORDER BY i.created_at DESC
        LIMIT 10
    ");
    $stmtInc->execute([$like, $like, $like, $like]);
    $results['incidents'] = $stmtInc->fetchAll(PDO::FETCH_ASSOC);

    // ── Utilisateurs ──────────────────────────────────────────
    $stmtUsr = $db->prepare("
        SELECT id, full_name, email, phone, role, is_active, created_at,
               (SELECT COUNT(*) FROM incidents WHERE user_id = users.id) AS incidents_count
        FROM users
        WHERE full_name LIKE ? OR email LIKE ? OR phone LIKE ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmtUsr->execute([$like, $like, $like]);
    $results['users'] = $stmtUsr->fetchAll(PDO::FETCH_ASSOC);

    // ── Catégories ────────────────────────────────────────────
    $stmtCat = $db->prepare("
        SELECT c.id, c.name, c.icon, c.color, c.is_active,
               COUNT(i.id) AS incidents_count
        FROM categories c
        LEFT JOIN incidents i ON i.category_id = c.id
        WHERE c.name LIKE ?
        GROUP BY c.id
        ORDER BY incidents_count DESC
        LIMIT 5
    ");
    $stmtCat->execute([$like]);
    $results['categories'] = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

    $total = count($results['incidents']) + count($results['users']) + count($results['categories']);
}

function status_label_search(string $s): string {
    return match($s) {
        'submitted'   => 'Soumis',
        'in_progress' => 'En cours',
        'resolved'    => 'Résolu',
        'rejected'    => 'Rejeté',
        default       => $s,
    };
}
function status_color_search(string $s): string {
    return match($s) {
        'submitted'   => '#f59e0b',
        'in_progress' => '#3b82f6',
        'resolved'    => '#22c55e',
        'rejected'    => '#ef4444',
        default       => '#6b7280',
    };
}

require_once __DIR__ . '/../includes/layout.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Recherche — CCDS Admin</title>
<style>
.search-hero{background:linear-gradient(135deg,#1d4ed8,#3b82f6);border-radius:12px;padding:28px;margin-bottom:24px;color:#fff}
.search-hero h2{margin:0 0 12px;font-size:1.4rem}
.search-form{display:flex;gap:10px}
.search-form input{flex:1;padding:12px 16px;border:none;border-radius:8px;font-size:1rem;outline:none}
.search-form button{padding:12px 20px;background:#fff;color:#1d4ed8;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:.9rem}
.results-meta{color:#6b7280;font-size:.875rem;margin-bottom:20px}
.section-title{font-size:1rem;font-weight:700;color:#111827;margin:24px 0 12px;display:flex;align-items:center;gap:8px}
.section-count{background:#e5e7eb;color:#374151;border-radius:20px;padding:1px 8px;font-size:.72rem;font-weight:600}
.result-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;margin-bottom:10px;display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit;transition:border-color .15s}
.result-card:hover{border-color:#3b82f6;background:#f0f7ff}
.result-icon{width:38px;height:38px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.result-main{flex:1;min-width:0}
.result-title{font-weight:600;font-size:.9rem;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.result-sub{font-size:.78rem;color:#6b7280;margin-top:2px}
.result-badge{padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:600;white-space:nowrap}
.empty-state{text-align:center;padding:48px;color:#9ca3af}
.empty-state .icon{font-size:3rem;margin-bottom:12px}
.highlight{background:#fef9c3;border-radius:2px;padding:0 2px}
</style>
</head>
<body>
<?php render_layout_header($admin, $page_title); ?>
<div class="admin-content">

<!-- Hero de recherche -->
<div class="search-hero">
    <h2>🔍 Recherche globale</h2>
    <form method="GET" action="/admin/" class="search-form">
        <input type="hidden" name="page" value="search">
        <input type="text" name="q" placeholder="Référence, titre, email, nom…"
               value="<?= e($query) ?>" autofocus autocomplete="off">
        <button type="submit">Rechercher</button>
    </form>
</div>

<?php if ($query && strlen($query) < 2): ?>
<p style="color:#ef4444;font-size:.875rem">Saisissez au moins 2 caractères.</p>

<?php elseif ($query && $total === 0): ?>
<div class="empty-state">
    <div class="icon">🔎</div>
    <p>Aucun résultat pour <strong>"<?= e($query) ?>"</strong></p>
    <p style="font-size:.8rem">Essayez une référence (ex: INC-2026-001), un email ou un titre partiel.</p>
</div>

<?php elseif ($query): ?>
<p class="results-meta"><?= $total ?> résultat<?= $total>1?'s':'' ?> pour <strong>"<?= e($query) ?>"</strong></p>

<!-- Incidents -->
<?php if ($results['incidents']): ?>
<div class="section-title">📍 Signalements <span class="section-count"><?= count($results['incidents']) ?></span></div>
<?php foreach ($results['incidents'] as $inc): ?>
<a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="result-card">
    <div class="result-icon" style="background:#eff6ff"><?= e($inc['category_icon']) ?></div>
    <div class="result-main">
        <div class="result-title"><?= e($inc['reference']) ?> — <?= e($inc['title']) ?></div>
        <div class="result-sub"><?= e($inc['category_name']) ?> · <?= e($inc['reporter_name']) ?> · <?= date('d/m/Y', strtotime($inc['created_at'])) ?></div>
    </div>
    <span class="result-badge" style="background:<?= status_color_search($inc['status']) ?>22;color:<?= status_color_search($inc['status']) ?>">
        <?= status_label_search($inc['status']) ?>
    </span>
    <?php if ($inc['votes_count']): ?>
    <span style="font-size:.78rem;color:#6b7280">👍 <?= $inc['votes_count'] ?></span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
<?php endif; ?>

<!-- Utilisateurs -->
<?php if ($results['users']): ?>
<div class="section-title">👤 Utilisateurs <span class="section-count"><?= count($results['users']) ?></span></div>
<?php foreach ($results['users'] as $u): ?>
<a href="/admin/?page=users&detail=<?= $u['id'] ?>" class="result-card">
    <div class="result-icon" style="background:#f0fdf4;font-size:1.2rem;font-weight:700;color:#15803d">
        <?= strtoupper(mb_substr($u['full_name'],0,1)) ?>
    </div>
    <div class="result-main">
        <div class="result-title"><?= e($u['full_name']) ?></div>
        <div class="result-sub"><?= e($u['email']) ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?> · <?= $u['incidents_count'] ?> signalement<?= $u['incidents_count']>1?'s':'' ?></div>
    </div>
    <span class="result-badge" style="background:<?= $u['role']==='admin'?'#fee2e2':($u['role']==='agent'?'#dbeafe':'#f3f4f6') ?>;color:<?= $u['role']==='admin'?'#b91c1c':($u['role']==='agent'?'#1d4ed8':'#6b7280') ?>">
        <?= ucfirst($u['role']) ?>
    </span>
    <?php if (!$u['is_active']): ?>
    <span class="result-badge" style="background:#f3f4f6;color:#9ca3af">Inactif</span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
<?php endif; ?>

<!-- Catégories -->
<?php if ($results['categories']): ?>
<div class="section-title">🏷️ Catégories <span class="section-count"><?= count($results['categories']) ?></span></div>
<?php foreach ($results['categories'] as $cat): ?>
<a href="/admin/?page=categories" class="result-card">
    <div class="result-icon" style="background:<?= e($cat['color']) ?>22"><?= e($cat['icon']) ?></div>
    <div class="result-main">
        <div class="result-title"><?= e($cat['name']) ?></div>
        <div class="result-sub"><?= $cat['incidents_count'] ?> signalement<?= $cat['incidents_count']>1?'s':'' ?></div>
    </div>
    <span class="result-badge" style="background:<?= $cat['is_active']?'#dcfce7':'#f3f4f6' ?>;color:<?= $cat['is_active']?'#15803d':'#9ca3af' ?>">
        <?= $cat['is_active']?'Active':'Inactive' ?>
    </span>
</a>
<?php endforeach; ?>
<?php endif; ?>

<?php else: ?>
<!-- État initial -->
<div class="empty-state">
    <div class="icon">🔍</div>
    <p style="font-size:1rem;color:#6b7280">Saisissez un terme pour rechercher dans les signalements, utilisateurs et catégories.</p>
    <p style="font-size:.8rem;color:#9ca3af">Exemples : INC-2026-001, jean.dupont@email.com, Voirie</p>
</div>
<?php endif; ?>

</div>
<?php render_layout_footer(); ?>
</body>
</html>
