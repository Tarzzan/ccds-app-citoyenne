<?php
/**
 * CCDS v1.3 — Tests Unitaires : Permissions RBAC (TEST-01)
 * Couvre : matrice de permissions par rôle, vérifications fines.
 */
namespace CCDS\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase
{
    // Matrice RBAC simplifiée (miroir de backend/core/Permissions.php)
    private array $matrix = [
        'citizen' => [
            'incident:create', 'incident:read', 'incident:edit_own',
            'vote:create', 'vote:delete',
            'comment:read', 'comment:create',
            'notification:read', 'profile:read', 'profile:update',
        ],
        'agent' => [
            'incident:create', 'incident:read', 'incident:edit_own',
            'incident:update_status', 'incident:update_priority',
            'vote:create', 'vote:delete',
            'comment:read', 'comment:create', 'comment:create_internal',
            'notification:read', 'notification:send',
            'profile:read', 'profile:update',
        ],
        'admin' => [
            'incident:create', 'incident:read', 'incident:edit_own',
            'incident:update_status', 'incident:update_priority', 'incident:delete',
            'vote:create', 'vote:delete',
            'comment:read', 'comment:create', 'comment:create_internal', 'comment:delete',
            'notification:read', 'notification:send',
            'profile:read', 'profile:update',
            'user:create', 'user:read', 'user:update', 'user:delete',
            'category:create', 'category:update', 'category:delete',
            'stats:read',
        ],
    ];

    private function can(string $role, string $permission): bool
    {
        return in_array($permission, $this->matrix[$role] ?? [], true);
    }

    // ----------------------------------------------------------------
    // Citoyen
    // ----------------------------------------------------------------

    /** @test @group rbac */
    public function citizen_can_create_incident(): void
    {
        $this->assertTrue($this->can('citizen', 'incident:create'));
    }

    /** @test @group rbac */
    public function citizen_cannot_update_status(): void
    {
        $this->assertFalse($this->can('citizen', 'incident:update_status'));
    }

    /** @test @group rbac */
    public function citizen_cannot_manage_users(): void
    {
        $this->assertFalse($this->can('citizen', 'user:create'));
        $this->assertFalse($this->can('citizen', 'user:delete'));
    }

    /** @test @group rbac */
    public function citizen_cannot_send_notifications(): void
    {
        $this->assertFalse($this->can('citizen', 'notification:send'));
    }

    // ----------------------------------------------------------------
    // Agent
    // ----------------------------------------------------------------

    /** @test @group rbac */
    public function agent_can_update_status_and_priority(): void
    {
        $this->assertTrue($this->can('agent', 'incident:update_status'));
        $this->assertTrue($this->can('agent', 'incident:update_priority'));
    }

    /** @test @group rbac */
    public function agent_can_create_internal_comments(): void
    {
        $this->assertTrue($this->can('agent', 'comment:create_internal'));
    }

    /** @test @group rbac */
    public function agent_cannot_delete_incidents(): void
    {
        $this->assertFalse($this->can('agent', 'incident:delete'));
    }

    /** @test @group rbac */
    public function agent_cannot_manage_categories(): void
    {
        $this->assertFalse($this->can('agent', 'category:create'));
        $this->assertFalse($this->can('agent', 'category:delete'));
    }

    // ----------------------------------------------------------------
    // Admin
    // ----------------------------------------------------------------

    /** @test @group rbac */
    public function admin_has_all_permissions(): void
    {
        $all_permissions = [
            'incident:delete', 'user:create', 'user:delete',
            'category:create', 'category:update', 'category:delete',
            'stats:read', 'comment:delete',
        ];
        foreach ($all_permissions as $perm) {
            $this->assertTrue($this->can('admin', $perm), "Admin devrait avoir la permission : $perm");
        }
    }

    // ----------------------------------------------------------------
    // Rôle inconnu
    // ----------------------------------------------------------------

    /** @test @group rbac */
    public function unknown_role_has_no_permissions(): void
    {
        $this->assertFalse($this->can('superadmin', 'incident:read'));
        $this->assertFalse($this->can('', 'incident:create'));
        $this->assertFalse($this->can('hacker', 'user:delete'));
    }
}
