<?php

/**
 * Ma Commune — PdfReportService
 * Génère un rapport PDF complet pour un incident.
 * Utilise FPDF (via Composer) — aucune dépendance système.
 *
 * Usage :
 *   $pdf = new PdfReportService($db);
 *   $pdf->generate($incidentId);  // envoie le PDF au navigateur
 */
class PdfReportService
{
    private PDO $db;
    private ?bool $hasPhotoSortOrderColumn = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function generate(int $incidentId): void
    {
        // ── Charger les données ───────────────────────────────
        $incident = $this->loadIncident($incidentId);
        if (!$incident) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Incident introuvable.']);
            exit;
        }

        $photos   = $this->loadPhotos($incidentId);
        $comments = $this->loadComments($incidentId);
        $history  = $this->loadHistory($incidentId);
        $votes    = (int)($incident['votes_count'] ?? 0);

        // ── Construire le PDF avec FPDF ───────────────────────
        // ── Construire le PDF avec FPDF ───────────────────────
        // FPDF est chargé via Composer autoload
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(10, 10, 10); // Marges latérales fines (1cm)
        $pdf->SetAutoPageBreak(true, 10); // Laisse l'imprimante aller très bas avant coupe
        $pdf->AddPage();

        // ── En-tête ───────────────────────────────────────────
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetTextColor(37, 99, 235);
        $pdf->Cell(0, 8, $this->latin1("Ordre d'Intervention — " . (defined('APP_NAME') ? APP_NAME : 'Ma Commune')), 0, 1, 'L');
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(4);

