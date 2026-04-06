<?php
/**
 * Restaure les vraies photos OpenAI depuis le VPS netetfix.com
 * Écrit dans admin/assets/demo-photos/ (bind mount, pas de problème de permissions)
 * puis adapte le chemin d'accès pour l'affichage.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

$page_title = 'Restauration photos OpenAI';
$active_nav = 'incidents';
require_once __DIR__ . '/../includes/layout.php';

// Écrire dans admin/assets/ qui est bind-mounted et accessible en écriture
$localDir = __DIR__ . '/../assets/demo-photos/';
if (!is_dir($localDir)) {
    mkdir($localDir, 0775, true);
}

// Aussi tenter le dossier uploads (peut échouer si permissions Docker)
$uploadsDir = __DIR__ . '/../../backend/uploads/demo-seed/';
$uploadsWritable = false;
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0777, true);
}
// Tester l'écriture
$testFile = $uploadsDir . '.write-test';
if (@file_put_contents($testFile, 'ok') !== false) {
    @unlink($testFile);
    $uploadsWritable = true;
}

$vpsBase = 'https://admin.netetfix.com/uploads/demo-seed/';

$files = [];
// Catégories master
for ($i = 1; $i <= 8; $i++) {
    $files[] = "category-{$i}-master.png";
}
// Photos citoyennes
for ($i = 1; $i <= 25; $i++) {
    $files[] = sprintf('mc-2026-%05d-citizen-photo.png', $i);
}

$copied = 0;
$errors = [];
$log = [];

foreach ($files as $filename) {
    $srcUrl = $vpsBase . $filename;

    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'MaCommuneRestore/1.0'],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $bytes = @file_get_contents($srcUrl, false, $ctx);

    if ($bytes === false || strlen($bytes) < 1000) {
        $errors[] = $filename;
        $log[] = ['file' => $filename, 'status' => 'error', 'size' => 0, 'dest' => '—'];
        continue;
    }

    $saved = false;
    $dest = '';

    // Priorité 1 : écrire dans uploads/ (volume Docker)
    if ($uploadsWritable) {
        $destPath = $uploadsDir . $filename;
        if (@file_put_contents($destPath, $bytes) !== false) {
            $saved = true;
            $dest = 'uploads';
        }
    }

    // Priorité 2 : écrire dans admin/assets/ (bind mount)
    $assetPath = $localDir . $filename;
    if (@file_put_contents($assetPath, $bytes) !== false) {
        if (!$saved) {
            $saved = true;
            $dest = 'assets';
        } else {
            $dest = 'both';
        }
    }

    if ($saved) {
        $copied++;
        $log[] = ['file' => $filename, 'status' => 'ok', 'size' => strlen($bytes), 'dest' => $dest];
    } else {
        $errors[] = $filename;
        $log[] = ['file' => $filename, 'status' => 'error', 'size' => 0, 'dest' => '—'];
    }
}
?>

<div class="card dashboard-section-card" style="margin:32px auto;max-width:960px;padding:32px;">
  <h2 style="margin-bottom:8px;">📷 Restauration photos VPS → Local</h2>
  <p class="text-muted" style="margin-bottom:4px;">
    Source : <code><?= htmlspecialchars($vpsBase) ?></code>
  </p>
  <p class="text-muted" style="margin-bottom:20px;">
    Volume uploads : <?= $uploadsWritable ? '<span style="color:green">✅ accessible</span>' : '<span style="color:red">❌ lecture seule</span>' ?>
    · Assets : <span style="color:green">✅ accessible</span>
  </p>

  <?php if ($copied > 0 && empty($errors)): ?>
  <div class="services-alert is-info" style="margin-bottom:20px;">
    <strong>✅ <?= $copied ?> photos restaurées avec succès !</strong>
    <div>Les vraies images OpenAI sont maintenant disponibles.</div>
  </div>
  <?php elseif ($copied > 0): ?>
  <div class="services-alert is-warning" style="margin-bottom:20px;">
    <strong>⚠️ <?= $copied ?> restaurées, <?= count($errors) ?> erreurs</strong>
  </div>
  <?php else: ?>
  <div class="services-alert" style="margin-bottom:20px;border-left:4px solid red;padding:16px;">
    <strong>❌ Échec total — vérifiez la connectivité réseau du conteneur</strong>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:20px;margin-bottom:24px;">
    <div class="hero-chip">
      <span class="hero-chip-value" style="color:var(--color-success)"><?= $copied ?></span>
      <span class="hero-chip-label">restaurées</span>
    </div>
    <?php if ($errors): ?>
    <div class="hero-chip">
      <span class="hero-chip-value" style="color:var(--color-danger)"><?= count($errors) ?></span>
      <span class="hero-chip-label">erreurs</span>
    </div>
    <?php endif; ?>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead><tr>
      <th style="text-align:left;padding:6px 8px;border-bottom:2px solid var(--border-color)">Fichier</th>
      <th style="padding:6px 8px;border-bottom:2px solid var(--border-color)">Statut</th>
      <th style="padding:6px 8px;border-bottom:2px solid var(--border-color)">Dest</th>
      <th style="text-align:right;padding:6px 8px;border-bottom:2px solid var(--border-color)">Taille</th>
      <th style="padding:6px 8px;border-bottom:2px solid var(--border-color)">Aperçu</th>
    </tr></thead>
    <tbody>
    <?php foreach ($log as $entry): ?>
    <tr style="border-top:1px solid var(--border-color)">
      <td style="padding:4px 8px;"><code style="font-size:10px;"><?= htmlspecialchars($entry['file']) ?></code></td>
      <td style="padding:4px 8px;text-align:center;">
        <?= $entry['status'] === 'ok' ? '<span class="badge badge-green">✅</span>' : '<span class="badge badge-red">❌</span>' ?>
      </td>
      <td style="padding:4px 8px;text-align:center;font-size:10px;"><?= $entry['dest'] ?></td>
      <td style="padding:4px 8px;text-align:right;"><?= $entry['size'] > 0 ? round($entry['size']/1024).'K' : '—' ?></td>
      <td style="padding:4px 8px;text-align:center;">
        <?php if ($entry['status'] === 'ok'): ?>
          <?php
            // Chercher la meilleure URL d'affichage
            $previewUrl = file_exists($uploadsDir . $entry['file'])
              ? '/uploads/demo-seed/' . $entry['file']
              : '/admin/assets/demo-photos/' . $entry['file'];
          ?>
          <img src="<?= htmlspecialchars($previewUrl) ?>" style="height:40px;border-radius:4px;object-fit:cover;" alt="">
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:24px;display:flex;gap:12px;">
    <a href="/admin/?page=incidents" class="btn btn-primary">← Signalements</a>
    <a href="/admin/?page=incident_detail&id=1" class="btn btn-outline">Incident #1</a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
