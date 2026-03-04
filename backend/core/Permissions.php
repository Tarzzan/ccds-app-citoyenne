<?php
/**
 * CCDS v1.2 — Système de permissions RBAC (SEC-02)
 *
 * Chaque rôle dispose d'un ensemble de permissions nommées.
 * Les contrôleurs utilisent `Permissions::require()` au lieu de `require_role()`.
 *
 * Rôles disponibles : citizen, agent, admin
 */

class Permissions
{
    /**
     * Matrice des permissions par rôle.
     * Format : 'permission' => ['role1', 'role2', ...]
     */
    private const MATRIX = [
        // --- Incidents ---
        'incident:list'          => ['citizen', 'agent', 'admin'],
        'incident:read'          => ['citizen', 'agent', 'admin'],
        'incident:create'        => ['citizen', 'agent', 'admin'],
        'incident:edit_own'      => ['citizen'],           // citoyen modifie son propre signalement (statut=submitted)
        'incident:update_status' => ['agent', 'admin'],    // changer le statut
        'incident:set_priority'  => ['agent', 'admin'],
        'incident:add_note'      => ['agent', 'admin'],
        'incident:delete'        => ['admin'],

        // --- Commentaires ---
        'comment:list'           => ['citizen', 'agent', 'admin'],
        'comment:create'         => ['citizen', 'agent', 'admin'],
        'comment:delete_own'     => ['citizen'],
        'comment:delete_any'     => ['admin'],

        // --- Votes ---
        'vote:create'            => ['citizen', 'agent', 'admin'],
        'vote:delete_own'        => ['citizen', 'agent', 'admin'],
        'vote:read'              => ['citizen', 'agent', 'admin'],

        // --- Notifications ---
        'notification:read_own'  => ['citizen', 'agent', 'admin'],
        'notification:mark_read' => ['citizen', 'agent', 'admin'],
        'notification:send'      => ['agent', 'admin'],
        'notification:register_token' => ['citizen', 'agent', 'admin'],

        // --- Catégories ---
        'category:list'          => ['citizen', 'agent', 'admin'],
        'category:create'        => ['admin'],
        'category:update'        => ['admin'],
        'category:delete'        => ['admin'],

        // --- Utilisateurs ---
        'user:list'              => ['admin'],
        'user:create_agent'      => ['admin'],
        'user:update_role'       => ['admin'],
        'user:toggle_active'     => ['admin'],
        'user:read_own'          => ['citizen', 'agent', 'admin'],
        'user:update_own'        => ['citizen', 'agent', 'admin'],

        // --- Statistiques (admin) ---
        'stats:read'             => ['agent', 'admin'],
    ];

    /**
     * Vérifie si un rôle possède une permission donnée.
     */
    public static function can(string $role, string $permission): bool
    {
        return in_array($role, self::MATRIX[$permission] ?? [], true);
    }

    /**
     * Exige qu'un utilisateur authentifié possède une permission.
     * Envoie une erreur 403 si la permission est refusée.
     *
     * @param array  $auth       Payload JWT (contient 'role')
     * @param string $permission Nom de la permission (ex: 'incident:update_status')
     */
    public static function require(array $auth, string $permission): void
    {
        if (!self::can($auth['role'] ?? '', $permission)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => "Accès refusé : la permission '$permission' est requise.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Retourne toutes les permissions d'un rôle donné.
     * Utile pour le débogage ou l'affichage dans l'interface admin.
     */
    public static function getPermissionsForRole(string $role): array
    {
        $perms = [];
        foreach (self::MATRIX as $permission => $roles) {
            if (in_array($role, $roles, true)) {
                $perms[] = $permission;
            }
        }
        return $perms;
    }

    /**
     * Retourne la liste de tous les rôles disponibles.
     */
    public static function getRoles(): array
    {
        return ['citizen', 'agent', 'admin'];
    }
}
