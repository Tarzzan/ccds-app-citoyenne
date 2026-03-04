<?php
/**
 * CCDS Back-Office — Gestion des Notifications Push
 * v1.1 : liste, envoi manuel, statistiques
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin_auth();

$db = Database::getInstance()->getConnection();

// --- Traitement POST : envoi d'une notification manuelle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_manual') {
        $target    = $_POST['target'] ?? 'all';       // 'all' | 'user'
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $title     = trim($_POST['notif_title'] ?? '');
        $body      = trim($_POST['notif_body']  ?? '');
        $notif_type = 'system';

        if (strlen($title) < 2 || strlen($body) < 2) {
            $_SESSION['flash_error'] = 'Titre et message sont obligatoires.';
        } else {
            // Récupérer les tokens concernés
            if ($target === 'user' && $user_id > 0) {
                $tokens_stmt = $db->prepare("SELECT pt.token, pt.user_id FROM push_tokens pt WHERE pt.user_id = ? AND pt.is_active = 1");
                $tokens_stmt->execute([$user_id]);
            } else {
                $tokens_stmt = $db->query("SELECT pt.token, pt.user_id FROM push_tokens pt WHERE pt.is_active = 1");
            }
            $token_rows = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($token_rows)) {
                $_SESSION['flash_error'] = 'Aucun appareil enregistré pour cette cible.';
            } else {
                // Insérer les notifications en base
                $insert_stmt = $db->prepare("
                    INSERT INTO notifications (user_id, type, title, body, sent_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $seen_users = [];
                foreach ($token_rows as $row) {
                    if (!in_array($row['user_id'], $seen_users)) {
                        $insert_stmt->execute([$row['user_id'], $notif_type, $title, $body]);
                        $seen_users[] = $row['user_id'];
                    }
                }

                // Envoyer via Expo Push API
                $tokens = array_column($token_rows, 'token');
                $messages = array_map(fn($t) => [
                    'to'    => $t,
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                ], $tokens);

                $ch = curl_init('https://exp.host/--/api/v2/push/send');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($messages),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                ]);
                $response = curl_exec($ch);
                $err      = curl_error($ch);
                curl_close($ch);

                if ($err) {
                    $_SESSION['flash_error'] = "Erreur d'envoi : $err";
                } else {
                    $count = count($seen_users);
                    $_SESSION['flash_success'] = "Notification envoyée à $count utilisateur(s).";
                }
            }
        }
        header('Location: /admin/?page=notifications');
        exit;
    }
}

// --- Statistiques ---
$stats = [];
try {
    $stats['total_tokens']  = $db->query("SELECT COUNT(*) FROM push_tokens WHERE is_active = 1")->fetchColumn();
    $stats['total_notifs']  = $db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $stats['unread_notifs'] = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    $stats['users_with_tokens'] = $db->query("SELECT COUNT(DISTINCT user_id) FROM push_tokens WHERE is_active = 1")->fetchColumn();
} catch (PDOException $e) {
    $stats = ['total_tokens' => 'N/A', 'total_notifs' => 'N/A', 'unread_notifs' => 'N/A', 'users_with_tokens' => 'N/A'];
}

// --- Dernières notifications envoyées ---
$recent_notifs = [];
try {
    $recent_stmt = $db->query("
        SELECT n.*, u.full_name AS user_name
        FROM notifications n
        JOIN users u ON u.id = n.user_id
        ORDER BY n.sent_at DESC
        LIMIT 30
    ");
    $recent_notifs = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* Table pas encore créée */ }

// --- Liste des utilisateurs pour l'envoi ciblé ---
$users = $db->query("SELECT id, full_name, email FROM users WHERE role = 'citizen' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Notifications Push';
$active_nav = 'notifications';
require_once __DIR__ . '/../includes/layout.php';
?>

<!-- Statistiques -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:32px;font-weight:800;color:#1a7a42"><?= $stats['users_with_tokens'] ?></div>
    <div class="text-muted text-small">Appareils enregistrés</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:32px;font-weight:800;color:#3b82f6"><?= $stats['total_tokens'] ?></div>
    <div class="text-muted text-small">Tokens actifs</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:32px;font-weight:800;color:#8b5cf6"><?= $stats['total_notifs'] ?></div>
    <div class="text-muted text-small">Notifications envoyées</div>
  </div>
  <div class="card" style="text-align:center;padding:20px">
    <div style="font-size:32px;font-weight:800;color:#f59e0b"><?= $stats['unread_notifs'] ?></div>
    <div class="text-muted text-small">Non lues</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:24px">

  <!-- Formulaire d'envoi manuel -->
  <div class="card">
    <div class="card-header"><span class="card-title">📤 Envoyer une notification</span></div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="send_manual">

      <div class="form-group">
        <label class="form-label">Destinataires</label>
        <select name="target" class="form-control" id="target-select"
                onchange="document.getElementById('user-select').style.display=this.value==='user'?'block':'none'">
          <option value="all">Tous les citoyens</option>
          <option value="user">Un citoyen spécifique</option>
        </select>
      </div>

      <div class="form-group" id="user-select" style="display:none">
        <label class="form-label">Citoyen</label>
        <select name="user_id" class="form-control">
          <option value="">-- Choisir --</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?> (<?= e($u['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Titre <span style="color:#ef4444">*</span></label>
        <input type="text" name="notif_title" class="form-control"
               placeholder="Ex: Maintenance planifiée" maxlength="100" required>
      </div>

      <div class="form-group">
        <label class="form-label">Message <span style="color:#ef4444">*</span></label>
        <textarea name="notif_body" class="form-control" rows="3"
                  placeholder="Ex: Des travaux de maintenance auront lieu demain de 8h à 12h…"
                  maxlength="500" required></textarea>
      </div>

      <button type="submit" class="btn btn-primary w-100" style="justify-content:center">
        🔔 Envoyer la notification
      </button>
    </form>
  </div>

  <!-- Historique des notifications -->
  <div class="card">
    <div class="card-header"><span class="card-title">📋 Dernières notifications</span></div>

    <?php if (empty($recent_notifs)): ?>
      <p class="text-muted text-small">Aucune notification envoyée pour l'instant.</p>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table class="table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Destinataire</th>
              <th>Titre</th>
              <th>Envoyée</th>
              <th>Lu</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_notifs as $n):
              $type_icons = [
                'status_change'  => '🔄',
                'new_comment'    => '💬',
                'vote_milestone' => '🎉',
                'system'         => '📢',
              ];
              $icon = $type_icons[$n['type']] ?? '🔔';
            ?>
            <tr>
              <td><?= $icon ?> <?= e($n['type']) ?></td>
              <td><?= e($n['user_name']) ?></td>
              <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  title="<?= e($n['body']) ?>">
                <?= e($n['title']) ?>
              </td>
              <td class="text-muted text-small"><?= format_date($n['sent_at']) ?></td>
              <td>
                <?= $n['is_read']
                  ? '<span style="color:#16a34a;font-size:16px">✓</span>'
                  : '<span style="color:#94a3b8;font-size:16px">○</span>'
                ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
