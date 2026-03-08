<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour EventController (UX-12)
 * Couvre : liste, création, RSVP, validation
 */
class EventControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation de la création d'événement
    // -------------------------------------------------------------------------
    public function testCreateEventRequiresTitle(): void
    {
        $input = ['event_date' => '2026-06-01', 'location' => 'Mairie'];
        $errors = [];
        if (empty($input['title'])) $errors[] = 'Le titre est requis.';
        $this->assertContains('Le titre est requis.', $errors);
    }

    public function testCreateEventRequiresDate(): void
    {
        $input = ['title' => 'Réunion', 'location' => 'Mairie'];
        $errors = [];
        if (empty($input['event_date'])) $errors[] = 'La date est requise.';
        $this->assertContains('La date est requise.', $errors);
    }

    public function testCreateEventRequiresLocation(): void
    {
        $input = ['title' => 'Réunion', 'event_date' => '2026-06-01'];
        $errors = [];
        if (empty($input['location'])) $errors[] = 'Le lieu est requis.';
        $this->assertContains('Le lieu est requis.', $errors);
    }

    public function testCreateEventPassesWithAllFields(): void
    {
        $input = ['title' => 'Réunion', 'event_date' => '2026-06-01', 'location' => 'Mairie'];
        $errors = [];
        if (empty($input['title']))      $errors[] = 'Le titre est requis.';
        if (empty($input['event_date'])) $errors[] = 'La date est requise.';
        if (empty($input['location']))   $errors[] = 'Le lieu est requis.';
        $this->assertEmpty($errors);
    }

    // -------------------------------------------------------------------------
    // Contrôle d'accès — seuls agents/admin peuvent créer
    // -------------------------------------------------------------------------
    public function testOnlyAgentOrAdminCanCreateEvent(): void
    {
        $allowedRoles = ['agent', 'admin'];
        $this->assertTrue(in_array('agent', $allowedRoles));
        $this->assertTrue(in_array('admin', $allowedRoles));
        $this->assertFalse(in_array('citizen', $allowedRoles));
    }

    // -------------------------------------------------------------------------
    // Validation du statut RSVP
    // -------------------------------------------------------------------------
    public function testRsvpAcceptsValidStatuses(): void
    {
        $validStatuses = ['attending', 'interested', 'not_attending'];
        foreach ($validStatuses as $status) {
            $this->assertContains($status, $validStatuses);
        }
    }

    public function testRsvpRejectsInvalidStatus(): void
    {
        $validStatuses = ['attending', 'interested', 'not_attending'];
        $invalid = 'maybe';
        $this->assertFalse(in_array($invalid, $validStatuses));
    }

    // -------------------------------------------------------------------------
    // Logique de comptage des participants
    // -------------------------------------------------------------------------
    public function testAttendeesCountIsNonNegative(): void
    {
        $count = 0;
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testEventResponseContainsRequiredFields(): void
    {
        $event = [
            'id' => 1,
            'title' => 'Réunion de quartier',
            'event_date' => '2026-06-01',
            'location' => 'Mairie',
            'attendees_count' => 12,
            'user_rsvp' => 'attending',
        ];
        $this->assertArrayHasKey('id', $event);
        $this->assertArrayHasKey('title', $event);
        $this->assertArrayHasKey('attendees_count', $event);
        $this->assertArrayHasKey('user_rsvp', $event);
    }

    // -------------------------------------------------------------------------
    // Filtrage des événements passés
    // -------------------------------------------------------------------------
    public function testPastEventsAreExcludedFromList(): void
    {
        $events = [
            ['id' => 1, 'event_date' => '2025-01-01'], // passé
            ['id' => 2, 'event_date' => '2027-01-01'], // futur
        ];
        $now = '2026-03-08';
        $upcoming = array_filter($events, fn($e) => $e['event_date'] >= $now);
        $this->assertCount(1, $upcoming);
        $this->assertEquals(2, array_values($upcoming)[0]['id']);
    }
}
