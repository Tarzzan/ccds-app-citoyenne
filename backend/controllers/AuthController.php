<?php
/**
 * CCDS v1.2 — AuthController (TECH-01 + UX-03)
 *
 * POST /api/register        → Inscription
 * POST /api/login           → Connexion
 * GET  /api/profile         → Lire son profil
 * PUT  /api/profile         → Mettre à jour son profil
 * PUT  /api/profile/password → Changer son mot de passe
 */

require_once __DIR__ . '/../core/BaseController.php';
require_once __DIR__ . '/../core/Permissions.php';
require_once __DIR__ . '/../core/Security.php';

class AuthController extends BaseController
{
    // ----------------------------------------------------------------
    // POST /api/register
    // ----------------------------------------------------------------
    public function register(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error('Méthode non autorisée.', 405);
        }

        $body = Security::getJsonBody();

        $this->validate($body, [
            'email'     => 'required|email|max:255',
            'password'  => 'required|min:8|max:128',
            'full_name' => 'required|min:2|max:255',
        ]);

        $email = strtolower(trim($body['email']));

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $this->error('Cette adresse email est déjà utilisée.', 409);
        }

        $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare(
            'INSERT INTO users (email, password_hash, full_name, phone) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            $hash,
            trim($body['full_name']),
            isset($body['phone']) ? trim($body['phone']) : null,
        ]);

        $userId = (int)$this->db->lastInsertId();
        $token  = jwt_encode(['sub' => $userId, 'role' => 'citizen', 'email' => $email]);

        $this->success([
            'token'      => $token,
            'expires_in' => JWT_EXPIRY,
            'user'       => [
                'id'        => $userId,
                'email'     => $email,
                'full_name' => trim($body['full_name']),
                'role'      => 'citizen',
            ],
        ], 201, 'Compte créé avec succès.');
    }

    // ----------------------------------------------------------------
    // POST /api/login
    // ----------------------------------------------------------------
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->error('Méthode non autorisée.', 405);
        }

        $body = Security::getJsonBody();

        $this->validate($body, [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $email = strtolower(trim($body['email']));

        $stmt = $this->db->prepare(
            'SELECT id, email, password_hash, full_name, role, is_active FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['password'], $user['password_hash'])) {
            $this->error('Email ou mot de passe incorrect.', 401);
        }

        if (!(bool)$user['is_active']) {
            $this->error("Ce compte a été désactivé. Contactez l'administration.", 403);
        }

        $token = jwt_encode([
            'sub'   => (int)$user['id'],
            'role'  => $user['role'],
            'email' => $user['email'],
        ]);

        $this->success([
            'token'      => $token,
            'expires_in' => JWT_EXPIRY,
            'user'       => [
                'id'        => (int)$user['id'],
                'email'     => $user['email'],
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ],
        ], 200, 'Connexion réussie.');
    }

    // ----------------------------------------------------------------
    // GET /api/profile  (UX-03)
    // ----------------------------------------------------------------
    public function getProfile(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'user:read_own');

        $stmt = $this->db->prepare(
            'SELECT id, email, full_name, phone, role, created_at,
                    notification_status_change, notification_new_comment, notification_vote_milestone
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$auth['sub']]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->error('Utilisateur introuvable.', 404);
        }

        $this->success([
            'id'        => (int)$user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'phone'     => $user['phone'],
            'role'      => $user['role'],
            'created_at'=> $user['created_at'],
            'notification_preferences' => [
                'status_change'    => (bool)($user['notification_status_change'] ?? true),
                'new_comment'      => (bool)($user['notification_new_comment'] ?? true),
                'vote_milestone'   => (bool)($user['notification_vote_milestone'] ?? false),
            ],
        ]);
    }

    // ----------------------------------------------------------------
    // PUT /api/profile  (UX-03)
    // ----------------------------------------------------------------
    public function updateProfile(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'user:update_own');

        $body = Security::getJsonBody();

        $this->validate($body, [
            'full_name' => 'required|min:2|max:255',
        ]);

        // Mettre à jour les préférences de notification si fournies
        $notifStatusChange   = isset($body['notification_preferences']['status_change'])
            ? (int)(bool)$body['notification_preferences']['status_change'] : 1;
        $notifNewComment     = isset($body['notification_preferences']['new_comment'])
            ? (int)(bool)$body['notification_preferences']['new_comment'] : 1;
        $notifVoteMilestone  = isset($body['notification_preferences']['vote_milestone'])
            ? (int)(bool)$body['notification_preferences']['vote_milestone'] : 0;

        $stmt = $this->db->prepare(
            'UPDATE users
             SET full_name = ?,
                 phone = ?,
                 notification_status_change = ?,
                 notification_new_comment = ?,
                 notification_vote_milestone = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim($body['full_name']),
            isset($body['phone']) ? trim($body['phone']) : null,
            $notifStatusChange,
            $notifNewComment,
            $notifVoteMilestone,
            $auth['sub'],
        ]);

        $this->success(['updated' => true], 200, 'Profil mis à jour.');
    }

    // ----------------------------------------------------------------
    // PUT /api/profile/password  (UX-03)
    // ----------------------------------------------------------------
    public function changePassword(): void
    {
        $auth = $this->requireAuth();
        $this->requirePermission($auth, 'user:update_own');

        $body = Security::getJsonBody();

        $this->validate($body, [
            'current_password' => 'required',
            'new_password'     => 'required|min:8|max:128',
        ]);

        // Vérifier l'ancien mot de passe
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$auth['sub']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($body['current_password'], $user['password_hash'])) {
            $this->error('Mot de passe actuel incorrect.', 401);
        }

        $newHash = password_hash($body['new_password'], PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $auth['sub']]);

        $this->success(['updated' => true], 200, 'Mot de passe modifié avec succès.');
    }
}
