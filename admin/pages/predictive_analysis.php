<?php
/**
 * Page Admin — Analyse Prédictive des zones à risque (ADMIN-10)
 *
 * Analyse l'historique des signalements pour identifier les zones récurrentes
 * et prédire les futures zones à risque.
 */
$page_title = 'Analyse prédictive';

// ─── Zones à risque (clustering géographique simplifié) ──────────────────────
// Regrouper les incidents par zone de 0.01° (~1km) et calculer la récurrence
$hotspots = $pdo->query("
    SELECT
        ROUND(latitude, 2)  AS lat_zone,
        ROUND(longitude, 2) AS lng_zone,
        COUNT(*)            AS incident_count,
        AVG(votes_count)    AS avg_votes,
        MAX(created_at)     AS last_incident,
        MIN(created_at)     AS first_incident,
        GROUP_CONCAT(DISTINCT category_id) AS categories,
        SUM(CASE WHEN status IN ('submitted','in_progress') THEN 1 ELSE 0 END) AS unresolved_count,
        SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
        DATEDIFF(NOW(), MAX(created_at)) AS days_since_last
    FROM incidents
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    GROUP BY lat_zone, lng_zone
    HAVING incident_count >= 2
    ORDER BY incident_count DESC, avg_votes DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Tendances mensuelles par catégorie ─────────────────────────────────────
$trends = $pdo->query("
    SELECT
        c.name AS category,
        DATE_FORMAT(i.created_at, '%Y-%m') AS month,
        COUNT(*) AS count
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY c.name, month
    ORDER BY month ASC, count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Score de risque par zone ────────────────────────────────────────────────
// Score = (incidents × 2) + (votes moyens) + (non résolus × 3) - (jours depuis dernier × 0.1)
foreach ($hotspots as &$spot) {
    $spot['risk_score'] = round(
        ($spot['incident_count'] * 2)
        + ($spot['avg_votes'])
        + ($spot['unresolved_count'] * 3)
        - ($spot['days_since_last'] * 0.1)
    );
    $spot['risk_level'] = $spot['risk_score'] >= 15 ? 'critical'
        : ($spot['risk_score'] >= 8 ? 'high'
        : ($spot['risk_score'] >= 4 ? 'medium' : 'low'));
}
usort($hotspots, fn($a, $b) => $b['risk_score'] - $a['risk_score']);

// ─── Prévision des 30 prochains jours ───────────────────────────────────────
$forecast = $pdo->query("
    SELECT
        DAYOFWEEK(created_at) AS day_of_week,
        HOUR(created_at)      AS hour_of_day,
        COUNT(*)              AS avg_incidents
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY day_of_week, hour_of_day
    ORDER BY avg_incidents DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$days_fr = ['', 'Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
?>

<div class="page-header">
    <h1>🔮 Analyse prédictive</h1>
    <span class="badge-info">Basé sur les 90 derniers jours</span>
</div>

<!-- Carte des zones à risque -->
<div class="section-card">
    <div class="section-header">
        <h2>🗺️ Zones à risque identifiées</h2>
        <span class="badge-count"><?= count($hotspots) ?> zones</span>
    </div>
    <div class="hotspots-grid">
        <?php foreach ($hotspots as $i => $spot): ?>
        <div class="hotspot-card risk-<?= $spot['risk_level'] ?>">
            <div class="hotspot-rank">#<?= $i + 1 ?></div>
            <div class="hotspot-info">
                <div class="hotspot-coords">
                    📍 <?= number_format($spot['lat_zone'], 2) ?>°N,
                    <?= number_format($spot['lng_zone'], 2) ?>°E
                </div>
                <div class="hotspot-stats">
                    <span>📋 <?= $spot['incident_count'] ?> incidents</span>
                    <span>👍 <?= round($spot['avg_votes'], 1) ?> votes moy.</span>
                    <span>⚠️ <?= $spot['unresolved_count'] ?> non résolus</span>
                    <span>🕐 Il y a <?= $spot['days_since_last'] ?>j</span>
                </div>
            </div>
            <div class="risk-badge risk-badge-<?= $spot['risk_level'] ?>">
                <?php
                $labels = ['critical' => '🔴 Critique', 'high' => '🟠 Élevé', 'medium' => '🟡 Moyen', 'low' => '🟢 Faible'];
                echo $labels[$spot['risk_level']];
                ?>
                <br><small>Score: <?= $spot['risk_score'] ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tendances par catégorie -->
<div class="charts-grid">
    <div class="section-card">
        <div class="section-header">
            <h2>📈 Tendances par catégorie (6 mois)</h2>
        </div>
        <div style="height: 280px;">
            <canvas id="trendsChart"></canvas>
        </div>
    </div>

    <!-- Créneaux à risque -->
    <div class="section-card">
        <div class="section-header">
            <h2>⏰ Créneaux les plus actifs</h2>
        </div>
        <div class="forecast-list">
            <?php foreach ($forecast as $slot): ?>
            <div class="forecast-item">
                <span class="forecast-time">
                    <?= $days_fr[$slot['day_of_week']] ?> <?= str_pad($slot['hour_of_day'], 2, '0', STR_PAD_LEFT) ?>h
                </span>
                <div class="forecast-bar-container">
                    <div class="forecast-bar" style="width: <?= min(100, $slot['avg_incidents'] * 5) ?>%"></div>
                </div>
                <span class="forecast-count"><?= $slot['avg_incidents'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.badge-info  { background: #3B82F622; color: #3B82F6; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.badge-count { background: #3B82F6; color: #fff; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.section-card { background: var(--card-bg, #1e293b); border-radius: 14px; padding: 20px; margin-bottom: 20px; }
.section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.section-header h2 { font-size: 16px; font-weight: 700; margin: 0; }
.charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

.hotspots-grid { display: flex; flex-direction: column; gap: 10px; }
.hotspot-card { display: flex; align-items: center; gap: 12px; padding: 14px; border-radius: 10px; background: rgba(255,255,255,0.04); border-left: 4px solid; }
.risk-critical { border-color: #EF4444; }
.risk-high     { border-color: #F97316; }
.risk-medium   { border-color: #F59E0B; }
.risk-low      { border-color: #10B981; }
.hotspot-rank  { font-size: 18px; font-weight: 800; color: #94a3b8; min-width: 30px; }
.hotspot-info  { flex: 1; }
.hotspot-coords { font-size: 13px; font-weight: 600; color: #e2e8f0; margin-bottom: 4px; }
.hotspot-stats { display: flex; flex-wrap: wrap; gap: 8px; }
.hotspot-stats span { font-size: 11px; color: #94a3b8; }
.risk-badge { text-align: right; font-size: 12px; font-weight: 700; }
.risk-badge-critical { color: #EF4444; }
.risk-badge-high     { color: #F97316; }
.risk-badge-medium   { color: #F59E0B; }
.risk-badge-low      { color: #10B981; }

.forecast-list { display: flex; flex-direction: column; gap: 8px; }
.forecast-item { display: flex; align-items: center; gap: 10px; }
.forecast-time { font-size: 12px; font-weight: 700; color: #94a3b8; min-width: 60px; }
.forecast-bar-container { flex: 1; height: 8px; background: rgba(255,255,255,0.08); border-radius: 4px; overflow: hidden; }
.forecast-bar { height: 100%; background: linear-gradient(90deg, #3B82F6, #8B5CF6); border-radius: 4px; }
.forecast-count { font-size: 12px; font-weight: 700; color: #3B82F6; min-width: 20px; text-align: right; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
// Graphique des tendances
const trendsData = <?= json_encode($trends) ?>;
const months     = [...new Set(trendsData.map(d => d.month))];
const categories = [...new Set(trendsData.map(d => d.category))];
const colors     = ['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899'];

const datasets = categories.map((cat, i) => ({
    label: cat,
    data: months.map(m => {
        const found = trendsData.find(d => d.category === cat && d.month === m);
        return found ? found.count : 0;
    }),
    borderColor: colors[i % colors.length],
    backgroundColor: colors[i % colors.length] + '33',
    tension: 0.4,
    fill: true,
}));

new Chart(document.getElementById('trendsChart').getContext('2d'), {
    type: 'line',
    data: { labels: months, datasets },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#94a3b8', font: { size: 11 } } } },
        scales: {
            x: { ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { color: '#334155' } },
            y: { ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
        },
    },
});
</script>
