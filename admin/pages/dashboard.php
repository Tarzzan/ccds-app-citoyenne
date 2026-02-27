<?php
/**
 * CCDS Back-Office — Tableau de bord
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Tableau de bord';
$active_nav = 'dashboard';

$db = Database::getInstance()->getConnection();

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
    SELECT c.name, c.color, COUNT(i.id) AS cnt
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
           i.created_at, c.name AS cat_name, c.color AS cat_color,
           u.full_name AS reporter
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    ORDER BY i.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// --- Nombre d'utilisateurs actifs ---
$user_count = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

require_once __DIR__ . '/../includes/layout.php';
?>

<!-- KPIs -->
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon blue">📋</div>
    <div>
      <div class="stat-value"><?= $kpis['total'] ?></div>
      <div class="stat-label">Signalements total</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow">🕐</div>
    <div>
      <div class="stat-value"><?= $kpis['submitted'] ?></div>
      <div class="stat-label">En attente de traitement</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">⚙️</div>
    <div>
      <div class="stat-value"><?= $kpis['in_progress'] ?></div>
      <div class="stat-label">En cours de traitement</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">✅</div>
    <div>
      <div class="stat-value"><?= $kpis['resolved'] ?></div>
      <div class="stat-label">Résolus</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">📅</div>
    <div>
      <div class="stat-value"><?= $kpis['today'] ?></div>
      <div class="stat-label">Signalements aujourd'hui</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon purple">👥</div>
    <div>
      <div class="stat-value"><?= $user_count ?></div>
      <div class="stat-label">Utilisateurs actifs</div>
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
            <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>">
              <?= e($inc['cat_name']) ?>
            </span>
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
      borderColor: '#1d4ed8',
      backgroundColor: 'rgba(29,78,216,.08)',
      tension: 0.4,
      fill: true,
      pointBackgroundColor: '#1d4ed8',
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
