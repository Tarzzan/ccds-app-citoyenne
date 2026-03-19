<?php
/**
 * Ma Commune Back-Office — Analyse prédictive territoriale
 *
 * Cette vue ne prétend pas faire de "ML". Elle consolide des signaux simples
 * et utiles pour anticiper les zones à surveiller en Guyane.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin_auth();
$page_title = 'Analyse prédictive';
$active_nav = 'predictive_analysis';

$db = Database::getInstance();

if (($admin['role'] ?? null) !== 'admin') {
    render_error(403, 'Accès réservé aux administrateurs.');
}

$windowDays = (int)($_GET['window'] ?? 180);
if (!in_array($windowDays, [30, 90, 180, 365], true)) {
    $windowDays = 180;
}

$hotspotsStmt = $db->prepare("
    SELECT
        ROUND(i.latitude, 2) AS lat_zone,
        ROUND(i.longitude, 2) AS lng_zone,
        COUNT(*) AS incident_count,
        COALESCE(AVG(i.votes_count), 0) AS avg_votes,
        SUM(CASE WHEN i.status IN ('submitted', 'acknowledged', 'in_progress') THEN 1 ELSE 0 END) AS unresolved_count,
        SUM(CASE WHEN i.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
        MAX(i.created_at) AS last_incident_at,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS categories
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    WHERE i.latitude IS NOT NULL
      AND i.longitude IS NOT NULL
      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY ROUND(i.latitude, 2), ROUND(i.longitude, 2)
    HAVING incident_count >= 2
    ORDER BY incident_count DESC, avg_votes DESC
    LIMIT 12
");
$hotspotsStmt->execute([$windowDays]);
$hotspots = $hotspotsStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($hotspots as &$hotspot) {
    $daysSinceLast = max(0, (int)((new DateTimeImmutable($hotspot['last_incident_at']))->diff(new DateTimeImmutable())->format('%a')));
    $riskScore = ($hotspot['incident_count'] * 2.5)
        + ($hotspot['unresolved_count'] * 3)
        + ((float)$hotspot['avg_votes'] * 1.2)
        - ($daysSinceLast * 0.12);

    $hotspot['days_since_last'] = $daysSinceLast;
    $hotspot['risk_score'] = max(0, (int)round($riskScore));
    $hotspot['risk_level'] = $hotspot['risk_score'] >= 16 ? 'critical'
        : ($hotspot['risk_score'] >= 10 ? 'high'
        : ($hotspot['risk_score'] >= 5 ? 'medium' : 'low'));
}
unset($hotspot);

usort($hotspots, static fn(array $a, array $b): int => $b['risk_score'] <=> $a['risk_score']);

$trendStmt = $db->prepare("
    SELECT
        DATE_FORMAT(i.created_at, '%Y-%m') AS month_key,
        c.name AS category_name,
        COUNT(*) AS total
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, category_name
    ORDER BY month_key ASC, total DESC
");
$trendStmt->execute();
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

$forecastStmt = $db->prepare("
    SELECT
        DAYOFWEEK(created_at) AS day_of_week,
        HOUR(created_at) AS hour_of_day,
        COUNT(*) AS total
    FROM incidents
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    GROUP BY DAYOFWEEK(created_at), HOUR(created_at)
    ORDER BY total DESC
    LIMIT 10
");
$forecastStmt->execute();
$forecastSlots = $forecastStmt->fetchAll(PDO::FETCH_ASSOC);

$topCategoriesStmt = $db->prepare("
    SELECT c.name, c.color, c.icon, COUNT(i.id) AS incident_count
    FROM categories c
    JOIN incidents i ON i.category_id = c.id
    WHERE i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    GROUP BY c.id
    ORDER BY incident_count DESC, c.name ASC
    LIMIT 4
");
$topCategoriesStmt->execute([$windowDays]);
$topCategories = $topCategoriesStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($topCategories as &$topCategory) {
    $visual = category_visual_resolve($topCategory['icon'] ?? null, $topCategory['name'] ?? null);
    $topCategory['visual_description'] = $visual['description'] ?? '';
}
unset($topCategory);

$servicePressureStmt = $db->prepare("
    SELECT
        c.service,
        COUNT(i.id) AS incident_count,
        SUM(CASE WHEN i.status IN ('submitted', 'acknowledged', 'in_progress') THEN 1 ELSE 0 END) AS unresolved_count
    FROM categories c
    LEFT JOIN incidents i
      ON i.category_id = c.id
      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    WHERE c.service IS NOT NULL AND c.service <> ''
    GROUP BY c.service
    HAVING incident_count > 0
    ORDER BY unresolved_count DESC, incident_count DESC
    LIMIT 6
");
$servicePressureStmt->execute([$windowDays]);
$servicePressure = $servicePressureStmt->fetchAll(PDO::FETCH_ASSOC);

$topSignal = $hotspots[0] ?? null;
$daysFr = ['', 'Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$predictiveHeroVisual = generated_visual_url('HERO-01');

function coord_label(float $value, string $positive, string $negative): string
{
    return number_format(abs($value), 2, ',', ' ') . '°' . ($value >= 0 ? $positive : $negative);
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-hero <?= $predictiveHeroVisual ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Lecture prospective</div>
    <h2 class="page-hero-title">Zones à surveiller en priorité</h2>
    <p class="page-hero-text">
      Cette vue transforme l historique des signalements en signaux opérationnels.
      Elle aide à anticiper les secteurs à forte récurrence et à prioriser l action publique.
    </p>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= count($hotspots) ?></span>
      <span class="hero-chip-label">zone(s) retenue(s)</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)count($servicePressure) ?></span>
      <span class="hero-chip-label">service(s) sous tension</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$windowDays ?></span>
      <span class="hero-chip-label">jours analyses</span>
    </div>
  </div>
  <?php if ($predictiveHeroVisual): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero">
        <?= generated_visual_html('HERO-01', ['class' => 'generated-visual generated-visual--contain', 'label' => 'Lecture prospective']) ?>
        <div class="generated-visual-caption">
          <strong>Récurrence lisible</strong>
          <span>La prediction reste une aide d arbitrage : repérer les zones chaudes, pas promettre une certitude artificielle.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
  <?php foreach ([30 => '30 j', 90 => '90 j', 180 => '180 j', 365 => '1 an'] as $option => $label): ?>
    <a href="/admin/?page=predictive_analysis&window=<?= $option ?>"
       class="btn btn-sm <?= $windowDays === $option ? 'btn-primary' : 'btn-outline' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!empty($topCategories)): ?>
  <div class="admin-category-strip" style="margin-bottom:18px;">
    <?php foreach ($topCategories as $category): ?>
      <div class="admin-category-pill">
        <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'sm', $category['color'] ?? null) ?>
        <div class="admin-category-pill-copy">
          <strong><?= e($category['name']) ?></strong>
          <span><?= e($category['visual_description'] ?: 'Categorie dominante sur la fenetre analysee') ?></span>
        </div>
        <span class="admin-category-pill-count admin-category-pill-count--wide"><?= (int)$category['incident_count'] ?> cas</span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1.2fr .8fr;gap:24px;align-items:start;margin-bottom:24px;">
  <div class="card" style="padding:24px;">
    <div class="card-header" style="padding:0 0 12px;">
      <span class="card-title">Signal directeur</span>
      <span class="text-small text-muted">Fenêtre d'analyse : <?= $windowDays ?> jours</span>
    </div>
    <?php if ($topSignal): ?>
      <div style="display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:16px;align-items:start;">
        <div>
          <div class="text-small text-muted">Zone dominante</div>
          <div style="font-size:18px;font-weight:800;margin-top:6px;">
            <?= coord_label((float)$topSignal['lat_zone'], 'N', 'S') ?> ·
            <?= coord_label((float)$topSignal['lng_zone'], 'E', 'W') ?>
          </div>
          <div class="text-small text-muted" style="margin-top:6px;"><?= e($topSignal['categories']) ?></div>
        </div>
        <div>
          <div class="text-small text-muted">Charge cumulée</div>
          <div style="font-size:34px;font-weight:800;color:#9a3412;"><?= (int)$topSignal['risk_score'] ?></div>
          <div class="text-small text-muted">score de risque opérationnel</div>
        </div>
        <div>
          <div class="text-small text-muted">Dernière récurrence</div>
          <div style="font-size:34px;font-weight:800;color:#0f766e;"><?= (int)$topSignal['days_since_last'] ?> j</div>
          <div class="text-small text-muted">depuis le dernier incident</div>
        </div>
      </div>
    <?php else: ?>
      <div class="text-muted">Pas assez d'historique géolocalisé pour produire une lecture de risque fiable.</div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:24px;">
    <div class="card-header" style="padding:0 0 12px;">
      <span class="card-title">Services sous tension</span>
    </div>
    <div style="display:grid;gap:10px;">
      <?php if (empty($servicePressure)): ?>
        <div class="text-muted">Aucune pression notable détectée sur la période.</div>
      <?php else: ?>
        <?php foreach ($servicePressure as $service): ?>
          <div style="padding:12px 14px;border-radius:14px;background:rgba(255,255,255,.55);border:1px solid rgba(30,41,59,.06);">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;">
              <strong><?= e($service['service']) ?></strong>
              <span class="badge <?= (int)$service['unresolved_count'] > 0 ? 'badge-yellow' : 'badge-green' ?>">
                <?= (int)$service['unresolved_count'] ?> non résolu<?= (int)$service['unresolved_count'] > 1 ? 's' : '' ?>
              </span>
            </div>
            <div class="text-small text-muted" style="margin-top:6px;">
              <?= (int)$service['incident_count'] ?> signalement<?= (int)$service['incident_count'] > 1 ? 's' : '' ?> sur la fenêtre analysée
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.2fr .8fr;gap:24px;align-items:start;margin-bottom:24px;">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Hotspots territoriaux</span>
      <span class="text-small text-muted"><?= count($hotspots) ?> zones retenues</span>
    </div>
    <div style="display:grid;gap:10px;">
      <?php if (empty($hotspots)): ?>
        <div class="text-muted">Aucune zone récurrente détectée avec le volume actuel.</div>
      <?php else: ?>
        <?php foreach ($hotspots as $index => $spot): ?>
          <div style="display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;padding:14px;border-radius:16px;background:rgba(255,255,255,.56);border:1px solid rgba(30,41,59,.06);border-left:5px solid <?= $spot['risk_level'] === 'critical' ? '#dc2626' : ($spot['risk_level'] === 'high' ? '#ea580c' : ($spot['risk_level'] === 'medium' ? '#ca8a04' : '#15803d')) ?>;">
            <div style="font-size:24px;font-weight:800;color:#94a3b8;">#<?= $index + 1 ?></div>
            <div>
              <div style="font-weight:800;">
                <?= coord_label((float)$spot['lat_zone'], 'N', 'S') ?> ·
                <?= coord_label((float)$spot['lng_zone'], 'E', 'W') ?>
              </div>
              <div class="text-small text-muted" style="margin-top:4px;"><?= e($spot['categories']) ?></div>
              <div class="text-small" style="margin-top:8px;display:flex;gap:12px;flex-wrap:wrap;">
                <span>📋 <?= (int)$spot['incident_count'] ?> cas</span>
                <span>⚠️ <?= (int)$spot['unresolved_count'] ?> ouverts</span>
                <span>👍 <?= number_format((float)$spot['avg_votes'], 1, ',', ' ') ?> votes moy.</span>
                <span>🕒 <?= (int)$spot['days_since_last'] ?> j</span>
              </div>
            </div>
            <div style="text-align:right;">
              <div class="badge <?= $spot['risk_level'] === 'critical' ? 'badge-red' : ($spot['risk_level'] === 'high' ? 'badge-yellow' : ($spot['risk_level'] === 'medium' ? 'badge-blue' : 'badge-green')) ?>">
                <?= $spot['risk_level'] === 'critical' ? 'Critique' : ($spot['risk_level'] === 'high' ? 'Élevé' : ($spot['risk_level'] === 'medium' ? 'Modéré' : 'Faible')) ?>
              </div>
              <div style="font-size:28px;font-weight:800;margin-top:6px;"><?= (int)$spot['risk_score'] ?></div>
              <div class="text-small text-muted">score</div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Créneaux à surveiller</span>
      <span class="text-small text-muted">Basés sur 90 jours</span>
    </div>
    <div style="display:grid;gap:10px;">
      <?php if (empty($forecastSlots)): ?>
        <div class="text-muted">Pas assez de données pour estimer des créneaux récurrents.</div>
      <?php else: ?>
        <?php foreach ($forecastSlots as $slot): ?>
          <div style="display:grid;grid-template-columns:78px 1fr 28px;gap:10px;align-items:center;">
            <strong style="font-size:12px;color:#64748b;">
              <?= $daysFr[(int)$slot['day_of_week']] ?>
              <?= str_pad((string)$slot['hour_of_day'], 2, '0', STR_PAD_LEFT) ?>h
            </strong>
            <div style="height:10px;border-radius:999px;background:rgba(30,41,59,.08);overflow:hidden;">
              <div style="height:100%;width:<?= min(100, (int)$slot['total'] * 10) ?>%;background:linear-gradient(90deg,#1d4ed8,#0f766e);"></div>
            </div>
            <span style="font-size:12px;font-weight:800;color:#1e3a8a;"><?= (int)$slot['total'] ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">Tendance par catégorie</span>
    <span class="text-small text-muted">Six derniers mois</span>
  </div>
  <div class="chart-container" style="height:340px;">
    <canvas id="trendChart"></canvas>
  </div>
</div>

<script>
const trendRows = <?= json_encode($trendRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const months = [...new Set(trendRows.map((row) => row.month_key))];
const categories = [...new Set(trendRows.map((row) => row.category_name))];
const palette = ['#1d4ed8', '#0f766e', '#b45309', '#be123c', '#4f46e5', '#15803d', '#334155'];

const datasets = categories.map((category, index) => ({
  label: category,
  data: months.map((month) => {
    const found = trendRows.find((row) => row.category_name === category && row.month_key === month);
    return found ? Number(found.total) : 0;
  }),
  borderColor: palette[index % palette.length],
  backgroundColor: `${palette[index % palette.length]}22`,
  borderWidth: 2,
  tension: 0.3,
  fill: false,
}));

new Chart(document.getElementById('trendChart').getContext('2d'), {
  type: 'line',
  data: { labels: months, datasets },
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
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
