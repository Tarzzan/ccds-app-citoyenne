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

function realtime_snapshot(PDO $db): array
{
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
    ];
}

$snapshot = realtime_snapshot($db);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
  <div>
    <div class="text-small" style="text-transform:uppercase;letter-spacing:.16em;color:#7c8b78;">Veille opérationnelle</div>
    <h2 style="margin:6px 0 8px;font-size:28px;font-weight:800;">Flux communal sur 24 heures</h2>
    <p class="text-muted" style="max-width:760px;margin:0;">
      Cette vue sert au pilotage rapide des incidents, interactions citoyennes et inscriptions récentes.
      Les données sont actualisées automatiquement depuis la base locale toutes les 20 secondes.
    </p>
  </div>
  <div class="badge badge-green" id="live-status" style="padding:10px 14px;border-radius:999px;">
    Synchronisé à <span id="live-time" style="margin-left:6px;font-weight:800;"><?= date('H:i:s') ?></span>
  </div>
</div>

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
