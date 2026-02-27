<?php
/**
 * CCDS — Tests d'Intégration : Endpoint Incidents API
 */

namespace CCDS\Tests\Integration;

use PHPUnit\Framework\TestCase;

class ApiIncidentsTest extends TestCase
{
    private string $baseUrl;
    private ?string $citizenToken  = null;
    private ?string $agentToken    = null;

    protected function setUp(): void
    {
        $this->baseUrl = $_ENV['API_BASE_URL'] ?? 'http://localhost/api';

        // Obtenir un token citoyen de test
        $email    = 'incident_test_' . uniqid() . '@ccds-test.fr';
        $register = $this->post('/auth/register', [
            'full_name' => 'Citoyen Incident',
            'email'     => $email,
            'password'  => 'TestP@ss123!',
        ]);
        $this->citizenToken = $register['body']['token'] ?? null;
    }

    // ----------------------------------------------------------------
    // Helpers HTTP
    // ----------------------------------------------------------------

    private function post(string $endpoint, array $data, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                $headers
            ),
            CURLOPT_TIMEOUT => 10,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => json_decode($body, true) ?? []];
    }

    private function get(string $endpoint, array $headers = []): array
    {
        $ch = curl_init($this->baseUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => json_decode($body, true) ?? []];
    }

    private function authHeader(): array
    {
        return $this->citizenToken
            ? ["Authorization: Bearer {$this->citizenToken}"]
            : [];
    }

    // ----------------------------------------------------------------
    // Tests : Catégories (GET /categories)
    // ----------------------------------------------------------------

    /**
     * @test
     * @group incidents
     * @group categories
     */
    public function get_categories_returns_200_and_list(): void
    {
        $response = $this->get('/categories', $this->authHeader());

        $this->assertEquals(200, $response['status'],
            'GET /categories doit retourner HTTP 200.');
        $this->assertIsArray($response['body'],
            'La réponse doit être un tableau de catégories.');
        $this->assertNotEmpty($response['body'],
            'La liste des catégories ne doit pas être vide (8 catégories par défaut).');

        // Vérifier la structure d'une catégorie
        $firstCat = $response['body'][0] ?? [];
        $this->assertArrayHasKey('id',    $firstCat);
        $this->assertArrayHasKey('name',  $firstCat);
        $this->assertArrayHasKey('color', $firstCat);
    }

    // ----------------------------------------------------------------
    // Tests : Création d'incident (POST /incidents)
    // ----------------------------------------------------------------

    /**
     * @test
     * @group incidents
     * @group create
     */
    public function create_incident_with_valid_data_returns_201(): void
    {
        if (!$this->citizenToken) {
            $this->markTestSkipped('Token citoyen non disponible — serveur API requis.');
        }

        $response = $this->post('/incidents', [
            'category_id' => 1,
            'description' => 'Trou dans la chaussée rue de la Paix, dangereux pour les cyclistes.',
            'latitude'    => 48.8566,
            'longitude'   => 2.3522,
            'address'     => '10 Rue de la Paix, Paris',
        ], $this->authHeader());

        $this->assertEquals(201, $response['status'],
            'La création d\'un incident valide doit retourner HTTP 201.');
        $this->assertArrayHasKey('id',        $response['body']);
        $this->assertArrayHasKey('reference', $response['body']);
        $this->assertMatchesRegularExpression(
            '/^CCDS-\d{8}-[A-Z0-9]+$/',
            $response['body']['reference'] ?? '',
            'La référence doit suivre le format CCDS-YYYYMMDD-XXXXX.'
        );
    }

    /**
     * @test
     * @group incidents
     * @group create
     */
    public function create_incident_without_auth_returns_401(): void
    {
        $response = $this->post('/incidents', [
            'category_id' => 1,
            'description' => 'Test sans authentification.',
            'latitude'    => 48.8566,
            'longitude'   => 2.3522,
        ]);

        $this->assertEquals(401, $response['status'],
            'Créer un incident sans token doit retourner HTTP 401.');
    }

    /**
     * @test
     * @group incidents
     * @group create
     */
    public function create_incident_with_missing_required_fields_returns_422(): void
    {
        if (!$this->citizenToken) {
            $this->markTestSkipped('Token citoyen non disponible — serveur API requis.');
        }

        $response = $this->post('/incidents', [
            // description manquante, coordonnées manquantes
            'category_id' => 1,
        ], $this->authHeader());

        $this->assertEquals(422, $response['status'],
            'Un incident sans champs obligatoires doit retourner HTTP 422.');
    }

    /**
     * @test
     * @group incidents
     * @group create
     */
    public function create_incident_with_invalid_coordinates_returns_422(): void
    {
        if (!$this->citizenToken) {
            $this->markTestSkipped('Token citoyen non disponible — serveur API requis.');
        }

        $response = $this->post('/incidents', [
            'category_id' => 1,
            'description' => 'Coordonnées invalides.',
            'latitude'    => 999.0,   // Invalide
            'longitude'   => -999.0,  // Invalide
        ], $this->authHeader());

        $this->assertEquals(422, $response['status'],
            'Des coordonnées hors limites doivent retourner HTTP 422.');
    }

    // ----------------------------------------------------------------
    // Tests : Liste des incidents (GET /incidents)
    // ----------------------------------------------------------------

    /**
     * @test
     * @group incidents
     * @group list
     */
    public function get_incidents_with_auth_returns_200_and_paginated_list(): void
    {
        if (!$this->citizenToken) {
            $this->markTestSkipped('Token citoyen non disponible — serveur API requis.');
        }

        $response = $this->get('/incidents', $this->authHeader());

        $this->assertEquals(200, $response['status'],
            'GET /incidents avec token doit retourner HTTP 200.');
        $this->assertArrayHasKey('data',  $response['body'],
            'La réponse doit contenir une clé "data".');
        $this->assertArrayHasKey('total', $response['body'],
            'La réponse doit contenir une clé "total".');
        $this->assertArrayHasKey('page',  $response['body'],
            'La réponse doit contenir une clé "page".');
    }

    /**
     * @test
     * @group incidents
     * @group list
     */
    public function get_incidents_supports_status_filter(): void
    {
        if (!$this->citizenToken) {
            $this->markTestSkipped('Token citoyen non disponible — serveur API requis.');
        }

        $response = $this->get('/incidents?status=resolved', $this->authHeader());

        $this->assertEquals(200, $response['status']);

        // Tous les incidents retournés doivent avoir le statut "resolved"
        $incidents = $response['body']['data'] ?? [];
        foreach ($incidents as $inc) {
            $this->assertEquals('resolved', $inc['status'],
                'Tous les incidents filtrés doivent avoir le statut "resolved".');
        }
    }
}
