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
$themePalette = visual_admin_data_palette();

$db = Database::getInstance();
$isAdmin = ($admin['role'] ?? '') === 'admin';
$service_tables_ready = admin_db_has_table($db, 'services') && admin_db_has_table($db, 'service_category_map');
$category_has_slug = admin_db_has_column($db, 'categories', 'slug');
$services = $service_tables_ready ? intervention_get_services($db) : [];

$success = '';
$error   = '';

function admin_category_slugify(string $value): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim(mb_strtolower($value)));
    $normalized = $normalized === false ? trim(mb_strtolower($value)) : $normalized;
    $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'categorie';
}

function admin_category_unique_slug(PDO $db, string $name, ?int $excludeId = null): string
{
    $baseSlug = admin_category_slugify($name);
    $slug = $baseSlug;
    $index = 2;

    while (true) {
        $sql = 'SELECT id FROM categories WHERE slug = ?';
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $index;
        $index++;
    }
}

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
        $color   = trim($_POST['color']   ?? ($themePalette['primary'] ?? '#355160'));
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
                $error = "Une catégorie avec le nom \"$name\" existe déjà. Réutiliser l entrée existante évite de disperser le catalogue.";
            } else {
                $slug = $category_has_slug ? admin_category_unique_slug($db, $name) : null;
                $columns = ['name', 'icon', 'color', 'service', 'is_active'];
                $values = ['?', '?', '?', '?', '1'];
                $params = [$name, $icon, $color, $service];

                if ($category_has_slug) {
                    $columns[] = 'slug';
                    $values[] = '?';
                    $params[] = $slug;
                }

                $db->prepare(
                    sprintf(
                        'INSERT INTO categories (%s) VALUES (%s)',
                        implode(', ', $columns),
                        implode(', ', $values)
                    )
                )->execute($params);
                $categoryId = (int)$db->lastInsertId();
                if ($service_tables_ready) {
                    intervention_sync_category_default_service($db, $categoryId, $serviceId > 0 ? $serviceId : null);
                }
                $success = "Catégorie \"$name\" créée. Le repère visuel et le service par défaut sont maintenant disponibles pour les prochains dossiers.";
            }
        }
    }

    // Mettre à jour une catégorie
    elseif ($action === 'update' && $isAdmin) {
        $id      = (int)($_POST['id']      ?? 0);
        $name    = trim($_POST['name']     ?? '');
        $icon    = trim($_POST['icon']     ?? 'road');
        $color   = trim($_POST['color']    ?? ($themePalette['primary'] ?? '#355160'));
        $service = trim($_POST['service']  ?? '');
        $serviceId = $service_tables_ready ? (int)($_POST['service_id'] ?? 0) : 0;

        if ($service_tables_ready && $serviceId > 0) {
            $serviceRow = intervention_get_service_by_id($db, $serviceId);
            $service = $serviceRow['name'] ?? '';
        }

        if ($id && strlen($name) >= 2) {
            $assignments = ['name = ?', 'icon = ?', 'color = ?', 'service = ?'];
            $params = [$name, $icon, $color, $service];

            if ($category_has_slug) {
                $assignments[] = 'slug = ?';
                $params[] = admin_category_unique_slug($db, $name, $id);
            }

            $params[] = $id;
            $db->prepare(
                sprintf('UPDATE categories SET %s WHERE id = ?', implode(', ', $assignments))
            )->execute($params);
            if ($service_tables_ready) {
                intervention_sync_category_default_service($db, $id, $serviceId > 0 ? $serviceId : null);
            }
            $success = "Catégorie mise à jour. Le référentiel est désormais aligné avec ce nouveau libellé et ce nouveau service.";
        } else {
            $error = 'Données invalides. Vérifier le nom, le service choisi et le repère visuel avant d enregistrer.';
        }
    }

    // Activer / Désactiver
    elseif ($action === 'toggle' && $isAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('UPDATE categories SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
            $success = 'Statut de la catégorie mis à jour. Les nouveaux dossiers suivront désormais ce niveau d activation.';
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
                $success = 'Catégorie désactivée. Des signalements y sont encore associés, la suppression complète reste donc verrouillée.';
            } else {
                $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                $success = 'Catégorie supprimée. Le catalogue ne la proposera plus pour les prochains signalements.';
            }
        }
    }
    
    // Changer le pack d'icônes global
    elseif ($action === 'set_pack' && $isAdmin) {
        $newPack = $_POST['active_pack'] ?? 'kourou';
        if (in_array($newPack, ['kourou', 'cayenne', '3d_clay'])) {
            $configFile = dirname(__DIR__) . '/includes/.icon_pack_config.json';
            file_put_contents($configFile, json_encode(['active_pack' => $newPack]));
            $success = 'Thème visuel mis à jour : ' . $newPack . '. L ensemble de la plateforme utilise désormais ce pack d icônes.';
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
$default_color   = $edit_cat['color'] ?? ($selected_visual['accent'] ?? ($themePalette['primary'] ?? '#355160'));
$default_service_id = (int)($edit_cat['mapped_service_id'] ?? 0);
$default_scene_url = category_scene_visual_url($default_icon, $edit_cat['name'] ?? ($selected_visual['label'] ?? ''));

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="categories-admin">
<div class="page-hero">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Catalogue métier</div>
    <div class="page-hero-title">Maintenir un référentiel lisible pour toute l interface.</div>
    <?php if ($isTrainingMode): ?>
    <div class="page-hero-text">
      Les catégories structurent les signalements, les services responsables et les repères visuels utilisés dans l’ensemble du produit. Cette page doit rester claire, stable et éditable sans déformer le catalogue.
    </div>
    <?php endif; ?>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= count($categories) ?></span>
      <span class="hero-chip-label">categorie(s) au catalogue</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= count(array_filter($categories, static fn($cat) => (int)($cat['is_active'] ?? 0) === 1)) ?></span>
      <span class="hero-chip-label">categorie(s) actives</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= array_sum(array_map(static fn($cat) => (int)($cat['incident_count'] ?? 0), $categories)) ?></span>
      <span class="hero-chip-label">signalements relies</span>
    </div>
  </div>
</div>
<?php if ($success): ?>
  <div class="alert alert-success categories-alert">
    OK · <?= e($success) ?>
  </div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger categories-alert">
    Erreur · <?= e($error) ?>
  </div>
<?php endif; ?>
<?php if (!$isAdmin): ?>
  <div class="alert alert-warning categories-alert">
    Cette page est en lecture seule pour votre rôle. La création et la modification des catégories sont réservées aux administrateurs.
  </div>
<?php endif; ?>

<div class="categories-admin-layout">

  <!-- Liste des catégories -->
  <div class="card">
    <div class="card-header">
      <div>
        <span class="card-title"><?= count($categories) ?> catégorie<?= count($categories) > 1 ? 's' : '' ?></span>
        <?php if ($isTrainingMode): ?>
        <p class="admin-section-note">Lire d abord la lisibilité du catalogue : nom, service par défaut, activité réelle et possibilité d agir sans casser des dossiers existants.</p>
        <?php endif; ?>
      </div>
      <div>
        <form method="POST" action="" data-async-form style="display:flex; gap:0.5rem; align-items:center;">
          <input type="hidden" name="action" value="set_pack">
          <span class="text-small text-muted">Thème Global :</span>
          <select name="active_pack" class="form-control" style="width:140px; padding:0.25rem 0.5rem; height:auto;" onchange="this.form.submit()">
            <option value="kourou" <?= category_visual_active_pack() === 'kourou' ? 'selected' : '' ?>>Minimal (Kourou)</option>
            <option value="cayenne" <?= category_visual_active_pack() === 'cayenne' ? 'selected' : '' ?>>Détaillé (Cayenne)</option>
            <option value="3d_clay" <?= category_visual_active_pack() === '3d_clay' ? 'selected' : '' ?>>Premium (3D Clay)</option>
          </select>
        </form>
      </div>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th class="categories-repere-col">Repère</th>
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
          <tr class="<?= !(bool)$cat['is_active'] ? 'categories-row-muted' : '' ?>">
            <td class="categories-repere-cell">
              <?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'md', $cat['color'] ?? null) ?>
            </td>
            <td>
              <?php $categoryVisual = category_visual_resolve($cat['icon'] ?? 'road', $cat['name'] ?? null); ?>
              <div class="admin-category-cell-copy">
                <span><strong><?= e($cat['name']) ?></strong></span>
                <div class="text-muted text-small"><?= e($categoryVisual['description'] ?? '') ?></div>
              </div>
            </td>
            <td class="text-muted text-small"><?= e($cat['mapped_service_name'] ?? $cat['service'] ?? '—') ?></td>
            <td>
              <div class="categories-color-row">
                <div class="categories-swatch" style="background:<?= e($cat['color']) ?>"></div>
                <code class="categories-code"><?= e($cat['color']) ?></code>
              </div>
            </td>
            <td class="text-center">
              <?php if ($cat['incident_count'] > 0): ?>
                <a href="/admin/?page=incidents&cat=<?= $cat['id'] ?>"
                   class="badge badge-blue categories-link-reset" data-async-link data-async-scope="admin-main">
                  <?= $cat['incident_count'] ?>
                </a>
              <?php else: ?>
                <span class="text-muted">0</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($cat['total_votes'] > 0): ?>
                <span class="categories-vote-copy"><?= (int)$cat['total_votes'] ?> soutien<?= (int)$cat['total_votes'] > 1 ? 's' : '' ?></span>
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
              <div class="categories-actions">
                <?php if ($isAdmin): ?>
                  <a href="/admin/?page=categories&edit=<?= $cat['id'] ?>"
                     class="btn btn-outline btn-sm" title="Modifier" data-async-link data-async-scope="admin-main">Editer</a>

                  <form method="POST" action="" class="categories-inline-form" data-async-form>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm"
                            title="<?= $cat['is_active'] ? 'Désactiver' : 'Activer' ?>">
                      <?= $cat['is_active'] ? 'Pause' : 'Activer' ?>
                    </button>
                  </form>

                  <?php if ($cat['incident_count'] == 0): ?>
                  <form method="POST" action="" class="categories-inline-form"
                        data-async-form
                        onsubmit="return confirm('Supprimer la catégorie « <?= e($cat['name']) ?> » ? Cette action est irréversible.')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">Supprimer</button>
                  </form>
                  <?php else: ?>
                    <button class="btn btn-outline btn-sm categories-blocked-btn" disabled title="Impossible : des signalements utilisent cette catégorie">Utilisee</button>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted text-small">Lecture seule</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($categories)): ?>
          <tr><td colspan="8" class="text-center text-muted categories-empty-row">Aucune catégorie. Créer d abord un premier repère métier pour éviter un catalogue vide côté saisie citoyenne et backoffice.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Formulaire création / édition -->
  <div class="card categories-editor-card">
    <div class="card-header card-header--split">
      <span class="card-title"><?= $isAdmin ? ($edit_cat ? 'Modifier la catégorie' : 'Nouvelle catégorie') : 'Catalogue des catégories' ?></span>
      <?php if ($edit_cat && $isAdmin): ?>
        <a href="/admin/?page=categories" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Annuler</a>
      <?php endif; ?>
    </div>
    <?php if ($isAdmin): ?>
    <?php if ($isTrainingMode): ?>
    <div class="admin-form-guide">
      <strong>Sequence conseillee</strong>
      <div class="admin-form-guide-list">
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">01</span>
          <div>
            <strong>Nommer la categorie</strong>
            <span>Choisir un libelle stable, compréhensible par l habitant comme par le service.</span>
          </div>
        </div>
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">02</span>
          <div>
            <strong>Fixer le repere visuel</strong>
            <span>L univers visuel sert au reperage rapide. Il doit rester distinct des autres categories proches.</span>
          </div>
        </div>
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">03</span>
          <div>
            <strong>Rattacher le bon service</strong>
            <span>Le service choisi deviendra la porte d entree par defaut des prochains dossiers de cette categorie.</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <form method="POST" action="" class="categories-form-shell" data-async-form>
      <input type="hidden" name="action" value="<?= $edit_cat ? 'update' : 'create' ?>">
      <?php if ($edit_cat): ?>
        <input type="hidden" name="id" value="<?= $edit_cat['id'] ?>">
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Nom <span class="form-required">*</span></label>
        <input type="text" name="name" class="form-control"
               value="<?= e($edit_cat['name'] ?? '') ?>"
               placeholder="Ex: Voirie, Éclairage public…" required maxlength="100">
        <?php if ($isTrainingMode): ?>
        <div class="admin-section-note">Eviter les doublons metier et les intitulés trop proches qui feraient hesiter dans le mobile ou le backoffice.</div>
        <?php endif; ?>
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
              data-category-color="<?= e($visual['accent'] ?? ($themePalette['primary'] ?? '#355160')) ?>"
              data-category-scene-url="<?= e((string)category_scene_visual_url($visual['key'] ?? 'road', $visual['label'] ?? '')) ?>"
              data-category-scene-label="<?= e($visual['label'] ?? '') ?>"
            >
              <input type="radio" name="category_icon_preview" value="<?= e($visual['key'] ?? '') ?>" <?= $is_selected ? 'checked' : '' ?>>
              <div class="category-preset-top">
                <?= category_visual_html($visual['key'] ?? 'road', $visual['label'] ?? '', 'lg', $visual['accent'] ?? null) ?>
                <span class="category-preset-dot" style="background:<?= e($visual['accent'] ?? ($themePalette['primary'] ?? '#355160')) ?>"></span>
              </div>
              <div class="category-preset-title"><?= e($visual['label'] ?? '') ?></div>
              <div class="category-preset-text"><?= e($visual['description'] ?? '') ?></div>
            </label>
          <?php endforeach; ?>
        </div>
        <?php if ($isTrainingMode): ?>
        <div class="text-muted text-small categories-helper" style="margin-top:0.75rem;">Le pictogramme final appliqué dépend du `Thème Global` couramment activé sur toute l'interface.</div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="form-label">Couleur d'identification</label>
        <div class="categories-color-row">
          <input type="color" name="color" id="colorPicker"
                 value="<?= e($default_color) ?>"
                 class="categories-color-input">
          <input type="text" id="colorHexInput" class="form-control"
                 value="<?= e($default_color) ?>"
                 placeholder="<?= e($themePalette['primary'] ?? '#355160') ?>" maxlength="7" class="categories-hex-input">
        </div>
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
          <?php if ($isTrainingMode): ?>
          <div class="text-muted text-small categories-helper">
            Ce service deviendra le responsable par défaut des dossiers de cette catégorie.
          </div>
          <?php endif; ?>
        <?php else: ?>
          <input type="text" name="service" class="form-control"
                 value="<?= e($edit_cat['service'] ?? '') ?>"
                 placeholder="Ex: Direction des routes, DEAL…" maxlength="150">
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary btn-block-center">
        <?= $edit_cat ? 'Enregistrer les modifications' : 'Créer la catégorie premium' ?>
      </button>
    </form>
    <?php else: ?>
      <div class="categories-readonly-copy">
        <p class="text-muted">Les catégories structurent toute l interface. Leur création et leur modification restent réservées aux administrateurs.</p>
        <ul class="text-small text-muted categories-readonly-list">
          <li>consulter la liste et les services rattachés</li>
          <li>ouvrir la file des dossiers liés à une catégorie</li>
          <li>remonter à un administrateur si un changement de catalogue est nécessaire</li>
        </ul>
      </div>
    <?php endif; ?>
  </div>

</div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
