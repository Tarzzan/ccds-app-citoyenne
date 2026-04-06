<?php
/**
 * Copie les 8 icônes de catégories premium dans admin/assets/img/categories/
 * Et met à jour la BDD pour pointer vers les nouveaux fichiers.
 * Accès : http://localhost:8080/admin/pages/seed_category_icons.php
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

$destDir = __DIR__ . '/../assets/img/categories/';
if (!is_dir($destDir)) {
    mkdir($destDir, 0775, true);
}

// Source : les icônes servies via le VPS (elles seront uploadées manuellement)
// Fallback : générer des icônes GD colorées si les vrais fichiers ne sont pas dispo
$categories = [
    1 => ['name' => 'Voirie & Chaussée',    'color' => '#D96B2B', 'icon' => 'road'],
    2 => ['name' => 'Éclairage Public',     'color' => '#D9A22E', 'icon' => 'lightbulb'],
    3 => ['name' => 'Espaces Verts',        'color' => '#2E8B57', 'icon' => 'tree'],
    4 => ['name' => 'Propreté & Déchets',   'color' => '#7C4FD9', 'icon' => 'trash'],
    5 => ['name' => 'Mobilier Urbain',      'color' => '#287C96', 'icon' => 'bench'],
    6 => ['name' => 'Réseaux & Inondations','color' => '#2676D2', 'icon' => 'droplets'],
    7 => ['name' => 'Signalisation',        'color' => '#E07A22', 'icon' => 'triangle-alert'],
    8 => ['name' => 'Bâtiments Communaux',  'color' => '#5A6F7F', 'icon' => 'building-2'],
];

function hexToRgb(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
}

$log = [];
foreach ($categories as $id => $cat) {
    $filename = "category-{$id}-master.png";
    $dest = $destDir . $filename;

    // Essayer de copier depuis le VPS
    $vpsUrl = "https://admin.netetfix.com/uploads/demo-seed/{$filename}";
    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'MaCommuneRestore/1.0'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $bytes = @file_get_contents($vpsUrl, false, $ctx);

    if ($bytes !== false && strlen($bytes) > 1000) {
        file_put_contents($dest, $bytes);
        $log[] = ['id' => $id, 'name' => $cat['name'], 'status' => 'vps', 'size' => strlen($bytes)];
    } else {
        // Fallback : générer une icône GD premium
        $size = 512;
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);

        [$r, $g, $b] = hexToRgb($cat['color']);

        // Cercle de fond avec gradient
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $dx = $x - $size/2;
                $dy = $y - $size/2;
                $dist = sqrt($dx*$dx + $dy*$dy);
                $radius = $size * 0.44;
                if ($dist <= $radius) {
                    $f = $dist / $radius;
                    $cr = (int)min(255, $r + (255 - $r) * $f * 0.3);
                    $cg = (int)min(255, $g + (255 - $g) * $f * 0.3);
                    $cb = (int)min(255, $b + (255 - $b) * $f * 0.3);
                    $alpha = (int)max(0, min(127, ($dist > $radius - 3) ? (int)(($dist - ($radius - 3)) / 3 * 40) : 20));
                    $c = imagecolorallocatealpha($img, $cr, $cg, $cb, $alpha);
                    imagesetpixel($img, $x, $y, $c);
                }
            }
        }

        // Icône texte au centre
        $white = imagecolorallocate($img, 255, 255, 255);
        $iconText = strtoupper(substr($cat['icon'], 0, 3));
        $fs = 5;
        $tw = imagefontwidth($fs) * strlen($iconText);
        $th = imagefontheight($fs);
        imagestring($img, $fs, ($size - $tw) / 2, ($size - $th) / 2, $iconText, $white);

        imagepng($img, $dest, 6);
        imagedestroy($img);
        $log[] = ['id' => $id, 'name' => $cat['name'], 'status' => 'gd', 'size' => filesize($dest)];
    }
}

// Affichage
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><title>Category Icons</title></head>
<body style="font-family:sans-serif;padding:40px;max-width:900px;margin:auto;background:#f5f2ed;">
<h1>📦 Icônes de catégories générées</h1>
<p>Stockées dans <code>admin/assets/img/categories/</code></p>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-top:24px;">
<?php foreach ($log as $entry): ?>
<div style="text-align:center;padding:16px;background:#fff;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,.08);">
  <img src="/admin/assets/img/categories/category-<?= $entry['id'] ?>-master.png"
       style="width:100px;height:100px;border-radius:20px;object-fit:cover;margin-bottom:8px;" alt="">
  <div style="font-weight:700;font-size:13px;"><?= htmlspecialchars($entry['name']) ?></div>
  <div style="font-size:11px;color:#888;margin-top:4px;">
    <?= $entry['status'] === 'vps' ? '✅ VPS' : '🎨 GD' ?>
    · <?= round($entry['size']/1024) ?>K
  </div>
</div>
<?php endforeach; ?>
</div>
<p style="margin-top:24px;"><a href="/admin/?page=dashboard">← Retour au tableau de bord</a></p>
</body></html>
