<?php
/**
 * CCDS Back-Office — Carte des signalements
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$admin      = require_admin_auth();
$page_title = 'Carte des signalements';
$active_nav = 'map';

$db = Database::getInstance()->getConnection();

// Récupérer tous les signalements avec coordonnées
$incidents = $db->query("
    SELECT i.id, i.reference, i.description, i.status, i.latitude, i.longitude,
           i.address, i.created_at,
           c.name AS cat_name, c.color AS cat_color,
           u.full_name AS reporter
    FROM incidents i
    JOIN categories c ON c.id = i.category_id
    JOIN users u ON u.id = i.user_id
    WHERE i.latitude IS NOT NULL AND i.longitude IS NOT NULL
    ORDER BY i.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/layout.php';
?>

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

const map = L.map('admin-map').setView([46.6, 2.3], 6);
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
      <span style="background:${inc.cat_color}22;color:${inc.cat_color};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">${inc.cat_name}</span>
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
