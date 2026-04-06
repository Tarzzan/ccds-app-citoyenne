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
                $sessionUser = [
                    'id'        => $user['id'],
                    'email'     => $user['email'],
                    'full_name' => $user['full_name'],
                    'role'      => $user['role'],
                ];
                $_SESSION['admin_user'] = admin_enrich_user_with_service_scope($db, $sessionUser);
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

$brand_mark_url = visual_admin_brand_asset_url('brand_mark_url', '/admin/assets/img/ma-commune-guyane-mark.png');
$defaultLoginSubtitle = defined('APP_ADMIN_LOGIN_SUBTITLE') ? APP_ADMIN_LOGIN_SUBTITLE : 'Territoire · poste de suivi public';
$login_subtitle = visual_admin_brand_text('login_subtitle', $defaultLoginSubtitle);
$login_scene_url = visual_admin_slot_url('login_scene', 'ILL-05');
$login_inset_url = visual_admin_slot_url('login_inset', 'ILL-02');
$login_agent_url = visual_admin_slot_url('login_agent', 'CHAR-04');
$theme_settings = visual_admin_theme_settings();
$theme_inline_style = visual_admin_theme_inline_style();
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
<body data-theme-variant="<?= e((string)$theme_settings['variant']) ?>" style="<?= e($theme_inline_style) ?>">
<div class="login-page">
  <div class="login-shell">
    <aside class="login-aside">
      <div class="login-aside-kicker">Back-office local</div>
      <h1 class="login-aside-title">Coordonner la reponse publique sans perdre le terrain de vue.</h1>
      <p class="login-aside-text">
        Le back-office relie les signalements, les services, la planification d intervention
        et la transparence citoyenne dans une seule lecture de travail.
      </p>
      <div class="login-aside-points">
        <div class="login-aside-point">
          <strong>Qualifier</strong>
          <span>Priorites, categories, service responsable et prise en charge.</span>
        </div>
        <div class="login-aside-point">
          <strong>Planifier</strong>
          <span>Equipe interne ou prestataire, creneau et preuve de suivi.</span>
        </div>
        <div class="login-aside-point">
          <strong>Rendre lisible</strong>
          <span>Une chronologie claire pour les agents comme pour les habitants.</span>
        </div>
      </div>
      <div class="login-aside-visual">
        <div class="login-aside-surface">
          <?php if ($login_scene_url): ?>
            <img src="<?= e($login_scene_url) ?>" alt="Contexte d accueil" class="login-aside-scene" loading="lazy">
          <?php endif; ?>
          <?php if ($login_inset_url): ?>
            <img src="<?= e($login_inset_url) ?>" alt="Repere local" class="login-aside-inset" loading="lazy">
          <?php endif; ?>
          <?php if ($login_agent_url): ?>
            <img src="<?= e($login_agent_url) ?>" alt="Repere agent" class="login-aside-agent" loading="lazy">
          <?php endif; ?>
          <div class="login-aside-surface-kicker">Lecture de poste</div>
          <h2 class="login-aside-surface-title">Un back-office utile doit prioriser, structurer et rendre visible.</h2>
          <p class="login-aside-surface-copy">
            L entrée d administration doit inspirer confiance sans s appuyer sur des illustrations parasites. Elle doit faire sentir la rigueur du poste, la coordination des services et la responsabilite publique.
          </p>
          <div class="login-aside-metrics">
            <div class="login-aside-metric">
              <strong>1</strong>
              <span>poste de supervision unifie</span>
            </div>
            <div class="login-aside-metric">
              <strong>3</strong>
              <span>gestes cles : qualifier, planifier, publier</span>
            </div>
            <div class="login-aside-metric">
              <strong>0</strong>
              <span>visuel decoratif inutile dans la chaine de travail</span>
            </div>
          </div>
        </div>
      </div>
    </aside>

    <div class="login-card">
      <div class="login-kicker">Administration communale</div>
      <div class="login-logo">
        <img src="<?= e($brand_mark_url) ?>" alt="<?= e((defined('APP_NAME') ? APP_NAME : 'Ma Commune') . ' marque') ?>" class="logo-icon">
        <div class="logo-name"><?= defined('APP_NAME') ? e(APP_NAME) : 'Ma Commune' ?></div>
        <div class="logo-sub"><?= e($login_subtitle) ?></div>
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
        <button type="submit" class="btn btn-primary w-100 btn-block-center">
          Se connecter
        </button>
      </form>

      <p class="login-footnote">
        Accès réservé aux agents et administrateurs municipaux.
      </p>
    </div>
  </div>
</div>
</body>
</html>
