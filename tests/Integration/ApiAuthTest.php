<?php
/**
 * CCDS — Tests d'Intégration : Endpoint d'Authentification API
 *
 * Ces tests simulent des requêtes HTTP vers l'API REST.
 * Ils nécessitent un serveur Apache/PHP actif avec la BDD de test configurée.
 * En CI/CD, utiliser un serveur PHP intégré : php -S localhost:8080 -t backend/
 */

namespace CCDS\Tests\Integration;

use PHPUnit\Framework\TestCase;

class ApiAuthTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        $this->baseUrl = $_ENV['API_BASE_URL'] ?? 'http://localhost/api';
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
            CURLOPT_TIMEOUT        => 10,
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
            CURLOPT_HTTPHEADER     => array_merge(
                ['Accept: application/json'],
                $headers
            ),
            CURLOPT_TIMEOUT => 10,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => json_decode($body, true) ?? []];
    }

    // ----------------------------------------------------------------
    // Tests : Inscription (POST /auth/register)
    // ----------------------------------------------------------------

    /**
     * @test
     * @group auth
     * @group register
     */
    public function register_with_valid_data_returns_201_and_token(): void
    {
        $uniqueEmail = 'test_' . uniqid() . '@ccds-test.fr';
        $response    = $this->post('/auth/register', [
            'full_name' => 'Citoyen Test',
            'email'     => $uniqueEmail,
            'password'  => 'TestP@ss123!',
        ]);

        $this->assertEquals(201, $response['status'],
            'L\'inscription avec des données valides doit retourner HTTP 201.');
        $this->assertArrayHasKey('token', $response['body'],
            'La réponse doit contenir un token JWT.');
        $this->assertArrayHasKey('user', $response['body'],
            'La réponse doit contenir les données utilisateur.');
        $this->assertEquals($uniqueEmail, $response['body']['user']['email'] ?? null);
    }

    /**
     * @test
     * @group auth
     * @group register
     */
    public function register_with_missing_fields_returns_422(): void
    {
        $response = $this->post('/auth/register', [
            'email' => 'incomplete@ccds-test.fr',
            // full_name et password manquants
        ]);

        $this->assertEquals(422, $response['status'],
            'Une inscription incomplète doit retourner HTTP 422.');
        $this->assertArrayHasKey('errors', $response['body'],
            'La réponse doit contenir un tableau d\'erreurs de validation.');
    }

    /**
     * @test
     * @group auth
     * @group register
     */
    public function register_with_duplicate_email_returns_409(): void
    {
        $email = 'duplicate_' . uniqid() . '@ccds-test.fr';

        // Premier enregistrement
        $this->post('/auth/register', [
            'full_name' => 'Premier',
            'email'     => $email,
            'password'  => 'TestP@ss123!',
        ]);

        // Deuxième enregistrement avec le même email
        $response = $this->post('/auth/register', [
            'full_name' => 'Doublon',
            'email'     => $email,
            'password'  => 'AnotherP@ss123!',
        ]);

        $this->assertEquals(409, $response['status'],
            'Un email déjà utilisé doit retourner HTTP 409 Conflict.');
    }

    /**
     * @test
     * @group auth
     * @group register
     */
    public function register_with_invalid_email_returns_422(): void
    {
        $response = $this->post('/auth/register', [
            'full_name' => 'Test',
            'email'     => 'not-an-email',
            'password'  => 'TestP@ss123!',
        ]);

        $this->assertEquals(422, $response['status'],
            'Un email invalide doit retourner HTTP 422.');
    }

    // ----------------------------------------------------------------
    // Tests : Connexion (POST /auth/login)
    // ----------------------------------------------------------------

    /**
     * @test
     * @group auth
     * @group login
     */
    public function login_with_valid_credentials_returns_200_and_token(): void
    {
        // Créer un compte de test
        $email    = 'login_test_' . uniqid() . '@ccds-test.fr';
        $password = 'LoginP@ss123!';
        $this->post('/auth/register', [
            'full_name' => 'Login Test',
            'email'     => $email,
            'password'  => $password,
        ]);

        // Tenter la connexion
        $response = $this->post('/auth/login', [
            'email'    => $email,
            'password' => $password,
        ]);

        $this->assertEquals(200, $response['status'],
            'Une connexion avec des identifiants valides doit retourner HTTP 200.');
        $this->assertArrayHasKey('token', $response['body'],
            'La réponse doit contenir un token JWT.');

        // Vérifier la structure du token JWT (3 parties)
        $token = $response['body']['token'];
        $this->assertCount(3, explode('.', $token),
            'Le token JWT doit avoir 3 parties séparées par des points.');
    }

    /**
     * @test
     * @group auth
     * @group login
     */
    public function login_with_wrong_password_returns_401(): void
    {
        $response = $this->post('/auth/login', [
            'email'    => 'admin@ccds.fr',
            'password' => 'WrongPassword!',
        ]);

        $this->assertEquals(401, $response['status'],
            'Un mauvais mot de passe doit retourner HTTP 401.');
    }

    /**
     * @test
     * @group auth
     * @group login
     */
    public function login_with_nonexistent_email_returns_401(): void
    {
        $response = $this->post('/auth/login', [
            'email'    => 'ghost_' . uniqid() . '@nowhere.fr',
            'password' => 'AnyPassword!',
        ]);

        $this->assertEquals(401, $response['status'],
            'Un email inexistant doit retourner HTTP 401.');
    }

    // ----------------------------------------------------------------
    // Tests : Accès protégé
    // ----------------------------------------------------------------

    /**
     * @test
     * @group auth
     * @group protected
     */
    public function accessing_protected_endpoint_without_token_returns_401(): void
    {
        $response = $this->get('/incidents');

        $this->assertEquals(401, $response['status'],
            'Accéder à un endpoint protégé sans token doit retourner HTTP 401.');
    }

    /**
     * @test
     * @group auth
     * @group protected
     */
    public function accessing_protected_endpoint_with_invalid_token_returns_401(): void
    {
        $response = $this->get('/incidents', [
            'Authorization: Bearer this.is.not.a.valid.token',
        ]);

        $this->assertEquals(401, $response['status'],
            'Un token invalide doit retourner HTTP 401.');
    }
}
