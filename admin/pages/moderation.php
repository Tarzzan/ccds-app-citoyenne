<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../backend/config/ContentModerationService.php';

$admin = require_admin_auth();
$page_title = 'Modération';
$active_nav = 'moderation';
$db = Database::getInstance();
$moderation = new ContentModerationService($db);

if (($admin['role'] ?? '') !== 'admin') {
    render_error(403, 'Accès réservé aux administrateurs.');
}

function moderation_group_counts(array $rows, array $labels): string
{
    $parts = [];
    foreach ($rows as $reason => $count) {
        $parts[] = ($labels[$reason] ?? $reason) . ' · ' . $count;
    }

    return implode(' / ', $parts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $moderation->isAvailable()) {
    $action = $_POST['action'] ?? '';
    $adminId = (int)$admin['id'];

    try {
        switch ($action) {
            case 'save_settings':
                $moderation->saveSettings($_POST, $adminId);
                $_SESSION['flash_success'] = 'Réglages de modération enregistrés. Les prochains arbitrages automatiques utiliseront désormais ce cadrage.';
                break;

            case 'dismiss_photo_reports':
                $moderation->dismissPhotoReports((int)($_POST['photo_id'] ?? 0), $adminId);
                $_SESSION['flash_success'] = 'Signalement photo classé sans suite. La preuve reste visible tant qu une autre décision n est pas prise.';
                break;

            case 'hide_photo':
                $reason = $_POST['reason'] ?? ContentModerationService::PHOTO_REASON_INAPPROPRIATE;
                $moderation->hidePhotoByAdmin((int)($_POST['photo_id'] ?? 0), $reason, $adminId);
                $_SESSION['flash_success'] = 'Photo remplacée côté public. Le dossier conserve la trace de la décision pour l équipe.';
                break;

            case 'restore_photo':
                $moderation->restorePhoto((int)($_POST['photo_id'] ?? 0), $adminId);
                $_SESSION['flash_success'] = 'Photo restaurée côté public. La preuve citoyenne redevient visible dans l application.';
                break;

            case 'dismiss_comment_reports':
                $moderation->dismissCommentReports((int)($_POST['comment_id'] ?? 0), $adminId);
                $_SESSION['flash_success'] = 'Signalement commentaire classé sans suite. Le texte reste visible tant qu une autre décision n est pas prise.';
                break;

            case 'hide_comment':
                $reason = $_POST['reason'] ?? ContentModerationService::COMMENT_REASON_INAPPROPRIATE;
                $moderation->hideCommentByAdmin((int)($_POST['comment_id'] ?? 0), $reason, $adminId);
                $_SESSION['flash_success'] = 'Commentaire masqué côté public. Le backoffice garde la version source pour l arbitrage.';
                break;

            case 'restore_comment':
                $moderation->restoreComment((int)($_POST['comment_id'] ?? 0), $adminId);
                $_SESSION['flash_success'] = 'Commentaire restauré côté public. La version d origine redevient visible pour les habitants.';
                break;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage() ?: 'Impossible de traiter cette action de modération. Vérifier le contenu, le motif choisi et la disponibilité du service.';
    }

    header('Location: /admin/?page=moderation');
    exit;
}

$settings = $moderation->getSettings();
$photoPending = [];
$commentPending = [];
$stats = [
    'pending_photo_content' => 0,
    'pending_comment_content' => 0,
    'auto_hidden_photos' => 0,
    'hidden_comments' => 0,
];

if ($moderation->isAvailable()) {
    try {
        $stats['pending_photo_content'] = (int)$db->query("SELECT COUNT(DISTINCT photo_id) FROM photo_reports WHERE status = 'pending'")->fetchColumn();
        $stats['pending_comment_content'] = (int)$db->query("SELECT COUNT(DISTINCT comment_id) FROM comment_reports WHERE status = 'pending'")->fetchColumn();
        $stats['auto_hidden_photos'] = (int)$db->query("SELECT COUNT(*) FROM photos WHERE moderation_status IN ('auto_hidden', 'hidden_by_admin')")->fetchColumn();
        $stats['hidden_comments'] = (int)$db->query("SELECT COUNT(*) FROM comments WHERE moderation_status IN ('auto_hidden', 'hidden_by_admin')")->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        $photoRows = $db->query("
            SELECT pr.id AS report_id, pr.photo_id, pr.reason, pr.created_at, pr.reporter_id,
                   reporter.full_name AS reporter_name,
                   p.file_path, p.file_name, p.moderation_status, p.moderation_reason, p.moderation_placeholder_key, p.moderation_report_count,
                   i.id AS incident_id, i.reference AS incident_ref, i.title AS incident_title,
                   owner.full_name AS owner_name
            FROM photo_reports pr
            JOIN photos p ON p.id = pr.photo_id
            JOIN incidents i ON i.id = pr.incident_id
            JOIN users reporter ON reporter.id = pr.reporter_id
            JOIN users owner ON owner.id = i.user_id
            WHERE pr.status = 'pending'
            ORDER BY pr.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($photoRows as $row) {
            $photoId = (int)$row['photo_id'];
            if (!isset($photoPending[$photoId])) {
                $publicPhoto = $moderation->publicPhotoPayload([
                    'url' => $moderation->uploadUrl($row['file_path']),
                    'moderation_status' => $row['moderation_status'],
                    'moderation_reason' => $row['moderation_reason'],
                    'moderation_placeholder_key' => $row['moderation_placeholder_key'],
                ]);
                $photoPending[$photoId] = [
                    'photo_id' => $photoId,
                    'incident_id' => (int)$row['incident_id'],
                    'incident_ref' => $row['incident_ref'],
                    'incident_title' => $row['incident_title'],
                    'owner_name' => $row['owner_name'],
                    'original_url' => $moderation->uploadUrl($row['file_path']),
                    'public_url' => $publicPhoto['url'],
                    'public_message' => $publicPhoto['moderation_message'],
                    'moderation_status' => $row['moderation_status'],
                    'report_count' => 0,
                    'reason_counts' => [],
                    'reporters' => [],
                    'latest_at' => $row['created_at'],
                ];
            }

            $photoPending[$photoId]['report_count']++;
            $photoPending[$photoId]['reason_counts'][$row['reason']] = ($photoPending[$photoId]['reason_counts'][$row['reason']] ?? 0) + 1;
            $photoPending[$photoId]['reporters'][] = $row['reporter_name'];
        }
    } catch (Throwable $e) {
    }

    try {
        $commentRows = $db->query("
            SELECT cr.id AS report_id, cr.comment_id, cr.reason, cr.created_at, cr.reporter_id,
                   reporter.full_name AS reporter_name,
                   c.comment, c.moderation_status, c.moderation_reason, c.moderation_report_count,
                   author.full_name AS author_name,
                   i.id AS incident_id, i.reference AS incident_ref, i.title AS incident_title
            FROM comment_reports cr
            JOIN comments c ON c.id = cr.comment_id
            JOIN users reporter ON reporter.id = cr.reporter_id
            JOIN users author ON author.id = c.user_id
            JOIN incidents i ON i.id = c.incident_id
            WHERE cr.status = 'pending'
            ORDER BY cr.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($commentRows as $row) {
            $commentId = (int)$row['comment_id'];
            if (!isset($commentPending[$commentId])) {
                $publicComment = $moderation->publicCommentPayload([
                    'comment' => $row['comment'],
                    'moderation_status' => $row['moderation_status'],
                    'moderation_reason' => $row['moderation_reason'],
                ]);
                $commentPending[$commentId] = [
                    'comment_id' => $commentId,
                    'incident_id' => (int)$row['incident_id'],
                    'incident_ref' => $row['incident_ref'],
                    'incident_title' => $row['incident_title'],
                    'author_name' => $row['author_name'],
                    'original_comment' => $row['comment'],
                    'public_comment' => $publicComment['comment'],
                    'moderation_status' => $row['moderation_status'],
                    'report_count' => 0,
                    'reason_counts' => [],
                    'reporters' => [],
                    'latest_at' => $row['created_at'],
                ];
            }

            $commentPending[$commentId]['report_count']++;
            $commentPending[$commentId]['reason_counts'][$row['reason']] = ($commentPending[$commentId]['reason_counts'][$row['reason']] ?? 0) + 1;
            $commentPending[$commentId]['reporters'][] = $row['reporter_name'];
        }
    } catch (Throwable $e) {
    }
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="moderation-shell page-async-scope" data-async-scope="moderation-admin">
  <div class="page-hero page-hero--with-visual moderation-hero moderation-hero--hybrid">
    <div class="page-hero-copy">
      <div class="page-hero-kicker moderation-kicker">Protection de la communauté</div>
      <div class="page-hero-title">Modérer les preuves citoyennes et les commentaires sans casser l’usage public</div>
      <?php if ($isTrainingMode): ?>
      <div class="page-hero-text">
        Cette file rassemble les alertes de modération, le retrait automatique configurable et les réglages “super admin”.
        Les contenus retirés restent auditables, restaurables et clairement remplaçables côté public.
      </div>
      <?php endif; ?>
    </div>
    <div class="page-hero-metrics">
      <div class="hero-chip">
        <span class="hero-chip-value"><?= $stats['pending_photo_content'] ?></span>
        <span class="hero-chip-label">photos a arbitrer</span>
      </div>
      <div class="hero-chip">
        <span class="hero-chip-value"><?= $stats['pending_comment_content'] ?></span>
        <span class="hero-chip-label">commentaires a arbitrer</span>
      </div>
      <div class="hero-chip">
        <span class="hero-chip-value"><?= $stats['auto_hidden_photos'] + $stats['hidden_comments'] ?></span>
        <span class="hero-chip-label">contenus deja retires</span>
      </div>
    </div>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
        <?= generated_visual_html('ILL-05', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Cadre de moderation']) ?>
        <?= generated_visual_html('ILL-02', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Signal communautaire']) ?>
        <?= generated_visual_html('CHAR-04', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais moderation']) ?>
        <?php if ($isTrainingMode): ?>
        <div class="generated-visual-caption hero-visual-stack-copy">
          <strong>Arbitrer sans casser la confiance</strong>
          <span>Une moderation utile protege la communaute tout en gardant une lecture claire des preuves et des decisions.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($isTrainingMode): ?>
  <div class="admin-guidance-grid">
    <div class="admin-guidance-card">
      <div class="admin-guidance-kicker">Bonne lecture</div>
      <h3>Arbitrer un contenu, pas punir a l aveugle.</h3>
      <p>
        Le bon usage consiste a lire le contexte du dossier, verifier le motif de signalement et choisir l action la plus lisible pour le citoyen comme pour l equipe.
      </p>
    </div>
    <div class="admin-guidance-card">
      <div class="admin-guidance-kicker">Reflexe utile</div>
      <h3>Commencer par la file, finir par la decision la moins irreversible.</h3>
      <p>
        Quand un doute persiste, classer ou masquer provisoirement vaut mieux qu une suppression trop rapide qui brouille ensuite la trace d exploitation.
      </p>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$moderation->isAvailable()): ?>
    <div class="moderation-empty">
      La modération V1.1.0 n’est pas encore disponible sur cet environnement. Lancez d’abord la migration backend correspondante.
    </div>
  <?php else: ?>
    <div class="moderation-grid">
      <div class="moderation-kpi"><strong><?= $stats['pending_photo_content'] ?></strong><span>photos à arbitrer</span></div>
      <div class="moderation-kpi"><strong><?= $stats['pending_comment_content'] ?></strong><span>commentaires à arbitrer</span></div>
      <div class="moderation-kpi"><strong><?= $stats['auto_hidden_photos'] ?></strong><span>photos retirées côté public</span></div>
      <div class="moderation-kpi"><strong><?= $stats['hidden_comments'] ?></strong><span>commentaires masqués côté public</span></div>
    </div>

    <?php if (empty($photoPending) && empty($commentPending)): ?>
      <div class="admin-empty-state admin-empty-state--spaced">
        <strong>Aucun arbitrage en attente pour le moment.</strong>
        <span>La file est propre. Garder ce poste pour les réglages, les contrôles ponctuels et la relecture des décisions déjà prises.</span>
      </div>
    <?php endif; ?>

    <div class="moderation-duo">
      <section class="moderation-queues">
        <div class="moderation-section-title">File photos</div>
        <?php if (empty($photoPending)): ?>
          <div class="moderation-empty">Aucune photo signalée en attente. Les prochaines alertes réapparaîtront ici avec leur motif, leurs signaleurs et la vue publique actuelle.</div>
        <?php else: ?>
          <?php foreach ($photoPending as $photo): ?>
            <article class="moderation-queue-card">
              <div class="moderation-queue-head">
                <div>
                  <h3><?= e($photo['incident_ref']) ?> · <?= e($photo['incident_title']) ?></h3>
                  <div class="moderation-meta">Photo citoyenne de <?= e($photo['owner_name']) ?> · <?= $photo['report_count'] ?> signalement(s) · Dernier vote <?= format_date($photo['latest_at']) ?></div>
                </div>
                <a href="/admin/?page=incident_detail&id=<?= (int)$photo['incident_id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Ouvrir le dossier</a>
              </div>

              <div class="moderation-badges">
                <span class="moderation-badge"><?= e(moderation_group_counts($photo['reason_counts'], ContentModerationService::photoReasons())) ?></span>
                <span class="moderation-badge"><?= e(implode(', ', array_unique($photo['reporters']))) ?></span>
                <span class="moderation-badge"><?= e($photo['moderation_status']) ?></span>
              </div>

              <div class="moderation-preview-grid">
                <div class="moderation-preview-pane">
                  <div class="moderation-preview-label">Original</div>
                  <span class="admin-proof-thumb-wrap">
                    <img src="<?= e($photo['original_url']) ?>" alt="Photo originale" class="moderation-image">
                    <?php if ((int)$photo['report_count'] > 1): ?>
                      <span class="admin-proof-thumb-badge"><?= (int)$photo['report_count'] ?></span>
                    <?php endif; ?>
                  </span>
                  <div class="moderation-preview-copy">
                    <strong>Capture source citoyenne</strong>
                    <span>Transmise par <?= e($photo['owner_name']) ?> dans le dossier <?= e($photo['incident_ref']) ?>.</span>
                    <span>Cette vue sert d’arbitrage avant remplacement ou restauration.</span>
                  </div>
                </div>
                <div class="moderation-preview-pane">
                  <div class="moderation-preview-label">Vue publique actuelle</div>
                  <span class="admin-proof-thumb-wrap">
                    <img src="<?= e($photo['public_url']) ?>" alt="Vue publique" class="moderation-image">
                  </span>
                  <div class="moderation-preview-copy">
                    <strong><?= !empty($photo['public_message']) ? 'Remplacement actuellement visible' : 'Photo actuellement visible côté public' ?></strong>
                    <span><?= !empty($photo['public_message']) ? 'Le public voit déjà un visuel de substitution ou un message de retrait.' : 'Aucun remplacement n’est encore appliqué sur l’application citoyenne.' ?></span>
                  </div>
                  <?php if (!empty($photo['public_message'])): ?>
                    <div class="moderation-text-box moderation-text-box--spaced"><?= e($photo['public_message']) ?></div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="moderation-actions">
                <form method="POST">
                  <input type="hidden" name="action" value="dismiss_photo_reports">
                  <input type="hidden" name="photo_id" value="<?= (int)$photo['photo_id'] ?>">
                  <button class="btn btn-outline" type="submit">Classer sans suite</button>
                </form>
                <form method="POST" class="moderation-inline">
                  <input type="hidden" name="action" value="hide_photo">
                  <input type="hidden" name="photo_id" value="<?= (int)$photo['photo_id'] ?>">
                  <select name="reason">
                    <?php foreach (ContentModerationService::photoReasons() as $reasonKey => $reasonLabel): ?>
                      <option value="<?= e($reasonKey) ?>" <?= array_key_first($photo['reason_counts']) === $reasonKey ? 'selected' : '' ?>><?= e($reasonLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary" type="submit">Remplacer côté public</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="action" value="restore_photo">
                  <input type="hidden" name="photo_id" value="<?= (int)$photo['photo_id'] ?>">
                  <button class="btn btn-outline" type="submit">Restaurer</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="moderation-section-title">File commentaires</div>
        <?php if (empty($commentPending)): ?>
          <div class="moderation-empty">Aucun commentaire signalé en attente. Cette file se remplit seulement lorsqu un texte doit être relu, masqué ou restauré.</div>
        <?php else: ?>
          <?php foreach ($commentPending as $comment): ?>
            <article class="moderation-queue-card">
              <div class="moderation-queue-head">
                <div>
                  <h3><?= e($comment['incident_ref']) ?> · <?= e($comment['incident_title']) ?></h3>
                  <div class="moderation-meta">Commentaire de <?= e($comment['author_name']) ?> · <?= $comment['report_count'] ?> signalement(s) · Dernier vote <?= format_date($comment['latest_at']) ?></div>
                </div>
                <a href="/admin/?page=incident_detail&id=<?= (int)$comment['incident_id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Ouvrir le dossier</a>
              </div>

              <div class="moderation-badges">
                <span class="moderation-badge"><?= e(moderation_group_counts($comment['reason_counts'], ContentModerationService::commentReasons())) ?></span>
                <span class="moderation-badge"><?= e(implode(', ', array_unique($comment['reporters']))) ?></span>
                <span class="moderation-badge"><?= e($comment['moderation_status']) ?></span>
              </div>

              <div class="moderation-preview-grid">
                <div class="moderation-preview-pane">
                  <div class="moderation-preview-label">Commentaire original</div>
                  <div class="moderation-text-box"><?= nl2br(e($comment['original_comment'])) ?></div>
                  <div class="moderation-preview-copy">
                    <strong>Texte source</strong>
                    <span>Publié par <?= e($comment['author_name']) ?> dans le dossier <?= e($comment['incident_ref']) ?>.</span>
                  </div>
                </div>
                <div class="moderation-preview-pane">
                  <div class="moderation-preview-label">Vue publique actuelle</div>
                  <div class="moderation-text-box"><?= nl2br(e($comment['public_comment'])) ?></div>
                  <div class="moderation-preview-copy">
                    <strong><?= $comment['moderation_status'] === 'visible' ? 'Version actuellement visible' : 'Version actuellement masquée côté public' ?></strong>
                    <span>Comparer cette vue avec l’original pour décider d’un maintien, d’un masquage ou d’une restauration.</span>
                  </div>
                </div>
              </div>

              <div class="moderation-actions">
                <form method="POST">
                  <input type="hidden" name="action" value="dismiss_comment_reports">
                  <input type="hidden" name="comment_id" value="<?= (int)$comment['comment_id'] ?>">
                  <button class="btn btn-outline" type="submit">Classer sans suite</button>
                </form>
                <form method="POST" class="moderation-inline">
                  <input type="hidden" name="action" value="hide_comment">
                  <input type="hidden" name="comment_id" value="<?= (int)$comment['comment_id'] ?>">
                  <select name="reason">
                    <?php foreach (ContentModerationService::commentReasons() as $reasonKey => $reasonLabel): ?>
                      <option value="<?= e($reasonKey) ?>" <?= array_key_first($comment['reason_counts']) === $reasonKey ? 'selected' : '' ?>><?= e($reasonLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary" type="submit">Masquer côté public</button>
                </form>
                <form method="POST">
                  <input type="hidden" name="action" value="restore_comment">
                  <input type="hidden" name="comment_id" value="<?= (int)$comment['comment_id'] ?>">
                  <button class="btn btn-outline" type="submit">Restaurer</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <aside class="moderation-settings">
        <div>
          <h2>Réglages super admin</h2>
          <small>Seuils, messages publics et arbitrage auto. Cette surface n’est disponible que dans le backoffice.</small>
        </div>
        <?php if ($isTrainingMode): ?>
        <div class="admin-form-guide">
          <strong>Ordre conseille</strong>
          <div class="admin-form-guide-list">
            <div class="admin-form-guide-item">
              <span class="admin-form-guide-step">01</span>
              <div>
                <strong>Fixer les seuils</strong>
                <span>Commencer par les seuils automatiques avant d ajuster les messages publics.</span>
              </div>
            </div>
            <div class="admin-form-guide-item">
              <span class="admin-form-guide-step">02</span>
              <div>
                <strong>Relire les messages visibles</strong>
                <span>Les messages publics doivent expliquer la mesure de moderation sans surjouer la sanction.</span>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <form method="POST" class="moderation-settings-form">
          <input type="hidden" name="action" value="save_settings">
          <div class="moderation-settings-grid">
            <label>
              Seuil auto-retrait photo
              <input type="number" min="1" max="99" name="photo_auto_hide_threshold" value="<?= e($settings['photo_auto_hide_threshold']) ?>">
            </label>
            <label>
              Seuil auto-retrait commentaire
              <input type="number" min="1" max="99" name="comment_auto_hide_threshold" value="<?= e($settings['comment_auto_hide_threshold']) ?>">
            </label>
          </div>

          <label class="moderation-toggle">
            <input type="checkbox" name="photo_auto_hide_enabled" value="1" <?= $settings['photo_auto_hide_enabled'] === '1' ? 'checked' : '' ?>>
            Activer le retrait automatique des photos
          </label>
          <label class="moderation-toggle">
            <input type="checkbox" name="comment_auto_hide_enabled" value="1" <?= $settings['comment_auto_hide_enabled'] === '1' ? 'checked' : '' ?>>
            Activer le retrait automatique des commentaires
          </label>

          <label>
            Ordre de priorité en cas d’égalité photo
            <input type="text" name="photo_tie_breaker_order" value="<?= e($settings['photo_tie_breaker_order']) ?>">
          </label>

          <label>
            Message public photo -18
            <textarea name="photo_public_message_under_18"><?= e($settings['photo_public_message_under_18']) ?></textarea>
          </label>
          <label>
            Message public photo sensible
            <textarea name="photo_public_message_sensitive"><?= e($settings['photo_public_message_sensitive']) ?></textarea>
          </label>
          <label>
            Message public photo non appropriée
            <textarea name="photo_public_message_inappropriate"><?= e($settings['photo_public_message_inappropriate']) ?></textarea>
          </label>
          <label>
            Message public commentaire masqué
            <textarea name="comment_public_message"><?= e($settings['comment_public_message']) ?></textarea>
          </label>

          <button type="submit" class="btn btn-primary">Enregistrer les réglages</button>
        </form>
      </aside>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
