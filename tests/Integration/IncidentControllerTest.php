<?php
/**
 * CCDS v1.3 — Tests d'Intégration : IncidentController (TEST-01)
 * Couvre : création, lecture, édition, filtres, votes.
 * Note : utilise une base de données de test (CCDS_TEST_DB).
 */
namespace CCDS\Tests\Integration;

use PHPUnit\Framework\TestCase;

class IncidentControllerTest extends TestCase
{
    private static \PDO $db;
    private static string $token_citizen;
    private static string $token_agent;
    private static int    $incident_id;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST')     ?: 'localhost';
        $name = getenv('DB_TEST_NAME') ?: 'ccds_test';
        $user = getenv('DB_USER')     ?: 'root';
        $pass = getenv('DB_PASS')     ?: '';

        try {
            self::$db = new \PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException $e) {
            self::markTestSkipped("Base de test indisponible : " . $e->getMessage());
        }

        // Créer les tokens de test (simulés)
        self::$token_citizen = self::makeJwt(['user_id' => 1, 'role' => 'citizen', 'exp' => time() + 3600]);
        self::$token_agent   = self::makeJwt(['user_id' => 2, 'role' => 'agent',   'exp' => time() + 3600]);
    }

    // ----------------------------------------------------------------
    // Lecture des incidents
    // ----------------------------------------------------------------

    /** @test @group incidents */
    public function list_incidents_returns_paginated_results(): void
    {
        $response = $this->apiRequest('GET', '/incidents?page=1&limit=10');
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('incidents',  $response['body']['data']);
        $this->assertArrayHasKey('pagination', $response['body']['data']);
    }

    /** @test @group incidents */
    public function list_incidents_supports_status_filter(): void
    {
        $response = $this->apiRequest('GET', '/incidents?status=submitted');
        $this->assertEquals(200, $response['status']);
        if (!empty($response['body']['data']['incidents'])) {
            foreach ($response['body']['data']['incidents'] as $inc) {
                $this->assertEquals('submitted', $inc['status']);
            }
        }
    }

    /** @test @group incidents */
    public function list_incidents_supports_search_query(): void
    {
        $response = $this->apiRequest('GET', '/incidents?q=nid+de+poule');
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('incidents', $response['body']['data']);
    }

    /** @test @group incidents */
    public function list_incidents_supports_sort_by_votes(): void
    {
        $response = $this->apiRequest('GET', '/incidents?sort=votes&order=desc');
        $this->assertEquals(200, $response['status']);
    }

    // ----------------------------------------------------------------
    // Création
    // ----------------------------------------------------------------

    /** @test @group incidents */
    public function create_incident_requires_authentication(): void
    {
        $response = $this->apiRequest('POST', '/incidents', [
            'title'       => 'Test sans auth',
            'description' => 'Description de test',
            'category_id' => 1,
            'latitude'    => 4.9224,
            'longitude'   => -52.3135,
        ]);
        $this->assertEquals(401, $response['status']);
    }

    /** @test @group incidents */
    public function create_incident_validates_required_fields(): void
    {
        $response = $this->apiRequest('POST', '/incidents', [], self::$token_citizen);
        $this->assertContains($response['status'], [400, 422]);
    }

    // ----------------------------------------------------------------
    // Édition
    // ----------------------------------------------------------------

    /** @test @group incidents */
    public function edit_incident_requires_ownership(): void
    {
        // L'agent (user_id=2) ne peut pas éditer l'incident du citoyen (user_id=1)
        // sauf via update_status
        $response = $this->apiRequest('PATCH', '/incidents/1', [
            'description' => 'Modification non autorisée',
        ], self::$token_agent);
        // 403 ou 404 selon l'implémentation
        $this->assertContains($response['status'], [403, 404]);
    }

    // ----------------------------------------------------------------
    // Votes
    // ----------------------------------------------------------------

    /** @test @group votes */
    public function vote_for_incident_requires_authentication(): void
    {
        $response = $this->apiRequest('POST', '/incidents/1/vote');
        $this->assertEquals(401, $response['status']);
    }

    /** @test @group votes */
    public function vote_returns_updated_count(): void
    {
        $response = $this->apiRequest('POST', '/incidents/1/vote', [], self::$token_citizen);
        if ($response['status'] === 200 || $response['status'] === 201) {
            $this->assertArrayHasKey('votes_count', $response['body']['data'] ?? []);
        } else {
            // Vote déjà existant (409) ou incident inexistant (404) — acceptable en test
            $this->assertContains($response['status'], [200, 201, 409, 404]);
        }
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function apiRequest(string $method, string $path, array $body = [], string $token = ''): array
    {
        $base = getenv('API_BASE_URL') ?: 'http://localhost/api';
        $url  = $base . $path;

        $headers = ['Content-Type: application/json'];
        if ($token) $headers[] = "Authorization: Bearer $token";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            self::markTestSkipped("API non disponible à $url");
        }

        return ['status' => $status, 'body' => json_decode($raw, true) ?? []];
    }

    private static function makeJwt(array $payload): string
    {
        $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = base64_encode(json_encode($payload));
        $sig     = base64_encode(hash_hmac('sha256', "$header.$body", 'test_secret', true));
        return "$header.$body.$sig";
    }
}
