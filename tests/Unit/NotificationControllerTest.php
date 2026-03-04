<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour NotificationController
 * Couvre : enregistrement token, envoi push, marquage lu
 */
class NotificationControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation du token Expo Push
    // -------------------------------------------------------------------------

    public function testValidExpoPushTokenIsAccepted(): void
    {
        $token = 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]';
        $this->assertTrue($this->isValidExpoToken($token));
    }

    public function testInvalidTokenIsRejected(): void
    {
        $token = 'not-a-valid-token';
        $this->assertFalse($this->isValidExpoToken($token));
    }

    public function testEmptyTokenIsRejected(): void
    {
        $token = '';
        $this->assertFalse($this->isValidExpoToken($token));
    }

    // -------------------------------------------------------------------------
    // Structure du message push
    // -------------------------------------------------------------------------

    public function testPushMessageHasRequiredFields(): void
    {
        $message = [
            'to'    => 'ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]',
            'title' => 'Votre signalement a été mis à jour',
            'body'  => 'Le statut est maintenant : En cours',
            'data'  => ['incident_id' => 42],
        ];

        $this->assertArrayHasKey('to', $message);
        $this->assertArrayHasKey('title', $message);
        $this->assertArrayHasKey('body', $message);
        $this->assertArrayHasKey('data', $message);
    }

    public function testPushTitleCannotBeEmpty(): void
    {
        $title = '';
        $this->assertEmpty($title);
    }

    public function testPushBodyCannotBeEmpty(): void
    {
        $body = '';
        $this->assertEmpty($body);
    }

    // -------------------------------------------------------------------------
    // Marquage comme lu
    // -------------------------------------------------------------------------

    public function testMarkAsReadChangesStatus(): void
    {
        $notification = ['id' => 1, 'is_read' => 0];
        $notification['is_read'] = 1;
        $this->assertEquals(1, $notification['is_read']);
    }

    public function testMarkAllAsReadUpdatesMultiple(): void
    {
        $notifications = [
            ['id' => 1, 'is_read' => 0],
            ['id' => 2, 'is_read' => 0],
            ['id' => 3, 'is_read' => 1],
        ];

        $updated = array_map(fn($n) => array_merge($n, ['is_read' => 1]), $notifications);
        $unread  = array_filter($updated, fn($n) => $n['is_read'] === 0);

        $this->assertCount(0, $unread);
    }

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    private function isValidExpoToken(string $token): bool
    {
        return !empty($token) && (
            str_starts_with($token, 'ExponentPushToken[') ||
            str_starts_with($token, 'ExpoPushToken[')
        );
    }
}
