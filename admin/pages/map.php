<?php
/**
 * Ma Commune Back-Office — Carte des signalements
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Carte des signalements';
$active_nav = 'map';
$statusPalette = [
    'submitted' => '#94a3b8',
    'acknowledged' => '#0f3026',
    'in_progress' => '#27473b',
    'resolved' => '#10b981',
    'rejected' => '#ef4444',
    'default' => '#0f3026',
];

$db = Database::getInstance();
$service_tables_ready = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'intervention_plans');
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;
$lead_photo_select = admin_incident_first_photo_select($db, 'i');
$service_scope_where = '';
$service_join_sql = $service_tables_ready ? "
    LEFT JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
    LEFT JOIN services mapped_service ON mapped_service.id = scm.service_id
    LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
        SELECT p2.id
        FROM intervention_plans p2
        WHERE p2.incident_id = i.id
        ORDER BY p2.created_at DESC, p2.id DESC
        LIMIT 1
    )
    LEFT JOIN services plan_service ON plan_service.id = latest_plan.service_id
" : '';

if ($agent_is_scoped) {
    if (!empty($agent_service_scope_ids)) {
        $safeServiceIds = implode(',', array_map('intval', $agent_service_scope_ids));
        $service_scope_where = " AND COALESCE(latest_plan.service_id, scm.service_id) IN ($safeServiceIds)";
        $scope_notice = $admin['primary_service_name']
            ? 'La carte est limitee au service ' . $admin['primary_service_name'] . '.'
            : 'La carte est limitee a vos services rattaches.';
    } else {
        $service_scope_where = ' AND 1 = 0';
        $scope_notice = 'Aucun service ne vous est encore attribue. La carte restera vide tant que le rattachement n est pas renseigne.';
    }
}

// Récupérer tous les signalements avec coordonnées
$incidents = $db->query("
    SELECT i.id, i.reference, i.description, i.status, i.latitude, i.longitude,
           i.address, i.created_at,
           c.name AS cat_name, c.color AS cat_color, c.icon AS cat_icon,
           u.full_name AS reporter,
           {$lead_photo_select},
           (SELECT COUNT(*) FROM photos ph WHERE ph.incident_id = i.id) AS photo_count,
           " . ($service_tables_ready ? "
           COALESCE(plan_service.name, mapped_service.name) AS service_name,
           latest_plan.status AS current_plan_status,
           latest_plan.scheduled_date AS current_plan_date,
           latest_plan.time_window_start AS current_plan_time_start,
           latest_plan.time_window_end AS current_plan_time_end,
           latest_plan.source_type AS current_plan_source_type,
           latest_plan.provider_name AS current_plan_provider_name
           " : "
           NULL AS service_name,
           NULL AS current_plan_status,
           NULL AS current_plan_date,
           NULL AS current_plan_time_start,
           NULL AS current_plan_time_end,
           NULL AS current_plan_source_type,
           NULL AS current_plan_provider_name
           ") . "
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    $service_join_sql
    WHERE i.latitude IS NOT NULL AND i.longitude IS NOT NULL
    $service_scope_where
    ORDER BY i.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($incidents as &$incident) {
    $visual = category_visual_resolve($incident['cat_icon'] ?? null, $incident['cat_name'] ?? null);
    $incident['cat_badge_url'] = category_visual_asset_url($incident['cat_icon'] ?? null, $incident['cat_name'] ?? null);
    $incident['cat_scene_url'] = category_scene_visual_url($incident['cat_icon'] ?? null, $incident['cat_name'] ?? null);
    $incident['cat_description'] = $visual['description'] ?? '';
    $incident['lead_photo'] = admin_incident_preview_photo($db, $incident);
}
unset($incident);

$mapExecution = [
    'unplanned' => 0,
    'scheduled' => 0,
    'overdue' => 0,
    'in_progress' => 0,
    'provider' => 0,
    'internal' => 0,
];
$today = date('Y-m-d');
foreach ($incidents as $incident) {
    $planStatus = (string)($incident['current_plan_status'] ?? '');
    $planDate = trim((string)($incident['current_plan_date'] ?? ''));
    if ($planStatus === 'in_progress') {
        $mapExecution['in_progress']++;
    } elseif (in_array($planStatus, ['scheduled', 'rescheduled'], true) && $planDate !== '') {
        if ($planDate < $today) {
            $mapExecution['overdue']++;
        } else {
            $mapExecution['scheduled']++;
        }
    } else {
        $mapExecution['unplanned']++;
    }

    if (($incident['current_plan_source_type'] ?? '') === 'provider') {
        $mapExecution['provider']++;
    } elseif (!empty($incident['current_plan_status'])) {
        $mapExecution['internal']++;
    }
}

$mapHeroSceneAsset = null;
$mapHeroInsetAsset = null;
$mapHeroAgentAsset = null;
$mapHeroHasVisual = false;

require_once __DIR__ . '/../includes/layout.php';
?>

<div class="page-hero <?= $mapHeroHasVisual ? 'page-hero--with-visual' : '' ?>">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Lecture cartographique</div>
    <h2 class="page-hero-title">Voir la zone utile, puis ouvrir le bon dossier.</h2>
    <p class="page-hero-text">
      La carte doit aider à repérer les signaux, lire la pression d’exécution et ouvrir rapidement les dossiers qui exigent une action visible.
    </p>
    <div class="page-hero-actions">
      <a href="/admin/?page=incidents" class="btn btn-primary btn-sm" data-async-link data-async-scope="admin-main">Ouvrir la file</a>
      <a href="/admin/?page=services" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Voir les services</a>
    </div>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= count($incidents) ?></span>
      <span class="hero-chip-label">signalement(s) géolocalisé(s)</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$mapExecution['in_progress'] ?></span>
      <span class="hero-chip-label">en intervention</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$mapExecution['overdue'] ?></span>
      <span class="hero-chip-label">en retard</span>
    </div>
  </div>
  <?php if ($mapHeroHasVisual): ?>
    <div class="page-hero-visual">
      <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
        <?php if ($mapHeroSceneAsset): ?>
          <?= generated_visual_html($mapHeroSceneAsset, ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Lecture cartographique locale']) ?>
        <?php endif; ?>
        <?php if ($mapHeroInsetAsset): ?>
          <?= generated_visual_html($mapHeroInsetAsset, ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Zone secondaire sous surveillance']) ?>
        <?php endif; ?>
        <?php if ($mapHeroAgentAsset): ?>
          <?= generated_visual_html($mapHeroAgentAsset, ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Relais cartographique']) ?>
        <?php endif; ?>
        <div class="generated-visual-caption hero-visual-stack-copy">
          <strong>Carte terrain guidee</strong>
          <span>La zone principale, la zone secondaire et le relais agent restent visibles pour lire une pression locale avant d ouvrir le bon dossier.</span>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($scope_notice): ?>
<div class="alert alert-info stats-scope-alert"><?= e($scope_notice) ?></div>
<?php endif; ?>

<div class="card dashboard-section-card">
  <div class="card-header">
    <span class="card-title">Lecture d execution de la carte</span>
    <span class="text-muted text-small">La carte doit d abord aider a reperer ce qui reste a planifier, ce qui derive et ce qui est deja en passage terrain.</span>
  </div>
  <div class="services-mode-band services-mode-band--tight">
    <div class="services-mode-card">
      <strong><?= (int)$mapExecution['unplanned'] ?></strong>
      <span>a planifier</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$mapExecution['scheduled'] ?></strong>
      <span>prevues</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$mapExecution['overdue'] ?></strong>
      <span>en retard</span>
    </div>
  </div>
  <div class="services-mode-band services-mode-band--spaced">
    <div class="services-mode-card">
      <strong><?= (int)$mapExecution['in_progress'] ?></strong>
      <span>en intervention</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$mapExecution['internal'] ?></strong>
      <span>equipe interne</span>
    </div>
    <div class="services-mode-card">
      <strong><?= (int)$mapExecution['provider'] ?></strong>
      <span>prestataire</span>
    </div>
  </div>
</div>

<div class="card map-admin-shell">
  <div id="admin-map" class="map-admin-canvas"></div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(() => {
function initAdminMap() {
  if (typeof L === 'undefined') {
    setTimeout(initAdminMap, 50);
    return;
  }

  const mapElement = document.getElementById('admin-map');
  if (!mapElement) {
    return;
  }

  if (window.__adminLeafletMap) {
    window.__adminLeafletMap.remove();
    window.__adminLeafletMap = null;
  }

const incidents = <?= json_encode($incidents) ?>;
const statusColors = <?= json_encode($statusPalette, JSON_UNESCAPED_SLASHES) ?>;
const statusLabels = {
  submitted:'Soumis', acknowledged:'Pris en charge',
  in_progress:'En cours', resolved:'Résolu', rejected:'Rejeté'
};
const planLabels = {
  scheduled: 'Prévue',
  rescheduled: 'Reprogrammée',
  in_progress: 'En intervention',
  completed: 'Terminée',
  cancelled: 'Annulée'
};

const KOUROU_CENTER = [5.1597, -52.6498];
const map = L.map('admin-map').setView(KOUROU_CENTER, 13);
window.__adminLeafletMap = map;
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors'
}).addTo(map);

incidents.forEach(inc => {
  const color = statusColors[inc.status] || statusColors.default;
  const planLabel = inc.current_plan_status ? (planLabels[inc.current_plan_status] || inc.current_plan_status) : 'Pas encore planifiée';
  const timeWindow = [inc.current_plan_time_start, inc.current_plan_time_end].filter(Boolean).join(' - ');
  const executionLabel = inc.current_plan_source_type === 'provider'
    ? `Prestataire missionné${inc.current_plan_provider_name ? ` · ${inc.current_plan_provider_name}` : ''}`
    : (inc.current_plan_status ? 'Équipe interne' : 'Sans mode d exécution défini');
  const marker = L.circleMarker([inc.latitude, inc.longitude], {
    radius: 9, fillColor: color, color: '#fff',
    weight: 2, opacity: 1, fillOpacity: 0.9
  }).addTo(map);

  // Ouvre le popup (et donc la photo) directement au survol !
  marker.on('mouseover', function(e) {
    this.openPopup();
  });

  const date = new Date(inc.created_at).toLocaleDateString('fr-FR');
  marker.bindPopup(`
    <div class="map-admin-popup">
      <code class="map-admin-popup-ref">${inc.reference}</code>
      <div class="map-admin-popup-title">${inc.description.substring(0,80)}${inc.description.length>80?'…':''}</div>
      <div class="map-admin-popup-category">
        <span class="map-admin-popup-badge-visual" style="--category-accent:${inc.cat_color}">
          <img src="${inc.cat_badge_url}" alt="${inc.cat_name}">
        </span>
        <div class="map-admin-popup-copy">
          <span class="map-admin-popup-category-pill" style="--category-accent:${inc.cat_color}">${inc.cat_name}</span>
          ${inc.cat_description ? `<span class="map-admin-popup-description">${inc.cat_description}</span>` : ''}
        </div>
      </div>
      <div class="map-admin-popup-tags">
        <span class="map-admin-popup-tag map-admin-popup-tag--status" style="--status-accent:${color}">${statusLabels[inc.status]||inc.status}</span>
        <span class="map-admin-popup-tag map-admin-popup-tag--plan">${planLabel}</span>
      </div>
      <div class="map-admin-popup-service">
        Service · ${inc.service_name || 'Service a confirmer'}
      </div>
      <div class="map-admin-popup-meta">
        ${executionLabel}
        ${inc.current_plan_date ? ` · ${inc.current_plan_date}` : ''}
        ${timeWindow ? ` · ${timeWindow}` : ''}
      </div>
      <div class="map-admin-popup-date">Citoyen · ${inc.reporter} · Date · ${date}</div>
      ${inc.lead_photo && inc.lead_photo.url ? `
        <div class="map-admin-popup-proof js-map-insta-trigger" data-incident-id="${inc.id}" style="cursor:pointer;" title="Cliquer pour voir la preuve en grand">
          <span class="admin-proof-thumb-wrap">
            <img src="${inc.lead_photo.url}" alt="Preuve citoyenne" class="map-admin-popup-proof-image">
            ${(inc.photo_count || 0) > 1 ? `<span class="admin-proof-thumb-badge">+${(inc.photo_count || 1) - 1}</span>` : ''}
            <span class="map-admin-popup-proof-zoom-hint">&#128269; Voir</span>
          </span>
          <div class="map-admin-popup-proof-copy">
            <strong>${inc.photo_count || 1} photo${(inc.photo_count || 1) > 1 ? 's' : ''}</strong>
            <span>${inc.lead_photo.moderation_message || ((inc.photo_count || 0) > 1 ? 'Serie photo visible dans le dossier.' : 'Preuve citoyenne visible dans le dossier.')}</span>
          </div>
        </div>
      ` : ''}
      <a href="/admin/?page=incident_detail&id=${inc.id}" data-async-link data-async-scope="admin-main"
         class="map-admin-popup-link">
        Traiter ce signalement →
      </a>
    </div>
  `);
});

map.on('popupopen', (event) => {
  window.AdminShell?.bindAsyncLinks?.(event.popup.getElement());
  const el = event.popup.getElement();
  const trigger = el?.querySelector('.js-map-insta-trigger');
  if (trigger) {
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const id = parseInt(trigger.dataset.incidentId, 10);
      if (id) openMapInstaModal(id);
    });
  }
});

// Légende
const legend = L.control({ position: 'bottomright' });
legend.onAdd = () => {
  const div = L.DomUtil.create('div', 'map-admin-legend');
  div.innerHTML = '<strong class="map-admin-legend-title">Statuts</strong>' +
    Object.entries(statusLabels).map(([k,v]) =>
      `<div class="map-admin-legend-row">
        <span class="map-admin-legend-dot" style="--legend-accent:${statusColors[k]}"></span>${v}
      </div>`
    ).join('');
  return div;
};
legend.addTo(map);
}
initAdminMap();
})();
</script>

<!-- Modal Instagram depuis la carte -->
<div class="insta-hover-overlay" id="instaHoverOverlay" aria-hidden="true"></div>
<div class="insta-modal" id="instaModal" aria-hidden="true">
  <button class="insta-modal-close" type="button" onclick="closeMapInstaModal()" aria-label="Fermer">×</button>
  <div class="insta-modal-content">
    <div class="insta-photo-col">
      <img id="instaModalImg" src="" alt="Preuve citoyenne" style="width:100%;height:100%;object-fit:cover;display:block;">
    </div>
    <div class="insta-comments-col">
      <div class="insta-header">
        <div id="instaHeaderAvatar" style="width:40px;height:40px;border-radius:50%;border:2px solid transparent;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;"></div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:800;font-size:15px;color:var(--gray-800);" id="instaAuthorName">-</div>
          <div style="color:var(--gray-600);font-size:13px;" id="instaMetaText">-</div>
        </div>
      </div>
      <div class="insta-comments-list" id="instaCommentsList"></div>
    </div>
  </div>
</div>

<style>
.map-admin-popup-proof-zoom-hint {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0); color: #fff; font-size: 12px; font-weight: 600;
  opacity: 0; transition: opacity .18s, background .18s;
  border-radius: 8px 8px 0 0;
}
.js-map-insta-trigger:hover .map-admin-popup-proof-zoom-hint {
  opacity: 1; background: rgba(0,0,0,.38);
}
.insta-hover-overlay { position:fixed;pointer-events:none;z-index:9999;left:50%;top:50%;transform:translate(-50%,-50%) scale(.94);opacity:0;visibility:hidden;transition:opacity .18s ease,transform .18s ease,visibility .18s ease;background:color-mix(in srgb,var(--primary-dark) 78%,rgba(15,23,42,.82));border:1px solid rgba(255,255,255,.10);border-radius:22px;padding:14px;display:flex;gap:12px;box-shadow:0 32px 70px rgba(15,23,42,.34);backdrop-filter:blur(16px);}
.insta-hover-overlay.active{opacity:1;visibility:visible;transform:translate(-50%,-50%) scale(1);}
.insta-modal{position:fixed;inset:0;z-index:10000;display:none;align-items:center;justify-content:center;padding:28px;background:rgba(15,23,42,.72);backdrop-filter:blur(8px);}
.insta-modal.active{display:flex;}
.insta-modal-close{position:absolute;top:22px;right:22px;width:42px;height:42px;border:0;border-radius:999px;background:rgba(255,255,255,.12);color:#fff;font-size:22px;cursor:pointer;}
.insta-modal-content{width:min(1120px,100%);height:min(82vh,760px);display:grid;grid-template-columns:minmax(0,1.4fr) minmax(320px,.86fr);overflow:hidden;border-radius:30px;background:linear-gradient(180deg,rgba(255,255,255,.98),color-mix(in srgb,var(--primary-light) 14%,#fff));box-shadow:0 34px 84px rgba(15,23,42,.32);}
.insta-photo-col{min-width:0;background:linear-gradient(180deg,color-mix(in srgb,var(--primary-dark) 82%,#101828),color-mix(in srgb,var(--primary-dark) 62%,var(--accent)));}
.insta-comments-col{min-width:0;display:flex;flex-direction:column;background:#fff;}
.insta-header{display:flex;align-items:center;gap:12px;padding:20px 22px;border-bottom:1px solid color-mix(in srgb,var(--secondary) 14%,var(--gray-200));}
.insta-comments-list{flex:1;overflow-y:auto;padding:0 22px 22px;}
.insta-comment{display:flex;gap:12px;padding:16px 0;border-bottom:1px solid color-mix(in srgb,var(--secondary) 10%,var(--gray-200));}
.insta-comment:last-child{border-bottom:0;}
.insta-comment-avatar{width:36px;height:36px;border-radius:999px;flex:0 0 36px;display:inline-flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--primary) 20%,#fff);color:var(--primary-dark);font-weight:800;}
.insta-comment-content{min-width:0;display:grid;gap:4px;}
.insta-comment-content strong{color:var(--gray-800);}
.insta-comment-content span{color:var(--gray-600);line-height:1.55;}
</style>

<script>
let mapInstaAbort = null;

function openMapInstaModal(id) {
  document.getElementById('instaAuthorName').textContent = '…';
  document.getElementById('instaMetaText').textContent = 'Chargement…';
  document.getElementById('instaCommentsList').innerHTML = '<div style="text-align:center;padding:40px;color:var(--gray-400);">Chargement…</div>';
  document.getElementById('instaModalImg').src = '';
  document.getElementById('instaModal').classList.add('active');
  document.body.style.overflow = 'hidden';

  if (mapInstaAbort) mapInstaAbort.abort();
  mapInstaAbort = new AbortController();

  fetch(`/admin/index.php?page=ajax_incident_media&id=${id}`, { signal: mapInstaAbort.signal })
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;

      const photo = data.photos?.[0];
      if (photo?.url) document.getElementById('instaModalImg').src = photo.url;

      const meta = data.meta || {};
      const author = meta.reporter_name || 'Citoyen';
      const initial = author.charAt(0).toUpperCase();
      document.getElementById('instaHeaderAvatar').textContent = initial;
      document.getElementById('instaAuthorName').textContent = author;
      document.getElementById('instaMetaText').textContent = meta.reference || '';

      const descBlock = `<div style="padding:24px 0 0;">
        <div class="insta-comment">
          <div class="insta-comment-avatar" style="background:color-mix(in srgb,var(--primary) 18%,#fff)">${initial}</div>
          <div class="insta-comment-content"><strong>${author}</strong><span>${meta.description || ''}</span></div>
        </div>
      </div>`;

      const comments = data.comments || [];
      const commentsHtml = comments.map(cm => {
        const ci = (cm.author || 'A').charAt(0).toUpperCase();
        return `<div class="insta-comment">
          <div class="insta-comment-avatar">${ci}</div>
          <div class="insta-comment-content"><strong>${cm.author || 'Agent'}</strong><span>${cm.content || ''}</span></div>
        </div>`;
      }).join('');

      document.getElementById('instaCommentsList').innerHTML = descBlock + commentsHtml;
    })
    .catch(() => {});
}

function closeMapInstaModal() {
  document.getElementById('instaModal').classList.remove('active');
  document.body.style.overflow = '';
  if (mapInstaAbort) { mapInstaAbort.abort(); mapInstaAbort = null; }
}

document.getElementById('instaModal').addEventListener('click', e => {
  if (e.target.id === 'instaModal') closeMapInstaModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeMapInstaModal();
});
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
