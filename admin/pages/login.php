<?php
/**
 * Ma Commune Back-Office — Page de connexion
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

            $passwordHash = $user['password_hash'] ?? $user['password'] ?? null;
            $passwordOk = $user && $passwordHash ? password_verify($password, $passwordHash) : false;

            if ($passwordOk) {
                $_SESSION['admin_user'] = [
                    'id'        => $user['id'],
                    'email'     => $user['email'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ];
                // Le schéma local n'expose pas toujours last_login.
                try {
                    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                } catch (Throwable $e) {
                    // Ne pas bloquer la connexion si cette colonne n'existe pas.
                }
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
  <title>Connexion — <?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Merriweather:wght@700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/admin/assets/css/admin.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-kicker">Administration communale</div>
    <div class="login-logo">
      <img src="/admin/assets/img/ma-commune-guyane-mark.png" alt="Ma Commune Guyane" class="logo-icon">
      <div class="logo-name"><?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?></div>
      <div class="logo-sub">Guyane · devoir de suivi public</div>
    </div>

    <div class="login-intro">
      Le back-office permet de suivre, prioriser et documenter les réponses apportées aux signalements citoyens.
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

    <p class="login-footnote">
      Accès réservé aux agents et administrateurs municipaux.
    </p>
  </div>
</div>
</body>
</html>
