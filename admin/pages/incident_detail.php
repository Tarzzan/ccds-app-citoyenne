<?php
/**
 * CCDS Back-Office — Détail et traitement d'un signalement
 * v1.1 : affichage des votes + envoi de notification push aux citoyens
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

$id = (int)($_GET['id'] ?? 0);
if (!$id) render_error(400, 'Identifiant de signalement manquant.');

$db = Database::getInstance();

// Charger le signalement
$stmt = $db->prepare("
    SELECT i.*, c.name AS cat_name, c.color AS cat_color,
           u.full_name AS reporter_name, u.email AS reporter_email, u.phone AS reporter_phone,
           COALESCE(i.votes_count, 0) AS votes_count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$inc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inc) render_error(404, 'Signalement introuvable.');

// Charger les photos
$photos_stmt = $db->prepare("SELECT * FROM photos WHERE incident_id = ? ORDER BY id");
$photos_stmt->execute([$id]);
$photos = $photos_stmt->fetchAll(PDO::FETCH_ASSOC);

// Charger l'historique des statuts
$history_stmt = $db->prepare("
    SELECT sh.*, u.full_name AS changed_by_name
    FROM status_history sh
    JOIN users u ON u.id = sh.changed_by
    WHERE sh.incident_id = ?
    ORDER BY sh.changed_at DESC
");
$history_stmt->execute([$id]);
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Charger les commentaires (publics + internes)
$comments_stmt = $db->prepare("
    SELECT cm.*, u.full_name AS author_name, u.role AS author_role
    FROM comments cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.incident_id = ?
    ORDER BY cm.created_at ASC
");
$comments_stmt->execute([$id]);
$comments = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Charger les votants (v1.1) — si la table existe
$voters = [];
try {
    $voters_stmt = $db->prepare("
        SELECT u.full_name, u.email, v.created_at
        FROM votes v
        JOIN users u ON u.id = v.user_id
        WHERE v.incident_id = ?
        ORDER BY v.created_at DESC
        LIMIT 20
    ");
    $voters_stmt->execute([$id]);
    $voters = $voters_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table votes pas encore créée — ignorer
}

// --- Traitement des formulaires POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Changement de statut
    if ($action === 'change_status') {
        $new_status = $_POST['new_status'] ?? '';
        $note       = trim($_POST['note'] ?? '');
        $priority   = $_POST['priority'] ?? $inc['priority'];
        $send_notif = isset($_POST['send_notification']) ? 1 : 0;
        $valid_statuses = ['submitted','acknowledged','in_progress','resolved','rejected'];

        if (!in_array($new_status, $valid_statuses)) {
            $_SESSION['flash_error'] = 'Statut invalide.';
        } else {
            $db->prepare("UPDATE incidents SET status = ?, priority = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$new_status, $priority, $id]);
            $db->prepare("INSERT INTO status_history (incident_id, old_status, new_status, changed_by, note, changed_at)
                          VALUES (?, ?, ?, ?, ?, NOW())")
               ->execute([$id, $inc['status'], $new_status, $admin['id'], $note ?: null]);

            // Envoyer une notification push si demandé (v1.1)
            if ($send_notif) {
                try {
                    $notif_title = 'Mise à jour de votre signalement';
                    $status_labels = [
                        'submitted'    => 'Soumis',
                        'acknowledged' => 'Pris en charge',
                        'in_progress'  => 'En cours de traitement',
                        'resolved'     => 'Résolu ✅',
                        'rejected'     => 'Rejeté',
                    ];
                    $notif_body = sprintf(
                        'Votre signalement %s est maintenant : %s',
                        $inc['reference'],
                        $status_labels[$new_status] ?? $new_status
                    );
                    if ($note) $notif_body .= "\n" . $note;

                    // Insérer la notification en base
                    $db->prepare("
                        INSERT INTO notifications (user_id, type, title, body, incident_id, sent_at)
                        SELECT ?, 'status_change', ?, ?, ?, NOW()
                    ")->execute([$inc['user_id'], $notif_title, $notif_body, $id]);

                    // Envoyer via Expo Push API
                    $tokens_stmt = $db->prepare("SELECT token FROM push_tokens WHERE user_id = ? AND is_active = 1");
                    $tokens_stmt->execute([$inc['user_id']]);
                    $tokens = $tokens_stmt->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($tokens)) {
                        $messages = array_map(fn($t) => [
                            'to'    => $t,
                            'title' => $notif_title,
                            'body'  => $notif_body,
                            'data'  => ['incident_reference' => $inc['reference']],
                            'sound' => 'default',
                        ], $tokens);

                        $ch = curl_init('https://exp.host/--/api/v2/push/send');
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => json_encode($messages),
                            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 10,
                        ]);
                        curl_exec($ch);
                        curl_close($ch);
                    }

                    $_SESSION['flash_success'] = 'Statut mis à jour et notification envoyée.';
                } catch (Exception $e) {
                    $_SESSION['flash_success'] = 'Statut mis à jour (notification non envoyée : ' . $e->getMessage() . ').';
                }
            } else {
                $_SESSION['flash_success'] = 'Statut mis à jour avec succès.';
            }
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

            // Notifier le citoyen d'un nouveau commentaire public (v1.1)
            if (!$is_internal) {
                try {
                    $db->prepare("
                        INSERT INTO notifications (user_id, type, title, body, incident_id, sent_at)
                        VALUES (?, 'new_comment', ?, ?, ?, NOW())
                    ")->execute([
                        $inc['user_id'],
                        'Nouveau commentaire sur votre signalement',
                        'Un agent a répondu à votre signalement ' . $inc['reference'],
                        $id,
                    ]);
                } catch (PDOException $e) { /* Table pas encore créée */ }
            }

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
        <!-- Badge votes (v1.1) -->
        <?php if ($inc['votes_count'] > 0): ?>
        <span class="badge" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0">
          👍 <?= (int)$inc['votes_count'] ?> vote<?= $inc['votes_count'] > 1 ? 's' : '' ?> "Moi aussi"
        </span>
        <?php endif; ?>
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

    <!-- Votants "Moi aussi" (v1.1) -->
    <?php if (!empty($voters)): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title">👍 Citoyens concernés (<?= count($voters) ?>)</span>
        <span class="text-muted text-small" style="margin-left:8px">Ont voté "Moi aussi"</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($voters as $v): ?>
          <span style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:20px;padding:4px 12px;font-size:13px;color:#15803d">
            <?= e($v['full_name']) ?>
          </span>
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

    <!-- Changer le statut + envoi notification (v1.1) -->
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

        <!-- Option notification push (v1.1) -->
        <div class="form-group" style="background:#f0fdf4;border-radius:8px;padding:12px;border:1px solid #bbf7d0">
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer">
            <input type="checkbox" name="send_notification" value="1" checked style="margin-top:2px">
            <div>
              <span style="font-size:13px;font-weight:600;color:#15803d">🔔 Notifier le citoyen</span>
              <p style="font-size:11px;color:#166534;margin:2px 0 0">
                Envoie une notification push sur l'application mobile du citoyen
              </p>
            </div>
          </label>
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
