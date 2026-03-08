<?php
/**
 * CCDS Back-Office — Page de connexion
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// Si déjà connecté, rediriger vers le dashboard
if (!empty($_SESSION['admin_user'])) {
    header('Location: /admin/?page=dashboard');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Veuillez renseigner votre email et votre mot de passe.';
    } else {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND role IN ('admin','agent') AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['admin_user'] = [
                    'id'        => $user['id'],
                    'email'     => $user['email'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ];
                // Mettre à jour last_login
                $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                header('Location: /admin/?page=dashboard');
                exit;
            } else {
                $error = 'Email ou mot de passe incorrect, ou compte non autorisé.';
            }
        } catch (Exception $e) {
            $error = 'Erreur de connexion à la base de données.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — <?= defined('APP_SHORT_NAME') ? e(APP_SHORT_NAME) : 'MaCommune' ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/admin/assets/css/admin.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-icon">🏛️</div>
      <div class="logo-name"><?= defined('APP_SHORT_NAME') ? e(APP_SHORT_NAME) : 'MaCommune' ?></div>
      <div class="logo-sub">Espace Administration</div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label class="form-label" for="email">Adresse email</label>
        <input type="email" id="email" name="email" class="form-control"
               placeholder="admin@mairie.fr" required
               value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Mot de passe</label>
        <input type="password" id="password" name="password" class="form-control"
               placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary w-100" style="justify-content:center;padding:12px;">
        🔐 Se connecter
      </button>
    </form>

    <p style="text-align:center;margin-top:20px;font-size:12px;color:#94a3b8;">
      Accès réservé aux agents et administrateurs municipaux.
    </p>
  </div>
</div>
</body>
</html>
