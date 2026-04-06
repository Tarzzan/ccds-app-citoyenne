<?php
$lat = 5.1597; $lon = -52.6498;
$mapUrl = "https://staticmap.openstreetmap.de/staticmap.php?center={$lat},{$lon}&zoom=16&size=400x300&markers={$lat},{$lon},red-pushpin";
$opts = ["http" => [
    "user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "header" => "Accept: image/png,image/jpeg,image/*\r\nAccept-Language: fr-FR,fr;q=0.9,en-US;q=0.8\r\n"
]];
$context = stream_context_create($opts);
$imgData = @file_get_contents($mapUrl, false, $context);
if ($imgData === false) { echo "OSM fetch FAILED again.\n"; }
else { echo "OSM fetch SUCCESS, " . strlen($imgData) . " bytes.\n"; }
