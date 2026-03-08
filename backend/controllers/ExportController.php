<?php
/**
 * CCDS — ExportController
 * Export global des incidents en CSV ou PDF pour les administrateurs.
 *
 * GET /api/admin/reports/export?format=csv           → CSV de tous les incidents
 * GET /api/admin/reports/export?format=pdf           → PDF récapitulatif
 * GET /api/admin/reports/export?format=csv&status=X  → Filtrer par statut
 * GET /api/admin/reports/export?format=csv&from=YYYY-MM-DD&to=YYYY-MM-DD → Filtrer par date
 */
class ExportController extends BaseController
{
    public function export(): void
    {
        $user = $this->requireAuth();
        if (($user['role'] ?? '') !== 'admin') {
            $this->error('Accès réservé aux administrateurs.', 403);
        }

        $format  = $_GET['format']   ?? 'csv';
        $status  = $_GET['status']   ?? null;
        $from    = $_GET['from']     ?? null;
        $to      = $_GET['to']       ?? null;
        $catId   = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

        // Construire la requête avec filtres optionnels
        $where  = [];
        $params = [];

        if ($status) {
            $allowed = ['submitted', 'in_progress', 'resolved', 'rejected'];
            if (in_array($status, $allowed)) {
                $where[]  = 'i.status = ?';
                $params[] = $status;
            }
        }
        if ($from) {
            $where[]  = 'DATE(i.created_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[]  = 'DATE(i.created_at) <= ?';
            $params[] = $to;
        }
        if ($catId) {
            $where[]  = 'i.category_id = ?';
            $params[] = $catId;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("
            SELECT
                i.id,
                i.reference,
                i.title,
                i.description,
                i.status,
                i.priority,
                i.latitude,
                i.longitude,
                i.address,
                i.created_at,
                i.updated_at,
                cat.name  AS category_name,
                u.full_name AS reporter_name,
                u.email   AS reporter_email,
                COALESCE(agent.full_name, '') AS agent_name
            FROM incidents i
            JOIN categories cat ON cat.id = i.category_id
            JOIN users u ON u.id = i.user_id
            LEFT JOIN users agent ON agent.id = i.assigned_to
            $whereClause
            ORDER BY i.created_at DESC
            LIMIT 5000
        ");
        $stmt->execute($params);
        $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($format === 'pdf') {
            $this->exportPdf($incidents);
        } else {
            $this->exportCsv($incidents);
        }
    }

    // ── Export CSV ────────────────────────────────────────────
    private function exportCsv(array $incidents): void
    {
        $filename = 'Ma_Commune_Incidents_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');

        // BOM UTF-8 pour Excel
        fwrite($out, "\xEF\xBB\xBF");

        // En-têtes
        fputcsv($out, [
            'ID', 'Référence', 'Titre', 'Description', 'Statut', 'Priorité',
            'Catégorie', 'Adresse', 'Latitude', 'Longitude',
            'Citoyen', 'Email', 'Agent assigné',
            'Créé le', 'Mis à jour le'
        ], ';');

        $statusLabels = [
            'submitted'   => 'Soumis',
            'in_progress' => 'En cours',
            'resolved'    => 'Résolu',
            'rejected'    => 'Rejeté',
        ];
        $priorityLabels = [
            'low'    => 'Basse',
            'medium' => 'Moyenne',
            'high'   => 'Haute',
            'urgent' => 'Urgent',
        ];

        foreach ($incidents as $i) {
            fputcsv($out, [
                $i['id'],
                $i['reference'],
                $i['title'],
                $i['description'],
                $statusLabels[$i['status']] ?? $i['status'],
                $priorityLabels[$i['priority'] ?? ''] ?? ($i['priority'] ?? ''),
                $i['category_name'],
                $i['address'] ?? '',
                $i['latitude']  ?? '',
                $i['longitude'] ?? '',
                $i['reporter_name'],
                $i['reporter_email'],
                $i['agent_name'],
                $i['created_at'],
                $i['updated_at'],
            ], ';');
        }

        fclose($out);
        exit;
    }

    // ── Export PDF ────────────────────────────────────────────
    private function exportPdf(array $incidents): void
    {
        $fpdfPath = __DIR__ . '/../vendor/fpdf/fpdf.php';
        if (!file_exists($fpdfPath)) {
            $this->error('FPDF non disponible', 500);
        }
        require_once $fpdfPath;

        $appName = defined('APP_NAME') ? APP_NAME : 'Ma Commune';
        $filename = $appName . '_Rapport_Incidents_' . date('Ymd') . '.pdf';

        $pdf = new \FPDF('L', 'mm', 'A4'); // Paysage pour les colonnes
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        // ── En-tête ──
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(0, 10, $this->l1($appName . ' — Rapport des Incidents'), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(0, 6, 'Généré le ' . date('d/m/Y à H:i') . ' — ' . count($incidents) . ' incident(s)', 0, 1, 'C');
        $pdf->Ln(4);

        // ── Statistiques rapides ──
        $stats = $this->computeStats($incidents);
        $pdf->SetFillColor(239, 246, 255);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(37, 99, 235);
        $statCols = [
            'Total'       => count($incidents),
            'Soumis'      => $stats['submitted'],
            'En cours'    => $stats['in_progress'],
            'Résolus'     => $stats['resolved'],
            'Rejetés'     => $stats['rejected'],
        ];
        $colW = 267 / count($statCols);
        foreach ($statCols as $label => $val) {
            $pdf->Cell($colW, 8, $this->l1($label . ' : ' . $val), 1, 0, 'C', true);
        }
        $pdf->Ln(12);

        // ── Tableau ──
        $headers = ['Réf.', 'Titre', 'Statut', 'Priorité', 'Catégorie', 'Citoyen', 'Agent', 'Date'];
        $widths  = [22,      70,      22,        20,          30,          40,         35,     28];

        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(30, 64, 175);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $k => $h) {
            $pdf->Cell($widths[$k], 7, $this->l1($h), 1, 0, 'C', true);
        }
        $pdf->Ln();

        $statusLabels = [
            'submitted'   => 'Soumis',
            'in_progress' => 'En cours',
            'resolved'    => 'Résolu',
            'rejected'    => 'Rejeté',
        ];
        $priorityLabels = ['low' => 'Basse', 'medium' => 'Moyenne', 'high' => 'Haute', 'urgent' => 'Urgent'];
        $statusColors = [
            'submitted'   => [251, 191, 36],
            'in_progress' => [59,  130, 246],
            'resolved'    => [34,  197, 94],
            'rejected'    => [239, 68,  68],
        ];

        $pdf->SetFont('Arial', '', 7.5);
        $fill = false;
        foreach ($incidents as $i) {
            $color = $statusColors[$i['status']] ?? [200, 200, 200];
            $pdf->SetTextColor(17, 24, 39);
            $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);

            $row = [
                $i['reference'],
                mb_substr($i['title'], 0, 35) . (mb_strlen($i['title']) > 35 ? '…' : ''),
                $statusLabels[$i['status']] ?? $i['status'],
                $priorityLabels[$i['priority'] ?? ''] ?? '',
                mb_substr($i['category_name'], 0, 18),
                mb_substr($i['reporter_name'], 0, 20),
                mb_substr($i['agent_name'], 0, 18),
                date('d/m/Y', strtotime($i['created_at'])),
            ];

            foreach ($row as $k => $val) {
                if ($k === 2) { // Colonne statut colorée
                    $pdf->SetFillColor(...$color);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->Cell($widths[$k], 6, $this->l1($val), 1, 0, 'C', true);
                    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
                    $pdf->SetTextColor(17, 24, 39);
                } else {
                    $pdf->Cell($widths[$k], 6, $this->l1($val), 1, 0, 'L', true);
                }
            }
            $pdf->Ln();
            $fill = !$fill;
        }

        // ── Pied de page ──
        $pdf->SetY(-12);
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 6, $this->l1($appName . ' — Document confidentiel — ' . date('d/m/Y')), 0, 0, 'C');

        $pdf->Output('D', $filename);
        exit;
    }

    // ── Helpers ───────────────────────────────────────────────
    private function computeStats(array $incidents): array
    {
        $stats = ['submitted' => 0, 'in_progress' => 0, 'resolved' => 0, 'rejected' => 0];
        foreach ($incidents as $i) {
            if (isset($stats[$i['status']])) {
                $stats[$i['status']]++;
            }
        }
        return $stats;
    }

    private function l1(string $str): string
    {
        return mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    }
}
