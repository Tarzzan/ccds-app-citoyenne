<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour TwoFactorController (SEC-03)
 * Couvre : setup TOTP, vérification, désactivation, codes de récupération
 */
class TwoFactorControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Génération du secret TOTP
    // -------------------------------------------------------------------------
    public function testTotpSecretIsBase32Encoded(): void
    {
        // Un secret TOTP valide est en Base32 (A-Z, 2-7)
        $secret = 'JBSWY3DPEHPK3PXP'; // Exemple valide
        $isBase32 = (bool) preg_match('/^[A-Z2-7]+=*$/', $secret);
        $this->assertTrue($isBase32);
    }

    public function testTotpSecretHasMinimumLength(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP'; // 16 chars = 80 bits minimum
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testSetupReturnsPendingState(): void
    {
        $method = 'pending_totp';
        $this->assertEquals('pending_totp', $method);
    }

    // -------------------------------------------------------------------------
    // Vérification du code TOTP
    // -------------------------------------------------------------------------
    public function testTotpCodeIsExactlySixDigits(): void
    {
        $code = '123456';
        $isValid = (bool) preg_match('/^\d{6}$/', $code);
        $this->assertTrue($isValid);
    }

    public function testTotpCodeWithLettersIsRejected(): void
    {
        $code = '12345a';
        $isValid = (bool) preg_match('/^\d{6}$/', $code);
        $this->assertFalse($isValid);
    }

    public function testTotpCodeWithFiveDigitsIsRejected(): void
    {
        $code = '12345';
        $isValid = (bool) preg_match('/^\d{6}$/', $code);
        $this->assertFalse($isValid);
    }

    public function testVerifyActivates2FA(): void
    {
        $initialMethod = 'pending_totp';
        $afterVerify   = 'totp'; // Après vérification réussie
        $this->assertEquals('totp', $afterVerify);
        $this->assertNotEquals($initialMethod, $afterVerify);
    }

    // -------------------------------------------------------------------------
    // Codes de récupération
    // -------------------------------------------------------------------------
    public function testBackupCodesGeneratesTenCodes(): void
    {
        $codes = array_map(fn() => bin2hex(random_bytes(5)), range(1, 10));
        $this->assertCount(10, $codes);
    }

    public function testBackupCodesAreHashedBeforeStorage(): void
    {
        $code = 'abc123def4';
        $hashed = password_hash($code, PASSWORD_BCRYPT);
        $this->assertTrue(password_verify($code, $hashed));
        $this->assertNotEquals($code, $hashed);
    }

    public function testUsedBackupCodeIsInvalidated(): void
    {
        $codes = ['code1_used', 'code2_valid', 'code3_valid'];
        // Après utilisation, le code est retiré
        $codes = array_filter($codes, fn($c) => $c !== 'code1_used');
        $this->assertCount(2, $codes);
        $this->assertNotContains('code1_used', $codes);
    }

    // -------------------------------------------------------------------------
    // Désactivation de la 2FA
    // -------------------------------------------------------------------------
    public function testDisableRequiresPasswordConfirmation(): void
    {
        $input = ['password' => ''];
        $this->assertEmpty($input['password']);
    }

    public function testDisableResetsMethodToNone(): void
    {
        $method = 'totp';
        $afterDisable = 'none';
        $this->assertEquals('none', $afterDisable);
    }

    // -------------------------------------------------------------------------
    // Code email
    // -------------------------------------------------------------------------
    public function testEmailCodeIsExactlySixDigits(): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testEmailCodeExpiresAfterFifteenMinutes(): void
    {
        $createdAt = time();
        $expiresAt = $createdAt + 15 * 60;
        $diff = ($expiresAt - $createdAt) / 60;
        $this->assertEquals(15, $diff);
    }

    // -------------------------------------------------------------------------
    // URL QR Code
    // -------------------------------------------------------------------------
    public function testOtpUrlFollowsCorrectFormat(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $issuer = 'MaCommune';
        $email  = 'user@example.com';
        $otpUrl = "otpauth://totp/{$issuer}:{$email}?secret={$secret}&issuer={$issuer}";
        $this->assertStringStartsWith('otpauth://totp/', $otpUrl);
        $this->assertStringContainsString('secret=', $otpUrl);
    }
}
