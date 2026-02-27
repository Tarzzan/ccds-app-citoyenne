<?php
/**
 * CCDS — API Authentification
 * POST /api/register  → Inscription d'un citoyen
 * POST /api/login     → Connexion et obtention d'un token JWT
 */

/**
 * Inscription d'un nouvel utilisateur (rôle 'citizen' par défaut).
 */
function handle_register(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Méthode non autorisée.', 405);
    }

    $body = get_json_body();

    // Validation des champs
    $errors = validate($body, [
        'email'     => 'required|email|max:255',
        'password'  => 'required|min:8|max:128',
        'full_name' => 'required|min:2|max:255',
    ]);
    if (!empty($errors)) {
        json_error('Données invalides.', 422, $errors);
    }

    $db    = Database::getInstance();
    $email = strtolower(trim($body['email']));

    // Vérifier que l'email n'est pas déjà utilisé
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_error('Cette adresse email est déjà utilisée.', 409);
    }

    // Hachage sécurisé du mot de passe
    $hash = password_hash($body['password'], PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $db->prepare(
        'INSERT INTO users (email, password_hash, full_name, phone) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $email,
        $hash,
        trim($body['full_name']),
        isset($body['phone']) ? trim($body['phone']) : null,
    ]);

    $userId = (int)$db->lastInsertId();

    // Générer un token JWT directement après l'inscription
    $token = jwt_encode(['sub' => $userId, 'role' => 'citizen', 'email' => $email]);

    json_success([
        'token'     => $token,
        'expires_in'=> JWT_EXPIRY,
        'user'      => [
            'id'        => $userId,
            'email'     => $email,
            'full_name' => trim($body['full_name']),
            'role'      => 'citizen',
        ],
    ], 201, 'Compte créé avec succès.');
}

/**
 * Connexion d'un utilisateur existant.
 * Retourne un token JWT en cas de succès.
 */
function handle_login(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Méthode non autorisée.', 405);
    }

    $body = get_json_body();

    $errors = validate($body, [
        'email'    => 'required|email',
        'password' => 'required',
    ]);
    if (!empty($errors)) {
        json_error('Données invalides.', 422, $errors);
    }

    $db    = Database::getInstance();
    $email = strtolower(trim($body['email']));

    $stmt = $db->prepare(
        'SELECT id, email, password_hash, full_name, role, is_active FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Message générique pour ne pas révéler si l'email existe
    if (!$user || !password_verify($body['password'], $user['password_hash'])) {
        json_error('Email ou mot de passe incorrect.', 401);
    }

    if (!(bool)$user['is_active']) {
        json_error('Ce compte a été désactivé. Contactez l\'administration.', 403);
    }

    $token = jwt_encode([
        'sub'   => (int)$user['id'],
        'role'  => $user['role'],
        'email' => $user['email'],
    ]);

    json_success([
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
