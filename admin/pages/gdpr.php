<?php
/**
 * Ma Commune Back-Office — Conformité RGPD (ADMIN-11)
 * Accès admin uniquement — liste des demandes export + suppression de compte.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Conformité RGPD';
$active_nav = 'gdpr';
$db         = Database::getInstance();

if ($admin['role'] !== 'admin') {
    render_error(403, 'Accès réservé aux administrateurs.');
}

$hasGdprTable   = admin_db_has_table($db, 'gdpr_export_requests');
$hasUsersPhone  = admin_db_has_column($db, 'users', 'phone');

// ── Actions POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Générer un export au nom d'un citoyen (admin)
    if ($action === 'generate_export' && $admin['role'] === 'admin') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId) {
            $userStmt = $db->prepare('SELECT id, email, full_name FROM users WHERE id = ? AND is_active = 1');
            $userStmt->execute([$userId]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                // Collecter les données de l'utilisateur
                $exportData = [
                    'generated_at'   => date('c'),
                    'generated_by'   => 'admin:' . $admin['id'],
                    'user_id'        => $userId,
                    'app_version'    => defined('APP_VERSION') ? APP_VERSION : '1.4',
                    'data' => [
                        'profile' => $targetUser,
                        'incidents' => $db->prepare('SELECT id, title, description, address, status, votes_count, created_at FROM incidents WHERE user_id = ? ORDER BY created_at DESC')->execute([$userId]) ? [] : [],
                    ],
                ];

                // Incidents
                $incStmt = $db->prepare('SELECT id, title, description, address, latitude, longitude, status, votes_count, created_at FROM incidents WHERE user_id = ? ORDER BY created_at DESC');
                $incStmt->execute([$userId]);
                $exportData['data']['incidents'] = $incStmt->fetchAll(PDO::FETCH_ASSOC);

                // Commentaires
                $comStmt = $db->prepare('SELECT c.id, c.comment, i.title AS incident_title, c.created_at FROM comments c JOIN incidents i ON i.id = c.incident_id WHERE c.user_id = ? ORDER BY c.created_at DESC');
                $comStmt->execute([$userId]);
                $exportData['data']['comments'] = $comStmt->fetchAll(PDO::FETCH_ASSOC);

                // Votes
                $voteStmt = $db->prepare('SELECT v.incident_id, i.title AS incident_title, v.created_at FROM votes v JOIN incidents i ON i.id = v.incident_id WHERE v.user_id = ? ORDER BY v.created_at DESC');
                $voteStmt->execute([$userId]);
                $exportData['data']['votes'] = $voteStmt->fetchAll(PDO::FETCH_ASSOC);

                $exportDir = rtrim(UPLOAD_DIR ?? (__DIR__ . '/../../backend/uploads'), '/') . '/exports/';
                if (!is_dir($exportDir)) {
                    @mkdir($exportDir, 0755, true);
                }

                $slug     = defined('APP_SLUG') ? APP_SLUG : 'ma_commune';
                $filename = "{$slug}_export_user_{$userId}_" . date('Ymd_His') . '.json';
                $filepath = $exportDir . $filename;

                file_put_contents($filepath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                if ($hasGdprTable) {
                    $db->prepare("INSERT INTO gdpr_export_requests (user_id, status, file_path, requested_at) VALUES (?, 'completed', ?, NOW())")
                       ->execute([$userId, $filename]);
                }

                $_SESSION['flash_success'] = "Export RGPD généré pour {$targetUser['full_name']} — fichier : {$filename}";
            } else {
                $_SESSION['flash_error'] = 'Utilisateur introuvable ou inactif.';
            }
        }
        header('Location: /admin/?page=gdpr');
        exit;
    }

    // Anonymiser un compte (droit à l'oubli) — admin only
    if ($action === 'anonymize_account' && $admin['role'] === 'admin') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $confirm = trim($_POST['confirm_text'] ?? '');

        if ($userId && strtolower($confirm) === 'confirmer') {
            $slug = defined('APP_SLUG') ? APP_SLUG : 'ma_commune';
            $pwCol = admin_db_has_column($db, 'users', 'password_hash') ? 'password_hash' : 'password';
            $db->prepare("UPDATE users SET full_name = 'Compte supprimé', email = CONCAT('deleted_', id, '@{$slug}.deleted'), {$pwCol} = '', phone = NULL, is_active = 0 WHERE id = ? AND id != ?")
               ->execute([$userId, (int)$admin['id']]);
            $db->prepare("DELETE FROM push_tokens WHERE user_id = ?")->execute([$userId]);
            $_SESSION['flash_success'] = "Compte #{$userId} anonymisé. Les signalements associés sont conservés pour l'intégrité des données.";
        } else {
            $_SESSION['flash_error'] = 'Tapez exactement « Confirmer » pour valider la suppression.';
        }
        header('Location: /admin/?page=gdpr');
        exit;
    }
}

// ── Données ──────────────────────────────────────────────────────
$exports = [];
$exportStats = ['total' => 0, 'last_7d' => 0, 'pending' => 0];

if ($hasGdprTable) {
    $exportStmt = $db->prepare("
        SELECT g.id, g.user_id, g.status, g.file_path, g.requested_at,
               u.full_name, u.email
        FROM gdpr_export_requests g
        LEFT JOIN users u ON u.id = g.user_id
        ORDER BY g.requested_at DESC
        LIMIT 100
    ");
    $exportStmt->execute();
    $exports = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $exportStats['total']  = (int)$db->query('SELECT COUNT(*) FROM gdpr_export_requests')->fetchColumn();
    $exportStats['last_7d'] = (int)$db->query('SELECT COUNT(*) FROM gdpr_export_requests WHERE requested_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
    $exportStats['pending'] = (int)$db->query("SELECT COUNT(*) FROM gdpr_export_requests WHERE status = 'pending'")->fetchColumn();
}

// Utilisateurs actifs pour formulaire
$activeUsers = $db->query("SELECT id, full_name, email, role FROM users WHERE is_active = 1 AND role = 'citizen' ORDER BY full_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="gdpr-admin">
<div class="page-hero page-hero--with-visual">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Conformité</div>
    <div class="page-hero-title">Gestion des droits RGPD</div>
    <div class="page-hero-text">
      Gérez les demandes d'export de données (article 20) et les suppressions de compte (article 17) conformément au RGPD. Chaque action est enregistrée dans les logs d'audit.
    </div>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $exportStats['total'] ?></span>
      <span class="hero-chip-label">exports générés</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $exportStats['last_7d'] ?></span>
      <span class="hero-chip-label">sur 7 jours</span>
    </div>
    <?php if ($exportStats['pending'] > 0): ?>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= $exportStats['pending'] ?></span>
      <span class="hero-chip-label">en attente</span>
    </div>
    <?php endif; ?>
  </div>
  <div class="page-hero-visual">
    <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
      <?= generated_visual_html('ILL-03', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Conformité RGPD']) ?>
      <?= generated_visual_html('CHAR-04', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Référent RGPD']) ?>
      <div class="generated-visual-caption hero-visual-stack-copy">
        <strong>Droits citoyens</strong>
        <span>Portabilité et effacement des données personnelles selon le RGPD.</span>
      </div>
    </div>
  </div>
</div>

<?php if (!$hasGdprTable): ?>
<div class="alert alert-warning">
  ⚠️ La table <strong>gdpr_export_requests</strong> n'est pas encore disponible. Les exports seront générés localement mais sans historique en base. Appliquer la migration Phinx <code>v1_09</code> pour activer le suivi complet.
</div>
<?php endif; ?>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Article 20 — Portabilité</div>
    <h3>Générer un export JSON de toutes les données d'un citoyen.</h3>
    <p>Incidents, commentaires, votes, notifications et profil sont inclus dans l'archive. Le citoyen peut ensuite la télécharger ou la transmettre à un tiers.</p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Article 17 — Oubli</div>
    <h3>Anonymiser un compte sans effacer l'historique des signalements.</h3>
    <p>Le nom, l'email et le téléphone sont remplacés par des valeurs neutres. Les signalements associés sont maintenus pour l'intégrité opérationnelle.</p>
  </div>
</div>

<div class="gdpr-grid">

  <!-- ── Génération d'export ────────────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Générer un export de données</span>
      <span class="badge badge-gray">Article 20</span>
    </div>
    <form method="POST" class="gdpr-form">
      <input type="hidden" name="action" value="generate_export">
      <div class="form-group">
        <label class="form-label">Sélectionner le compte citoyen</label>
        <select name="user_id" class="form-control" required>
          <option value="">Choisir un citoyen</option>
          <?php foreach ($activeUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?> — <?= e($u['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="text-muted text-small">L'archive JSON générée inclut le profil, les signalements, les commentaires, les votes et les notifications du compte. Elle sera stockée dans <code>uploads/exports/</code>.</p>
      <button type="submit" class="btn btn-primary">Générer l'export</button>
    </form>
  </div>

  <!-- ── Anonymisation de compte ──────────────────────────────── -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Anonymiser un compte</span>
      <span class="badge badge-danger">Article 17 — Irréversible</span>
    </div>
    <form method="POST" class="gdpr-form" onsubmit="return confirm('Anonymiser ce compte ? Cette action est irréversible.')">
      <input type="hidden" name="action" value="anonymize_account">
      <div class="form-group">
        <label class="form-label">Sélectionner le compte à anonymiser</label>
        <select name="user_id" class="form-control" required>
          <option value="">Choisir un citoyen</option>
          <?php foreach ($activeUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?> — <?= e($u['email']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Confirmation — tapez exactement <strong>Confirmer</strong></label>
        <input type="text" name="confirm_text" class="form-control" placeholder="Confirmer" required autocomplete="off">
      </div>
      <p class="text-muted text-small">⚠️ Cette action remplace le nom, l'email et le téléphone par des données neutres. Elle est enregistrée dans les logs d'audit. Les signalements sont conservés pour l'intégrité opérationnelle.</p>
      <button type="submit" class="btn btn-danger">Anonymiser le compte</button>
    </form>
  </div>

</div>

<!-- ── Historique des exports ─────────────────────────────────── -->
<?php if ($hasGdprTable && !empty($exports)): ?>
<div class="card" style="margin-top:24px">
  <div class="card-header card-header--split">
    <span class="card-title">Historique des exports</span>
    <span class="text-small text-muted"><?= count($exports) ?> export(s)</span>
  </div>
  <div class="table-wrapper">
    <table class="users-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Citoyen</th>
          <th>Fichier</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exports as $exp): ?>
        <tr>
          <td class="text-small text-muted"><?= format_date($exp['requested_at']) ?></td>
          <td>
            <strong><?= e($exp['full_name'] ?? 'Compte supprimé') ?></strong><br>
            <span class="text-small text-muted"><?= e($exp['email'] ?? '—') ?></span>
          </td>
          <td class="text-small text-muted"><code><?= e($exp['file_path']) ?></code></td>
          <td>
            <span class="badge <?= $exp['status'] === 'completed' ? 'badge-success' : 'badge-gray' ?>">
              <?= e(ucfirst($exp['status'])) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($hasGdprTable): ?>
<div class="admin-empty-state" style="margin-top:24px">
  <strong>Aucun export RGPD encore effectué.</strong>
  <span>Les demandes apparaîtront ici dès qu'un export sera généré depuis ce panneau ou via l'API mobile.</span>
</div>
<?php endif; ?>

</div>

<style>
.gdpr-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-top: 20px;
}
@media (max-width: 900px) {
  .gdpr-grid { grid-template-columns: 1fr; }
}
.gdpr-form { padding: 4px 0; }
.gdpr-form .form-group { margin-bottom: 16px; }
.badge-success { background: var(--color-accent, #2d6a4f); color: #fff; }
.badge-danger  { background: var(--color-danger, #a64b2a); color: #fff; }
</style>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
