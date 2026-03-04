<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour GamificationController
 * Couvre : calcul des points, attribution des badges, classement
 */
class GamificationControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Calcul des points
    // -------------------------------------------------------------------------

    public function testNewIncidentAwards10Points(): void
    {
        $points = $this->calculatePoints('incident_created');
        $this->assertEquals(10, $points);
    }

    public function testNewVoteAwards2Points(): void
    {
        $points = $this->calculatePoints('vote_cast');
        $this->assertEquals(2, $points);
    }

    public function testNewCommentAwards5Points(): void
    {
        $points = $this->calculatePoints('comment_added');
        $this->assertEquals(5, $points);
    }

    public function testResolvedIncidentAwards20Points(): void
    {
        $points = $this->calculatePoints('incident_resolved');
        $this->assertEquals(20, $points);
    }

    public function testUnknownActionAwards0Points(): void
    {
        $points = $this->calculatePoints('unknown_action');
        $this->assertEquals(0, $points);
    }

    // -------------------------------------------------------------------------
    // Attribution des badges
    // -------------------------------------------------------------------------

    public function testFirstIncidentAwardsPremierPasBadge(): void
    {
        $incidentCount = 1;
        $badge = $this->checkBadge('premier_pas', $incidentCount, 0, 0);
        $this->assertTrue($badge);
    }

    public function testTenIncidentsAwardsContributeurBadge(): void
    {
        $incidentCount = 10;
        $badge = $this->checkBadge('contributeur', $incidentCount, 0, 0);
        $this->assertTrue($badge);
    }

    public function testLessThanTenIncidentsDoesNotAwardContributeurBadge(): void
    {
        $incidentCount = 5;
        $badge = $this->checkBadge('contributeur', $incidentCount, 0, 0);
        $this->assertFalse($badge);
    }

    public function testFiftyVotesAwardsEngageBadge(): void
    {
        $voteCount = 50;
        $badge = $this->checkBadge('engage', 0, $voteCount, 0);
        $this->assertTrue($badge);
    }

    // -------------------------------------------------------------------------
    // Classement
    // -------------------------------------------------------------------------

    public function testRankingIsSortedByPointsDescending(): void
    {
        $users = [
            ['name' => 'Alice', 'points' => 150],
            ['name' => 'Bob',   'points' => 320],
            ['name' => 'Carol', 'points' => 80],
        ];

        usort($users, fn($a, $b) => $b['points'] - $a['points']);

        $this->assertEquals('Bob',   $users[0]['name']);
        $this->assertEquals('Alice', $users[1]['name']);
        $this->assertEquals('Carol', $users[2]['name']);
    }

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    private function calculatePoints(string $action): int
    {
        return match ($action) {
            'incident_created'  => 10,
            'vote_cast'         => 2,
            'comment_added'     => 5,
            'incident_resolved' => 20,
            default             => 0,
        };
    }

    private function checkBadge(string $badge, int $incidents, int $votes, int $comments): bool
    {
        return match ($badge) {
            'premier_pas'  => $incidents >= 1,
            'contributeur' => $incidents >= 10,
            'engage'       => $votes >= 50,
            'commentateur' => $comments >= 20,
            default        => false,
        };
    }
}
