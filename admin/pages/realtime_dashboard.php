<?php
/**
 * Page Admin — Dashboard Temps Réel (ADMIN-09)
 * Affiche l'activité en direct via WebSocket (Ratchet).
 */
$page_title = 'Tableau de bord temps réel';

// KPIs du jour
$today_incidents = $pdo->query("SELECT COUNT(*) FROM incidents WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$today_users     = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$today_votes     = $pdo->query("SELECT COUNT(*) FROM votes WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$today_comments  = $pdo->query("SELECT COUNT(*) FROM comments WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// 10 dernières activités
$recent = $pdo->query("
    SELECT 'incident' AS type, title AS label, created_at, u.name AS user_name
    FROM incidents i JOIN users u ON u.id = i.user_id
    UNION ALL
    SELECT 'vote', CONCAT('Vote sur : ', i.title), v.created_at, u.name
    FROM votes v JOIN incidents i ON i.id = v.incident_id JOIN users u ON u.id = v.user_id
    UNION ALL
    SELECT 'comment', CONCAT('Commentaire sur : ', i.title), c.created_at, u.name
    FROM comments c JOIN incidents i ON i.id = c.incident_id JOIN users u ON u.id = c.user_id
    ORDER BY created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-header">
    <h1>📡 Tableau de bord temps réel</h1>
    <div id="ws-status" class="ws-status ws-connecting">
        <span class="ws-dot"></span>
        <span id="ws-status-text">Connexion...</span>
    </div>
</div>

<!-- KPIs du jour -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon">📋</div>
        <div class="kpi-value" id="kpi-incidents"><?= $today_incidents ?></div>
        <div class="kpi-label">Signalements aujourd'hui</div>
        <div class="kpi-trend" id="trend-incidents"></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">👤</div>
        <div class="kpi-value" id="kpi-users"><?= $today_users ?></div>
        <div class="kpi-label">Nouvelles inscriptions</div>
        <div class="kpi-trend" id="trend-users"></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">👍</div>
        <div class="kpi-value" id="kpi-votes"><?= $today_votes ?></div>
        <div class="kpi-label">Votes "Moi aussi"</div>
        <div class="kpi-trend" id="trend-votes"></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">💬</div>
        <div class="kpi-value" id="kpi-comments"><?= $today_comments ?></div>
        <div class="kpi-label">Commentaires</div>
        <div class="kpi-trend" id="trend-comments"></div>
    </div>
</div>

<div class="dashboard-grid">
    <!-- Flux d'activité en direct -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>⚡ Activité en direct</h3>
            <span class="live-badge">LIVE</span>
        </div>
        <div id="activity-feed" class="activity-feed">
            <?php foreach ($recent as $item): ?>
            <div class="activity-item activity-<?= $item['type'] ?>">
                <span class="activity-icon">
                    <?= $item['type'] === 'incident' ? '📋' : ($item['type'] === 'vote' ? '👍' : '💬') ?>
                </span>
                <div class="activity-content">
                    <span class="activity-label"><?= htmlspecialchars($item['label']) ?></span>
                    <span class="activity-meta">
                        <?= htmlspecialchars($item['user_name']) ?> ·
                        <?= date('H:i', strtotime($item['created_at'])) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Graphique d'activité des dernières 24h -->
    <div class="dashboard-card">
        <div class="card-header">
            <h3>📊 Activité — 24 dernières heures</h3>
        </div>
        <div style="height: 280px;">
            <canvas id="activityChart"></canvas>
        </div>
    </div>
</div>

<!-- Utilisateurs connectés -->
<div class="dashboard-card" style="margin-top: 20px;">
    <div class="card-header">
        <h3>🟢 Utilisateurs actifs</h3>
        <span id="active-users-count" class="badge-count">0</span>
    </div>
    <div id="active-users-list" class="active-users-grid"></div>
</div>

<style>
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.ws-status { display: flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
.ws-connecting { background: #F59E0B22; color: #F59E0B; }
.ws-connected   { background: #10B98122; color: #10B981; }
.ws-error       { background: #EF444422; color: #EF4444; }
.ws-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; animation: pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.kpi-card { background: var(--card-bg, #1e293b); border-radius: 14px; padding: 20px; text-align: center; }
.kpi-icon { font-size: 28px; margin-bottom: 8px; }
.kpi-value { font-size: 36px; font-weight: 800; color: #3B82F6; }
.kpi-label { font-size: 12px; color: #94a3b8; margin-top: 4px; }
.kpi-trend { font-size: 11px; margin-top: 6px; font-weight: 600; }

.dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.dashboard-card { background: var(--card-bg, #1e293b); border-radius: 14px; padding: 20px; }
.card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.card-header h3 { font-size: 15px; font-weight: 700; margin: 0; }
.live-badge { background: #EF4444; color: #fff; font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 4px; animation: pulse 1s infinite; }
.badge-count { background: #3B82F6; color: #fff; font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 20px; }

.activity-feed { max-height: 300px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
.activity-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px; border-radius: 8px; background: rgba(255,255,255,0.04); animation: slideIn 0.3s ease; }
@keyframes slideIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }
.activity-icon { font-size: 18px; }
.activity-content { flex: 1; }
.activity-label { display: block; font-size: 13px; font-weight: 600; color: #e2e8f0; }
.activity-meta { font-size: 11px; color: #94a3b8; }

.active-users-grid { display: flex; flex-wrap: wrap; gap: 10px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
// ─── Graphique 24h ───────────────────────────────────────────────────────────
const hours = Array.from({length: 24}, (_, i) => `${String(i).padStart(2,'0')}h`);
const activityCtx = document.getElementById('activityChart').getContext('2d');
const activityChart = new Chart(activityCtx, {
    type: 'bar',
    data: {
        labels: hours,
        datasets: [
            { label: 'Signalements', data: Array(24).fill(0), backgroundColor: '#3B82F6' },
            { label: 'Votes',        data: Array(24).fill(0), backgroundColor: '#10B981' },
            { label: 'Commentaires', data: Array(24).fill(0), backgroundColor: '#F59E0B' },
        ],
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#94a3b8', font: { size: 11 } } } },
        scales: {
            x: { stacked: true, ticks: { color: '#94a3b8', font: { size: 10 } }, grid: { color: '#334155' } },
            y: { stacked: true, ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
        },
    },
});

// ─── WebSocket Temps Réel ────────────────────────────────────────────────────
const wsStatus     = document.getElementById('ws-status');
const wsStatusText = document.getElementById('ws-status-text');
const feed         = document.getElementById('activity-feed');

function connectWebSocket() {
    // En production : ws://votre-serveur.com:8080
    const ws = new WebSocket('ws://localhost:8080');

    ws.onopen = () => {
        wsStatus.className = 'ws-status ws-connected';
        wsStatusText.textContent = 'Connecté — Temps réel actif';
        // S'authentifier comme admin
        ws.send(JSON.stringify({ type: 'auth', role: 'admin' }));
    };

    ws.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        handleRealtimeEvent(msg);
    };

    ws.onclose = () => {
        wsStatus.className = 'ws-status ws-error';
        wsStatusText.textContent = 'Déconnecté — Reconnexion dans 5s...';
        setTimeout(connectWebSocket, 5000);
    };

    ws.onerror = () => {
        wsStatus.className = 'ws-status ws-error';
        wsStatusText.textContent = 'Erreur de connexion';
    };
}

function handleRealtimeEvent(msg) {
    const icons = { incident: '📋', vote: '👍', comment: '💬', user: '👤', event: '📅' };
    const icon  = icons[msg.type] ?? '🔔';

    // Mettre à jour les KPIs
    if (msg.type === 'incident') updateKpi('kpi-incidents');
    if (msg.type === 'vote')     updateKpi('kpi-votes');
    if (msg.type === 'comment')  updateKpi('kpi-comments');
    if (msg.type === 'user')     updateKpi('kpi-users');

    // Ajouter au flux d'activité
    const item = document.createElement('div');
    item.className = `activity-item activity-${msg.type}`;
    item.innerHTML = `
        <span class="activity-icon">${icon}</span>
        <div class="activity-content">
            <span class="activity-label">${escapeHtml(msg.label ?? msg.type)}</span>
            <span class="activity-meta">${escapeHtml(msg.user ?? 'Système')} · ${new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit'})}</span>
        </div>`;
    feed.insertBefore(item, feed.firstChild);

    // Limiter à 20 items
    while (feed.children.length > 20) feed.removeChild(feed.lastChild);

    // Mettre à jour le graphique
    const hour = new Date().getHours();
    if (msg.type === 'incident') activityChart.data.datasets[0].data[hour]++;
    if (msg.type === 'vote')     activityChart.data.datasets[1].data[hour]++;
    if (msg.type === 'comment')  activityChart.data.datasets[2].data[hour]++;
    activityChart.update('none');
}

function updateKpi(id) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = parseInt(el.textContent) + 1;
        el.style.transform = 'scale(1.2)';
        setTimeout(() => el.style.transform = '', 300);
    }
}

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Démarrer la connexion WebSocket
connectWebSocket();
</script>
