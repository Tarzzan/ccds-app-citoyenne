<?php
/**
 * CCDS Back-Office — Détail et traitement d'un signalement
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

$id = (int)($_GET['id'] ?? 0);
if (!$id) render_error(400, 'Identifiant de signalement manquant.');

$db = Database::getInstance()->getConnection();

// Charger le signalement
$stmt = $db->prepare("
    SELECT i.*, c.name AS cat_name, c.color AS cat_color,
           u.full_name AS reporter_name, u.email AS reporter_email, u.phone AS reporter_phone
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$inc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inc) render_error(404, 'Signalement introuvable.');

// Charger les photos
$photos = $db->prepare("SELECT * FROM photos WHERE incident_id = ? ORDER BY id");
$photos->execute([$id]);
$photos = $photos->fetchAll(PDO::FETCH_ASSOC);

// Charger l'historique des statuts
$history = $db->prepare("
    SELECT sh.*, u.full_name AS changed_by_name
    FROM status_history sh
    JOIN users u ON u.id = sh.changed_by
    WHERE sh.incident_id = ?
    ORDER BY sh.changed_at DESC
");
$history->execute([$id]);
$history = $history->fetchAll(PDO::FETCH_ASSOC);

// Charger les commentaires (publics + internes)
$comments = $db->prepare("
    SELECT cm.*, u.full_name AS author_name, u.role AS author_role
    FROM comments cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.incident_id = ?
    ORDER BY cm.created_at ASC
");
$comments->execute([$id]);
$comments = $comments->fetchAll(PDO::FETCH_ASSOC);

// --- Traitement des formulaires POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Changement de statut
    if ($action === 'change_status') {
        $new_status = $_POST['new_status'] ?? '';
        $note       = trim($_POST['note'] ?? '');
        $priority   = $_POST['priority'] ?? $inc['priority'];
        $valid_statuses = ['submitted','acknowledged','in_progress','resolved','rejected'];

        if (!in_array($new_status, $valid_statuses)) {
            $_SESSION['flash_error'] = 'Statut invalide.';
        } else {
            $db->prepare("UPDATE incidents SET status = ?, priority = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$new_status, $priority, $id]);
            $db->prepare("INSERT INTO status_history (incident_id, old_status, new_status, changed_by, note, changed_at)
                          VALUES (?, ?, ?, ?, ?, NOW())")
               ->execute([$id, $inc['status'], $new_status, $admin['id'], $note ?: null]);
            $_SESSION['flash_success'] = 'Statut mis à jour avec succès.';
        }
        header("Location: /admin/?page=incident_detail&id=$id");
        exit;
    }

    // Ajout de commentaire (interne ou public)
    if ($action === 'add_comment') {
        $comment     = trim($_POST['comment'] ?? '');
        $is_internal = isset($_POST['is_internal']) ? 1 : 0;
        if (strlen($comment) < 2) {
            $_SESSION['flash_error'] = 'Le commentaire est trop court.';
        } else {
            $db->prepare("INSERT INTO comments (incident_id, user_id, comment, is_internal, created_at)
                          VALUES (?, ?, ?, ?, NOW())")
               ->execute([$id, $admin['id'], $comment, $is_internal]);
            $_SESSION['flash_success'] = 'Commentaire ajouté.';
        }
        header("Location: /admin/?page=incident_detail&id=$id");
        exit;
    }
}

$page_title = 'Signalement ' . e($inc['reference']);
$active_nav = 'incidents';
require_once __DIR__ . '/../includes/layout.php';
?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;">

  <!-- Colonne principale -->
  <div>

    <!-- En-tête du signalement -->
    <div class="card">
      <div class="d-flex align-center justify-between" style="margin-bottom:16px">
        <div>
          <code style="font-size:12px;color:#94a3b8"><?= e($inc['reference']) ?></code>
          <h2 style="font-size:20px;font-weight:800;margin-top:4px">
            <?= $inc['title'] ? e($inc['title']) : '<span class="text-muted">Sans titre</span>' ?>
          </h2>
        </div>
        <a href="/admin/?page=incidents" class="btn btn-outline btn-sm">← Retour</a>
      </div>

      <div class="d-flex gap-8 flex-wrap" style="margin-bottom:16px">
        <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
          <?= e($inc['cat_name']) ?>
        </span>
        <span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span>
        <span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span>
        <span class="badge badge-gray">📅 <?= format_date($inc['created_at']) ?></span>
      </div>

      <p style="font-size:15px;line-height:1.7;color:#374151;margin-bottom:16px"><?= nl2br(e($inc['description'])) ?></p>

      <?php if ($inc['address']): ?>
      <p class="text-muted text-small">📍 <?= e($inc['address']) ?></p>
      <?php endif; ?>
      <p class="text-muted text-small">🗺️ Coordonnées : <?= number_format($inc['latitude'],6) ?>, <?= number_format($inc['longitude'],6) ?></p>
    </div>

    <!-- Photos -->
    <?php if (!empty($photos)): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">📷 Photos (<?= count($photos) ?>)</span></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <?php foreach ($photos as $ph): ?>
          <a href="<?= e($ph['url']) ?>" target="_blank">
            <img src="<?= e($ph['url']) ?>" alt="Photo"
                 style="width:160px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #e2e8f0;">
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Commentaires -->
    <div class="card">
      <div class="card-header"><span class="card-title">💬 Commentaires</span></div>

      <?php foreach ($comments as $cm): ?>
        <div style="background:<?= $cm['is_internal'] ? '#fef9c3' : '#f8fafc' ?>;border-radius:10px;padding:14px;margin-bottom:12px;border-left:3px solid <?= $cm['is_internal'] ? '#f59e0b' : '#e2e8f0' ?>">
          <div class="d-flex align-center justify-between" style="margin-bottom:6px">
            <div>
              <strong><?= e($cm['author_name']) ?></strong>
              <span class="badge badge-<?= $cm['author_role']==='admin'?'red':($cm['author_role']==='agent'?'blue':'gray') ?>" style="margin-left:6px;font-size:10px">
                <?= role_label($cm['author_role']) ?>
              </span>
              <?php if ($cm['is_internal']): ?>
                <span class="badge badge-yellow" style="margin-left:4px;font-size:10px">🔒 Note interne</span>
              <?php endif; ?>
            </div>
            <span class="text-muted text-small"><?= format_date($cm['created_at']) ?></span>
          </div>
          <p style="margin:0;font-size:14px;line-height:1.6"><?= nl2br(e($cm['comment'])) ?></p>
        </div>
      <?php endforeach; ?>

      <?php if (empty($comments)): ?>
        <p class="text-muted text-small">Aucun commentaire pour l'instant.</p>
      <?php endif; ?>

      <!-- Formulaire d'ajout de commentaire -->
      <form method="POST" action="" style="margin-top:16px">
        <input type="hidden" name="action" value="add_comment">
        <div class="form-group">
          <label class="form-label">Ajouter un commentaire</label>
          <textarea name="comment" class="form-control" rows="3"
                    placeholder="Répondre au citoyen ou ajouter une note interne…" required></textarea>
        </div>
        <div class="d-flex align-center gap-8">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="is_internal" value="1">
            🔒 Note interne (non visible par le citoyen)
          </label>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto">Envoyer</button>
        </div>
      </form>
    </div>

  </div><!-- /col principale -->

  <!-- Colonne latérale -->
  <div>

    <!-- Informations citoyen -->
    <div class="card">
      <div class="card-header"><span class="card-title">👤 Citoyen</span></div>
      <p><strong><?= e($inc['reporter_name']) ?></strong></p>
      <p class="text-muted text-small">📧 <?= e($inc['reporter_email']) ?></p>
      <?php if ($inc['reporter_phone']): ?>
        <p class="text-muted text-small">📞 <?= e($inc['reporter_phone']) ?></p>
      <?php endif; ?>
    </div>

    <!-- Changer le statut -->
    <div class="card">
      <div class="card-header"><span class="card-title">⚙️ Traitement</span></div>
      <form method="POST" action="">
        <input type="hidden" name="action" value="change_status">
        <div class="form-group">
          <label class="form-label">Nouveau statut</label>
          <select name="new_status" class="form-control">
            <option value="submitted"    <?= $inc['status']==='submitted'    ?'selected':'' ?>>Soumis</option>
            <option value="acknowledged" <?= $inc['status']==='acknowledged' ?'selected':'' ?>>Pris en charge</option>
            <option value="in_progress"  <?= $inc['status']==='in_progress'  ?'selected':'' ?>>En cours de traitement</option>
            <option value="resolved"     <?= $inc['status']==='resolved'     ?'selected':'' ?>>Résolu</option>
            <option value="rejected"     <?= $inc['status']==='rejected'     ?'selected':'' ?>>Rejeté</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Priorité</label>
          <select name="priority" class="form-control">
            <option value="low"      <?= ($inc['priority']??'')==='low'      ?'selected':'' ?>>Faible</option>
            <option value="medium"   <?= ($inc['priority']??'medium')==='medium'   ?'selected':'' ?>>Normale</option>
            <option value="high"     <?= ($inc['priority']??'')==='high'     ?'selected':'' ?>>Haute</option>
            <option value="critical" <?= ($inc['priority']??'')==='critical' ?'selected':'' ?>>Critique</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Note de traitement (optionnel)</label>
          <textarea name="note" class="form-control" rows="2"
                    placeholder="Ex: Transmis au service voirie…"></textarea>
        </div>
        <button type="submit" class="btn btn-success w-100" style="justify-content:center">
          ✅ Mettre à jour
        </button>
      </form>
    </div>

    <!-- Historique des statuts -->
    <?php if (!empty($history)): ?>
    <div class="card">
      <div class="card-header"><span class="card-title">📜 Historique</span></div>
      <ul class="timeline">
        <?php foreach ($history as $h): ?>
        <li class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div>
              <?php if ($h['old_status']): ?>
                <span class="badge <?= status_class($h['old_status']) ?>" style="font-size:10px"><?= status_label($h['old_status']) ?></span>
                → 
              <?php endif; ?>
              <span class="badge <?= status_class($h['new_status']) ?>"><?= status_label($h['new_status']) ?></span>
            </div>
            <?php if ($h['note']): ?>
              <p style="font-size:13px;margin:4px 0 0"><?= e($h['note']) ?></p>
            <?php endif; ?>
            <div class="timeline-meta">
              <?= e($h['changed_by_name']) ?> · <?= format_date($h['changed_at']) ?>
            </div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

  </div><!-- /col latérale -->

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
