<?php
/**
 * Page d'administration : Sélection du pack d'icônes catégories.
 * Permet de visualiser et choisir entre les packs disponibles (Cayenne, Kourou).
 * Les images sont servies depuis le dossier artifacts (hôte) via base64 inline.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

// ── Configuration des packs ────────────────────────────────
$packs = [
    'kourou' => [
        'name'  => 'Kourou — Espace · Jungle · Vie',
        'desc'  => 'Identité visuelle inspirée du CSG, du fleuve Kourou et de la forêt amazonienne. Aquarelles tropicales avec une touche spatiale.',
        'color' => '#174b3a',
    ],
    'cayenne' => [
        'name'  => 'Cayenne — Capitale historique',
        'desc'  => 'Identité visuelle coloniale et tropicale, architecture créole et patrimoine de la capitale.',
        'color' => '#8B4513',
    ],
    '3d_clay' => [
        'name'  => 'Pâte à modeler — Dioramas 3D',
        'desc'  => 'Identité visuelle isométrique 3D inspirée des dioramas et de la pâte à modeler (Clay render). Coloré, chaleureux, ludique et très moderne.',
        'color' => '#3B82F6',
    ],
];

$categories = [
    1 => ['name' => 'Voirie & Chaussée',    'key' => 'voirie'],
    2 => ['name' => 'Éclairage Public',     'key' => 'eclairage'],
    3 => ['name' => 'Espaces Verts',        'key' => 'espaces_verts'],
    4 => ['name' => 'Propreté & Déchets',   'key' => 'proprete'],
    5 => ['name' => 'Mobilier Urbain',      'key' => 'mobilier'],
    6 => ['name' => 'Réseaux & Inondations','key' => 'reseaux'],
    7 => ['name' => 'Signalisation',        'key' => 'signalisation'],
    8 => ['name' => 'Bâtiments Communaux',  'key' => 'batiments'],
];

// Lire le pack actif depuis un fichier de config
$configFile = __DIR__ . '/../includes/.icon_pack_config.json';
$currentPack = 'kourou'; // Par défaut
if (is_file($configFile)) {
    $cfg = json_decode(file_get_contents($configFile), true);
    $currentPack = $cfg['active_pack'] ?? 'kourou';
}

// Sauvegarder le choix
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pack'])) {
    $chosen = $_POST['pack'];
    if (array_key_exists($chosen, $packs)) {
        $currentPack = $chosen;
        file_put_contents($configFile, json_encode([
            'active_pack' => $currentPack,
            'updated_at'  => date('c'),
            'updated_by'  => $admin['email'] ?? 'admin',
        ], JSON_PRETTY_PRINT));
    }
}

// Résoudre les chemins des images (dans admin/assets/img/icon-packs/)
$packBaseDir = dirname(__DIR__) . '/assets/img/icon-packs';

/**
 * Retourne une data URI base64 pour une image, ou un placeholder.
 */
function icon_data_uri(string $packDir, string $packId, int $catId): string {
    $filePath = $packDir . '/' . $packId . '/' . $catId . '.png';
    if (is_file($filePath)) {
        $data = file_get_contents($filePath);
        return 'data:image/png;base64,' . base64_encode($data);
    }
    return '';
}

/**
 * Retourne l'URL web pour une icône de pack.
 */
