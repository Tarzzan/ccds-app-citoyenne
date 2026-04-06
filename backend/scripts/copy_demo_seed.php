<?php
/**
 * Script temporaire — Copie les photos demo-seed depuis la sauvegarde du 23 mars.
 * Accéder via : http://localhost:8080/copy_demo_seed.php
 * À supprimer après utilisation.
 */

$src  = '/var/www/html/backend/uploads/demo-seed'; // Dans le conteneur Docker
$dest = '/var/www/html/backend/uploads/demo-seed';

// Les fichiers demo-seed sont dans le repo via bind-mount, mais depuis la sauvegarde
// le chemin host est différent. Ce script regarde s'ils existent déjà.

$uploadDir = __DIR__ . '/../uploads/';
$demoDir   = $uploadDir . 'demo-seed/';

echo "<h2>Rapport de copie demo-seed</h2>";
echo "<p>Upload dir : <code>" . htmlspecialchars($uploadDir) . "</code></p>";
echo "<p>Demo-seed dir : <code>" . htmlspecialchars($demoDir) . "</code> — ";
echo is_dir($demoDir) ? "<b style='color:green'>EXISTS</b>" : "<b style='color:red'>MISSING</b>";
echo "</p>";

// Lister tous les fichiers PNG dans le dossier incidents/
$incidentsDir = $uploadDir . 'incidents/';
echo "<h3>Dossier incidents/</h3>";
if (is_dir($incidentsDir)) {
    $files = glob($incidentsDir . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
    echo "<p>" . count($files) . " photos trouvées</p>";
    foreach (array_slice($files, 0, 5) as $f) {
        echo "<p>📷 " . htmlspecialchars(basename($f)) . "</p>";
    }
} else {
    echo "<p>Dossier incidents/ introuvable</p>";
}

// Lister les photos en base
echo "<h3>Photos en base de données</h3>";
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
try {
    $db = Database::getInstance();
    $count = $db->query("SELECT COUNT(*) FROM photos")->fetchColumn();
    echo "<p>Total photos en base : <b>$count</b></p>";
    
    $rows = $db->query("SELECT p.file_path, i.reference FROM photos p JOIN incidents i ON i.id = p.incident_id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $fullPath = $uploadDir . $r['file_path'];
        $exists = file_exists($fullPath) ? '✅' : '❌ MISSING';
        echo "<p>$exists <code>" . htmlspecialchars($r['reference']) . "</code> → " . htmlspecialchars($r['file_path']) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Erreur DB : " . htmlspecialchars($e->getMessage()) . "</p>";
}
