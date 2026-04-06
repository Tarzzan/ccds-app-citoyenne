<?php
$lat = 5.1597; $lon = -52.6498;
$mapUrl = "https://static-maps.yandex.ru/1.x/?ll={$lon},{$lat}&size=450,300&z=15&l=map&pt={$lon},{$lat},pm2rdl";
$opts = ["http" => ["user_agent" => "Mozilla/5.0"]];
$context = stream_context_create($opts);
$imgData = @file_get_contents($mapUrl, false, $context);
if ($imgData === false) { echo "Yandex static map fetch FAILED.\n"; }
else { echo "Yandex map OK, " . strlen($imgData) . " bytes.\n"; }
