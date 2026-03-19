<?php
/**
 * Ma Commune Back-Office — Carte des signalements
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Carte des signalements';
$active_nav = 'map';

$db = Database::getInstance();
$service_tables_ready = admin_db_has_table($db, 'services')
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'intervention_plans');
$agent_service_scope_ids = $service_tables_ready ? admin_allowed_service_ids($admin) : [];
$agent_is_scoped = $service_tables_ready && admin_is_service_scoped_agent($admin);
$scope_notice = null;
$service_scope_join = '';
$service_scope_where = '';

if ($agent_is_scoped) {
    if (!empty($agent_service_scope_ids)) {
        $safeServiceIds = implode(',', array_map('intval', $agent_service_scope_ids));
        $service_scope_join = "
            LEFT JOIN service_category_map scm ON scm.category_id = c.id AND scm.is_default = 1
            LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
                SELECT p2.id
                FROM intervention_plans p2
                WHERE p2.incident_id = i.id
                ORDER BY p2.created_at DESC, p2.id DESC
                LIMIT 1
            )
        ";
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
           u.full_name AS reporter
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    $service_scope_join
    WHERE i.latitude IS NOT NULL AND i.longitude IS NOT NULL
    $service_scope_where
    ORDER BY i.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($incidents as &$incident) {
    $incident['cat_badge_url'] = category_visual_asset_url($incident['cat_icon'] ?? null, $incident['cat_name'] ?? null);
    $incident['cat_scene_url'] = category_scene_visual_url($incident['cat_icon'] ?? null, $incident['cat_name'] ?? null);
}
unset($incident);

require_once __DIR__ . '/../includes/layout.php';
?>

<?php if ($scope_notice): ?>
<div class="alert alert-info" style="margin-bottom:16px;"><?= e($scope_notice) ?></div>
<?php endif; ?>

<div class="card" style="padding:0;overflow:hidden;">
  <div id="admin-map" style="height:calc(100vh - 200px);min-height:500px;width:100%;"></div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const incidents = <?= json_encode($incidents) ?>;
const statusColors = {
  submitted: '#94a3b8', acknowledged: '#3b82f6',
  in_progress: '#f59e0b', resolved: '#22c55e', rejected: '#ef4444'
};
const statusLabels = {
  submitted:'Soumis', acknowledged:'Pris en charge',
  in_progress:'En cours', resolved:'Résolu', rejected:'Rejeté'
};

const KOUROU_CENTER = [5.1597, -52.6498];
const map = L.map('admin-map').setView(KOUROU_CENTER, 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '© OpenStreetMap contributors'
}).addTo(map);

incidents.forEach(inc => {
  const color = statusColors[inc.status] || '#94a3b8';
  const marker = L.circleMarker([inc.latitude, inc.longitude], {
    radius: 9, fillColor: color, color: '#fff',
    weight: 2, opacity: 1, fillOpacity: 0.9
  }).addTo(map);

  const date = new Date(inc.created_at).toLocaleDateString('fr-FR');
  marker.bindPopup(`
    <div style="min-width:220px;font-family:Inter,sans-serif">
      <code style="font-size:11px;color:#94a3b8">${inc.reference}</code>
      <div style="font-size:13px;font-weight:700;margin:4px 0">${inc.description.substring(0,80)}${inc.description.length>80?'…':''}</div>
      <div style="display:flex;align-items:center;gap:8px;margin:8px 0 6px">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:11px;background:${inc.cat_color}18;border:1px solid ${inc.cat_color}33;overflow:hidden">
          <img src="${inc.cat_badge_url}" alt="${inc.cat_name}" style="width:100%;height:100%;object-fit:contain">
        </span>
        <span style="background:${inc.cat_color}22;color:${inc.cat_color};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">${inc.cat_name}</span>
      </div>
      ${inc.cat_scene_url ? `
        <div style="margin:8px 0;border-radius:12px;overflow:hidden;border:1px solid rgba(15,76,42,.08);background:#f8f5ed">
          <img src="${inc.cat_scene_url}" alt="${inc.cat_name}" style="display:block;width:100%;aspect-ratio:4/3;object-fit:cover">
        </div>
      ` : ''}
      <span style="background:${color}22;color:${color};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;margin-left:4px">${statusLabels[inc.status]||inc.status}</span>
      <div style="font-size:11px;color:#94a3b8;margin-top:6px">👤 ${inc.reporter} · 📅 ${date}</div>
      <a href="/admin/?page=incident_detail&id=${inc.id}"
         style="display:block;margin-top:10px;background:#1d4ed8;color:#fff;padding:6px 12px;border-radius:6px;text-align:center;font-size:12px;font-weight:600;text-decoration:none">
        Traiter ce signalement →
      </a>
    </div>
  `);
});

// Légende
const legend = L.control({ position: 'bottomright' });
legend.onAdd = () => {
  const div = L.DomUtil.create('div', '');
  div.style.cssText = 'background:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.15);font-family:Inter,sans-serif;font-size:12px';
  div.innerHTML = '<strong style="display:block;margin-bottom:8px">Statuts</strong>' +
    Object.entries(statusLabels).map(([k,v]) =>
      `<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
        <span style="width:12px;height:12px;border-radius:50%;background:${statusColors[k]};display:inline-block"></span>${v}
      </div>`
    ).join('');
  return div;
};
legend.addTo(map);
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
