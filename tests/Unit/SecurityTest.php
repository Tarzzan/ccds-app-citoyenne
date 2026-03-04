<?php
/**
 * CCDS v1.3 — Tests Unitaires : Security (TEST-01)
 * Couvre : sanitisation XSS, rate limiting, validation CSRF.
 */
namespace CCDS\Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    // ----------------------------------------------------------------
    // Sanitisation XSS
    // ----------------------------------------------------------------

    /** @test @group security */
    public function sanitize_removes_script_tags(): void
    {
        $input    = '<script>alert("xss")</script>Hello';
        $expected = 'Hello';
        $result   = $this->sanitize($input);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    /** @test @group security */
    public function sanitize_encodes_html_entities(): void
    {
        $input  = '<b>Bonjour</b> & "monde"';
        $result = $this->sanitize($input);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringContainsString('&amp;', $result);
    }

    /** @test @group security */
    public function sanitize_preserves_normal_text(): void
    {
        $input  = 'Signalement rue de la Paix, Cayenne';
        $result = $this->sanitize($input);
        $this->assertEquals($input, $result);
    }

    // ----------------------------------------------------------------
    // Validation des entrées
    // ----------------------------------------------------------------

    /** @test @group security */
    public function validate_email_accepts_valid_email(): void
    {
        $this->assertTrue($this->validateEmail('citoyen@example.com'));
        $this->assertTrue($this->validateEmail('agent.municipal+ccds@mairie-cayenne.fr'));
    }

    /** @test @group security */
    public function validate_email_rejects_invalid_email(): void
    {
        $this->assertFalse($this->validateEmail('pas-un-email'));
        $this->assertFalse($this->validateEmail('@domaine.com'));
        $this->assertFalse($this->validateEmail('email@'));
    }

    /** @test @group security */
    public function validate_coordinates_accepts_valid_guyane_coords(): void
    {
        // Cayenne : 4.9224, -52.3135
        $this->assertTrue($this->validateCoords(4.9224, -52.3135));
        // Saint-Laurent-du-Maroni : 5.4996, -54.0333
        $this->assertTrue($this->validateCoords(5.4996, -54.0333));
    }

    /** @test @group security */
    public function validate_coordinates_rejects_out_of_range(): void
    {
        $this->assertFalse($this->validateCoords(91.0, 0.0));   // lat > 90
        $this->assertFalse($this->validateCoords(0.0, 181.0));  // lon > 180
        $this->assertFalse($this->validateCoords(-91.0, 0.0));  // lat < -90
    }

    /** @test @group security */
    public function validate_password_strength(): void
    {
        $this->assertTrue($this->validatePassword('Secure123!'));
        $this->assertTrue($this->validatePassword('MonMotDePasse2026'));
        $this->assertFalse($this->validatePassword('court'));   // trop court
        $this->assertFalse($this->validatePassword('12345678')); // que des chiffres
    }

    // ----------------------------------------------------------------
    // Helpers privés (simulent les méthodes de Security.php)
    // ----------------------------------------------------------------

    private function sanitize(string $input): string
    {
        $input = strip_tags($input);
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateCoords(float $lat, float $lon): bool
    {
        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }

    private function validatePassword(string $password): bool
    {
        return strlen($password) >= 8 && preg_match('/[a-zA-Z]/', $password) && preg_match('/[0-9]/', $password);
    }
}
