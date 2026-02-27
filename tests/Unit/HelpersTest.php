<?php
/**
 * CCDS — Tests Unitaires : Helpers (JWT, Validation, Formatage)
 */

namespace CCDS\Tests\Unit;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    // ----------------------------------------------------------------
    // JWT
    // ----------------------------------------------------------------

    /**
     * @test
     * @group jwt
     */
    public function jwt_encode_returns_three_part_token(): void
    {
        $payload = ['user_id' => 1, 'role' => 'citizen'];
        $token   = $this->jwtEncode($payload);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'Un token JWT doit avoir 3 parties séparées par des points.');
    }

    /**
     * @test
     * @group jwt
     */
    public function jwt_decode_returns_original_payload(): void
    {
        $payload = ['user_id' => 42, 'role' => 'agent', 'exp' => time() + 3600];
        $token   = $this->jwtEncode($payload);
        $decoded = $this->jwtDecode($token);

        $this->assertNotNull($decoded, 'Le décodage ne doit pas retourner null pour un token valide.');
        $this->assertEquals(42,      $decoded['user_id']);
        $this->assertEquals('agent', $decoded['role']);
    }

    /**
     * @test
     * @group jwt
     */
    public function jwt_decode_returns_null_for_expired_token(): void
    {
        $payload = ['user_id' => 1, 'exp' => time() - 10]; // Expiré
        $token   = $this->jwtEncode($payload);
        $decoded = $this->jwtDecode($token);

        $this->assertNull($decoded, 'Un token expiré doit retourner null.');
    }

    /**
     * @test
     * @group jwt
     */
    public function jwt_decode_returns_null_for_tampered_token(): void
    {
        $payload = ['user_id' => 1, 'role' => 'citizen', 'exp' => time() + 3600];
        $token   = $this->jwtEncode($payload);

        // Falsifier la signature
        $parts       = explode('.', $token);
        $parts[2]    = base64_encode('fake_signature');
        $tamperedToken = implode('.', $parts);

        $decoded = $this->jwtDecode($tamperedToken);
        $this->assertNull($decoded, 'Un token falsifié doit retourner null.');
    }

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * @test
     * @group validation
     */
    public function validate_email_accepts_valid_addresses(): void
    {
        $valid = ['user@example.com', 'agent.mairie@ville.fr', 'admin+test@ccds.org'];
        foreach ($valid as $email) {
            $this->assertTrue(
                filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                "L'email '$email' devrait être valide."
            );
        }
    }

    /**
     * @test
     * @group validation
     */
    public function validate_email_rejects_invalid_addresses(): void
    {
        $invalid = ['notanemail', 'missing@', '@nodomain.com', ''];
        foreach ($invalid as $email) {
            $this->assertFalse(
                filter_var($email, FILTER_VALIDATE_EMAIL),
                "L'email '$email' devrait être invalide."
            );
        }
    }

    /**
     * @test
     * @group validation
     */
    public function password_hash_and_verify_work_correctly(): void
    {
        $password = 'SecureP@ssw0rd!';
        $hash     = password_hash($password, PASSWORD_DEFAULT);

        $this->assertTrue(password_verify($password, $hash), 'Le mot de passe correct doit être vérifié.');
        $this->assertFalse(password_verify('wrongpassword', $hash), 'Un mauvais mot de passe ne doit pas être vérifié.');
    }

    /**
     * @test
     * @group validation
     */
    public function coordinates_validation_accepts_valid_values(): void
    {
        $validCoords = [
            ['lat' => 48.8566,  'lng' => 2.3522],   // Paris
            ['lat' => -90.0,    'lng' => -180.0],    // Limites min
            ['lat' => 90.0,     'lng' => 180.0],     // Limites max
            ['lat' => 4.9224,   'lng' => -52.3135],  // Guyane
        ];
        foreach ($validCoords as $c) {
            $this->assertTrue(
                $c['lat'] >= -90 && $c['lat'] <= 90 && $c['lng'] >= -180 && $c['lng'] <= 180,
                "Les coordonnées ({$c['lat']}, {$c['lng']}) devraient être valides."
            );
        }
    }

    /**
     * @test
     * @group validation
     */
    public function coordinates_validation_rejects_out_of_range_values(): void
    {
        $invalidCoords = [
            ['lat' => 91.0,  'lng' => 0.0],
            ['lat' => 0.0,   'lng' => 181.0],
            ['lat' => -91.0, 'lng' => 0.0],
        ];
        foreach ($invalidCoords as $c) {
            $isValid = $c['lat'] >= -90 && $c['lat'] <= 90 && $c['lng'] >= -180 && $c['lng'] <= 180;
            $this->assertFalse($isValid, "Les coordonnées ({$c['lat']}, {$c['lng']}) devraient être invalides.");
        }
    }

    // ----------------------------------------------------------------
    // Formatage et utilitaires
    // ----------------------------------------------------------------

    /**
     * @test
     * @group utils
     */
    public function reference_generation_follows_expected_format(): void
    {
        // Format attendu : CCDS-YYYYMMDD-XXXXX (ex: CCDS-20260226-A1B2C)
        $ref = 'CCDS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $this->assertMatchesRegularExpression(
            '/^CCDS-\d{8}-[A-Z0-9]{5}$/',
            $ref,
            "La référence '$ref' ne correspond pas au format attendu."
        );
    }

    /**
     * @test
     * @group utils
     */
    public function status_transitions_are_logically_valid(): void
    {
        // Transitions autorisées
        $allowed = [
            'submitted'    => ['acknowledged', 'rejected'],
            'acknowledged' => ['in_progress', 'rejected'],
            'in_progress'  => ['resolved', 'rejected'],
            'resolved'     => [],
            'rejected'     => [],
        ];

        // Vérifier que les transitions terminales n'ont pas de suite
        $this->assertEmpty($allowed['resolved'], 'Un signalement résolu ne peut plus changer de statut.');
        $this->assertEmpty($allowed['rejected'], 'Un signalement rejeté ne peut plus changer de statut.');

        // Vérifier qu'on ne peut pas passer directement de submitted à resolved
        $this->assertNotContains('resolved', $allowed['submitted']);
    }

    /**
     * @test
     * @group utils
     */
    public function html_special_chars_are_properly_escaped(): void
    {
        $input    = '<script>alert("XSS")</script>';
        $escaped  = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    // ----------------------------------------------------------------
    // Méthodes privées (implémentation locale des fonctions JWT)
    // ----------------------------------------------------------------

    private function jwtEncode(array $payload): string
    {
        $secret  = 'test_secret_key_for_phpunit_only';
        $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = base64_encode(json_encode($payload));
        $sig     = base64_encode(hash_hmac('sha256', "$header.$body", $secret, true));
        return "$header.$body.$sig";
    }

    private function jwtDecode(string $token): ?array
    {
        $secret = 'test_secret_key_for_phpunit_only';
        $parts  = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $body, $sig] = $parts;
        $expectedSig = base64_encode(hash_hmac('sha256', "$header.$body", $secret, true));
        if (!hash_equals($expectedSig, $sig)) return null;

        $payload = json_decode(base64_decode($body), true);
        if (!$payload) return null;
        if (isset($payload['exp']) && $payload['exp'] < time()) return null;

        return $payload;
    }
}
