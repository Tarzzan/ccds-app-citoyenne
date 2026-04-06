<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

function generateOsmStaticMap(float $lat, float $lon, string $destFile): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        echo "ERROR: GD extension not installed.\n";
        return false; 
    }

    $zoom = 16;
    $pxTotal = (($lon + 180) / 360) * 256 * pow(2, $zoom);
    $latRad = deg2rad($lat);
    $pyTotal = (1 - log(tan($latRad) + 1 / cos($latRad)) / pi()) / 2 * 256 * pow(2, $zoom);

    $xtile = (int)floor($pxTotal / 256);
    $ytile = (int)floor($pyTotal / 256);

    $offsetX = $pxTotal - ($xtile * 256);
    $offsetY = $pyTotal - ($ytile * 256);

    $imgWidth = 768; 
    $imgHeight = 768;
    $img = imagecreatetruecolor($imgWidth, $imgHeight);
    $bg = imagecolorallocate($img, 245, 245, 245);
    imagefill($img, 0, 0, $bg);

    $opts = [
        "http" => ["user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) CCDS-Field/1.0"]
    ];
    $ctx = stream_context_create($opts);

    $successCount = 0;
    for ($i = -1; $i <= 1; $i++) {
        for ($j = -1; $j <= 1; $j++) {
            $tx = $xtile + $i;
            $ty = $ytile + $j;
            $url = "https://tile.openstreetmap.org/{$zoom}/{$tx}/{$ty}.png";
            $tileData = file_get_contents($url, false, $ctx);
            if ($tileData !== false) {
                $tileImg = imagecreatefromstring($tileData);
                if ($tileImg) {
                    imagecopy($img, $tileImg, ($i + 1) * 256, ($j + 1) * 256, 0, 0, 256, 256);
                    imagedestroy($tileImg);
                    $successCount++;
                } else {
                    echo "ERROR: Could not parse image for $url\n";
                }
            } else {
                echo "ERROR: Could not fetch $url\n";
            }
        }
    }
    
    echo "SUCCESSFULLY FETCHED $successCount/9 TILES\n";

    $pinX = 256 + $offsetX;
    $pinY = 256 + $offsetY;
    $shadow = imagecolorallocatealpha($img, 0, 0, 0, 80);
    $white = imagecolorallocate($img, 255, 255, 255);
    $red = imagecolorallocate($img, 239, 68, 68);

    imagefilledellipse($img, (int)$pinX, (int)$pinY + 4, 34, 34, $shadow); 
    imagefilledellipse($img, (int)$pinX, (int)$pinY, 32, 32, $white); 
    imagefilledellipse($img, (int)$pinX, (int)$pinY, 24, 24, $red); 
    imagefilledellipse($img, (int)$pinX, (int)$pinY, 8, 8, $white); 

    $result = imagepng($img, $destFile, 9);
    imagedestroy($img);
    echo "Result of imagepng: " . ($result ? 'TRUE' : 'FALSE') . "\n";
    return $result;
}

$dest = sys_get_temp_dir() . '/test_map_stitch.png';
if (file_exists($dest)) unlink($dest);
generateOsmStaticMap(5.1597, -52.6498, $dest);
if (file_exists($dest)) {
    echo "File generated, size: " . filesize($dest) . " bytes\n";
} else {
    echo "File not generated.\n";
}
