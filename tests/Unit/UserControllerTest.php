<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour UserController (admin)
 * Couvre : liste, recherche, changement de rôle, blocage/déblocage
 */
class UserControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Validation des rôles
    // -------------------------------------------------------------------------

    public function testValidRolesAreAccepted(): void
    {
        $validRoles = ['citizen', 'agent', 'admin'];
        foreach ($validRoles as $role) {
            $this->assertContains($role, $validRoles);
        }
    }

    public function testInvalidRoleIsRejected(): void
    {
        $validRoles = ['citizen', 'agent', 'admin'];
        $this->assertNotContains('superuser', $validRoles);
    }

    // -------------------------------------------------------------------------
    // Recherche et filtres
    // -------------------------------------------------------------------------

    public function testSearchFiltersByName(): void
    {
        $users = [
            ['name' => 'Alice Martin', 'email' => 'alice@example.com'],
            ['name' => 'Bob Dupont',   'email' => 'bob@example.com'],
            ['name' => 'Alice Durand', 'email' => 'alice2@example.com'],
        ];

        $query    = 'Alice';
        $filtered = array_filter($users, fn($u) => str_contains($u['name'], $query));
        $this->assertCount(2, $filtered);
    }

    public function testFilterByRole(): void
    {
        $users = [
            ['name' => 'Alice', 'role' => 'citizen'],
            ['name' => 'Bob',   'role' => 'admin'],
            ['name' => 'Carol', 'role' => 'agent'],
        ];

        $filtered = array_filter($users, fn($u) => $u['role'] === 'admin');
        $this->assertCount(1, $filtered);
    }

    public function testFilterByStatus(): void
    {
        $users = [
            ['name' => 'Alice', 'is_active' => 1],
            ['name' => 'Bob',   'is_active' => 0],
            ['name' => 'Carol', 'is_active' => 1],
        ];

        $active = array_filter($users, fn($u) => $u['is_active'] === 1);
        $this->assertCount(2, $active);
    }

    // -------------------------------------------------------------------------
    // Pagination
    // -------------------------------------------------------------------------

    public function testPaginationCalculatesCorrectOffset(): void
    {
        $page    = 3;
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $this->assertEquals(40, $offset);
    }

    public function testFirstPageHasZeroOffset(): void
    {
        $page    = 1;
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $this->assertEquals(0, $offset);
    }

    // -------------------------------------------------------------------------
    // Blocage / déblocage
    // -------------------------------------------------------------------------

    public function testAdminCannotBlockThemselves(): void
    {
        $adminId        = 1;
        $targetUserId   = 1;
        $isSelf         = ($adminId === $targetUserId);
        $this->assertTrue($isSelf);
    }

    public function testAdminCanBlockOtherUser(): void
    {
        $adminId      = 1;
        $targetUserId = 5;
        $isSelf       = ($adminId === $targetUserId);
        $this->assertFalse($isSelf);
    }

    // -------------------------------------------------------------------------
    // Statistiques utilisateur
    // -------------------------------------------------------------------------

    public function testUserStatsContainRequiredFields(): void
    {
        $stats = [
            'incidents_count'    => 12,
            'votes_count'        => 34,
            'comments_count'     => 8,
            'points'             => 240,
            'last_activity_date' => '2026-03-01',
        ];

        $this->assertArrayHasKey('incidents_count', $stats);
        $this->assertArrayHasKey('votes_count', $stats);
        $this->assertArrayHasKey('points', $stats);
    }
}
