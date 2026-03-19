<?php
/**
 * Ma Commune Back-Office — Pilotage temps réel
 *
 * L'ancienne version dépendait d'un ancien contexte PDO et d'une auth WebSocket
 * inexistante côté back-office. Cette page s'appuie désormais sur le bootstrap
 * actuel et un rafraîchissement JSON périodique compatible avec la session admin.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin_auth();
$page_title = 'Pilotage temps réel';
$active_nav = 'realtime_dashboard';

$db = Database::getInstance();

if (($admin['role'] ?? null) !== 'admin') {
    render_error(403, 'Accès réservé aux administrateurs.');
}

function realtime_snapshot(PDO $db): array
{
    $serviceTablesReady = admin_db_has_table($db, 'services')
        && admin_db_has_table($db, 'service_category_map')
        && admin_db_has_table($db, 'intervention_plans');
    $summaryStmt = $db->query("
        SELECT
            (SELECT COUNT(*) FROM incidents WHERE DATE(created_at) = CURDATE()) AS incidents_today,
            (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) AS users_today,
            (SELECT COUNT(*) FROM votes WHERE DATE(created_at) = CURDATE()) AS votes_today,
            (SELECT COUNT(*) FROM comments WHERE DATE(created_at) = CURDATE()) AS comments_today,
            (SELECT COUNT(*) FROM incidents WHERE status IN ('submitted', 'acknowledged', 'in_progress')) AS open_incidents,
            (SELECT COUNT(*) FROM incidents WHERE status = 'resolved' AND DATE(updated_at) = CURDATE()) AS resolved_today
    ");
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $recentStmt = $db->query("
        SELECT *
        FROM (
            SELECT
                'incident' AS type,
                COALESCE(NULLIF(i.title, ''), CONCAT('Signalement ', i.reference)) AS label,
                u.full_name AS actor_name,
                i.created_at AS happened_at,
                i.id AS incident_id
            FROM incidents i
            JOIN users u ON u.id = i.user_id

            UNION ALL

            SELECT
                'vote' AS type,
                CONCAT('Soutien sur ', COALESCE(NULLIF(i.title, ''), i.reference)) AS label,
                u.full_name AS actor_name,
                v.created_at AS happened_at,
                i.id AS incident_id
            FROM votes v
            JOIN incidents i ON i.id = v.incident_id
            JOIN users u ON u.id = v.user_id

            UNION ALL

            SELECT
                'comment' AS type,
                CONCAT('Commentaire sur ', COALESCE(NULLIF(i.title, ''), i.reference)) AS label,
                u.full_name AS actor_name,
                c.created_at AS happened_at,
                i.id AS incident_id
            FROM comments c
            JOIN incidents i ON i.id = c.incident_id
            JOIN users u ON u.id = c.user_id

            UNION ALL

            SELECT
                'user' AS type,
                'Nouvelle inscription citoyenne' AS label,
                u.full_name AS actor_name,
                u.created_at AS happened_at,
                NULL AS incident_id
            FROM users u
        ) activity
        ORDER BY happened_at DESC
        LIMIT 18
    ");
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    $series = [];
    $incidentSeriesStmt = $db->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS bucket, COUNT(*) AS total
        FROM incidents
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY bucket
        ORDER BY bucket ASC
    ");
    foreach ($incidentSeriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $series[$row['bucket']]['incidents'] = (int)$row['total'];
    }

    $voteSeriesStmt = $db->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS bucket, COUNT(*) AS total
        FROM votes
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY bucket
        ORDER BY bucket ASC
    ");
    foreach ($voteSeriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $series[$row['bucket']]['votes'] = (int)$row['total'];
    }

    $commentSeriesStmt = $db->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS bucket, COUNT(*) AS total
        FROM comments
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY bucket
        ORDER BY bucket ASC
    ");
    foreach ($commentSeriesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $series[$row['bucket']]['comments'] = (int)$row['total'];
    }

    $timeline = [];
    $cursor = new DateTimeImmutable('-23 hours');
    for ($i = 0; $i < 24; $i++) {
        $bucket = $cursor->format('Y-m-d H:00:00');
        $timeline[] = [
            'label' => $cursor->format('H\\h'),
            'incidents' => $series[$bucket]['incidents'] ?? 0,
            'votes' => $series[$bucket]['votes'] ?? 0,
            'comments' => $series[$bucket]['comments'] ?? 0,
        ];
        $cursor = $cursor->modify('+1 hour');
    }

    $activeUsersStmt = $db->query("
        SELECT actor_name, COUNT(*) AS activity_count, MAX(happened_at) AS last_seen
        FROM (
            SELECT u.full_name AS actor_name, i.created_at AS happened_at
            FROM incidents i
            JOIN users u ON u.id = i.user_id
            WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)

            UNION ALL

            SELECT u.full_name AS actor_name, v.created_at AS happened_at
            FROM votes v
            JOIN users u ON u.id = v.user_id
            WHERE v.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)

            UNION ALL

            SELECT u.full_name AS actor_name, c.created_at AS happened_at
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ) activity
        GROUP BY actor_name
        ORDER BY activity_count DESC, last_seen DESC
        LIMIT 8
    ");
    $activeUsers = $activeUsersStmt->fetchAll(PDO::FETCH_ASSOC);

    $topCategoriesStmt = $db->query("
        SELECT c.name, c.color, c.icon, COUNT(i.id) AS incident_count
        FROM categories c
        JOIN incidents i ON i.category_id = c.id
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY c.id
        ORDER BY incident_count DESC, c.name ASC
        LIMIT 4
    ");
    $topCategories = $topCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($topCategories as &$category) {
        $visual = category_visual_resolve($category['icon'] ?? null, $category['name'] ?? null);
        $category['visual_description'] = $visual['description'] ?? '';
    }
    unset($category);

    $executionSummary = null;
    if ($serviceTablesReady) {
        $executionStmt = $db->query("
            SELECT
                SUM(CASE WHEN i.status IN ('submitted','acknowledged','in_progress') AND latest_plan.id IS NOT NULL
                    AND COALESCE(latest_plan.status, '') NOT IN ('completed', 'cancelled')
                    AND COALESCE(latest_plan.source_type, 'internal') = 'internal' THEN 1 ELSE 0 END) AS internal_count,
                SUM(CASE WHEN i.status IN ('submitted','acknowledged','in_progress') AND latest_plan.id IS NOT NULL
                    AND COALESCE(latest_plan.status, '') NOT IN ('completed', 'cancelled')
                    AND latest_plan.source_type = 'provider' THEN 1 ELSE 0 END) AS provider_count,
                SUM(CASE WHEN i.status IN ('submitted','acknowledged','in_progress') AND latest_plan.id IS NULL THEN 1 ELSE 0 END) AS unplanned_count
            FROM incidents i
            LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
                SELECT p2.id
                FROM intervention_plans p2
                WHERE p2.incident_id = i.id
                ORDER BY p2.created_at DESC, p2.id DESC
                LIMIT 1
            )
        ");
        $executionSummary = $executionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return [
        'generated_at' => date(DATE_ATOM),
        'summary' => [
            'incidents_today' => (int)($summary['incidents_today'] ?? 0),
            'users_today' => (int)($summary['users_today'] ?? 0),
            'votes_today' => (int)($summary['votes_today'] ?? 0),
            'comments_today' => (int)($summary['comments_today'] ?? 0),
            'open_incidents' => (int)($summary['open_incidents'] ?? 0),
            'resolved_today' => (int)($summary['resolved_today'] ?? 0),
        ],
        'timeline' => $timeline,
        'recent' => $recent,
        'active_users' => $activeUsers,
        'top_categories' => $topCategories,
        'execution_summary' => $executionSummary,
    ];
}

$snapshot = realtime_snapshot($db);
$realtimeHeroVisual = generated_visual_url('HERO-01');

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-hero <?= $realtimeHeroVisual ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Veille operationnelle</div>
    <h2 class="page-hero-title">Flux communal sur 24 heures</h2>
    <p class="page-hero-text">
      Cette vue sert au pilotage rapide des incidents, interactions citoyennes et inscriptions récentes.
      Les données sont actualisées automatiquement depuis la base locale toutes les 20 secondes.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$snapshot['summary']['incidents_today'] ?></span>
      <span class="hero-chip-label">signalements aujourd hui</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$snapshot['summary']['resolved_today'] ?></span>
      <span class="hero-chip-label">situations resolues</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value" id="live-time"><?= date('H:i:s') ?></span>
      <span class="hero-chip-label">synchronise a l instant</span>
    </div>
  </div>
  <?php if ($realtimeHeroVisual): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <?= generated_visual_html('HERO-01', ['class' => 'generated-visual generated-visual--contain', 'label' => 'Veille operationnelle']) ?>
        <div class="generated-visual-caption">
          <strong>Vue en mouvement</strong>
          <span>La veille temps reel doit aider a capter vite les signaux qui montent sur le territoire.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($snapshot['top_categories'])): ?>
  <div class="admin-category-strip" style="margin-bottom:18px;">
    <?php foreach ($snapshot['top_categories'] as $category): ?>
      <div class="admin-category-pill">
        <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'sm', $category['color'] ?? null) ?>
        <div class="admin-category-pill-copy">
          <strong><?= e($category['name']) ?></strong>
          <span><?= e($category['visual_description'] ?: 'Categorie dominante sur les dernières 24 heures') ?></span>
        </div>
        <span class="admin-category-pill-count admin-category-pill-count--wide"><?= (int)$category['incident_count'] ?> recents</span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($snapshot['execution_summary'])): ?>
  <div class="services-mode-band" style="margin-bottom:24px;">
    <div class="services-mode-card">
      <strong>Equipe interne</strong>
      <span><?= (int)($snapshot['execution_summary']['internal_count'] ?? 0) ?> dossier<?= ((int)($snapshot['execution_summary']['internal_count'] ?? 0)) > 1 ? 's' : '' ?> actuellement portes en interne</span>
    </div>
    <div class="services-mode-card">
      <strong>Prestataire missionne</strong>
      <span><?= (int)($snapshot['execution_summary']['provider_count'] ?? 0) ?> dossier<?= ((int)($snapshot['execution_summary']['provider_count'] ?? 0)) > 1 ? 's' : '' ?> actuellement portes par un prestataire</span>
    </div>
    <div class="services-mode-card">
      <strong>A planifier</strong>
      <span><?= (int)($snapshot['execution_summary']['unplanned_count'] ?? 0) ?> dossier<?= ((int)($snapshot['execution_summary']['unplanned_count'] ?? 0)) > 1 ? 's' : '' ?> ouverts restent encore sans plan</span>
    </div>
  </div>
<?php endif; ?>

<div class="stats-grid" style="grid-template-columns:repeat(4, minmax(0, 1fr));margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon blue">📋</div>
    <div>
      <div class="stat-value" id="stat-incidents"><?= $snapshot['summary']['incidents_today'] ?></div>
      <div class="stat-label">Signalements aujourd'hui</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">👤</div>
    <div>
      <div class="stat-value" id="stat-users"><?= $snapshot['summary']['users_today'] ?></div>
      <div class="stat-label">Nouvelles inscriptions</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow">👍</div>
    <div>
      <div class="stat-value" id="stat-votes"><?= $snapshot['summary']['votes_today'] ?></div>
      <div class="stat-label">Votes citoyens</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">💬</div>
    <div>
      <div class="stat-value" id="stat-comments"><?= $snapshot['summary']['comments_today'] ?></div>
      <div class="stat-label">Commentaires</div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;align-items:start;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Activité glissante sur 24h</span>
      <span class="text-small text-muted">Signalements, votes, commentaires</span>
    </div>
    <div class="chart-container" style="height:320px;">
      <canvas id="activityChart"></canvas>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Repères du jour</span>
    </div>
    <div style="display:grid;gap:12px;">
      <div style="padding:14px;border-radius:16px;background:rgba(14, 116, 144, 0.08);">
        <div class="text-small text-muted">Dossiers encore ouverts</div>
        <div style="font-size:30px;font-weight:800;color:#0f766e;" id="stat-open"><?= $snapshot['summary']['open_incidents'] ?></div>
      </div>
      <div style="padding:14px;border-radius:16px;background:rgba(180, 83, 9, 0.08);">
        <div class="text-small text-muted">Résolus aujourd'hui</div>
        <div style="font-size:30px;font-weight:800;color:#b45309;" id="stat-resolved"><?= $snapshot['summary']['resolved_today'] ?></div>
      </div>
      <div style="padding:14px;border-radius:16px;background:rgba(30, 41, 59, 0.05);">
        <div class="text-small text-muted">Mode de lecture</div>
        <div style="font-size:14px;font-weight:700;">Rafraîchissement automatique compatible session admin</div>
        <div class="text-small text-muted" style="margin-top:6px;">
          Cette vue remplace l'ancien branchement WebSocket direct qui n'était pas exploitable depuis le back-office.
        </div>
      </div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.35fr .95fr;gap:24px;align-items:start;margin-top:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Dernières interactions</span>
      <span class="text-small text-muted">18 événements récents</span>
    </div>
    <div id="activity-feed" style="display:grid;gap:10px;">
      <?php foreach ($snapshot['recent'] as $item): ?>
        <div style="display:flex;gap:12px;padding:14px;border-radius:16px;background:rgba(255,255,255,0.58);border:1px solid rgba(30,41,59,.06);">
          <div style="font-size:20px;">
            <?= $item['type'] === 'incident' ? '📋' : ($item['type'] === 'vote' ? '👍' : ($item['type'] === 'comment' ? '💬' : '👤')) ?>
          </div>
          <div style="flex:1;">
            <div style="font-weight:700;"><?= e($item['label']) ?></div>
            <div class="text-small text-muted">
              <?= e($item['actor_name']) ?> · <?= format_date($item['happened_at']) ?>
            </div>
          </div>
          <?php if (!empty($item['incident_id'])): ?>
            <a href="/admin/?page=incident_detail&id=<?= (int)$item['incident_id'] ?>" class="btn btn-outline btn-sm">Ouvrir</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Usagers actifs sur 24h</span>
      <span class="text-small text-muted" id="active-users-count"><?= count($snapshot['active_users']) ?> profils</span>
    </div>
    <div id="active-users-list" style="display:grid;gap:10px;">
      <?php if (empty($snapshot['active_users'])): ?>
        <div class="text-muted">Aucune activité citoyenne récente détectée.</div>
      <?php else: ?>
        <?php foreach ($snapshot['active_users'] as $user): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,0.55);border:1px solid rgba(30,41,59,.06);">
            <div>
              <div style="font-weight:700;"><?= e($user['actor_name']) ?></div>
              <div class="text-small text-muted">Dernière activité : <?= format_date($user['last_seen']) ?></div>
            </div>
            <span class="badge badge-blue"><?= (int)$user['activity_count'] ?> action<?= (int)$user['activity_count'] > 1 ? 's' : '' ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const initialSnapshot = <?= json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const activityCtx = document.getElementById('activityChart').getContext('2d');
let refreshTimer = null;

const activityChart = new Chart(activityCtx, {
  type: 'bar',
  data: {
    labels: initialSnapshot.timeline.map((point) => point.label),
    datasets: [
      {
        label: 'Signalements',
        data: initialSnapshot.timeline.map((point) => point.incidents),
        backgroundColor: '#1d4ed8',
        borderRadius: 8,
      },
      {
        label: 'Votes',
        data: initialSnapshot.timeline.map((point) => point.votes),
        backgroundColor: '#ca8a04',
        borderRadius: 8,
      },
      {
        label: 'Commentaires',
        data: initialSnapshot.timeline.map((point) => point.comments),
        backgroundColor: '#15803d',
        borderRadius: 8,
      },
    ],
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        position: 'bottom',
        labels: { boxWidth: 12, usePointStyle: true },
      },
    },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } },
      x: { grid: { display: false } },
    },
  },
});

function iconFor(type) {
  if (type === 'incident') return '📋';
  if (type === 'vote') return '👍';
  if (type === 'comment') return '💬';
  return '👤';
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function renderRecent(items) {
  const feed = document.getElementById('activity-feed');
  feed.innerHTML = '';

  for (const item of items) {
    const wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;gap:12px;padding:14px;border-radius:16px;background:rgba(255,255,255,0.58);border:1px solid rgba(30,41,59,.06);';
    wrapper.innerHTML = `
      <div style="font-size:20px;">${iconFor(item.type)}</div>
      <div style="flex:1;">
        <div style="font-weight:700;">${escapeHtml(item.label)}</div>
        <div class="text-small text-muted">${escapeHtml(item.actor_name)} · ${escapeHtml(item.happened_at)}</div>
      </div>
      ${item.incident_id ? `<a href="/admin/?page=incident_detail&id=${Number(item.incident_id)}" class="btn btn-outline btn-sm">Ouvrir</a>` : ''}
    `;
    feed.appendChild(wrapper);
  }
}

function renderActiveUsers(users) {
  const list = document.getElementById('active-users-list');
  const count = document.getElementById('active-users-count');
  count.textContent = `${users.length} profil${users.length > 1 ? 's' : ''}`;
  list.innerHTML = '';

  if (!users.length) {
    const empty = document.createElement('div');
    empty.className = 'text-muted';
    empty.textContent = 'Aucune activité citoyenne récente détectée.';
    list.appendChild(empty);
    return;
  }

  for (const user of users) {
    const item = document.createElement('div');
    item.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,0.55);border:1px solid rgba(30,41,59,.06);';
    const countLabel = Number(user.activity_count) > 1 ? 'actions' : 'action';
    item.innerHTML = `
      <div>
        <div style="font-weight:700;">${escapeHtml(user.actor_name)}</div>
        <div class="text-small text-muted">Dernière activité : ${escapeHtml(user.last_seen)}</div>
      </div>
      <span class="badge badge-blue">${Number(user.activity_count)} ${countLabel}</span>
    `;
    list.appendChild(item);
  }
}

function updateSummary(summary) {
  document.getElementById('stat-incidents').textContent = summary.incidents_today;
  document.getElementById('stat-users').textContent = summary.users_today;
  document.getElementById('stat-votes').textContent = summary.votes_today;
  document.getElementById('stat-comments').textContent = summary.comments_today;
  document.getElementById('stat-open').textContent = summary.open_incidents;
  document.getElementById('stat-resolved').textContent = summary.resolved_today;
}

function updateChart(timeline) {
  activityChart.data.labels = timeline.map((point) => point.label);
  activityChart.data.datasets[0].data = timeline.map((point) => point.incidents);
  activityChart.data.datasets[1].data = timeline.map((point) => point.votes);
  activityChart.data.datasets[2].data = timeline.map((point) => point.comments);
  activityChart.update();
}

async function refreshRealtime() {
  try {
    const response = await fetch('/admin/?page=realtime_dashboard&format=json', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const payload = await response.json();
    updateSummary(payload.summary);
    updateChart(payload.timeline);
    renderRecent(payload.recent);
    renderActiveUsers(payload.active_users);

    const liveStatus = document.getElementById('live-status');
    liveStatus.className = 'badge badge-green';
    document.getElementById('live-time').textContent = new Date(payload.generated_at).toLocaleTimeString('fr-FR');
  } catch (error) {
    const liveStatus = document.getElementById('live-status');
    liveStatus.className = 'badge badge-red';
    liveStatus.textContent = 'Synchronisation indisponible';
  }
}

refreshTimer = setInterval(refreshRealtime, 20000);
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
