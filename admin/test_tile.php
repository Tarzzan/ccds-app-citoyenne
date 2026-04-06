<?php
$lat = 5.1597; $lon = -52.6498; $zoom = 15;
$xtile = floor((($lon + 180) / 360) * pow(2, $zoom));
$ytile = floor((1 - log(tan(deg2rad($lat)) + 1 / cos(deg2rad($lat))) / pi()) /2 * pow(2, $zoom));

$url = "https://tile.openstreetmap.org/{$zoom}/{$xtile}/{$ytile}.png";
$opts = ["http" => ["user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"]];
$ctx = stream_context_create($opts);

$img = @file_get_contents($url, false, $ctx);
if ($img) echo "TILE OK: " . strlen($img) . " bytes\n";
else echo "TILE FAILED\n";
