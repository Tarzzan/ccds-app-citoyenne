<?php
/**
 * Ma Commune v1.2 — Gestion des catégories (ADMIN-02)
 * CRUD complet : liste, création, modification, activation/désactivation, suppression.
 * Nouvelles colonnes : univers visuel, votes totaux, édition inline.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Catégories';
$active_nav = 'categories';

$db = Database::getInstance();
$isAdmin = ($admin['role'] ?? '') === 'admin';
$service_tables_ready = admin_db_has_table($db, 'services') && admin_db_has_table($db, 'service_category_map');
$services = $service_tables_ready ? intervention_get_services($db) : [];

$success = '';
$error   = '';

// --- Traitement des actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$isAdmin) {
        $_SESSION['flash_error'] = 'Cette page est en lecture seule pour votre role. Les modifications de categories sont reservees aux administrateurs.';
        header('Location: /admin/?page=categories');
        exit;
    }

    // Créer une catégorie
    if ($action === 'create' && $isAdmin) {
        $name    = trim($_POST['name']    ?? '');
        $icon    = trim($_POST['icon']    ?? 'road');
        $color   = trim($_POST['color']   ?? '#1d4ed8');
        $service = trim($_POST['service'] ?? '');
        $serviceId = $service_tables_ready ? (int)($_POST['service_id'] ?? 0) : 0;

        if ($service_tables_ready && $serviceId > 0) {
            $serviceRow = intervention_get_service_by_id($db, $serviceId);
            $service = $serviceRow['name'] ?? '';
        }

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
                $categoryId = (int)$db->lastInsertId();
                if ($service_tables_ready) {
                    intervention_sync_category_default_service($db, $categoryId, $serviceId > 0 ? $serviceId : null);
                }
                $success = "Catégorie \"$name\" créée avec succès.";
            }
        }
    }

    // Mettre à jour une catégorie
    elseif ($action === 'update' && $isAdmin) {
        $id      = (int)($_POST['id']      ?? 0);
        $name    = trim($_POST['name']     ?? '');
        $icon    = trim($_POST['icon']     ?? 'road');
        $color   = trim($_POST['color']    ?? '#1d4ed8');
        $service = trim($_POST['service']  ?? '');
        $serviceId = $service_tables_ready ? (int)($_POST['service_id'] ?? 0) : 0;

        if ($service_tables_ready && $serviceId > 0) {
            $serviceRow = intervention_get_service_by_id($db, $serviceId);
            $service = $serviceRow['name'] ?? '';
        }

        if ($id && strlen($name) >= 2) {
            $db->prepare(
                'UPDATE categories SET name = ?, icon = ?, color = ?, service = ? WHERE id = ?'
            )->execute([$name, $icon, $color, $service, $id]);
            if ($service_tables_ready) {
                intervention_sync_category_default_service($db, $id, $serviceId > 0 ? $serviceId : null);
            }
            $success = "Catégorie mise à jour.";
        } else {
            $error = 'Données invalides.';
        }
    }

    // Activer / Désactiver
    elseif ($action === 'toggle' && $isAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('UPDATE categories SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            $success = 'Statut de la catégorie mis à jour.';
        }
        header('Location: /admin/?page=categories'); exit;
    }

    // Supprimer
    elseif ($action === 'delete' && $isAdmin) {
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
if ($service_tables_ready) {
    $categories = $db->query("
        SELECT c.*,
               MAX(s.id) AS mapped_service_id,
               MAX(s.name) AS mapped_service_name,
               COUNT(i.id)                    AS incident_count,
               COALESCE(SUM(i.votes_count),0) AS total_votes
        FROM categories c
        LEFT JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
        LEFT JOIN services s ON s.id = scm.service_id
        LEFT JOIN incidents i ON i.category_id = c.id
        GROUP BY c.id
        ORDER BY c.is_active DESC, c.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $categories = $db->query("
        SELECT c.*,
               NULL AS mapped_service_id,
               NULL AS mapped_service_name,
               COUNT(i.id)                    AS incident_count,
               COALESCE(SUM(i.votes_count),0) AS total_votes
        FROM categories c
        LEFT JOIN incidents i ON i.category_id = c.id
        GROUP BY c.id
        ORDER BY c.is_active DESC, c.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Catégorie en cours d'édition
$edit_id  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_cat = null;
if ($edit_id) {
    foreach ($categories as $cat) {
        if ((int)$cat['id'] === $edit_id) { $edit_cat = $cat; break; }
    }
}

$visual_catalog  = category_visuals_catalog();
$selected_visual = category_visual_resolve($edit_cat['icon'] ?? 'road', $edit_cat['name'] ?? '');
$default_icon    = $selected_visual['key'] ?? ($edit_cat['icon'] ?? 'road');
$default_color   = $edit_cat['color'] ?? ($selected_visual['accent'] ?? '#1d4ed8');
$default_service_id = (int)($edit_cat['mapped_service_id'] ?? 0);
$default_scene_url = category_scene_visual_url($default_icon, $edit_cat['name'] ?? ($selected_visual['label'] ?? ''));

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
<?php if (!$isAdmin): ?>
  <div class="alert alert-warning" style="margin-bottom:16px;padding:12px 16px;background:#fef3c7;border-radius:8px;color:#92400e;border:1px solid #fcd34d">
    Cette page est en lecture seule pour votre rôle. La création et la modification des catégories sont réservées aux administrateurs.
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
            <th style="width:64px">Repère</th>
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
            <td style="text-align:center"><?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'md', $cat['color'] ?? null) ?></td>
            <td>
              <?php $categoryVisual = category_visual_resolve($cat['icon'] ?? 'road', $cat['name'] ?? null); ?>
              <div class="admin-category-cell-copy">
                <span style="font-weight:700;font-size:14px"><?= e($cat['name']) ?></span>
                <div class="text-muted text-small"><?= e($categoryVisual['description'] ?? '') ?></div>
              </div>
            </td>
            <td class="text-muted text-small"><?= e($cat['mapped_service_name'] ?? $cat['service'] ?? '—') ?></td>
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
                <?php if ($isAdmin): ?>
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
                <?php else: ?>
                  <span class="text-muted text-small">Lecture seule</span>
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
      <span class="card-title"><?= $isAdmin ? ($edit_cat ? '✏️ Modifier la catégorie' : '➕ Nouvelle catégorie') : '📚 Catalogue des catégories' ?></span>
      <?php if ($edit_cat && $isAdmin): ?>
        <a href="/admin/?page=categories" class="btn btn-outline btn-sm">✕ Annuler</a>
      <?php endif; ?>
    </div>
    <?php if ($isAdmin): ?>
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
        <label class="form-label">Univers visuel</label>
        <input type="hidden" name="icon" id="categoryIconInput" value="<?= e($default_icon) ?>">
        <div class="category-preset-grid">
          <?php foreach ($visual_catalog as $visual): ?>
            <?php $is_selected = ($default_icon === ($visual['key'] ?? '')); ?>
            <label
              class="category-preset<?= $is_selected ? ' is-selected' : '' ?>"
              data-category-key="<?= e($visual['key'] ?? '') ?>"
              data-category-color="<?= e($visual['accent'] ?? '#1d4ed8') ?>"
              data-category-scene-url="<?= e((string)category_scene_visual_url($visual['key'] ?? 'road', $visual['label'] ?? '')) ?>"
              data-category-scene-label="<?= e($visual['label'] ?? '') ?>"
            >
              <input type="radio" name="category_icon_preview" value="<?= e($visual['key'] ?? '') ?>" <?= $is_selected ? 'checked' : '' ?>>
              <div class="category-preset-top">
                <?= category_visual_html($visual['key'] ?? 'road', $visual['label'] ?? '', 'lg', $visual['accent'] ?? null) ?>
                <span class="category-preset-dot" style="background:<?= e($visual['accent'] ?? '#1d4ed8') ?>"></span>
              </div>
              <div class="category-preset-title"><?= e($visual['label'] ?? '') ?></div>
              <div class="category-preset-text"><?= e($visual['description'] ?? '') ?></div>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="text-muted text-small" style="margin-top:6px">Chaque catégorie dispose maintenant d’un pictogramme premium cohérent entre mobile, back-office et administration.</div>
        <div class="category-scene-preview<?= $default_scene_url ? ' is-ready' : '' ?>" id="categoryScenePreview" data-default-label="<?= e($selected_visual['label'] ?? 'Categorie') ?>">
          <img id="categoryScenePreviewImage" alt="Scene terrain categorie"<?= $default_scene_url ? ' src="' . e($default_scene_url) . '"' : '' ?>>
          <div class="category-scene-preview-copy">
            <strong id="categoryScenePreviewTitle"><?= e($selected_visual['label'] ?? 'Categorie') ?></strong>
            <span id="categoryScenePreviewText">Les scenes terrain validées viendront ici clarifier la réalité du terrain sans remplacer l’icône fonctionnelle.</span>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Couleur d'identification</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" name="color" id="colorPicker"
                 value="<?= e($default_color) ?>"
                 style="width:48px;height:40px;border:none;cursor:pointer;border-radius:8px;padding:2px">
          <input type="text" id="colorHexInput" class="form-control"
                 value="<?= e($default_color) ?>"
                 placeholder="#1d4ed8" maxlength="7" style="flex:1;font-family:monospace">
        </div>
        <script>
          (() => {
            const picker = document.getElementById('colorPicker');
            const hexIn = document.getElementById('colorHexInput');
            const iconInput = document.getElementById('categoryIconInput');
            const presets = Array.from(document.querySelectorAll('.category-preset'));
            const scenePreview = document.getElementById('categoryScenePreview');
            const scenePreviewImage = document.getElementById('categoryScenePreviewImage');
            const scenePreviewTitle = document.getElementById('categoryScenePreviewTitle');

            if (!picker || !hexIn || !iconInput) {
              return;
            }

            const updateScenePreview = (preset) => {
              if (!scenePreview || !scenePreviewImage || !scenePreviewTitle || !preset) {
                return;
              }

              const sceneUrl = preset.getAttribute('data-category-scene-url') || '';
              const sceneLabel = preset.getAttribute('data-category-scene-label') || scenePreview.dataset.defaultLabel || 'Categorie';
              scenePreviewTitle.textContent = sceneLabel;

              if (!sceneUrl) {
                scenePreview.classList.remove('is-ready');
                scenePreviewImage.removeAttribute('src');
                return;
              }

              scenePreviewImage.src = sceneUrl;
              scenePreview.classList.add('is-ready');
            };

            const syncColorFieldNames = () => {
              picker.name = '';
              hexIn.name = 'color';
            };

            picker.addEventListener('input', () => {
              hexIn.value = picker.value;
              syncColorFieldNames();
            });

            hexIn.addEventListener('input', () => {
              if (/^#[0-9a-fA-F]{6}$/.test(hexIn.value)) {
                picker.value = hexIn.value;
              }
            });

            hexIn.addEventListener('change', syncColorFieldNames);

            presets.forEach((preset) => {
              preset.addEventListener('click', () => {
                const key = preset.getAttribute('data-category-key') || 'road';
                const color = preset.getAttribute('data-category-color') || picker.value;

                iconInput.value = key;
                picker.value = color;
                hexIn.value = color;
                syncColorFieldNames();

                presets.forEach((entry) => entry.classList.remove('is-selected'));
                preset.classList.add('is-selected');

                const radio = preset.querySelector('input[type="radio"]');
                if (radio) {
                  radio.checked = true;
                }

                updateScenePreview(preset);
              });
            });

            const selectedPreset = presets.find((entry) => entry.classList.contains('is-selected')) || presets[0];
            updateScenePreview(selectedPreset);
            syncColorFieldNames();
          })();
        </script>
      </div>

      <div class="form-group">
        <label class="form-label">Service responsable</label>
        <?php if ($service_tables_ready): ?>
          <select name="service_id" class="form-control">
            <option value="">Aucun service rattache pour l instant</option>
            <?php foreach ($services as $service_option): ?>
              <option
                value="<?= (int)$service_option['id'] ?>"
                <?= $default_service_id === (int)$service_option['id'] ? 'selected' : '' ?>
              >
                <?= e($service_option['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted text-small" style="margin-top:6px">
            Ce service deviendra le responsable par défaut des dossiers de cette catégorie.
          </div>
        <?php else: ?>
          <input type="text" name="service" class="form-control"
                 value="<?= e($edit_cat['service'] ?? '') ?>"
                 placeholder="Ex: Direction des routes, DEAL…" maxlength="150">
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:8px">
        <?= $edit_cat ? '💾 Enregistrer les modifications' : '➕ Créer la catégorie premium' ?>
      </button>
    </form>
    <?php else: ?>
      <div style="padding:16px 4px 4px">
        <p class="text-muted" style="margin:0 0 12px">Les catégories structurent tout le territoire. Leur création et leur modification restent réservées aux administrateurs.</p>
        <ul class="text-small text-muted" style="margin:0;padding-left:18px;line-height:1.7">
          <li>consulter la liste et les services rattachés</li>
          <li>ouvrir la file des dossiers liés à une catégorie</li>
          <li>remonter à un administrateur si un changement de catalogue est nécessaire</li>
        </ul>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
