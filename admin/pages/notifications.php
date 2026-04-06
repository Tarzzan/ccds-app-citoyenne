<?php
/**
 * Ma Commune Back-Office — Gestion des notifications push
 * v1.1 : liste, envoi manuel, statistiques
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../backend/config/NotificationStore.php';
$admin = require_admin_auth();
$isAdmin = ($admin['role'] ?? '') === 'admin';

$db = Database::getInstance();

// --- Traitement POST : envoi d'une notification manuelle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_manual') {
        if (!$isAdmin) {
            $_SESSION['flash_error'] = 'Seuls les administrateurs peuvent envoyer une notification manuelle.';
            header('Location: /admin/?page=notifications');
            exit;
        }

        $target    = $_POST['target'] ?? 'all';       // 'all' | 'user'
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $title     = trim($_POST['notif_title'] ?? '');
        $body      = trim($_POST['notif_body']  ?? '');
        $notif_type = 'system';

        if (strlen($title) < 2 || strlen($body) < 2) {
            $_SESSION['flash_error'] = 'Titre et message sont obligatoires. L envoi ne part pas tant que l intention et la consigne ne sont pas lisibles.';
        } else {
            // Récupérer les tokens concernés
            if ($target === 'user' && $user_id > 0) {
                $tokens_stmt = $db->prepare("SELECT pt.token, pt.user_id FROM push_tokens pt WHERE pt.user_id = ?");
                $tokens_stmt->execute([$user_id]);
            } else {
                $tokens_stmt = $db->query("SELECT pt.token, pt.user_id FROM push_tokens pt");
            }
            $token_rows = $tokens_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($token_rows)) {
                $_SESSION['flash_error'] = 'Aucun appareil enregistré pour cette cible. Vérifier qu au moins un citoyen actif possède un token push avant de relancer l envoi.';
            } else {
                // Insérer les notifications en base
                $seen_users = [];
                foreach ($token_rows as $row) {
                    if (!in_array($row['user_id'], $seen_users)) {
                        NotificationStore::insert(
                            $db,
                            (int) $row['user_id'],
                            null,
                            $notif_type,
                            $title,
                            $body,
                            ['source' => 'admin_manual'],
                            0
                        );
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
                    $_SESSION['flash_error'] = "Erreur d'envoi : $err. Le message n a pas quitté le backoffice.";
                } else {
                    $count = count($seen_users);
                    $_SESSION['flash_success'] = "Notification envoyée à $count utilisateur(s). Vérifier ensuite l historique pour confirmer la bonne cible et l état de lecture.";
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
    $stats['total_tokens']  = $db->query("SELECT COUNT(*) FROM push_tokens")->fetchColumn();
    $stats['total_notifs']  = $db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $stats['unread_notifs'] = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    $stats['users_with_tokens'] = $db->query("SELECT COUNT(DISTINCT user_id) FROM push_tokens")->fetchColumn();
} catch (PDOException $e) {
    $stats = ['total_tokens' => 'N/A', 'total_notifs' => 'N/A', 'unread_notifs' => 'N/A', 'users_with_tokens' => 'N/A'];
}

// --- Dernières notifications envoyées ---
$recent_notifs = [];
try {
    $sentColumn = NotificationStore::timestampColumn($db);
    $recent_stmt = $db->query("
        SELECT n.*, n.{$sentColumn} AS sent_at, u.full_name AS user_name
        FROM notifications n
        JOIN users u ON u.id = n.user_id
        ORDER BY n.{$sentColumn} DESC
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
<div class="page-hero page-hero--with-visual">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Lien citoyen</div>
    <h2 class="page-hero-title">Parler peu, mais au bon moment.</h2>
    <p class="page-hero-text">
      Les notifications doivent remercier, informer ou orienter. Cette surface sert a garder ce canal utile, lisible et non intrusif.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$stats['users_with_tokens'] ?></span>
      <span class="hero-chip-label">appareil(s) joignable(s)</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$stats['total_notifs'] ?></span>
      <span class="hero-chip-label">notification(s) envoye(e)s</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$stats['unread_notifs'] ?></span>
      <span class="hero-chip-label">encore non lue(s)</span>
    </div>
  </div>
  <div class="page-hero-visual">
    <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
      <?= generated_visual_html('ILL-02', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Signal diffusion']) ?>
      <?= generated_visual_html('ILL-05', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Cadence utile']) ?>
      <?= generated_visual_html('CHAR-05', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais notifications']) ?>
      <div class="generated-visual-caption hero-visual-stack-copy">
        <strong>Canal utile</strong>
        <span>Informer, remercier ou orienter sans transformer la pile en bruit permanent.</span>
      </div>
    </div>
  </div>
</div>

<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Bonne pratique</div>
    <h3>Ne notifier que lorsqu il y a une vraie valeur pour l habitant.</h3>
    <p>
      Une notification utile explique un changement, un commentaire ou une information municipale importante. Elle n existe pas pour remplir la pile.
    </p>
    <div class="admin-guidance-visual">
      <?= generated_visual_html('ILL-01', ['class' => 'generated-visual generated-visual--cover admin-proof-thumb admin-proof-thumb--small', 'label' => 'Info utile']) ?>
      <div class="admin-guidance-visual-copy">
        <strong>Informer sans saturer</strong>
        <span>Un bon envoi doit laisser une impression de service attentif, pas une sensation de spam.</span>
      </div>
    </div>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Objectif produit</div>
    <h3>Transformer l alerte en signe de confiance.</h3>
    <p>
      Chaque envoi doit renforcer l impression d un service attentif, capable de remercier, d expliquer et d orienter sans sur-solliciter.
    </p>
    <div class="admin-guidance-visual">
      <?= generated_visual_html('CHAR-04', ['class' => 'generated-visual generated-visual--portrait admin-proof-thumb admin-proof-thumb--small', 'label' => 'Relais citoyen']) ?>
      <div class="admin-guidance-visual-copy">
        <strong>Une parole municipale plus claire</strong>
        <span>Le backoffice doit aider a choisir le bon ton, la bonne cible et le bon moment.</span>
      </div>
    </div>
  </div>
</div>

<div class="page-async-scope" data-async-scope="notifications-admin">
<!-- Statistiques -->
<div class="notifications-kpi-grid">
  <div class="card notifications-kpi-card">
    <div class="notifications-kpi-value notifications-kpi-value--green"><?= $stats['users_with_tokens'] ?></div>
    <div class="text-muted text-small">Appareils enregistrés</div>
  </div>
  <div class="card notifications-kpi-card">
    <div class="notifications-kpi-value notifications-kpi-value--blue"><?= $stats['total_tokens'] ?></div>
    <div class="text-muted text-small">Tokens actifs</div>
  </div>
  <div class="card notifications-kpi-card">
    <div class="notifications-kpi-value notifications-kpi-value--violet"><?= $stats['total_notifs'] ?></div>
    <div class="text-muted text-small">Notifications envoyées</div>
  </div>
  <div class="card notifications-kpi-card">
    <div class="notifications-kpi-value notifications-kpi-value--amber"><?= $stats['unread_notifs'] ?></div>
    <div class="text-muted text-small">Non lues</div>
  </div>
</div>

<?php if (!$isAdmin): ?>
<div class="alert alert-warning notifications-readonly-alert">Cette page est en lecture seule pour votre role. Les envois manuels sont reserves aux administrateurs.</div>
<?php endif; ?>

<div class="<?= $isAdmin ? 'notifications-layout' : '' ?>">

  <!-- Formulaire d'envoi manuel -->
  <?php if ($isAdmin): ?>
    <div class="card">
      <div class="card-header card-header--split">
        <div>
          <span class="card-title">Envoyer une notification</span>
          <p class="admin-section-note">Cadrer un message utile avant envoi : qui reçoit quoi, et dans quel contexte produit.</p>
        </div>
      </div>
      <div class="admin-form-guide">
        <strong>Ordre conseille</strong>
        <div class="admin-form-guide-list">
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">01</span>
            <div>
              <strong>Choisir la bonne cible</strong>
              <span>Utiliser `Tous les citoyens` seulement pour une information transversale ou un changement de service large.</span>
            </div>
          </div>
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">02</span>
            <div>
              <strong>Nommer l action</strong>
              <span>Le titre doit annoncer le fait utile, pas seulement l intention municipale.</span>
            </div>
          </div>
          <div class="admin-form-guide-item">
            <span class="admin-form-guide-step">03</span>
            <div>
              <strong>Rester bref</strong>
              <span>Le message doit expliquer quoi faire, quoi lire ou quel changement attendre, sans remplir la pile pour rien.</span>
            </div>
          </div>
        </div>
      </div>
      <form method="POST" action="" data-async-form>
        <input type="hidden" name="action" value="send_manual">

        <div class="form-group">
          <label class="form-label">Destinataires</label>
          <select name="target" class="form-control" id="target-select"
                  onchange="document.getElementById('user-select').style.display=this.value==='user'?'block':'none'">
            <option value="all">Tous les citoyens</option>
            <option value="user">Un citoyen spécifique</option>
          </select>
        </div>

        <div class="form-group notifications-user-select" id="user-select">
          <label class="form-label">Citoyen</label>
          <select name="user_id" class="form-control">
            <option value="">-- Choisir --</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?> (<?= e($u['email']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Titre <span class="notifications-required">*</span></label>
          <input type="text" name="notif_title" class="form-control"
                 placeholder="Ex: Maintenance planifiée" maxlength="100" required>
          <div class="admin-section-note">Formuler le fait saillant en premier : maintenance, fermeture, rappel, retour de service.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Message <span class="notifications-required">*</span></label>
          <textarea name="notif_body" class="form-control" rows="3"
                    placeholder="Ex: Des travaux de maintenance auront lieu demain de 8h à 12h…"
                    maxlength="500" required></textarea>
          <div class="admin-section-note">Garder un message actionnable : ce qui change, quand, et si l habitant doit faire quelque chose.</div>
        </div>

        <button type="submit" class="btn btn-primary w-100 notifications-submit">
          Envoyer la notification
        </button>
      </form>
    </div>
  <?php endif; ?>

  <!-- Historique des notifications -->
  <div class="card">
    <div class="card-header card-header--split">
      <div>
        <span class="card-title">Dernières notifications</span>
        <p class="admin-section-note">Lecture rapide des derniers envois pour vérifier le type de message, le destinataire et l’état de lecture.</p>
      </div>
      <span class="badge badge-gray"><?= count($recent_notifs) ?> envoi<?= count($recent_notifs) > 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($recent_notifs)): ?>
      <div class="admin-empty-state">
        <strong>Aucune notification envoyée pour l instant.</strong>
        <span>La pile reste vide tant qu aucun message manuel ou événement produit n a déclenché d envoi. C est un état normal sur un canal peu sollicité.</span>
      </div>
    <?php else: ?>
      <div class="table-wrapper">
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
                'status_change'  => 'Statut',
                'new_comment'    => 'Commentaire',
                'vote_milestone' => 'Soutien',
                'system'         => 'Systeme',
              ];
              $icon = $type_icons[$n['type']] ?? 'Info';
            ?>
            <tr>
              <td><?= $icon ?> · <?= e($n['type']) ?></td>
              <td><?= e($n['user_name']) ?></td>
              <td class="notifications-title-cell"
                  title="<?= e($n['body']) ?>">
                <?= e($n['title']) ?>
              </td>
              <td class="text-muted text-small"><?= format_date($n['sent_at']) ?></td>
              <td>
                <?= $n['is_read']
                  ? '<span class="notifications-read-state notifications-read-state--read">Lu</span>'
                  : '<span class="notifications-read-state notifications-read-state--pending">En attente</span>'
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
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
