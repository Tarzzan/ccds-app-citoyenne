<?php

require_once __DIR__ . '/../core/BaseController.php';

/**
 * Ma Commune — ReportController
 * Génération de rapports PDF pour les incidents.
 *
 * GET /api/incidents/{id}/report  → Télécharger le PDF de l'incident
 */
class ReportController extends BaseController
{
    private function resolveAdminSessionAuth(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $admin = $_SESSION['admin_user'] ?? null;
        if (!is_array($admin)) {
            return null;
        }

        $role = $admin['role'] ?? null;
        $userId = (int)($admin['id'] ?? 0);
        if (!in_array($role, ['agent', 'admin'], true) || $userId <= 0) {
            return null;
        }

        return [
            'sub' => $userId,
            'role' => $role,
            'email' => $admin['email'] ?? null,
            'full_name' => $admin['full_name'] ?? null,
        ];
    }

    public function downloadPdf(int $incidentId): void
    {
        $auth = $this->getOptionalAuth() ?? $this->resolveAdminSessionAuth();
        if (!$auth) {
            $this->error("Token d'authentification manquant.", 401);
        }
        if (!in_array($auth['role'] ?? '', ['agent', 'admin'], true)) {
            $this->error('Accès réservé aux agents et administrateurs.', 403);
        }
        $this->requirePermission($auth, 'incident:read');

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
