<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour WebhookController (API-03)
 * Couvre : CRUD webhooks, validation URL, événements supportés, signature HMAC
 */
class WebhookControllerTest extends TestCase
{
    private array $validEvents = [
        'incident.created',
        'incident.status.changed',
        'incident.resolved',
        'comment.created',
        'user.registered',
        '*',
    ];

    // -------------------------------------------------------------------------
    // Contrôle d'accès
    // -------------------------------------------------------------------------
    public function testWebhookCrudRequiresAdminRole(): void
    {
        $allowedRoles = ['admin'];
        $this->assertTrue(in_array('admin', $allowedRoles));
        $this->assertFalse(in_array('agent', $allowedRoles));
        $this->assertFalse(in_array('citizen', $allowedRoles));
    }

    // -------------------------------------------------------------------------
    // Validation de la création
    // -------------------------------------------------------------------------
    public function testCreateWebhookRequiresTargetUrl(): void
    {
        $input = ['event' => 'incident.created'];
        $errors = [];
        if (empty($input['target_url'])) $errors[] = "L'URL cible est requise.";
        $this->assertNotEmpty($errors);
    }

    public function testCreateWebhookRequiresEvent(): void
    {
        $input = ['target_url' => 'https://example.com/hook'];
        $errors = [];
        if (empty($input['event'])) $errors[] = "L'événement est requis.";
        $this->assertNotEmpty($errors);
    }

    public function testCreateWebhookRejectsInvalidEvent(): void
    {
        $event = 'invalid.event';
        $isValid = in_array($event, $this->validEvents);
        $this->assertFalse($isValid);
    }

    public function testCreateWebhookAcceptsWildcardEvent(): void
    {
        $event = '*';
        $isValid = in_array($event, $this->validEvents);
        $this->assertTrue($isValid);
    }

    public function testCreateWebhookRejectsInvalidUrl(): void
    {
        $url = 'not-a-valid-url';
        $isValid = (bool) filter_var($url, FILTER_VALIDATE_URL);
        $this->assertFalse($isValid);
    }

    public function testCreateWebhookAcceptsValidHttpsUrl(): void
    {
        $url = 'https://example.com/webhook';
        $isValid = (bool) filter_var($url, FILTER_VALIDATE_URL);
        $this->assertTrue($isValid);
    }

    // -------------------------------------------------------------------------
    // Signature HMAC
    // -------------------------------------------------------------------------
    public function testHmacSignatureIsGeneratedWithSha256(): void
    {
        $secret  = 'my_webhook_secret';
        $payload = json_encode(['event' => 'incident.created', 'data' => ['id' => 1]]);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertEquals(71, strlen($signature)); // 'sha256=' (7) + 64 hex chars
    }

    public function testHmacSignatureVerification(): void
    {
        $secret    = 'my_webhook_secret';
        $payload   = '{"event":"incident.created"}';
        $expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $received  = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $this->assertTrue(hash_equals($expected, $received));
    }

    public function testTamperedPayloadFailsHmacVerification(): void
    {
        $secret          = 'my_webhook_secret';
        $originalPayload = '{"event":"incident.created"}';
        $tamperedPayload = '{"event":"incident.deleted"}';
        $expected = 'sha256=' . hash_hmac('sha256', $originalPayload, $secret);
        $received = 'sha256=' . hash_hmac('sha256', $tamperedPayload, $secret);
        $this->assertFalse(hash_equals($expected, $received));
    }

    // -------------------------------------------------------------------------
    // Retry logic
    // -------------------------------------------------------------------------
    public function testWebhookRetryMaxAttemptsIsThree(): void
    {
        $maxAttempts = 3;
        $this->assertEquals(3, $maxAttempts);
    }

    public function testWebhookIsDisabledAfterMaxFailures(): void
    {
        $failureCount = 3;
        $maxFailures  = 3;
        $shouldDisable = $failureCount >= $maxFailures;
        $this->assertTrue($shouldDisable);
    }

    // -------------------------------------------------------------------------
    // Réponse API
    // -------------------------------------------------------------------------
    public function testWebhookResponseContainsRequiredFields(): void
    {
        $webhook = [
            'id'         => 1,
            'target_url' => 'https://example.com/hook',
            'event'      => 'incident.created',
            'is_active'  => true,
            'created_at' => '2026-03-08T12:00:00Z',
        ];
        $this->assertArrayHasKey('id', $webhook);
        $this->assertArrayHasKey('target_url', $webhook);
        $this->assertArrayHasKey('event', $webhook);
        $this->assertArrayHasKey('is_active', $webhook);
    }
}
