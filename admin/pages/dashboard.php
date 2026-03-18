<?php
/**
 * Ma Commune Back-Office — Tableau de bord
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Tableau de bord';
$active_nav = 'dashboard';

$db = Database::getInstance();

// --- KPIs globaux ---
$kpis = $db->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(status = 'submitted')                            AS submitted,
        SUM(status = 'in_progress')                          AS in_progress,
        SUM(status = 'resolved')                             AS resolved,
        SUM(status = 'rejected')                             AS rejected,
        SUM(DATE(created_at) = CURDATE())                    AS today,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS week
    FROM incidents
")->fetch(PDO::FETCH_ASSOC);

// --- Répartition par catégorie ---
$by_cat = $db->query("
    SELECT c.name, c.color, c.icon, COUNT(i.id) AS cnt
    FROM categories c
    LEFT JOIN incidents i ON i.category_id = c.id
    GROUP BY c.id ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// --- Évolution sur 14 jours ---
$evolution = $db->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- 10 derniers signalements ---
$recent = $db->query("
    SELECT i.id, i.reference, i.description, i.status, i.priority,
           i.created_at, c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS reporter
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    ORDER BY i.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// --- Nombre d'utilisateurs actifs ---
$user_count = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

// --- KPIs communaute (consultations & evenements) ---
$community = [
    'active_polls' => 0,
    'poll_votes' => 0,
    'upcoming_events' => 0,
    'event_attendees' => 0,
];
$community_recent_polls = [];
$community_upcoming_events = [];

try {
    $community = array_merge($community, $db->query("
        SELECT
            (SELECT COUNT(*) FROM polls WHERE status = 'active' AND (ends_at IS NULL OR ends_at >= NOW())) AS active_polls,
            (SELECT COUNT(*) FROM poll_votes pv JOIN polls p ON p.id = pv.poll_id WHERE p.status = 'active' AND (p.ends_at IS NULL OR p.ends_at >= NOW())) AS poll_votes,
            (SELECT COUNT(*) FROM events WHERE event_date >= NOW()) AS upcoming_events,
            (SELECT COUNT(*) FROM event_rsvps r JOIN events e ON e.id = r.event_id WHERE e.event_date >= NOW() AND r.status = 'attending') AS event_attendees
    ")->fetch(PDO::FETCH_ASSOC) ?: []);

    $community_recent_polls = $db->query("
        SELECT
            p.id, p.title, p.ends_at, p.status,
            (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.id) AS total_votes
        FROM polls p
        WHERE p.status = 'active' AND (p.ends_at IS NULL OR p.ends_at >= NOW())
        ORDER BY COALESCE(p.ends_at, '9999-12-31 23:59:59') ASC, p.created_at DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $community_upcoming_events = $db->query("
        SELECT
            e.id, e.title, e.location, e.event_date,
            (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.status = 'attending') AS attendees_count
        FROM events e
        WHERE e.event_date >= NOW()
        ORDER BY e.event_date ASC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $community_recent_polls = [];
    $community_upcoming_events = [];
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-hero">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Pilotage territorial</div>
    <h2 class="page-hero-title">Coordonner la réponse publique locale</h2>
    <p class="page-hero-text">
      Ce tableau de bord rassemble les besoins remontés du terrain afin d'aider les agents à arbitrer, traiter et rendre visible l'action communale.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['week'] ?></span>
      <span class="hero-chip-label">signalements sur 7 jours</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['submitted'] ?></span>
      <span class="hero-chip-label">à qualifier rapidement</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['resolved'] ?></span>
      <span class="hero-chip-label">situations résolues</span>
    </div>
  </div>
</div>

<!-- KPIs -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon canopy">📋</div>
    <div>
      <div class="stat-value"><?= $kpis['total'] ?></div>
      <div class="stat-label">Signalements enregistrés</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon awara">🕐</div>
    <div>
      <div class="stat-value"><?= $kpis['submitted'] ?></div>
      <div class="stat-label">À qualifier</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon river">⚙️</div>
    <div>
      <div class="stat-value"><?= $kpis['in_progress'] ?></div>
      <div class="stat-label">En traitement</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon leaf">✅</div>
    <div>
      <div class="stat-value"><?= $kpis['resolved'] ?></div>
      <div class="stat-label">Résolus</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon sand">📅</div>
    <div>
      <div class="stat-value"><?= $kpis['today'] ?></div>
      <div class="stat-label">Signalements aujourd'hui</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon laterite">👥</div>
    <div>
      <div class="stat-value"><?= $user_count ?></div>
      <div class="stat-label">Comptes actifs</div>
    </div>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon river">🗳️</div>
    <div>
      <div class="stat-value"><?= (int)$community['active_polls'] ?></div>
      <div class="stat-label">Consultations actives</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon leaf">🙋</div>
    <div>
      <div class="stat-value"><?= (int)$community['poll_votes'] ?></div>
      <div class="stat-label">Votes en cours</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon canopy">📅</div>
    <div>
      <div class="stat-value"><?= (int)$community['upcoming_events'] ?></div>
      <div class="stat-label">Rendez-vous à venir</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon awara">🤝</div>
    <div>
      <div class="stat-value"><?= (int)$community['event_attendees'] ?></div>
      <div class="stat-label">Participations annoncées</div>
    </div>
  </div>
</div>

<!-- Graphiques -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px;">

  <!-- Évolution 14 jours -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">📈 Signalements — 14 derniers jours</span>
    </div>
    <div class="chart-container">
      <canvas id="chartEvolution"></canvas>
    </div>
  </div>

  <!-- Répartition par catégorie -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">🏷️ Par catégorie</span>
    </div>
    <div class="chart-container">
      <canvas id="chartCategories"></canvas>
    </div>
    <div style="display:grid;gap:10px;padding:4px 6px 0;">
      <?php foreach ($by_cat as $cat): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #f1ece0;">
          <?= category_visual_html($cat['icon'] ?? 'road', $cat['name'], 'sm', $cat['color'] ?? null) ?>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:700;color:#183229;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($cat['name']) ?></div>
            <div class="text-small text-muted"><?= (int)$cat['cnt'] ?> signalement<?= ((int)$cat['cnt']) > 1 ? 's' : '' ?></div>
          </div>
          <span class="badge" style="background:<?= e($cat['color']) ?>22;color:<?= e($cat['color']) ?>"><?= (int)$cat['cnt'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">🗳️ Concertation citoyenne</span>
      <a href="/admin/?page=polls" class="btn btn-outline btn-sm">Gérer →</a>
    </div>
    <?php if (empty($community_recent_polls)): ?>
      <p class="text-muted" style="padding:8px 4px 4px;">Aucune consultation active. Lancez une question flash pour sonder les priorités du territoire.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($community_recent_polls as $poll): ?>
          <div style="padding:14px;border:1px solid #ece4d5;border-radius:16px;background:#fffaf2;">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
              <div>
                <div style="font-weight:800;color:#1f2937;"><?= e($poll['title']) ?></div>
                <div class="text-small text-muted" style="margin-top:6px;">
                  <?= (int)$poll['total_votes'] ?> vote(s)
                  <?= !empty($poll['ends_at']) ? ' · jusqu’au ' . e(format_date_short($poll['ends_at'])) : ' · sans date de fin' ?>
                </div>
              </div>
              <span class="badge badge-green">En cours</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">📅 Rendez-vous communaux</span>
      <a href="/admin/?page=events" class="btn btn-outline btn-sm">Planifier →</a>
    </div>
    <?php if (empty($community_upcoming_events)): ?>
      <p class="text-muted" style="padding:8px 4px 4px;">Aucun événement programmé. Publiez un rendez-vous pour donner de la visibilité à l’action communale.</p>
    <?php else: ?>
      <div style="display:grid;gap:12px;">
        <?php foreach ($community_upcoming_events as $event): ?>
          <div style="padding:14px;border:1px solid #dce6ea;border-radius:16px;background:#f8fbfc;">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
              <div>
                <div style="font-weight:800;color:#1f2937;"><?= e($event['title']) ?></div>
                <div class="text-small text-muted" style="margin-top:6px;">
                  <?= e(format_date($event['event_date'])) ?> · <?= e($event['location'] ?: 'Lieu à confirmer') ?>
                </div>
              </div>
              <span class="badge badge-blue"><?= (int)$event['attendees_count'] ?> participant(s)</span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Derniers signalements -->
<div class="card">
  <div class="card-header">
    <span class="card-title">🕐 Derniers signalements</span>
    <a href="/admin/?page=incidents" class="btn btn-outline btn-sm">Voir tout →</a>
  </div>
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Référence</th>
          <th>Description</th>
          <th>Catégorie</th>
          <th>Statut</th>
          <th>Priorité</th>
          <th>Citoyen</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent as $inc): ?>
        <tr>
          <td><code style="font-size:11px"><?= e($inc['reference']) ?></code></td>
          <td><span class="truncate"><?= e($inc['description']) ?></span></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <?= category_visual_html($inc['cat_icon'] ?? 'road', $inc['cat_name'], 'sm', $inc['cat_color'] ?? null) ?>
              <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
                <?= e($inc['cat_name']) ?>
              </span>
            </div>
          </td>
          <td><span class="badge <?= status_class($inc['status']) ?>"><?= status_label($inc['status']) ?></span></td>
          <td><span class="badge <?= priority_class($inc['priority'] ?? 'medium') ?>"><?= priority_label($inc['priority'] ?? 'medium') ?></span></td>
          <td><?= e($inc['reporter']) ?></td>
          <td class="text-muted text-small"><?= format_date_short($inc['created_at']) ?></td>
          <td>
            <a href="/admin/?page=incident_detail&id=<?= $inc['id'] ?>" class="btn btn-outline btn-sm">Voir</a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <tr><td colspan="8" class="text-center text-muted" style="padding:32px">Aucun signalement pour le moment.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Graphique évolution
const evoData = <?= json_encode($evolution) ?>;
const evoLabels = evoData.map(d => {
  const dt = new Date(d.day);
  return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short' });
});
new Chart(document.getElementById('chartEvolution'), {
  type: 'line',
  data: {
    labels: evoLabels,
    datasets: [{
      label: 'Signalements',
      data: evoData.map(d => d.cnt),
      borderColor: '#2d6f86',
      backgroundColor: 'rgba(45,111,134,.10)',
      tension: 0.4,
      fill: true,
      pointBackgroundColor: '#2d6f86',
      pointRadius: 4,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
      x: { grid: { display: false } }
    }
  }
});

// Graphique catégories
const catData = <?= json_encode($by_cat) ?>;
new Chart(document.getElementById('chartCategories'), {
  type: 'doughnut',
  data: {
    labels: catData.map(c => c.name),
    datasets: [{
      data: catData.map(c => c.cnt),
      backgroundColor: catData.map(c => c.color),
      borderWidth: 2,
      borderColor: '#fff',
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 12 } }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