        // ── Bloc Dense : Informations ─────────────────────────
        $this->sectionTitle($pdf, 'Signalement ' . $incident['reference']);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 6, $this->latin1($incident['title']), 0, 1);
        $pdf->Ln(1);

        $this->row($pdf, 'Date & Heure', date('d/m/Y à H:i', strtotime($incident['created_at'])) . '  |  ' . $this->latin1('Statut : ') . $this->statusLabel($incident['status']));
        $this->row($pdf, 'Assigné à',    $incident['assigned_agent_name'] ? $incident['assigned_agent_name'] . ' (Intervention)' : 'Non assigné');
        $this->row($pdf, 'Adresse',      $incident['address'] ?: 'Non renseignée');
        $this->row($pdf, 'GPS',          $incident['latitude'] . ', ' . $incident['longitude']);
        $this->row($pdf, 'Déclarant',    $incident['reporter_name'] . ' (' . ($incident['reporter_phone'] ?: 'pas de tél.') . ')');
        $pdf->Ln(2);

        // ── Description ───────────────────────────────────────
        if ($incident['description']) {
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(55, 65, 81);
            $pdf->MultiCell(0, 5, $this->latin1($incident['description']), 0, 'L');
            $pdf->Ln(3);
        }

        // ── Carte locale & Preuve Principale ──────────────────
        $lat = $incident['latitude'];
        $lon = $incident['longitude'];
        $hasMap = !empty($lat) && !empty($lon);
        $leadPhoto = $photos[0] ?? null;
        
        $mapFile = null;
        if ($hasMap) {
            $mapFile = sys_get_temp_dir() . '/map_inc_' . $incident['id'] . '_osm_' . md5($lat.$lon) . '.png';
            if (!file_exists($mapFile) || filesize($mapFile) < 100) {
                $this->generateOsmStaticMap((float)$lat, (float)$lon, $mapFile);
            }
            if (!file_exists($mapFile) || filesize($mapFile) < 100) {
                $hasMap = false;
            }
        }

        if ($hasMap || $leadPhoto) {
            $this->sectionTitle($pdf, 'Cible & Cliché terrain');
            $y = $pdf->GetY();
            $x = 10;

            if ($hasMap) {
                $pdf->Image($mapFile, $x, $y, 65, 0, 'PNG'); // Réduction de 80 à 65mm
            }

            $fp = null;
            if ($leadPhoto && !empty($leadPhoto['file_path'])) {
                $fp = __DIR__ . '/../uploads/incidents/' . basename((string)$leadPhoto['file_path']);
            } elseif (function_exists('admin_demo_seed_photo_path')) {
                $fallback = admin_demo_seed_photo_path($incident['reference'] ?? null);
                if ($fallback) {
                    $fp = str_starts_with($fallback, 'http') ? $fallback : __DIR__ . '/../uploads/' . $fallback;
                }
            }

            if ($fp) {
                $isUrl = str_starts_with($fp, 'http');
                $localFp = $fp;
                if ($isUrl) {
                    $localFp = sys_get_temp_dir() . '/fallback_' . md5($fp) . '.png';
                    if (!file_exists($localFp) || filesize($localFp) < 100) {
                        $context = stream_context_create(["http" => ["user_agent" => "CCDS-Admin-PDF/1.0"]]);
                        $imgData = @file_get_contents($fp, false, $context);
                        if ($imgData) {
                            @file_put_contents($localFp, $imgData);
                        }
                    }
                }

                if (file_exists($localFp)) {
                    $ext = strtoupper(pathinfo(parse_url($fp, PHP_URL_PATH) ?: $fp, PATHINFO_EXTENSION));
                    $type = in_array($ext, ['JPG', 'JPEG']) ? 'JPEG' : ($ext === 'PNG' ? 'PNG' : null);
                    if ($type) {
                        $info = @getimagesize($localFp);
                        if ($info && !empty($info[1])) {
                            $ratio = $info[0] / $info[1];
                            $tw = 65; $th = $tw / $ratio;
                            if ($th > 65) { $th = 65; $tw = $th * $ratio; } // Échelle compacte 65mm (max)
                            $photoX = 200 - $tw; // Ancrage strict sur la marge droite (A4=210, Marge=10 => Droite=200)
                            $photoY = $y + (32.5 - ($th / 2)); 
                            $pdf->Image($localFp, $photoX, $photoY, $tw, $th, $type);
                        }
                    }
                }
            }

            // ── Injection du QR Code de Routage GPS ──
            if ($hasMap) {
                // On prépare une URL Google Maps Drop Pin
                $mapsUrl = "https://maps.google.com/?q={$lat},{$lon}";
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&color=1f2937&data=" . urlencode($mapsUrl);
                $qrFile = sys_get_temp_dir() . '/qr_inc_' . $incident['id'] . '_' . md5($qrUrl) . '.png';
                
                // On met en cache le QRCode pour que l'export soit instantané la fois d'après
                if (!file_exists($qrFile) || filesize($qrFile) < 100) {
                    $ctx = stream_context_create(["http" => ["user_agent" => "CCDS-Admin-PDF/1.0"]]);
                    $qrData = @file_get_contents($qrUrl, false, $ctx);
                    if ($qrData) { @file_put_contents($qrFile, $qrData); }
                }

                if (file_exists($qrFile) && filesize($qrFile) > 100) {
                    // Positionnement du QRCode (22x22mm) au centre mathématique absolu (105mm)
                    // Carte termine à X=75 / Photo démarre (si 65mm) à X=135 => Gap de 60mm.
                    // Milieu de Gap = 105. Point d'insertion X = 105 - (22/2) = 94.
                    $pdf->Image($qrFile, 94, $y + 19, 22, 22, 'PNG');
                    
                    // Label explicatif ultra discret en dessous (centré sur 105)
                    $pdf->SetXY(91, $y + 42);
                    $pdf->SetFont('Arial', 'I', 7);
                    $pdf->SetTextColor(156, 163, 175);
                    $pdf->Cell(28, 3, $this->latin1('Scanner (GPS)'), 0, 0, 'C');
                }
            }

            // Dégagement net : 65mm d'images + 4mm de ligne de flottaison
            $pdf->SetY($y + 69);
            $pdf->Ln(2);
        }

        // ── Photos annexes (si présentes) ─────────────────────
        if (count($photos) > 1) {
            $this->sectionTitle($pdf, 'Photos annexes');
            $x = 20;
            $y = $pdf->GetY();
            $imgW = 35; // Plus petites
            $imgH = 26;
            $col  = 0;
            for ($i = 1; $i < count($photos); $i++) {
                $photo = $photos[$i];
                $filePath = __DIR__ . '/../uploads/incidents/' . basename($photo['file_path']);
                if (file_exists($filePath)) {
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $type = match($ext) { 'jpg','jpeg' => 'JPEG', 'png' => 'PNG', default => null };
                    if ($type) {
                        if ($y + $imgH > 280) {
                            $pdf->AddPage();
                            $y = 10; $col = 0; $x = 10;
                        }
                        $pdf->Image($filePath, $x + $col * ($imgW + 4), $y, $imgW, $imgH, $type);
                        $col++;
                        if ($col >= 4) { $col = 0; $y += $imgH + 4; }
                    }
                }
            }
            $pdf->SetY($y + $imgH + 4);
            $pdf->Ln(2);
        }

        // ── Chronologie (Historique compressé) ────────────────
        if ($history) {
            $this->sectionTitle($pdf, 'Chronologie & Interventions');
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetFillColor(249, 250, 251);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell(25, 5, 'Date', 1, 0, 'L', true);
            $pdf->Cell(30, 5, 'Passage de', 1, 0, 'L', true);
            $pdf->Cell(30, 5, $this->latin1('À statut'), 1, 0, 'L', true);
            $pdf->Cell(0, 5, 'Agent / Validation', 1, 1, 'L', true);
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(55, 65, 81);
            foreach ($history as $h) {
                $pdf->Cell(25, 5, date('d/m/Y H:i', strtotime($h['changed_at'])), 1, 0);
                $pdf->Cell(30, 5, $this->latin1($this->statusLabel($h['old_status'])), 1, 0);
                $pdf->Cell(30, 5, $this->latin1($this->statusLabel($h['new_status'])), 1, 0);
                $pdf->Cell(0, 5, $this->latin1($h['agent_name']), 1, 1);
            }
            $pdf->Ln(3);
        }

        // ── Commentaires ──────────────────────────────────────
        if ($comments) {
            $this->sectionTitle($pdf, 'Commentaires annexes');
            foreach ($comments as $c) {
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetTextColor(37, 99, 235);
                $pdf->Cell(0, 4, $this->latin1($c['author_name']) . ' — ' . date('d/m/Y H:i', strtotime($c['created_at'])), 0, 1);
                $pdf->SetFont('Arial', '', 8);
                $pdf->SetTextColor(55, 65, 81);
                $pdf->MultiCell(0, 4, $this->latin1($c['comment']), 0, 'L');
                $pdf->Ln(1);
            }
        }

        // ── Pied de page ──────────────────────────────────────
        $pdf->SetY(-15);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 5, $this->latin1((defined('APP_NAME') ? APP_NAME : 'Ma Commune') . " — Rapport d'intervention / Zéro Papier — " . date('d/m/Y à H:i')), 0, 0, 'C');

        // ── Envoi ─────────────────────────────────────────────
        header('Cache-Control: no-cache, no-store, must-revalidate'); // Bloquer le cache navigateur
        header('Pragma: no-cache');
        header('Expires: 0');
        $filename = (defined('APP_REFERENCE_PREFIX') ? APP_REFERENCE_PREFIX : 'MC') . '_Incident_' . $incident['reference'] . '_' . date('YmdHis') . '.pdf';
        $pdf->Output('I', $filename); // 'I' pour Inline (affichage navigateur) au lieu de 'D'
    }

    // ── Helpers ───────────────────────────────────────────────
    private function sectionTitle(\FPDF $pdf, string $title): void
    {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetFillColor(241, 245, 249);
        $pdf->Cell(0, 6, ' ' . $this->latin1($title), 0, 1, 'L', true);
        $pdf->Ln(1);
    }

    private function row(\FPDF $pdf, string $label, string $value): void
    {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(25, 4.5, $this->latin1($label) . ' :', 0, 0); // Label compact
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 4.5, $this->latin1($value), 0, 1);
    }

    private function latin1(?string $str): string
    {
        if ($str === null || $str === '') {
            return '';
        }
        // Conversion robuste pour FPDF
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $str);
        if ($converted === false) {
            $converted = mb_convert_encoding($str, 'windows-1252', 'UTF-8');
        }
        return (string)$converted;
    }

    private function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        return match($status) {
            'submitted'    => 'Soumis',
            'acknowledged' => 'Pris en compte',
            'in_progress'  => 'En cours',
            'resolved'     => 'Résolu',
            'rejected'     => 'Rejeté',
            default        => ucfirst($status)
        };
    }

    private function loadIncident(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, cat.name AS category_name,
                   u.full_name AS reporter_name, u.email AS reporter_email, u.phone AS reporter_phone,
                   a.full_name AS assigned_agent_name
            FROM incidents i
            JOIN categories cat ON cat.id = i.category_id
            JOIN users u ON u.id = i.user_id
            LEFT JOIN users a ON a.id = i.assigned_to
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadPhotos(int $id): array
    {
        $orderBy = $this->hasPhotoSortOrderColumn() ? 'sort_order, id' : 'id';
        $stmt = $this->db->prepare("SELECT * FROM photos WHERE incident_id = ? ORDER BY {$orderBy}");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasPhotoSortOrderColumn(): bool
    {
        if ($this->hasPhotoSortOrderColumn !== null) {
            return $this->hasPhotoSortOrderColumn;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(['photos', 'sort_order']);
            $this->hasPhotoSortOrderColumn = (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $this->hasPhotoSortOrderColumn = false;
        }

        return $this->hasPhotoSortOrderColumn;
    }

    private function loadComments(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, u.full_name AS author_name
            FROM comments c JOIN users u ON u.id = c.user_id
            WHERE c.incident_id = ? ORDER BY c.created_at ASC
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadHistory(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT sh.*, u.full_name AS agent_name
            FROM status_history sh JOIN users u ON u.id = sh.user_id
            WHERE sh.incident_id = ? ORDER BY sh.changed_at ASC
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assemble une carte OSM haute qualité à partir des tuiles publiques (Slippy Map) 
     * pour contourner les fermetures des générateurs statiques "staticmap.*"
     */
    private function generateOsmStaticMap(float $lat, float $lon, string $destFile): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
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

        $imgWidth = 768; // 3x3 tuiles
        $imgHeight = 768;
        $img = imagecreatetruecolor($imgWidth, $imgHeight);
        $bg = imagecolorallocate($img, 240, 240, 240);
        imagefill($img, 0, 0, $bg);

        $opts = ["http" => ["user_agent" => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) CCDS-System/1.0", "timeout" => 5]];
        $ctx = stream_context_create($opts);

        for ($i = -1; $i <= 1; $i++) {
            for ($j = -1; $j <= 1; $j++) {
                $tx = $xtile + $i;
                $ty = $ytile + $j;
                $url = "https://tile.openstreetmap.org/{$zoom}/{$tx}/{$ty}.png";
                $tileData = @file_get_contents($url, false, $ctx);
                if ($tileData) {
                    $tileImg = @imagecreatefromstring($tileData);
                    if ($tileImg) {
                        imagecopy($img, $tileImg, ($i + 1) * 256, ($j + 1) * 256, 0, 0, 256, 256);
                        imagedestroy($tileImg);
                    }
                }
            }
        }

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
        return $result;
    }
}
