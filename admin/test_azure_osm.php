<?php
$lat = 5.1597; $lon = -52.6498;
$url = "https://osm-static-maps.sphere.core.windows.net/api/StaticMap?center={$lat},{$lon}&zoom=15&size=400x300&markers={$lat},{$lon}";
$opts = ["http" => ["user_agent" => "Mozilla/5.0"]];
$ctx = stream_context_create($opts);

$img = @file_get_contents($url, false, $ctx);
if ($img) {
    echo "Azure OSM OK: " . strlen($img) . " bytes\n";
} else {
    echo "Azure OSM FAILED\n";
}
