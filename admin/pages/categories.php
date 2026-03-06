<?php
/**
 * CCDS v1.2 — Gestion des catégories (ADMIN-02)
 * CRUD complet : liste, création, modification, activation/désactivation, suppression.
 * Nouvelles colonnes : icône emoji, votes totaux, édition inline.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Catégories';
$active_nav = 'categories';

$db = Database::getInstance();

$success = '';
$error   = '';

// --- Traitement des actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Créer une catégorie
    if ($action === 'create') {
        $name    = trim($_POST['name']    ?? '');
        $icon    = trim($_POST['icon']    ?? '📌');
        $color   = trim($_POST['color']   ?? '#1d4ed8');
        $service = trim($_POST['service'] ?? '');

        if (strlen($name) < 2) {
            $error = 'Le nom doit contenir au moins 2 caractères.';
        } else {
            $check = $db->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
            $check->execute([$name]);
            if ($check->fetch()) {
                $error = "Une catégorie avec le nom \"$name\" existe déjà.";
            } else {
                $db->prepare(
                    'INSERT INTO categories (name, icon, color, service, is_active) VALUES (?, ?, ?, ?, 1)'
                )->execute([$name, $icon, $color, $service]);
                $success = "Catégorie \"$name\" créée avec succès.";
            }
        }
    }

    // Mettre à jour une catégorie
    elseif ($action === 'update') {
        $id      = (int)($_POST['id']      ?? 0);
        $name    = trim($_POST['name']     ?? '');
        $icon    = trim($_POST['icon']     ?? '📌');
        $color   = trim($_POST['color']    ?? '#1d4ed8');
        $service = trim($_POST['service']  ?? '');

        if ($id && strlen($name) >= 2) {
            $db->prepare(
                'UPDATE categories SET name = ?, icon = ?, color = ?, service = ? WHERE id = ?'
            )->execute([$name, $icon, $color, $service, $id]);
            $success = "Catégorie mise à jour.";
        } else {
            $error = 'Données invalides.';
        }
    }

    // Activer / Désactiver
    elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('UPDATE categories SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            $success = 'Statut de la catégorie mis à jour.';
        }
        header('Location: /admin/?page=categories'); exit;
    }

    // Supprimer
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $count = $db->prepare('SELECT COUNT(*) FROM incidents WHERE category_id = ?');
            $count->execute([$id]);
            if ((int)$count->fetchColumn() > 0) {
                $db->prepare('UPDATE categories SET is_active = 0 WHERE id = ?')->execute([$id]);
                $success = 'Catégorie désactivée (des signalements y sont associés, suppression impossible).';
            } else {
                $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                $success = 'Catégorie supprimée.';
            }
        }
        header('Location: /admin/?page=categories'); exit;
    }
}

// --- Récupérer toutes les catégories avec stats ---
$categories = $db->query("
    SELECT c.*,
           COUNT(i.id)                    AS incident_count,
           COALESCE(SUM(i.votes_count),0) AS total_votes
    FROM categories c
    LEFT JOIN incidents i ON i.category_id = c.id
    GROUP BY c.id
    ORDER BY c.is_active DESC, c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Catégorie en cours d'édition
$edit_id  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_cat = null;
if ($edit_id) {
    foreach ($categories as $cat) {
        if ((int)$cat['id'] === $edit_id) { $edit_cat = $cat; break; }
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>

<?php if ($success): ?>
  <div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;background:#dcfce7;border-radius:8px;color:#166534;border:1px solid #bbf7d0">
    ✅ <?= e($success) ?>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger" style="margin-bottom:16px;padding:12px 16px;background:#fee2e2;border-radius:8px;color:#991b1b;border:1px solid #fecaca">
    ❌ <?= e($error) ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;">

  <!-- Liste des catégories -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">🏷️ <?= count($categories) ?> catégorie<?= count($categories) > 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th style="width:50px">Icône</th>
            <th>Nom</th>
            <th>Service</th>
            <th>Couleur</th>
            <th>Signalements</th>
            <th>Votes</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <tr style="<?= !(bool)$cat['is_active'] ? 'opacity:.45' : '' ?>">
            <td style="font-size:24px;text-align:center"><?= e($cat['icon'] ?? '📌') ?></td>
            <td>
              <span style="font-weight:700;font-size:14px"><?= e($cat['name']) ?></span>
            </td>
            <td class="text-muted text-small"><?= e($cat['service'] ?? '—') ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <div style="width:22px;height:22px;border-radius:5px;background:<?= e($cat['color']) ?>;flex-shrink:0"></div>
                <code style="font-size:11px;color:#64748b"><?= e($cat['color']) ?></code>
              </div>
            </td>
            <td class="text-center">
              <?php if ($cat['incident_count'] > 0): ?>
                <a href="/admin/?page=incidents&cat=<?= $cat['id'] ?>"
                   class="badge badge-blue" style="text-decoration:none">
                  <?= $cat['incident_count'] ?>
                </a>
              <?php else: ?>
                <span class="text-muted">0</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($cat['total_votes'] > 0): ?>
                <span style="color:#f59e0b;font-weight:700">👍 <?= (int)$cat['total_votes'] ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge <?= $cat['is_active'] ? 'badge-green' : 'badge-gray' ?>">
                <?= $cat['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:nowrap">
                <a href="/admin/?page=categories&edit=<?= $cat['id'] ?>"
                   class="btn btn-outline btn-sm" title="Modifier">✏️</a>

                <form method="POST" action="" style="display:inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                  <button type="submit" class="btn btn-outline btn-sm"
                          title="<?= $cat['is_active'] ? 'Désactiver' : 'Activer' ?>">
                    <?= $cat['is_active'] ? '⏸️' : '▶️' ?>
                  </button>
                </form>

                <?php if ($cat['incident_count'] == 0): ?>
                <form method="POST" action="" style="display:inline"
                      onsubmit="return confirm('Supprimer la catégorie « <?= e($cat['name']) ?> » ? Cette action est irréversible.')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">🗑️</button>
                </form>
                <?php else: ?>
                  <button class="btn btn-outline btn-sm" disabled title="Impossible : des signalements utilisent cette catégorie" style="opacity:.3">🗑️</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($categories)): ?>
          <tr><td colspan="8" class="text-center text-muted" style="padding:40px">Aucune catégorie.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Formulaire création / édition -->
  <div class="card" style="position:sticky;top:80px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title"><?= $edit_cat ? '✏️ Modifier la catégorie' : '➕ Nouvelle catégorie' ?></span>
      <?php if ($edit_cat): ?>
        <a href="/admin/?page=categories" class="btn btn-outline btn-sm">✕ Annuler</a>
      <?php endif; ?>
    </div>
    <form method="POST" action="" style="padding:0 4px 4px">
      <input type="hidden" name="action" value="<?= $edit_cat ? 'update' : 'create' ?>">
      <?php if ($edit_cat): ?>
        <input type="hidden" name="id" value="<?= $edit_cat['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Nom <span style="color:#ef4444">*</span></label>
        <input type="text" name="name" class="form-control"
               value="<?= e($edit_cat['name'] ?? '') ?>"
               placeholder="Ex: Voirie, Éclairage public…" required maxlength="100">
      </div>

      <div class="form-group">
        <label class="form-label">Icône (emoji)</label>
        <input type="text" name="icon" class="form-control"
               value="<?= e($edit_cat['icon'] ?? '📌') ?>"
               placeholder="Ex: 🛣️ 💡 🌳 🚰"
               maxlength="10"
               style="font-size:22px;text-align:center;letter-spacing:4px">
        <div class="text-muted text-small" style="margin-top:4px">Copiez un emoji depuis votre clavier ou <a href="https://emojipedia.org" target="_blank">emojipedia.org</a></div>
      </div>

      <div class="form-group">
        <label class="form-label">Couleur d'identification</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" name="color" id="colorPicker"
                 value="<?= e($edit_cat['color'] ?? '#1d4ed8') ?>"
                 style="width:48px;height:40px;border:none;cursor:pointer;border-radius:8px;padding:2px">
          <input type="text" id="colorHexInput" class="form-control"
                 value="<?= e($edit_cat['color'] ?? '#1d4ed8') ?>"
                 placeholder="#1d4ed8" maxlength="7" style="flex:1;font-family:monospace">
        </div>
        <script>
          const picker = document.getElementById('colorPicker');
          const hexIn  = document.getElementById('colorHexInput');
          picker.addEventListener('input', () => hexIn.value = picker.value);
          hexIn.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(hexIn.value)) picker.value = hexIn.value; });
          // Synchroniser le champ caché
          hexIn.addEventListener('change', () => { picker.name = ''; hexIn.name = 'color'; });
        </script>
      </div>

      <div class="form-group">
        <label class="form-label">Service responsable</label>
        <input type="text" name="service" class="form-control"
               value="<?= e($edit_cat['service'] ?? '') ?>"
               placeholder="Ex: Direction des routes, DEAL…" maxlength="150">
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:8px">
        <?= $edit_cat ? '💾 Enregistrer les modifications' : '➕ Créer la catégorie' ?>
      </button>
    </form>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
