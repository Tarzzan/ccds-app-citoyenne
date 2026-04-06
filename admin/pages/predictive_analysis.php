<?php
/**
 * Ma Commune Back-Office — Analyse prédictive
 *
 * Cette vue ne prétend pas faire de "ML". Elle consolide des signaux simples
 * et utiles pour anticiper les zones a surveiller.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin = require_admin_auth();
$page_title = 'Analyse prédictive';
$active_nav = 'predictive_analysis';
$themePalette = visual_admin_data_palette();

$db = Database::getInstance();
$lead_photo_select = admin_incident_first_photo_select($db, 'i');

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

$hotspotIncidentStmt = $db->prepare("
    SELECT
        i.id,
        i.reference,
        i.title,
        i.description,
        i.status,
        i.priority,
        i.created_at,
        u.full_name AS reporter_name,
        c.name AS category_name,
        c.icon AS category_icon,
        c.color AS category_color,
        {$lead_photo_select}
    FROM incidents i
    JOIN users u ON u.id = i.user_id
    JOIN categories c ON c.id = i.category_id
    WHERE ROUND(i.latitude, 2) = ?
      AND ROUND(i.longitude, 2) = ?
      AND i.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ORDER BY i.created_at DESC, i.votes_count DESC
    LIMIT 1
");

foreach ($hotspots as &$hotspot) {
    $hotspotIncidentStmt->execute([
        (float)$hotspot['lat_zone'],
        (float)$hotspot['lng_zone'],
        $windowDays,
    ]);
    $sampleIncident = $hotspotIncidentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($sampleIncident) {
        $sampleIncident['lead_photo'] = admin_incident_preview_photo($db, $sampleIncident);
    }
    $hotspot['sample_incident'] = $sampleIncident;
}
unset($hotspot);

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
$predictiveHeroSceneAsset = visual_admin_slot_asset('predictive_scene', 'ILL-05') ?? visual_admin_slot_asset('predictive_scene', 'ILL-01');
$predictiveHeroInsetAsset = visual_admin_slot_asset('predictive_inset', 'ILL-02') ?? visual_admin_slot_asset('predictive_inset', 'ILL-03');
$predictiveHeroAgentAsset = visual_admin_slot_asset('predictive_agent', 'CHAR-04') ?? visual_admin_slot_asset('predictive_agent', 'CHAR-05');
$predictiveHeroHasVisual = (bool)($predictiveHeroSceneAsset || $predictiveHeroInsetAsset || $predictiveHeroAgentAsset);

function coord_label(float $value, string $positive, string $negative): string
{
    return number_format(abs($value), 2, ',', ' ') . '°' . ($value >= 0 ? $positive : $negative);
}

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-async-scope" data-async-scope="predictive-admin">

<div class="page-hero <?= $predictiveHeroHasVisual ? 'page-hero--with-visual' : '' ?>">
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
  <?php if ($predictiveHeroHasVisual): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
        <?php if ($predictiveHeroSceneAsset): ?>
          <?= generated_visual_html($predictiveHeroSceneAsset, ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Lecture prospective locale']) ?>
        <?php endif; ?>
        <?php if ($predictiveHeroInsetAsset): ?>
          <?= generated_visual_html($predictiveHeroInsetAsset, ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Categorie sous tension']) ?>
        <?php endif; ?>
        <?php if ($predictiveHeroAgentAsset): ?>
          <?= generated_visual_html($predictiveHeroAgentAsset, ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais prospectif']) ?>
        <?php endif; ?>
        <div class="generated-visual-caption hero-visual-stack-copy">
          <strong>Pression a venir lisible</strong>
          <span>L analyse predictive revient vers un repere scene et agent pour lire les zones chaudes sans promettre une certitude artificielle.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<div class="predictive-window-toolbar">
  <?php foreach ([30 => '30 j', 90 => '90 j', 180 => '180 j', 365 => '1 an'] as $option => $label): ?>
    <a href="/admin/?page=predictive_analysis&window=<?= $option ?>"
       data-async-link
       class="btn btn-sm <?= $windowDays === $option ? 'btn-primary' : 'btn-outline' ?>">
      <?= $label ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!empty($topCategories)): ?>
  <div class="admin-category-strip admin-category-strip--spaced">
    <?php foreach ($topCategories as $category): ?>
      <div class="admin-category-pill">
        <?= category_visual_html($category['icon'] ?? 'road', $category['name'], 'md', $category['color'] ?? null) ?>
        <div class="admin-category-pill-copy">
          <strong><?= e($category['name']) ?></strong>
          <span><?= e($category['visual_description'] ?: 'Categorie dominante sur la fenetre analysee') ?></span>
        </div>
        <span class="admin-category-pill-count admin-category-pill-count--wide"><?= (int)$category['incident_count'] ?> cas</span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card predictive-card-gap">
  <div class="card-header">
    <span class="card-title">Cockpit de pression a venir</span>
    <span class="text-muted text-small">La lecture predictive doit d abord aider a voir ou la pression risque de retomber sur les services et les zones.</span>
  </div>
  <div class="services-mode-band services-mode-band--tight">
    <div class="services-mode-card">
      <strong><?= count($hotspots) ?></strong>
      <span>zone(s) a surveiller</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)count($servicePressure) ?></strong>
      <span>service(s) sous tension</span>
    </div>
    <div class="services-mode-card">
      <strong><?= $topSignal ? (int)$topSignal['unresolved_count'] : 0 ?></strong>
      <span>non resolu(s) sur le signal directeur</span>
    </div>
  </div>
</div>

<div class="predictive-top-grid predictive-top-grid-gap">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Signal directeur</span>
      <span class="text-small text-muted">Fenêtre d'analyse : <?= $windowDays ?> jours</span>
    </div>
    <?php if ($topSignal): ?>
      <div class="predictive-signal-grid">
        <div>
          <div class="text-small text-muted">Zone dominante</div>
          <div class="predictive-signal-number">
            <?= coord_label((float)$topSignal['lat_zone'], 'N', 'S') ?> ·
            <?= coord_label((float)$topSignal['lng_zone'], 'E', 'W') ?>
          </div>
          <div class="text-small text-muted predictive-signal-copy"><?= e($topSignal['categories']) ?></div>
        </div>
        <div>
          <div class="text-small text-muted">Charge cumulée</div>
          <div class="predictive-signal-score predictive-signal-score--risk"><?= (int)$topSignal['risk_score'] ?></div>
          <div class="text-small text-muted">score de risque opérationnel</div>
        </div>
        <div>
          <div class="text-small text-muted">Dernière récurrence</div>
          <div class="predictive-signal-score predictive-signal-score--cooldown"><?= (int)$topSignal['days_since_last'] ?> j</div>
          <div class="text-small text-muted">depuis le dernier incident</div>
        </div>
      </div>
    <?php else: ?>
      <div class="text-muted">Pas assez d'historique géolocalisé pour produire une lecture de risque fiable.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title">Services sous tension</span>
    </div>
    <div class="predictive-service-list">
      <?php if (empty($servicePressure)): ?>
        <div class="text-muted">Aucune pression notable détectée sur la période.</div>
      <?php else: ?>
        <?php foreach ($servicePressure as $service): ?>
          <div class="predictive-service-item">
            <div class="predictive-service-head">
              <strong><?= e($service['service']) ?></strong>
              <span class="badge <?= (int)$service['unresolved_count'] > 0 ? 'badge-yellow' : 'badge-green' ?>">
                <?= (int)$service['unresolved_count'] ?> non résolu<?= (int)$service['unresolved_count'] > 1 ? 's' : '' ?>
              </span>
            </div>
            <div class="text-small text-muted predictive-service-copy">
              <?= (int)$service['incident_count'] ?> signalement<?= (int)$service['incident_count'] > 1 ? 's' : '' ?> sur la fenêtre analysée
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="predictive-bottom-grid predictive-bottom-grid-gap">
  <div class="card">
    <div class="card-header">
      <span class="card-title">Hotspots territoriaux</span>
      <span class="text-small text-muted"><?= count($hotspots) ?> zones retenues</span>
    </div>
    <div class="predictive-hotspot-list">
      <?php if (empty($hotspots)): ?>
        <div class="text-muted">Aucune zone récurrente détectée avec le volume actuel.</div>
      <?php else: ?>
        <?php foreach ($hotspots as $index => $spot): ?>
          <div class="predictive-hotspot-item predictive-hotspot-item--<?= e($spot['risk_level']) ?>">
            <div class="predictive-hotspot-rank">#<?= $index + 1 ?></div>
            <div>
              <div class="predictive-hotspot-title">
                <?= coord_label((float)$spot['lat_zone'], 'N', 'S') ?> ·
                <?= coord_label((float)$spot['lng_zone'], 'E', 'W') ?>
              </div>
              <div class="text-small text-muted predictive-hotspot-copy"><?= e($spot['categories']) ?></div>
              <div class="text-small predictive-hotspot-meta">
                <span><?= (int)$spot['incident_count'] ?> cas</span>
                <span><?= (int)$spot['unresolved_count'] ?> ouverts</span>
                <span><?= number_format((float)$spot['avg_votes'], 1, ',', ' ') ?> soutiens moy.</span>
                <span><?= (int)$spot['days_since_last'] ?> j</span>
              </div>
              <?php if (!empty($spot['sample_incident'])): ?>
                <?php $sample = $spot['sample_incident']; ?>
                <div class="predictive-hotspot-sample">
                  <?php if (!empty($sample['lead_photo']['url'])): ?>
                    <span class="admin-proof-thumb-wrap">
                      <img src="<?= e($sample['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb admin-proof-thumb--small">
                    </span>
                  <?php endif; ?>
                  <div class="predictive-hotspot-sample-copy">
                    <strong><?= e($sample['reference']) ?> · <?= e($sample['title'] ?: 'Sans titre') ?></strong>
                    <span><?= e($sample['reporter_name']) ?> · <?= e(status_label($sample['status'])) ?> · <?= e($sample['category_name']) ?></span>
                    <?php if (!empty($sample['lead_photo']['moderation_message'])): ?>
                      <span><?= e($sample['lead_photo']['moderation_message']) ?></span>
                    <?php else: ?>
                      <span>Dossier representatif de cette zone.</span>
                    <?php endif; ?>
                  </div>
                  <a href="/admin/?page=incident_detail&id=<?= (int)$sample['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Ouvrir</a>
                </div>
              <?php endif; ?>
            </div>
            <div class="predictive-score">
              <div class="badge <?= $spot['risk_level'] === 'critical' ? 'badge-red' : ($spot['risk_level'] === 'high' ? 'badge-yellow' : ($spot['risk_level'] === 'medium' ? 'badge-blue' : 'badge-green')) ?>">
                <?= $spot['risk_level'] === 'critical' ? 'Critique' : ($spot['risk_level'] === 'high' ? 'Élevé' : ($spot['risk_level'] === 'medium' ? 'Modéré' : 'Faible')) ?>
              </div>
              <div class="predictive-score-value"><?= (int)$spot['risk_score'] ?></div>
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
    <div class="predictive-slot-list">
      <?php if (empty($forecastSlots)): ?>
        <div class="text-muted">Pas assez de données pour estimer des créneaux récurrents.</div>
      <?php else: ?>
        <?php foreach ($forecastSlots as $slot): ?>
          <div class="predictive-slot-item">
            <strong class="predictive-slot-label">
              <?= $daysFr[(int)$slot['day_of_week']] ?>
              <?= str_pad((string)$slot['hour_of_day'], 2, '0', STR_PAD_LEFT) ?>h
            </strong>
            <div class="predictive-slot-track">
              <div class="predictive-slot-fill" style="--slot-fill:<?= min(100, (int)$slot['total'] * 10) ?>%;"></div>
            </div>
            <span class="predictive-slot-value"><?= (int)$slot['total'] ?></span>
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
  <div class="chart-container chart-container--lg">
    <canvas id="trendChart"></canvas>
  </div>
</div>

<script>
(() => {
const trendCanvas = document.getElementById('trendChart');
if (!trendCanvas || typeof Chart === 'undefined') {
  return;
}

Chart.getChart(trendCanvas)?.destroy();

const trendRows = <?= json_encode($trendRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const months = [...new Set(trendRows.map((row) => row.month_key))];
const categories = [...new Set(trendRows.map((row) => row.category_name))];
const themePalette = <?= json_encode($themePalette, JSON_UNESCAPED_SLASHES) ?>;
const palette = [
  themePalette.primary,
  themePalette.secondary,
  themePalette.accent,
  themePalette.danger,
  themePalette.info,
  themePalette.success,
  themePalette.primary_dark
];

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

new Chart(trendCanvas.getContext('2d'), {
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
})();
</script>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
