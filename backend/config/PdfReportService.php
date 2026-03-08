<?php

/**
 * CCDS — PdfReportService
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
        // FPDF est chargé via Composer autoload
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(20, 20, 20);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        // ── En-tête ───────────────────────────────────────────
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetTextColor(37, 99, 235); // couleur principale
        $pdf->Cell(0, 10, 'Rapport d\'Incident — ' . (defined('APP_NAME') ? APP_NAME : 'Ma Commune'), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(0, 6, 'Généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');
        $pdf->Ln(4);

        // Ligne de séparation
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(6);

        // ── Référence + Statut ────────────────────────────────
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 8, $this->latin1($incident['reference'] . ' — ' . $incident['title']), 0, 1);

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(0, 6, 'Catégorie : ' . $this->latin1($incident['category_name']) . '  |  Statut : ' . $this->statusLabel($incident['status']) . '  |  Votes : ' . $votes, 0, 1);
        $pdf->Ln(4);

        // ── Informations générales ────────────────────────────
        $this->sectionTitle($pdf, 'Informations générales');
        $this->row($pdf, 'Référence',      $incident['reference']);
        $this->row($pdf, 'Titre',          $incident['title']);
        $this->row($pdf, 'Catégorie',      $incident['category_name']);
        $this->row($pdf, 'Adresse',        $incident['address'] ?: 'Non renseignée');
        $this->row($pdf, 'Coordonnées',    $incident['latitude'] . ', ' . $incident['longitude']);
        $this->row($pdf, 'Statut',         $this->statusLabel($incident['status']));
        $this->row($pdf, 'Votes "Moi aussi"', (string)$votes);
        $this->row($pdf, 'Signalé le',     date('d/m/Y à H:i', strtotime($incident['created_at'])));
        $this->row($pdf, 'Dernière màj',   $incident['updated_at'] ? date('d/m/Y à H:i', strtotime($incident['updated_at'])) : '—');
        $pdf->Ln(4);

        // ── Citoyen ───────────────────────────────────────────
        $this->sectionTitle($pdf, 'Citoyen déclarant');
        $this->row($pdf, 'Nom',    $incident['reporter_name']);
        $this->row($pdf, 'Email',  $incident['reporter_email']);
        $this->row($pdf, 'Tél.',   $incident['reporter_phone'] ?: '—');
        $pdf->Ln(4);

        // ── Description ───────────────────────────────────────
        if ($incident['description']) {
            $this->sectionTitle($pdf, 'Description');
            $pdf->SetFont('Arial', '', 10);
            $pdf->SetTextColor(55, 65, 81);
            $pdf->MultiCell(0, 6, $this->latin1($incident['description']), 0, 'L');
            $pdf->Ln(4);
        }

        // ── Photos ────────────────────────────────────────────
        if ($photos) {
            $this->sectionTitle($pdf, 'Photos (' . count($photos) . ')');
            $x = 20;
            $y = $pdf->GetY();
            $imgW = 55;
            $imgH = 40;
            $col  = 0;
            foreach ($photos as $photo) {
                $filePath = __DIR__ . '/../uploads/' . basename($photo['file_path']);
                if (file_exists($filePath)) {
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $type = match($ext) { 'jpg','jpeg' => 'JPEG', 'png' => 'PNG', default => null };
                    if ($type) {
                        if ($y + $imgH > 270) {
                            $pdf->AddPage();
                            $y = 20;
                            $col = 0;
                            $x = 20;
                        }
                        $pdf->Image($filePath, $x + $col * ($imgW + 5), $y, $imgW, $imgH, $type);
                        $col++;
                        if ($col >= 3) {
                            $col = 0;
                            $y += $imgH + 5;
                        }
                    }
                }
            }
            $pdf->SetY($y + $imgH + 8);
            $pdf->Ln(2);
        }

        // ── Historique des statuts ────────────────────────────
        if ($history) {
            $this->sectionTitle($pdf, 'Historique des statuts');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetFillColor(249, 250, 251);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->Cell(35, 7, 'Date', 1, 0, 'L', true);
            $pdf->Cell(35, 7, 'Ancien statut', 1, 0, 'L', true);
            $pdf->Cell(35, 7, 'Nouveau statut', 1, 0, 'L', true);
            $pdf->Cell(65, 7, 'Agent', 1, 1, 'L', true);
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(55, 65, 81);
            foreach ($history as $h) {
                $pdf->Cell(35, 6, date('d/m/Y H:i', strtotime($h['changed_at'])), 1, 0);
                $pdf->Cell(35, 6, $this->statusLabel($h['old_status']), 1, 0);
                $pdf->Cell(35, 6, $this->statusLabel($h['new_status']), 1, 0);
                $pdf->Cell(65, 6, $this->latin1($h['agent_name']), 1, 1);
            }
            $pdf->Ln(4);
        }

        // ── Commentaires ──────────────────────────────────────
        if ($comments) {
            $this->sectionTitle($pdf, 'Commentaires (' . count($comments) . ')');
            foreach ($comments as $c) {
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->SetTextColor(37, 99, 235);
                $pdf->Cell(0, 5, $this->latin1($c['author_name']) . ' — ' . date('d/m/Y H:i', strtotime($c['created_at'])), 0, 1);
                $pdf->SetFont('Arial', '', 9);
                $pdf->SetTextColor(55, 65, 81);
                $pdf->MultiCell(0, 5, $this->latin1($c['comment']), 0, 'L');
                $pdf->Ln(2);
            }
        }

        // ── Pied de page ──────────────────────────────────────
        $pdf->SetY(-20);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 6, (defined('APP_NAME') ? APP_NAME : 'Ma Commune') . ' — Rapport confidentiel — ' . date('d/m/Y'), 0, 0, 'C');

        // ── Envoi ─────────────────────────────────────────────
        $filename = (defined('APP_REFERENCE_PREFIX') ? APP_REFERENCE_PREFIX : 'MC') . '_Incident_' . $incident['reference'] . '_' . date('Ymd') . '.pdf';
        $pdf->Output('D', $filename);
    }

    // ── Helpers ───────────────────────────────────────────────
    private function sectionTitle(\FPDF $pdf, string $title): void
    {
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetFillColor(239, 246, 255);
        $pdf->Cell(0, 8, ' ' . $this->latin1($title), 0, 1, 'L', true);
        $pdf->Ln(2);
    }

    private function row(\FPDF $pdf, string $label, string $value): void
    {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(45, 6, $this->latin1($label) . ' :', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 6, $this->latin1($value), 0, 1);
    }

    private function latin1(string $str): string
    {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }

    private function statusLabel(string $status): string
    {
        return match($status) {
            'submitted'   => 'Soumis',
            'in_progress' => 'En cours',
            'resolved'    => 'Résolu',
            'rejected'    => 'Rejeté',
            default       => $status,
        };
    }

    private function loadIncident(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, cat.name AS category_name,
                   u.full_name AS reporter_name, u.email AS reporter_email, u.phone AS reporter_phone
            FROM incidents i
            JOIN categories cat ON cat.id = i.category_id
            JOIN users u ON u.id = i.user_id
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadPhotos(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM photos WHERE incident_id = ? ORDER BY sort_order, id");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            FROM status_history sh JOIN users u ON u.id = sh.changed_by
            WHERE sh.incident_id = ? ORDER BY sh.changed_at ASC
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
