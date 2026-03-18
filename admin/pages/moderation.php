<?php
/**
 * Ma Commune Back-Office — Modération des commentaires signalés
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Modération';
$active_nav = 'moderation';
$db         = Database::getInstance();

if ($admin['role'] !== 'admin') {
    render_error(403, 'Accès réservé aux administrateurs.');
}

function moderation_log(PDO $db, int $userId, string $action, string $targetType, ?int $targetId, array $details = []): void
{
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $action,
            $targetType,
            $targetId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        return;
    } catch (Throwable $e) {
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (admin_id, action, entity, entity_id, old_value, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $action,
            $targetType,
            $targetId,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $reportId = (int)($_POST['report_id'] ?? 0);

    if ($reportId > 0) {
        $stmt = $db->prepare("
            SELECT cr.*, c.user_id AS author_id, c.comment AS comment_body
            FROM comment_reports cr
            JOIN comments c ON c.id = cr.comment_id
            WHERE cr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($report) {
            if ($action === 'approve') {
                $db->prepare("UPDATE comment_reports SET status = 'dismissed', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                   ->execute([$admin['id'], $reportId]);
                $_SESSION['flash_success'] = 'Le signalement de commentaire a été classé sans suite.';
                moderation_log($db, (int)$admin['id'], 'comment_report.dismissed', 'comment_report', $reportId, ['comment_id' => (int)$report['comment_id']]);
            } elseif ($action === 'delete') {
                $db->beginTransaction();
                try {
                    $db->prepare("DELETE FROM comments WHERE id = ?")->execute([(int)$report['comment_id']]);
                    $db->prepare("UPDATE comment_reports SET status = 'actioned', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                       ->execute([$admin['id'], $reportId]);
                    $db->commit();
                    $_SESSION['flash_success'] = 'Le commentaire a été supprimé.';
                    moderation_log($db, (int)$admin['id'], 'comment.deleted_via_admin', 'comment', (int)$report['comment_id'], ['report_id' => $reportId]);
                } catch (Throwable $e) {
                    $db->rollBack();
                    $_SESSION['flash_error'] = 'Impossible de supprimer le commentaire.';
                }
            } elseif ($action === 'ban_user') {
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([(int)$report['author_id']]);
                    $db->prepare("DELETE FROM comments WHERE id = ?")->execute([(int)$report['comment_id']]);
                    $db->prepare("UPDATE comment_reports SET status = 'actioned', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                       ->execute([$admin['id'], $reportId]);
                    $db->commit();
                    $_SESSION['flash_success'] = 'L’utilisateur a été suspendu et le commentaire retiré.';
                    moderation_log($db, (int)$admin['id'], 'user.suspended_via_moderation', 'user', (int)$report['author_id'], ['report_id' => $reportId, 'comment_id' => (int)$report['comment_id']]);
                } catch (Throwable $e) {
                    $db->rollBack();
                    $_SESSION['flash_error'] = 'Impossible de suspendre cet utilisateur.';
                }
            }
        }
    }

    header('Location: /admin/?page=moderation');
    exit;
}

$stats = [
    'pending'   => 0,
    'dismissed' => 0,
    'actioned'  => 0,
];

try {
    $stats = $db->query("
        SELECT
            SUM(status = 'pending')   AS pending,
            SUM(status = 'dismissed') AS dismissed,
            SUM(status = 'actioned')  AS actioned
        FROM comment_reports
    ")->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (Throwable $e) {
}

$stmt = $db->query("
    SELECT cr.id AS report_id, cr.reason, cr.description, cr.status, cr.created_at,
           c.id AS comment_id, c.comment, c.is_edited,
           author.id AS author_id, author.full_name AS author_name, author.email AS author_email, author.role AS author_role,
           reporter.full_name AS reporter_name,
           i.id AS incident_id, i.reference AS incident_ref, i.title AS incident_title
    FROM comment_reports cr
    JOIN comments c ON c.id = cr.comment_id
    JOIN users author ON author.id = c.user_id
    JOIN users reporter ON reporter.id = cr.reporter_id
    JOIN incidents i ON i.id = c.incident_id
    WHERE cr.status = 'pending'
    ORDER BY cr.created_at DESC
");
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>
<style>
.moderation-hero {
  background: linear-gradient(135deg, #a64b2a 0%, #174b3a 65%, #2d6f86 100%);
  color: #fff;
  border-radius: 28px;
  padding: 28px;
  margin-bottom: 24px;
  box-shadow: 0 18px 38px rgba(14, 49, 39, .16);
}
.moderation-kicker {
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #f2d58c;
  margin-bottom: 10px;
}
.moderation-title {
  font-size: 30px;
  font-family: 'Merriweather', serif;
  font-weight: 900;
  line-height: 1.15;
  margin-bottom: 10px;
}
.moderation-text {
  color: rgba(255,255,255,.84);
  max-width: 620px;
}
.moderation-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.moderation-stat {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-radius: 20px;
  padding: 18px;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.moderation-stat-value {
  font-size: 28px;
  font-weight: 900;
  color: #183229;
}
.moderation-stat-label {
  font-size: 13px;
  color: #5e6c67;
  margin-top: 4px;
}
.report-card {
  background: rgba(255,253,248,.94);
  border: 1px solid #ece4d5;
  border-left: 6px solid #c94b3c;
  border-radius: 20px;
  padding: 20px;
  margin-bottom: 16px;
  box-shadow: 0 10px 24px rgba(14, 49, 39, .06);
}
.report-meta {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.report-badge {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}
.report-body {
  background: #fff7f2;
  border: 1px solid #efd9cf;
  border-radius: 16px;
  padding: 14px;
  color: #183229;
  line-height: 1.6;
  margin-bottom: 14px;
}
.report-link {
  font-size: 13px;
  color: #5e6c67;
  margin-bottom: 10px;
}
.report-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.report-actions form {
  display: inline;
}
.moderation-empty {
  text-align: center;
  padding: 56px 20px;
  color: #8d958f;
}
</style>

<div class="moderation-hero">
  <div class="moderation-kicker">Protection de la communauté</div>
  <div class="moderation-title">Commentaires signalés à examiner</div>
  <div class="moderation-text">
    Cette file de modération permet d’arbitrer les contenus signalés et de protéger un espace civique utile, lisible et respectueux.
  </div>
</div>

<div class="moderation-stats">
  <div class="moderation-stat">
    <div class="moderation-stat-value"><?= (int)($stats['pending'] ?? 0) ?></div>
    <div class="moderation-stat-label">signalements en attente</div>
  </div>
  <div class="moderation-stat">
    <div class="moderation-stat-value"><?= (int)($stats['dismissed'] ?? 0) ?></div>
    <div class="moderation-stat-label">classés sans suite</div>
  </div>
  <div class="moderation-stat">
    <div class="moderation-stat-value"><?= (int)($stats['actioned'] ?? 0) ?></div>
    <div class="moderation-stat-label">actions de modération</div>
  </div>
</div>

<?php if (empty($reports)): ?>
  <div class="moderation-empty">
    <div style="font-size:46px;margin-bottom:12px;">✅</div>
    <p>Aucun commentaire signalé à traiter.</p>
  </div>
<?php else: ?>
  <?php foreach ($reports as $report): ?>
    <div class="report-card">
      <div class="report-meta">
        <strong><?= e($report['author_name']) ?></strong>
        <span class="report-badge" style="background:#efe7d7;color:#5e6c67"><?= e(role_label($report['author_role'])) ?></span>
        <span class="report-badge" style="background:#fff1cf;color:#9f6816"><?= e($report['reason']) ?></span>
        <span style="color:#8d958f;font-size:12px"><?= e($report['author_email']) ?></span>
        <span style="margin-left:auto;color:#8d958f;font-size:12px"><?= format_date($report['created_at']) ?></span>
      </div>

      <div class="report-link">
        📌 Signalement : <a href="/admin/?page=incident_detail&id=<?= $report['incident_id'] ?>"><?= e($report['incident_ref']) ?> — <?= e($report['incident_title'] ?: 'Sans titre') ?></a>
        · signalé par <?= e($report['reporter_name']) ?>
      </div>

      <?php if (!empty($report['description'])): ?>
        <div class="report-link">Motif détaillé : <?= e($report['description']) ?></div>
      <?php endif; ?>

      <div class="report-body">
        <?= nl2br(e($report['comment'])) ?>
        <?php if (!empty($report['is_edited'])): ?>
          <span style="font-size:11px;color:#8d958f;margin-left:8px">(modifié)</span>
        <?php endif; ?>
      </div>

      <div class="report-actions">
        <form method="POST">
          <input type="hidden" name="report_id" value="<?= $report['report_id'] ?>">
          <input type="hidden" name="action" value="approve">
          <button type="submit" class="btn btn-outline">Classer sans suite</button>
        </form>
        <form method="POST" onsubmit="return confirm('Supprimer ce commentaire ?')">
          <input type="hidden" name="report_id" value="<?= $report['report_id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn btn-danger">Supprimer le commentaire</button>
        </form>
        <form method="POST" onsubmit="return confirm('Suspendre cet utilisateur et retirer le commentaire ?')">
          <input type="hidden" name="report_id" value="<?= $report['report_id'] ?>">
          <input type="hidden" name="action" value="ban_user">
          <button type="submit" class="btn btn-primary">Suspendre l’utilisateur</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
