<?php

/**
 * CCDS — ReportController
 * Génération de rapports PDF pour les incidents.
 *
 * GET /api/incidents/{id}/report  → Télécharger le PDF de l'incident
 */
class ReportController extends BaseController
{
    public function downloadPdf(int $incidentId): void
    {
        $this->requireRole('agent');
        Permissions::check($this->user, 'incidents.view');

        // Vérifier que l'incident existe
        $stmt = $this->db->prepare("SELECT id FROM incidents WHERE id = ?");
        $stmt->execute([$incidentId]);
        if (!$stmt->fetch()) {
            $this->error('Incident introuvable', 404);
        }

        // Charger FPDF via Composer autoload (si disponible) ou fallback manuel
        $composerAutoload = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            require_once $composerAutoload;
        } else {
            // Fallback : FPDF téléchargé manuellement dans vendor/fpdf/
            $fpdfPath = __DIR__ . '/../vendor/fpdf/fpdf.php';
            if (!file_exists($fpdfPath)) {
                $this->error('FPDF non disponible. Exécutez composer install.', 500);
            }
            require_once $fpdfPath;
        }

        require_once __DIR__ . '/../config/PdfReportService.php';

        // Générer et envoyer le PDF (Output 'D' = téléchargement)
        $service = new PdfReportService($this->db);
        $service->generate($incidentId);
    }
}
