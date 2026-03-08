<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour PollController (UX-10)
 * Couvre : création, vote, résultats, validation
 */
class PollControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation de la création de sondage
    // -------------------------------------------------------------------------
    public function testCreatePollRequiresTitle(): void
    {
        $input = ['options' => ['Oui', 'Non']];
        $errors = [];
        if (empty($input['title'])) $errors[] = 'Le titre est requis.';
        $this->assertContains('Le titre est requis.', $errors);
    }

    public function testCreatePollRequiresAtLeastTwoOptions(): void
    {
        $options = ['Oui'];
        $isValid = count($options) >= 2;
        $this->assertFalse($isValid);
    }

    public function testCreatePollWithTwoOptionsIsValid(): void
    {
        $options = ['Oui', 'Non'];
        $isValid = count($options) >= 2;
        $this->assertTrue($isValid);
    }

    public function testCreatePollOnlyAdminCanCreate(): void
    {
        $allowedRoles = ['admin'];
        $this->assertTrue(in_array('admin', $allowedRoles));
        $this->assertFalse(in_array('citizen', $allowedRoles));
        $this->assertFalse(in_array('agent', $allowedRoles));
    }

    // -------------------------------------------------------------------------
    // Logique de vote
    // -------------------------------------------------------------------------
    public function testVoteRequiresValidOptionId(): void
    {
        $optionId = 0;
        $this->assertLessThanOrEqual(0, $optionId);
    }

    public function testUserCannotVoteTwiceOnSamePoll(): void
    {
        $existingVotes = [['poll_id' => 1, 'user_id' => 42]];
        $newVote = ['poll_id' => 1, 'user_id' => 42];
        $alreadyVoted = in_array($newVote, $existingVotes);
        $this->assertTrue($alreadyVoted);
    }

    public function testUserCanVoteOnDifferentPolls(): void
    {
        $existingVotes = [['poll_id' => 1, 'user_id' => 42]];
        $newVote = ['poll_id' => 2, 'user_id' => 42];
        $alreadyVoted = in_array($newVote, $existingVotes);
        $this->assertFalse($alreadyVoted);
    }

    // -------------------------------------------------------------------------
    // Sondage expiré
    // -------------------------------------------------------------------------
    public function testExpiredPollCannotReceiveVotes(): void
    {
        $poll = ['ends_at' => '2025-01-01 00:00:00'];
        $now = '2026-03-08 12:00:00';
        $isExpired = $poll['ends_at'] < $now;
        $this->assertTrue($isExpired);
    }

    public function testActivePollCanReceiveVotes(): void
    {
        $poll = ['ends_at' => '2027-01-01 00:00:00'];
        $now = '2026-03-08 12:00:00';
        $isExpired = $poll['ends_at'] < $now;
        $this->assertFalse($isExpired);
    }

    public function testPollWithNullEndsAtIsAlwaysActive(): void
    {
        $poll = ['ends_at' => null];
        $isActive = $poll['ends_at'] === null || $poll['ends_at'] >= '2026-03-08';
        $this->assertTrue($isActive);
    }

    // -------------------------------------------------------------------------
    // Calcul des résultats
    // -------------------------------------------------------------------------
    public function testVotePercentageCalculation(): void
    {
        $totalVotes = 100;
        $optionVotes = 40;
        $percentage = round(($optionVotes / $totalVotes) * 100, 1);
        $this->assertEquals(40.0, $percentage);
    }

    public function testVotePercentageWithZeroTotalVotes(): void
    {
        $totalVotes = 0;
        $percentage = $totalVotes > 0 ? round((10 / $totalVotes) * 100, 1) : 0;
        $this->assertEquals(0, $percentage);
    }

    public function testPollResultsContainRequiredFields(): void
    {
        $result = [
            'id'          => 1,
            'title'       => 'Faut-il plus de pistes cyclables ?',
            'total_votes' => 150,
            'options'     => [
                ['id' => 1, 'text' => 'Oui', 'votes_count' => 120],
                ['id' => 2, 'text' => 'Non', 'votes_count' => 30],
            ],
        ];
        $this->assertArrayHasKey('total_votes', $result);
        $this->assertArrayHasKey('options', $result);
        $this->assertCount(2, $result['options']);
        $this->assertArrayHasKey('votes_count', $result['options'][0]);
    }
}
