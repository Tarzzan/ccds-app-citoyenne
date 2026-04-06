<?php
$lat = 5.1597; $lon = -52.6498;
$mapUrl = "https://staticmap.openstreetmap.de/staticmap.php?center={$lat},{$lon}&zoom=16&size=400x300&markers={$lat},{$lon},red-pushpin";
$opts = ["http" => ["user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"]];
$context = stream_context_create($opts);
$imgData = @file_get_contents($mapUrl, false, $context);
if ($imgData === false) { echo "OSM static map fetch FAILED.\n"; }
else { echo "OSM static map fetch OK, " . strlen($imgData) . " bytes.\n"; }
