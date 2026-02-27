<?php
/**
 * CCDS Back-Office — Gestion des utilisateurs
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Utilisateurs';
$active_nav = 'users';

$db = Database::getInstance()->getConnection();

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Créer un agent
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

    // Activer / désactiver un utilisateur
    if ($action === 'toggle_active' && $admin['role'] === 'admin') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $active  = (int)($_POST['is_active'] ?? 0);
        if ($uid && $uid !== $admin['id']) {
            $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$active, $uid]);
            $_SESSION['flash_success'] = $active ? 'Compte activé.' : 'Compte désactivé.';
        }
        header('Location: /admin/?page=users');
        exit;
    }

    // Changer le rôle
    if ($action === 'change_role' && $admin['role'] === 'admin') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['citizen','agent','admin']) ? $_POST['role'] : 'citizen';
        if ($uid && $uid !== $admin['id']) {
            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $uid]);
            $_SESSION['flash_success'] = 'Rôle mis à jour.';
        }
        header('Location: /admin/?page=users');
        exit;
    }
}

// --- Filtres ---
$f_role   = $_GET['role']   ?? '';
$f_search = trim($_GET['q'] ?? '');
$where    = ['1=1'];
$params   = [];
if ($f_role)   { $where[] = 'role = ?';                                      $params[] = $f_role; }
if ($f_search) { $where[] = '(full_name LIKE ? OR email LIKE ?)';            $params[] = "%$f_search%"; $params[] = "%$f_search%"; }
$where_sql = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM incidents i WHERE i.user_id = u.id) AS incident_count
    FROM users u
    WHERE $where_sql
    ORDER BY u.role ASC, u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

  <!-- Liste des utilisateurs -->
  <div>
    <!-- Filtres -->
    <div class="card" style="padding:16px 24px;margin-bottom:16px;">
      <form method="GET" action="" class="filters-bar">
        <input type="hidden" name="page" value="users">
        <input type="text" name="q" class="form-control search-input"
               placeholder="🔍 Nom ou email…" value="<?= e($f_search) ?>">
        <select name="role" class="form-control">
          <option value="">Tous les rôles</option>
          <option value="admin"   <?= $f_role==='admin'  ?'selected':'' ?>>Administrateurs</option>
          <option value="agent"   <?= $f_role==='agent'  ?'selected':'' ?>>Agents</option>
          <option value="citizen" <?= $f_role==='citizen'?'selected':'' ?>>Citoyens</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a href="/admin/?page=users" class="btn btn-outline">Réinitialiser</a>
      </form>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= count($users) ?> utilisateur<?= count($users) > 1 ? 's' : '' ?></span>
      </div>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Nom</th>
              <th>Email</th>
              <th>Rôle</th>
              <th>Signalements</th>
              <th>Statut</th>
              <th>Inscrit le</th>
              <?php if ($admin['role'] === 'admin'): ?>
              <th>Actions</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div class="d-flex align-center gap-8">
                  <div style="width:32px;height:32px;border-radius:50%;background:<?= $u['role']==='admin'?'#fee2e2':($u['role']==='agent'?'#dbeafe':'#f1f5f9') ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;color:<?= $u['role']==='admin'?'#b91c1c':($u['role']==='agent'?'#1d4ed8':'#475569') ?>">
                    <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                  </div>
                  <div>
                    <div class="fw-bold"><?= e($u['full_name']) ?></div>
                    <?php if ($u['id'] == $admin['id']): ?>
                      <span style="font-size:10px;color:#22c55e">● Vous</span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="text-muted text-small"><?= e($u['email']) ?></td>
              <td>
                <span class="badge <?= $u['role']==='admin'?'badge-red':($u['role']==='agent'?'badge-blue':'badge-gray') ?>">
                  <?= role_label($u['role']) ?>
                </span>
              </td>
              <td class="text-center"><?= $u['incident_count'] ?></td>
              <td>
                <span class="badge <?= $u['is_active'] ? 'badge-green' : 'badge-gray' ?>">
                  <?= $u['is_active'] ? 'Actif' : 'Inactif' ?>
                </span>
              </td>
              <td class="text-muted text-small"><?= format_date_short($u['created_at']) ?></td>
              <?php if ($admin['role'] === 'admin' && $u['id'] != $admin['id']): ?>
              <td>
                <div class="d-flex gap-8">
                  <!-- Toggle actif -->
                  <form method="POST" action="" style="display:inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $u['is_active'] ? 0 : 1 ?>">
                    <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                            data-confirm="<?= $u['is_active'] ? 'Désactiver ce compte ?' : 'Activer ce compte ?' ?>">
                      <?= $u['is_active'] ? 'Désactiver' : 'Activer' ?>
                    </button>
                  </form>
                  <!-- Changer rôle -->
                  <form method="POST" action="" style="display:inline">
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <select name="role" class="form-control" style="width:auto;padding:5px 8px;font-size:12px"
                            onchange="this.form.submit()">
                      <option value="citizen" <?= $u['role']==='citizen'?'selected':'' ?>>Citoyen</option>
                      <option value="agent"   <?= $u['role']==='agent'  ?'selected':'' ?>>Agent</option>
                      <option value="admin"   <?= $u['role']==='admin'  ?'selected':'' ?>>Admin</option>
                    </select>
                  </form>
                </div>
              </td>
              <?php elseif ($admin['role'] === 'admin'): ?>
              <td><span class="text-muted text-small">—</span></td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
            <tr><td colspan="7" class="text-center text-muted" style="padding:32px">Aucun utilisateur trouvé.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Formulaire création agent -->
  <?php if ($admin['role'] === 'admin'): ?>
  <div>
    <div class="card">
      <div class="card-header"><span class="card-title">➕ Créer un compte agent</span></div>
      <form method="POST" action="">
        <input type="hidden" name="action" value="create_agent">
        <div class="form-group">
          <label class="form-label">Nom complet</label>
          <input type="text" name="full_name" class="form-control" placeholder="Jean Dupont" required>
        </div>
        <div class="form-group">
          <label class="form-label">Adresse email</label>
          <input type="email" name="email" class="form-control" placeholder="agent@mairie.fr" required>
        </div>
        <div class="form-group">
          <label class="form-label">Mot de passe temporaire</label>
          <input type="text" name="password" class="form-control" placeholder="Mot de passe initial" required>
        </div>
        <div class="form-group">
          <label class="form-label">Rôle</label>
          <select name="role" class="form-control">
            <option value="agent">Agent municipal</option>
            <option value="admin">Administrateur</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary w-100" style="justify-content:center">
          Créer le compte
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