function icon_web_url(string $packId, int $catId): string {
    return '/admin/assets/img/icon-packs/' . rawurlencode($packId) . '/' . $catId . '.png';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Identité visuelle — Packs d'icônes</title>
<style>
  *,*::before,*::after { box-sizing: border-box; margin:0; padding:0; }
  body { font-family: 'Manrope','Segoe UI',sans-serif; background: #f5f2ed; color: #1a1a1a; }
  .page { max-width: 1060px; margin: 0 auto; padding: 32px 24px; }
  h1 { font-size: 24px; color: #174b3a; margin-bottom: 4px; }
  .subtitle { color: #666; font-size: 14px; margin-bottom: 28px; }

  .pack-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  .pack-card {
    background: #fff; border-radius: 20px; padding: 24px;
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
    border: 3px solid transparent;
    transition: border-color .2s, box-shadow .2s;
    position: relative;
  }
  .pack-card.active { border-color: #174b3a; box-shadow: 0 8px 32px rgba(23,75,58,.15); }
  .pack-card .badge-active {
    position: absolute; top: 16px; right: 16px;
    background: #174b3a; color: #fff; font-size: 11px; font-weight: 800;
    padding: 4px 12px; border-radius: 20px; text-transform: uppercase;
    letter-spacing: .5px; display: none;
  }
  .pack-card.active .badge-active { display: inline-block; }

  .pack-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
  .pack-header .dot { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; }
  .pack-header h2 { font-size: 17px; }
  .pack-desc { color: #666; font-size: 13px; line-height: 1.5; margin-bottom: 18px; }

  .icon-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; }
  .icon-cell { text-align: center; }
  .icon-cell img {
    width: 90px; height: 90px; border-radius: 50%; object-fit: cover;
    border: 2px solid #ece5d8; box-shadow: 0 4px 12px rgba(0,0,0,.08);
    transition: transform .2s;
  }
  .icon-cell img:hover { transform: scale(1.12); }
  .icon-cell span { display: block; font-size: 10.5px; color: #888; margin-top: 6px; font-weight: 600; }

  .btn-select {
    display: block; width: 100%; padding: 12px; border: none;
    border-radius: 14px; font-size: 14px; font-weight: 700;
    cursor: pointer; transition: background .2s, color .2s;
    text-align: center;
  }
  .btn-select--active {
    background: #e8f5e9; color: #174b3a; cursor: default;
  }
  .btn-select--inactive {
    background: #174b3a; color: #fff;
  }
  .btn-select--inactive:hover { background: #1e6b50; }

  .back-link { display: inline-block; margin-top: 24px; color: #174b3a; font-weight: 600; font-size: 14px; }

  .success-banner {
    background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
    padding: 14px 20px; border-radius: 14px; margin-bottom: 24px;
    font-weight: 600; color: #174b3a; font-size: 14px;
  }
</style>
</head>
<body>
<div class="page">
  <h1>🎨 Identité visuelle — Packs d'icônes</h1>
  <p class="subtitle">Choisissez le pack d'icônes catégories adapté à votre commune. Le pack sélectionné s'applique à l'ensemble du back-office et de l'application mobile.</p>

  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <div class="success-banner">✅ Pack « <?= htmlspecialchars($packs[$currentPack]['name']) ?> » activé avec succès.</div>
  <?php endif; ?>

  <div class="pack-grid">
  <?php foreach ($packs as $packId => $pack): ?>
    <div class="pack-card <?= $packId === $currentPack ? 'active' : '' ?>">
      <span class="badge-active">Actif</span>
      <div class="pack-header">
        <span class="dot" style="background:<?= $pack['color'] ?>"></span>
        <h2><?= htmlspecialchars($pack['name']) ?></h2>
      </div>
      <p class="pack-desc"><?= htmlspecialchars($pack['desc']) ?></p>

      <div class="icon-grid">
      <?php foreach ($categories as $catId => $cat):
        $webUrl = icon_web_url($packId, $catId);
        $dataUri = icon_data_uri($packBaseDir, $packId, $catId);
      ?>
        <div class="icon-cell">
          <?php if ($dataUri): ?>
            <img src="<?= $dataUri ?>" alt="<?= htmlspecialchars($cat['name']) ?>">
          <?php else: ?>
            <div style="width:90px;height:90px;border-radius:50%;background:#eee;margin:0 auto;display:flex;align-items:center;justify-content:center;font-size:24px;">?</div>
          <?php endif; ?>
          <span><?= htmlspecialchars($cat['name']) ?></span>
        </div>
      <?php endforeach; ?>
      </div>

      <form method="POST">
        <input type="hidden" name="pack" value="<?= $packId ?>">
        <?php if ($packId === $currentPack): ?>
          <button type="button" class="btn-select btn-select--active" disabled>✓ Pack actif</button>
        <?php else: ?>
          <button type="submit" class="btn-select btn-select--inactive">Activer ce pack</button>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
  </div>

  <a href="/admin/?page=dashboard" class="back-link">← Retour au tableau de bord</a>
</div>
</body>
</html>
