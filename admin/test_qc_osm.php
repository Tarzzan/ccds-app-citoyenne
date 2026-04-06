<?php
$lat = 5.1597; $lon = -52.6498;
$url = "https://quickchart.io/map?center={$lat},{$lon}&zoom=15&width=400&height=300&markers={$lat},{$lon},red";
$opts = ["http" => ["user_agent" => "Mozilla/5.0"]];
$ctx = stream_context_create($opts);

$img = @file_get_contents($url, false, $ctx);
if ($img) {
    echo "QuickChart OK: " . strlen($img) . " bytes\n";
} else {
    echo "QuickChart FAILED\n";
}
