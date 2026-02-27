<?php
/**
 * CCDS Back-Office — Gestion des catégories
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
require_admin_role(); // Réservé aux admins
$page_title = 'Catégories';
$active_nav = 'categories';

$db = Database::getInstance()->getConnection();

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name        = trim($_POST['name']        ?? '');
        $description = trim($_POST['description'] ?? '');
        $color       = trim($_POST['color']       ?? '#3b82f6');
        $service     = trim($_POST['service']      ?? '');
        if (!$name) { $_SESSION['flash_error'] = 'Le nom est obligatoire.'; }
        else {
            $db->prepare("INSERT INTO categories (name, description, color, responsible_service, is_active, created_at)
                          VALUES (?, ?, ?, ?, 1, NOW())")
               ->execute([$name, $description, $color, $service]);
            $_SESSION['flash_success'] = "Catégorie « $name » créée.";
        }
        header('Location: /admin/?page=categories'); exit;
    }

    if ($action === 'toggle') {
        $cid    = (int)($_POST['cat_id']   ?? 0);
        $active = (int)($_POST['is_active'] ?? 0);
        $db->prepare("UPDATE categories SET is_active = ? WHERE id = ?")->execute([$active, $cid]);
        $_SESSION['flash_success'] = $active ? 'Catégorie activée.' : 'Catégorie désactivée.';
        header('Location: /admin/?page=categories'); exit;
    }
}

$categories = $db->query("
    SELECT c.*, COUNT(i.id) AS incident_count
    FROM categories c
    LEFT JOIN incidents i ON i.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

  <!-- Liste -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><?= count($categories) ?> catégorie<?= count($categories)>1?'s':'' ?></span>
    </div>
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Couleur</th>
            <th>Nom</th>
            <th>Service responsable</th>
            <th>Signalements</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <tr>
            <td>
              <div style="width:28px;height:28px;border-radius:8px;background:<?= e($cat['color']) ?>"></div>
            </td>
            <td>
              <div class="fw-bold"><?= e($cat['name']) ?></div>
              <?php if ($cat['description']): ?>
                <div class="text-muted text-small"><?= e($cat['description']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-muted text-small"><?= $cat['responsible_service'] ? e($cat['responsible_service']) : '—' ?></td>
            <td class="text-center"><?= $cat['incident_count'] ?></td>
            <td>
              <span class="badge <?= $cat['is_active'] ? 'badge-green' : 'badge-gray' ?>">
                <?= $cat['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td>
              <form method="POST" action="" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                <input type="hidden" name="is_active" value="<?= $cat['is_active'] ? 0 : 1 ?>">
                <button type="submit" class="btn btn-sm <?= $cat['is_active'] ? 'btn-warning' : 'btn-success' ?>">
                  <?= $cat['is_active'] ? 'Désactiver' : 'Activer' ?>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Formulaire création -->
  <div class="card">
    <div class="card-header"><span class="card-title">➕ Nouvelle catégorie</span></div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label class="form-label">Nom <span style="color:#ef4444">*</span></label>
        <input type="text" name="name" class="form-control" placeholder="Ex: Voirie" required>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <input type="text" name="description" class="form-control" placeholder="Description courte">
      </div>
      <div class="form-group">
        <label class="form-label">Couleur</label>
        <input type="color" name="color" class="form-control" value="#3b82f6" style="height:42px;padding:4px 8px;cursor:pointer">
      </div>
      <div class="form-group">
        <label class="form-label">Service responsable</label>
        <input type="text" name="service" class="form-control" placeholder="Ex: Service Voirie">
      </div>
      <button type="submit" class="btn btn-primary w-100" style="justify-content:center">Créer</button>
    </form>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
