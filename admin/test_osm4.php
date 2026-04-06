<?php
$lat = 5.1597; $lon = -52.6498;
$apis = [
  "WM" => "https://maps.wikimedia.org/img/osm-intl,15,{$lat},{$lon},400x300.png",
  "CartoDB" => "https://s.osm.org/export/embed.html?bbox=-52.65,5.15,-52.64,5.16&layer=mapnik&marker={$lat},{$lon}",
];
$opts = ["http" => ["user_agent" => "Mozilla/5.0"]];
$ctx = stream_context_create($opts);

foreach ($apis as $name => $url) {
  $img = @file_get_contents($url, false, $ctx);
  echo $name . ": " . ($img ? strlen($img) . " bytes" : "FAILED") . "\n";
}
