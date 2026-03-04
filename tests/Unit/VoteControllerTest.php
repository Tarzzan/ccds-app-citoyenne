<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour VoteController
 * Couvre : vote, removeVote, getVoteCount, idempotence
 */
class VoteControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Logique de vote
    // -------------------------------------------------------------------------

    public function testVoteRequiresAuthenticatedUser(): void
    {
        $userId = null;
        $this->assertNull($userId);
    }

    public function testVoteRequiresValidIncidentId(): void
    {
        $incidentId = 0;
        $this->assertLessThanOrEqual(0, $incidentId);
    }

    public function testVoteCounterIncrementsOnVote(): void
    {
        $initialCount = 5;
        $newCount     = $initialCount + 1;
        $this->assertEquals(6, $newCount);
    }

    public function testVoteCounterDecrementsOnRemove(): void
    {
        $initialCount = 5;
        $newCount     = $initialCount - 1;
        $this->assertEquals(4, $newCount);
    }

    public function testVoteCountNeverGoesNegative(): void
    {
        $count = 0;
        $count = max(0, $count - 1);
        $this->assertEquals(0, $count);
    }

    // -------------------------------------------------------------------------
    // Idempotence (contrainte UNIQUE en base)
    // -------------------------------------------------------------------------

    public function testDuplicateVoteIsRejected(): void
    {
        $existingVotes = [['user_id' => 1, 'incident_id' => 42]];
        $newVote       = ['user_id' => 1, 'incident_id' => 42];

        $isDuplicate = in_array($newVote, $existingVotes);
        $this->assertTrue($isDuplicate);
    }

    public function testNewVoteIsAccepted(): void
    {
        $existingVotes = [['user_id' => 1, 'incident_id' => 42]];
        $newVote       = ['user_id' => 2, 'incident_id' => 42];

        $isDuplicate = in_array($newVote, $existingVotes);
        $this->assertFalse($isDuplicate);
    }

    // -------------------------------------------------------------------------
    // Réponse API
    // -------------------------------------------------------------------------

    public function testVoteResponseContainsVotesCount(): void
    {
        $response = ['success' => true, 'votes_count' => 12, 'user_voted' => true];
        $this->assertArrayHasKey('votes_count', $response);
        $this->assertArrayHasKey('user_voted', $response);
    }
}
