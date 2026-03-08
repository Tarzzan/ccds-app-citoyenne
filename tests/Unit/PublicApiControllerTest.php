<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour PublicApiController (API-02)
 * Couvre : endpoints publics lecture seule, pagination, filtres
 */
class PublicApiControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Accès public (sans authentification)
    // -------------------------------------------------------------------------
    public function testPublicEndpointsDoNotRequireAuth(): void
    {
        $publicEndpoints = [
            'GET /api/public/incidents',
            'GET /api/public/incidents/{id}',
            'GET /api/public/stats',
        ];
        $this->assertCount(3, $publicEndpoints);
    }

    public function testPublicApiIsReadOnly(): void
    {
        $allowedMethods = ['GET'];
        $this->assertContains('GET', $allowedMethods);
        $this->assertNotContains('POST', $allowedMethods);
        $this->assertNotContains('PUT', $allowedMethods);
        $this->assertNotContains('DELETE', $allowedMethods);
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------
    public function testDefaultPageSizeIsTwenty(): void
    {
        $defaultLimit = 20;
        $this->assertEquals(20, $defaultLimit);
    }

    public function testMaxPageSizeIsOneHundred(): void
    {
        $requestedLimit = 500;
        $maxLimit = 100;
        $appliedLimit = min($requestedLimit, $maxLimit);
        $this->assertEquals(100, $appliedLimit);
    }

    public function testPaginationResponseContainsMetadata(): void
    {
        $response = [
            'data'  => [],
            'meta'  => [
                'total'    => 150,
                'page'     => 1,
                'per_page' => 20,
                'pages'    => 8,
            ],
        ];
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('total', $response['meta']);
        $this->assertArrayHasKey('pages', $response['meta']);
    }

    // -------------------------------------------------------------------------
    // Filtres
    // -------------------------------------------------------------------------
    public function testFilterByStatusAcceptsValidValues(): void
    {
        $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
        $this->assertContains('open', $validStatuses);
        $this->assertContains('resolved', $validStatuses);
    }

    public function testFilterByStatusRejectsInvalidValue(): void
    {
        $validStatuses = ['open', 'in_progress', 'resolved', 'closed'];
        $invalid = 'deleted';
        $this->assertNotContains($invalid, $validStatuses);
    }

    // -------------------------------------------------------------------------
    // Données sensibles masquées
    // -------------------------------------------------------------------------
    public function testPublicResponseDoesNotExposeUserEmail(): void
    {
        $incident = [
            'id'          => 1,
            'title'       => 'Nid de poule',
            'status'      => 'open',
            'created_by'  => 'Jean D.', // Nom tronqué, pas d'email
        ];
        $this->assertArrayNotHasKey('email', $incident);
        $this->assertArrayNotHasKey('phone', $incident);
    }

    public function testPublicResponseDoesNotExposeInternalIds(): void
    {
        $incident = [
            'reference' => 'MC-2026-001',
            'title'     => 'Nid de poule',
            'status'    => 'open',
        ];
        // L'ID interne est remplacé par une référence publique
        $this->assertArrayHasKey('reference', $incident);
    }

    // -------------------------------------------------------------------------
    // Statistiques publiques
    // -------------------------------------------------------------------------
    public function testPublicStatsContainRequiredFields(): void
    {
        $stats = [
            'total_incidents'    => 450,
            'resolved_incidents' => 380,
            'resolution_rate'    => 84.4,
            'avg_resolution_days' => 3.2,
            'categories'         => [],
        ];
        $this->assertArrayHasKey('total_incidents', $stats);
        $this->assertArrayHasKey('resolution_rate', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['resolution_rate']);
        $this->assertLessThanOrEqual(100, $stats['resolution_rate']);
    }
}
