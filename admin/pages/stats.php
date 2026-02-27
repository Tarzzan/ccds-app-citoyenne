<?php
/**
 * CCDS Back-Office — Statistiques & Analytiques
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Statistiques';
$active_nav = 'stats';

$db = Database::getInstance()->getConnection();

// --- Période (défaut : 30 derniers jours) ---
$period = (int)($_GET['period'] ?? 30);
if (!in_array($period, [7, 30, 90, 365])) $period = 30;

// --- KPIs de la période ---
$kpis = $db->prepare("
    SELECT
        COUNT(*)                                AS total,
        SUM(status = 'resolved')                AS resolved,
        SUM(status = 'rejected')                AS rejected,
        SUM(status IN ('submitted','acknowledged','in_progress')) AS open,
        AVG(CASE WHEN status = 'resolved' AND updated_at IS NOT NULL
                 THEN TIMESTAMPDIFF(HOUR, created_at, updated_at) END) AS avg_resolution_hours
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$kpis->execute([$period]);
$kpis = $kpis->fetch(PDO::FETCH_ASSOC);

// Taux de résolution
$resolution_rate = $kpis['total'] > 0
    ? round($kpis['resolved'] / $kpis['total'] * 100, 1)
    : 0;

// --- Évolution quotidienne ---
$evolution = $db->prepare("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$evolution->execute([$period]);
$evolution = $evolution->fetchAll(PDO::FETCH_ASSOC);

// --- Par statut ---
$by_status = $db->query("
    SELECT status, COUNT(*) AS cnt FROM incidents GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// --- Par catégorie (période) ---
$by_cat = $db->prepare("
    SELECT c.name, c.color, COUNT(i.id) AS cnt
    FROM categories c
    LEFT JOIN incidents i ON i.category_id = c.id
        AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY c.id
    ORDER BY cnt DESC
");
$by_cat->execute([$period]);
$by_cat = $by_cat->fetchAll(PDO::FETCH_ASSOC);

// --- Top 5 zones (par adresse) ---
$top_zones = $db->prepare("
    SELECT address, COUNT(*) AS cnt
    FROM incidents
    WHERE address IS NOT NULL AND address != ''
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY address
    ORDER BY cnt DESC
    LIMIT 5
");
$top_zones->execute([$period]);
$top_zones = $top_zones->fetchAll(PDO::FETCH_ASSOC);

// --- Signalements par jour de la semaine ---
$by_weekday = $db->prepare("
    SELECT DAYOFWEEK(created_at) AS dow, COUNT(*) AS cnt
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY DAYOFWEEK(created_at)
    ORDER BY dow ASC
");
$by_weekday->execute([$period]);
$by_weekday_raw = $by_weekday->fetchAll(PDO::FETCH_KEY_PAIR);
$weekdays = ['', 'Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$by_weekday_data = [];
for ($d = 1; $d <= 7; $d++) {
    $by_weekday_data[] = ['day' => $weekdays[$d], 'cnt' => $by_weekday_raw[$d] ?? 0];
}

require_once __DIR__ . '/../includes/layout.php';
?>

<!-- Sélecteur de période -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:24px;">
  <span class="fw-bold" style="font-size:14px">Période :</span>
  <?php foreach ([7=>'7 jours', 30=>'30 jours', 90=>'3 mois', 365=>'1 an'] as $p => $label): ?>
    <a href="/admin/?page=stats&period=<?= $p ?>"
       class="btn btn-sm <?= $period === $p ? 'btn-primary' : 'btn-outline' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- KPIs -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr)">
  <div class="stat-card">
    <div class="stat-icon blue">📋</div>
    <div>
      <div class="stat-value"><?= $kpis['total'] ?></div>
      <div class="stat-label">Signalements</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon yellow">🔓</div>
    <div>
      <div class="stat-value"><?= $kpis['open'] ?></div>
      <div class="stat-label">Ouverts</div>
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
    <div class="stat-icon green">📊</div>
    <div>
      <div class="stat-value"><?= $resolution_rate ?>%</div>
      <div class="stat-label">Taux de résolution</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue">⏱️</div>
    <div>
      <div class="stat-value">
        <?php
        $h = $kpis['avg_resolution_hours'];
        if ($h === null) echo 'N/A';
        elseif ($h < 24) echo round($h) . 'h';
        else echo round($h / 24) . 'j';
        ?>
      </div>
      <div class="stat-label">Délai moyen résolution</div>
    </div>
  </div>
</div>

<!-- Graphiques ligne 1 -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">📈 Évolution des signalements (<?= $period ?> jours)</span>
    </div>
    <div class="chart-container">
      <canvas id="chartEvo"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">🥧 Répartition par statut</span>
    </div>
    <div class="chart-container">
      <canvas id="chartStatus"></canvas>
    </div>
  </div>
</div>

<!-- Graphiques ligne 2 -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">🏷️ Signalements par catégorie</span>
    </div>
    <div class="chart-container">
      <canvas id="chartCat"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title">📅 Activité par jour de la semaine</span>
    </div>
    <div class="chart-container">
      <canvas id="chartWeekday"></canvas>
    </div>
  </div>
</div>

<!-- Top zones -->
<?php if (!empty($top_zones)): ?>
<div class="card">
  <div class="card-header"><span class="card-title">📍 Top 5 zones les plus signalées</span></div>
  <?php foreach ($top_zones as $i => $zone): ?>
  <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9">
    <span style="width:24px;height:24px;border-radius:50%;background:#1d4ed8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0"><?= $i+1 ?></span>
    <span style="flex:1;font-size:14px"><?= e($zone['address']) ?></span>
    <span class="badge badge-blue"><?= $zone['cnt'] ?> signalement<?= $zone['cnt']>1?'s':'' ?></span>
    <div style="width:120px;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden">
      <div style="width:<?= round($zone['cnt']/$top_zones[0]['cnt']*100) ?>%;height:100%;background:#1d4ed8;border-radius:3px"></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
const evoData = <?= json_encode($evolution) ?>;
new Chart(document.getElementById('chartEvo'), {
  type: 'bar',
  data: {
    labels: evoData.map(d => {
      const dt = new Date(d.day);
      return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short' });
    }),
    datasets: [{
      label: 'Signalements',
      data: evoData.map(d => d.cnt),
      backgroundColor: 'rgba(29,78,216,.7)',
      borderRadius: 6,
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

const statusData = <?= json_encode($by_status) ?>;
const statusColors = { submitted:'#94a3b8', acknowledged:'#3b82f6', in_progress:'#f59e0b', resolved:'#22c55e', rejected:'#ef4444' };
const statusLabels = { submitted:'Soumis', acknowledged:'Pris en charge', in_progress:'En cours', resolved:'Résolu', rejected:'Rejeté' };
new Chart(document.getElementById('chartStatus'), {
  type: 'pie',
  data: {
    labels: statusData.map(s => statusLabels[s.status] || s.status),
    datasets: [{
      data: statusData.map(s => s.cnt),
      backgroundColor: statusData.map(s => statusColors[s.status] || '#94a3b8'),
      borderWidth: 2, borderColor: '#fff',
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10 } } }
  }
});

const catData = <?= json_encode($by_cat) ?>;
new Chart(document.getElementById('chartCat'), {
  type: 'bar',
  data: {
    labels: catData.map(c => c.name),
    datasets: [{
      data: catData.map(c => c.cnt),
      backgroundColor: catData.map(c => c.color),
      borderRadius: 6,
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
      y: { grid: { display: false } }
    }
  }
});

const wdData = <?= json_encode($by_weekday_data) ?>;
new Chart(document.getElementById('chartWeekday'), {
  type: 'bar',
  data: {
    labels: wdData.map(d => d.day),
    datasets: [{
      label: 'Signalements',
      data: wdData.map(d => d.cnt),
      backgroundColor: 'rgba(59,130,246,.7)',
      borderRadius: 6,
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
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
