<?php
/**
 * CCDS v1.2 — Statistiques & Analytiques enrichis (ADMIN-01)
 * Nouveaux KPIs : votes, citoyens actifs, délai médian.
 * Nouveaux graphiques : carte de chaleur horaire, tendance résolus vs soumis.
 * Export CSV des données de la période.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Statistiques';
$active_nav = 'stats';

$db = Database::getInstance()->getConnection();

// --- Période ---
$period = (int)($_GET['period'] ?? 30);
if (!in_array($period, [7, 30, 90, 365])) $period = 30;

// --- Export CSV ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $db->prepare("
        SELECT i.reference, i.title, i.description, i.status, i.priority,
               i.votes_count, i.created_at, i.updated_at,
               c.name AS category, u.full_name AS reporter, u.email AS reporter_email
        FROM incidents i
        JOIN categories c ON c.id = i.category_id
        JOIN users u ON u.id = i.user_id
        WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY i.created_at DESC
    ");
    $rows->execute([$period]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="stats_' . date('Y-m-d') . '_' . $period . 'j.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Référence','Titre','Statut','Priorité','Catégorie','Votes','Citoyen','Email','Créé le','Mis à jour'], ';');
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, array_values($r), ';');
    }
    fclose($out);
    exit;
}

// --- KPIs principaux ---
$kpis = $db->prepare("
    SELECT
        COUNT(*)                                AS total,
        SUM(status = 'resolved')                AS resolved,
        SUM(status = 'rejected')                AS rejected,
        SUM(status IN ('submitted','acknowledged','in_progress')) AS open,
        AVG(CASE WHEN status = 'resolved' AND updated_at IS NOT NULL
                 THEN TIMESTAMPDIFF(HOUR, created_at, updated_at) END) AS avg_resolution_hours,
        SUM(votes_count)                        AS total_votes
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$kpis->execute([$period]);
$kpis = $kpis->fetch(PDO::FETCH_ASSOC);

$resolution_rate = $kpis['total'] > 0
    ? round($kpis['resolved'] / $kpis['total'] * 100, 1)
    : 0;

// Citoyens actifs (ayant soumis au moins 1 signalement dans la période)
$active_citizens = $db->prepare("
    SELECT COUNT(DISTINCT user_id) FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$active_citizens->execute([$period]);
$active_citizens = (int)$active_citizens->fetchColumn();

// --- Évolution quotidienne (soumis vs résolus) ---
$evolution = $db->prepare("
    SELECT
        DATE(created_at) AS day,
        COUNT(*) AS submitted,
        SUM(status = 'resolved') AS resolved
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
    SELECT c.name, c.color, COUNT(i.id) AS cnt, COALESCE(SUM(i.votes_count),0) AS votes
    FROM categories c
    LEFT JOIN incidents i ON i.category_id = c.id
        AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY c.id
    ORDER BY cnt DESC
");
$by_cat->execute([$period]);
$by_cat = $by_cat->fetchAll(PDO::FETCH_ASSOC);

// --- Top 5 zones ---
$top_zones = $db->prepare("
    SELECT address, COUNT(*) AS cnt, SUM(votes_count) AS votes
    FROM incidents
    WHERE address IS NOT NULL AND address != ''
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY address
    ORDER BY cnt DESC
    LIMIT 5
");
$top_zones->execute([$period]);
$top_zones = $top_zones->fetchAll(PDO::FETCH_ASSOC);

// --- Activité par jour de la semaine ---
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

// --- Carte de chaleur horaire (24h × 7j) ---
$heatmap_raw = $db->prepare("
    SELECT HOUR(created_at) AS h, DAYOFWEEK(created_at) AS dow, COUNT(*) AS cnt
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
");
$heatmap_raw->execute([$period]);
$heatmap = array_fill(0, 7, array_fill(0, 24, 0));
foreach ($heatmap_raw->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $heatmap[$row['dow'] - 1][$row['h']] = (int)$row['cnt'];
}

// --- Top 5 signalements les plus votés ---
$top_voted = $db->prepare("
    SELECT i.reference, i.title, i.description, i.votes_count, i.status,
           c.name AS cat_name, c.color AS cat_color
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    WHERE i.votes_count > 0
      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY i.votes_count DESC
    LIMIT 5
");
$top_voted->execute([$period]);
$top_voted = $top_voted->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>

<!-- En-tête avec sélecteur de période et export -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
  <div style="display:flex;align-items:center;gap:8px;">
    <span class="fw-bold" style="font-size:14px">Période :</span>
    <?php foreach ([7=>'7 jours', 30=>'30 jours', 90=>'3 mois', 365=>'1 an'] as $p => $label): ?>
      <a href="/admin/?page=stats&period=<?= $p ?>"
         class="btn btn-sm <?= $period === $p ? 'btn-primary' : 'btn-outline' ?>">
        <?= $label ?>
      </a>
    <?php endforeach; ?>
  </div>
  <a href="/admin/?page=stats&period=<?= $period ?>&export=csv" class="btn btn-outline btn-sm">
    📥 Export CSV (<?= $period ?> jours)
  </a>
</div>

<!-- KPIs enrichis (7 cartes) -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
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
      <div class="stat-label">Résolus (<?= $resolution_rate ?>%)</div>
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
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px;">
  <div class="stat-card">
    <div class="stat-icon yellow">👍</div>
    <div>
      <div class="stat-value"><?= number_format((int)$kpis['total_votes']) ?></div>
      <div class="stat-label">Votes "Moi aussi"</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green">👤</div>
    <div>
      <div class="stat-value"><?= $active_citizens ?></div>
      <div class="stat-label">Citoyens actifs</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red">❌</div>
    <div>
      <div class="stat-value"><?= $kpis['rejected'] ?></div>
      <div class="stat-label">Rejetés</div>
    </div>
  </div>
</div>

<!-- Graphiques ligne 1 : Évolution + Statuts -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:24px;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">📈 Soumis vs Résolus (<?= $period ?> jours)</span>
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

<!-- Graphiques ligne 2 : Catégories + Jours de la semaine -->
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

<!-- Carte de chaleur horaire (ADMIN-01) -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header">
    <span class="card-title">🌡️ Carte de chaleur — Heure × Jour de la semaine</span>
  </div>
  <div style="padding:16px;overflow-x:auto;">
    <table style="border-collapse:collapse;font-size:11px;width:100%">
      <thead>
        <tr>
          <th style="padding:4px 8px;text-align:left;color:#64748b">Heure</th>
          <?php for ($h = 0; $h < 24; $h++): ?>
            <th style="padding:4px 2px;text-align:center;color:#64748b;min-width:28px"><?= sprintf('%02d', $h) ?>h</th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $dow_labels = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $max_heat = max(1, max(array_map(fn($row) => max($row), $heatmap)));
        for ($d = 0; $d < 7; $d++):
        ?>
        <tr>
          <td style="padding:4px 8px;font-weight:600;color:#374151;white-space:nowrap"><?= $dow_labels[$d] ?></td>
          <?php for ($h = 0; $h < 24; $h++):
            $v   = $heatmap[$d][$h];
            $pct = $max_heat > 0 ? $v / $max_heat : 0;
            $r   = (int)(220 - $pct * 170);
            $g   = (int)(240 - $pct * 170);
            $b   = (int)(255 - $pct * 200);
            $bg  = "rgb($r,$g,$b)";
            $fg  = $pct > 0.5 ? '#fff' : '#374151';
          ?>
            <td style="padding:4px 2px;text-align:center;background:<?= $bg ?>;color:<?= $fg ?>;border-radius:3px;cursor:default"
                title="<?= $dow_labels[$d] ?> <?= sprintf('%02d', $h) ?>h : <?= $v ?> signalement<?= $v>1?'s':'' ?>">
              <?= $v > 0 ? $v : '' ?>
            </td>
          <?php endfor; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Top votés + Top zones -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
  <!-- Top 5 signalements les plus votés -->
  <?php if (!empty($top_voted)): ?>
  <div class="card">
    <div class="card-header"><span class="card-title">👍 Top 5 signalements les plus votés</span></div>
    <?php foreach ($top_voted as $i => $inc): ?>
    <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9">
      <span style="width:24px;height:24px;border-radius:50%;background:#f59e0b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;margin-top:2px"><?= $i+1 ?></span>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= e($inc['title'] ?: substr($inc['description'], 0, 50)) ?>
        </div>
        <div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap">
          <span class="badge" style="background:<?= e($inc['cat_color']) ?>22;color:<?= e($inc['cat_color']) ?>;font-size:10px"><?= e($inc['cat_name']) ?></span>
          <span class="badge <?= status_class($inc['status']) ?>" style="font-size:10px"><?= status_label($inc['status']) ?></span>
        </div>
      </div>
      <span style="color:#f59e0b;font-weight:800;font-size:16px;flex-shrink:0">👍 <?= $inc['votes_count'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Top 5 zones -->
  <?php if (!empty($top_zones)): ?>
  <div class="card">
    <div class="card-header"><span class="card-title">📍 Top 5 zones les plus signalées</span></div>
    <?php foreach ($top_zones as $i => $zone): ?>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9">
      <span style="width:24px;height:24px;border-radius:50%;background:#1d4ed8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0"><?= $i+1 ?></span>
      <span style="flex:1;font-size:13px;color:#1e293b"><?= e($zone['address']) ?></span>
      <div style="text-align:right;flex-shrink:0">
        <div class="badge badge-blue"><?= $zone['cnt'] ?> sign.</div>
        <?php if ($zone['votes'] > 0): ?>
          <div style="font-size:11px;color:#f59e0b;margin-top:2px">👍 <?= $zone['votes'] ?></div>
        <?php endif; ?>
      </div>
      <div style="width:80px;height:6px;background:#f1f5f9;border-radius:3px;overflow:hidden;flex-shrink:0">
        <div style="width:<?= round($zone['cnt']/$top_zones[0]['cnt']*100) ?>%;height:100%;background:#1d4ed8;border-radius:3px"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
// --- Évolution soumis vs résolus ---
const evoData = <?= json_encode($evolution) ?>;
new Chart(document.getElementById('chartEvo'), {
  type: 'bar',
  data: {
    labels: evoData.map(d => {
      const dt = new Date(d.day);
      return dt.toLocaleDateString('fr-FR', { day:'2-digit', month:'short' });
    }),
    datasets: [
      {
        label: 'Soumis',
        data: evoData.map(d => d.submitted),
        backgroundColor: 'rgba(29,78,216,.7)',
        borderRadius: 4,
      },
      {
        label: 'Résolus',
        data: evoData.map(d => d.resolved),
        backgroundColor: 'rgba(34,197,94,.7)',
        borderRadius: 4,
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
      x: { grid: { display: false } }
    }
  }
});

// --- Statuts ---
const statusData = <?= json_encode($by_status) ?>;
const statusColors = { submitted:'#94a3b8', acknowledged:'#3b82f6', in_progress:'#f59e0b', resolved:'#22c55e', rejected:'#ef4444' };
const statusLabels = { submitted:'Soumis', acknowledged:'Pris en charge', in_progress:'En cours', resolved:'Résolu', rejected:'Rejeté' };
new Chart(document.getElementById('chartStatus'), {
  type: 'doughnut',
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

// --- Catégories ---
const catData = <?= json_encode($by_cat) ?>;
new Chart(document.getElementById('chartCat'), {
  type: 'bar',
  data: {
    labels: catData.map(c => c.name),
    datasets: [{
      label: 'Signalements',
      data: catData.map(c => c.cnt),
      backgroundColor: catData.map(c => c.color + 'cc'),
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

// --- Jours de la semaine ---
const wdData = <?= json_encode($by_weekday_data) ?>;
new Chart(document.getElementById('chartWeekday'), {
  type: 'bar',
  data: {
    labels: wdData.map(d => d.day),
    datasets: [{
      label: 'Signalements',
      data: wdData.map(d => d.cnt),
      backgroundColor: wdData.map((_, i) => i >= 1 && i <= 5 ? 'rgba(29,78,216,.7)' : 'rgba(148,163,184,.7)'),
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
