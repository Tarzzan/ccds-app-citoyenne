<?php
/**
 * Déploiement des icônes de catégories premium aquarelle.
 * Ce script encode les images générées en base64 et les écrit dans le volume uploads.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

// Destination writable dans le volume Docker
$destDir = '/var/www/backend/uploads/demo-seed/';
if (!is_dir($destDir)) {
    @mkdir($destDir, 0775, true);
}

$mapping = [
    1 => 'road',
    2 => 'lightbulb',
    3 => 'tree',
    4 => 'trash',
    5 => 'bench',
    6 => 'droplets',
    7 => 'triangle-alert',
    8 => 'building-2',
];

$catNames = [
    1 => 'Voirie & Chaussée',
    2 => 'Éclairage Public',
    3 => 'Espaces Verts',
    4 => 'Propreté & Déchets',
    5 => 'Mobilier Urbain',
    6 => 'Réseaux & Inondations',
    7 => 'Signalisation',
    8 => 'Bâtiments Communaux',
];

// Gestion upload POST
$log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['icons'])) {
    foreach ($_FILES['icons']['tmp_name'] as $idx => $tmpFile) {
        if (!is_uploaded_file($tmpFile)) continue;
        $catId = (int)$idx;
        if ($catId < 1 || $catId > 8) continue;
        $dest = $destDir . "category-{$catId}-master.png";
        if (move_uploaded_file($tmpFile, $dest)) {
            $log[] = ['id' => $catId, 'name' => $catNames[$catId], 'status' => 'ok', 'size' => filesize($dest)];
        } else {
            $log[] = ['id' => $catId, 'name' => $catNames[$catId], 'status' => 'error'];
        }
    }
}

// Vérifier ce qui existe déjà
$existing = [];
for ($i = 1; $i <= 8; $i++) {
    $f = $destDir . "category-{$i}-master.png";
    $existing[$i] = is_file($f) ? filesize($f) : false;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>Déploiement icônes catégories</title>
<style>
body { font-family: 'Manrope', sans-serif; padding: 40px; max-width: 960px; margin: auto; background: #f5f2ed; }
h1 { color: #174b3a; font-size: 22px; }
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 20px; }
.card { background: #fff; border-radius: 16px; padding: 16px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,.06); }
.card img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #ece4d5; }
.card h3 { font-size: 13px; margin: 10px 0 4px; }
.card small { color: #888; font-size: 11px; }
.status-ok { color: #2E8B57; font-weight: 700; }
.status-missing { color: #D96B2B; font-weight: 700; }
form { margin-top: 24px; padding: 20px; background: #fff; border-radius: 16px; }
input[type=file] { margin: 6px 0; }
button { background: #174b3a; color: #fff; border: none; padding: 12px 24px; border-radius: 12px; cursor: pointer; font-weight: 700; margin-top: 12px; }
</style>
</head>
<body>
<h1>📦 Icônes de catégories — État actuel</h1>

<?php if (!empty($log)): ?>
<div style="padding:12px 16px; background:#e8f5e9; border-radius:12px; margin-bottom:16px;">
  <strong>✅ <?= count($log) ?> icône(s) déployée(s)</strong>
</div>
<?php endif; ?>

<div class="grid">
<?php for ($i = 1; $i <= 8; $i++): ?>
<div class="card">
  <?php if ($existing[$i]): ?>
    <img src="/uploads/demo-seed/category-<?= $i ?>-master.png?t=<?= time() ?>" alt="">
    <h3><?= htmlspecialchars($catNames[$i]) ?></h3>
    <small class="status-ok">✓ <?= round($existing[$i]/1024) ?>K</small>
  <?php else: ?>
    <div style="width:100px;height:100px;border-radius:50%;background:#eee;margin:0 auto;display:flex;align-items:center;justify-content:center;">?</div>
    <h3><?= htmlspecialchars($catNames[$i]) ?></h3>
    <small class="status-missing">✗ Manquante</small>
  <?php endif; ?>
</div>
<?php endfor; ?>
</div>

<form method="POST" enctype="multipart/form-data">
  <h2 style="font-size:16px;margin-bottom:12px;">Uploader les icônes</h2>
  <?php for ($i = 1; $i <= 8; $i++): ?>
  <div style="margin-bottom:8px;">
    <label><strong><?= $i ?>.</strong> <?= htmlspecialchars($catNames[$i]) ?></label><br>
    <input type="file" name="icons[<?= $i ?>]" accept="image/png,image/jpeg">
  </div>
  <?php endfor; ?>
  <button type="submit">🚀 Déployer</button>
</form>

<p style="margin-top:20px;"><a href="/admin/?page=dashboard">← Retour au tableau de bord</a></p>
</body>
</html>
