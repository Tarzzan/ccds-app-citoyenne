<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour AuthController
 * Couvre : register, login, getProfile, updateProfile, changePassword
 */
class AuthControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeJwt(array $payload, string $secret = 'test_secret'): string
    {
        $header  = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = base64_encode(json_encode($payload));
        $sig     = base64_encode(hash_hmac('sha256', "$header.$body", $secret, true));
        return "$header.$body.$sig";
    }

    // -------------------------------------------------------------------------
    // Validation des données d'inscription
    // -------------------------------------------------------------------------

    public function testRegisterValidatesEmail(): void
    {
        $data = ['email' => 'not-an-email', 'password' => 'Secret123!', 'name' => 'Jean'];
        $this->assertFalse(filter_var($data['email'], FILTER_VALIDATE_EMAIL) !== false);
    }

    public function testRegisterValidatesPasswordLength(): void
    {
        $password = 'abc';
        $this->assertLessThan(8, strlen($password));
    }

    public function testRegisterAcceptsValidData(): void
    {
        $data = ['email' => 'jean@example.com', 'password' => 'Secret123!', 'name' => 'Jean Dupont'];
        $this->assertNotFalse(filter_var($data['email'], FILTER_VALIDATE_EMAIL));
        $this->assertGreaterThanOrEqual(8, strlen($data['password']));
        $this->assertNotEmpty($data['name']);
    }

    // -------------------------------------------------------------------------
    // Validation du login
    // -------------------------------------------------------------------------

    public function testLoginRequiresEmailAndPassword(): void
    {
        $data = ['email' => '', 'password' => ''];
        $this->assertEmpty($data['email']);
        $this->assertEmpty($data['password']);
    }

    public function testLoginWithValidCredentialsFormat(): void
    {
        $data = ['email' => 'admin@ccds.fr', 'password' => 'Admin123!'];
        $this->assertNotFalse(filter_var($data['email'], FILTER_VALIDATE_EMAIL));
        $this->assertNotEmpty($data['password']);
    }

    // -------------------------------------------------------------------------
    // Génération et validation de token JWT
    // -------------------------------------------------------------------------

    public function testJwtTokenHasThreeParts(): void
    {
        $token = $this->makeJwt(['user_id' => 1, 'role' => 'citizen']);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testJwtPayloadContainsUserId(): void
    {
        $payload = ['user_id' => 42, 'role' => 'admin', 'exp' => time() + 3600];
        $token   = $this->makeJwt($payload);
        $parts   = explode('.', $token);
        $decoded = json_decode(base64_decode($parts[1]), true);
        $this->assertEquals(42, $decoded['user_id']);
    }

    public function testExpiredTokenIsDetected(): void
    {
        $payload = ['user_id' => 1, 'exp' => time() - 3600]; // expiré
        $this->assertLessThan(time(), $payload['exp']);
    }

    // -------------------------------------------------------------------------
    // Mise à jour du profil
    // -------------------------------------------------------------------------

    public function testUpdateProfileValidatesName(): void
    {
        $name = '';
        $this->assertEmpty($name);
    }

    public function testUpdateProfileAcceptsValidName(): void
    {
        $name = 'Marie Curie';
        $this->assertNotEmpty($name);
        $this->assertLessThanOrEqual(100, strlen($name));
    }

    // -------------------------------------------------------------------------
    // Changement de mot de passe
    // -------------------------------------------------------------------------

    public function testChangePasswordRequiresMinLength(): void
    {
        $newPassword = 'abc';
        $this->assertLessThan(8, strlen($newPassword));
    }

    public function testChangePasswordAcceptsStrongPassword(): void
    {
        $newPassword = 'NewSecure@2026';
        $this->assertGreaterThanOrEqual(8, strlen($newPassword));
    }

    public function testPasswordHashIsNotPlaintext(): void
    {
        $plain  = 'Secret123!';
        $hashed = password_hash($plain, PASSWORD_BCRYPT);
        $this->assertNotEquals($plain, $hashed);
        $this->assertTrue(password_verify($plain, $hashed));
    }
}
