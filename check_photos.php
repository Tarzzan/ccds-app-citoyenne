<?php
require_once __DIR__ . '/admin/includes/bootstrap.php';
$db = Database::getInstance();
$stmt = $db->query("SELECT * FROM photos LIMIT 5");
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($photos);
