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
    if (!empty($_POST['google_id_token'])) {
        $idToken = trim($_POST['google_id_token']);
        $apiUrl = 'https://api.netetfix.com/api/auth/google';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id_token' => $idToken, 'platform' => 'web']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $res) {
            $data = json_decode($res, true);
            if (!empty($data['data']['user'])) {
                $apiUser = $data['data']['user'];
                if (in_array($apiUser['role'], ['admin', 'agent'])) {
                    $db = Database::getInstance();
                    $_SESSION['admin_user'] = admin_enrich_user_with_service_scope($db, $apiUser);
                    try {
                        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$apiUser['id']]);
                    } catch (Throwable $e) {}
                    header('Location: /admin/?page=dashboard');
                    exit;
                } else {
                    $error = 'Votre compte Google n\'est pas autorisé à accéder à l\'administration.';
                }
            } else {
                 $error = 'Échec de la connexion via Google (données utilisateur manquantes).';
            }
        } else {
            $errData = json_decode((string)$res, true);
            $error = $errData['message'] ?? 'Échec de la validation Google ou compte non autorisé.';
        }
    } else {
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

        <div style="text-align:center; margin: 16px 0; color: #999;">ou</div>
        
        <div id="g_id_onload"
             data-client_id="<?= e(getenv('GOOGLE_AUTH_WEB_CLIENT_ID') ?: '972738775169-fggq1j35v9uq7h3uorefjopvevp3duva.apps.googleusercontent.com') ?>"
             data-context="signin"
             data-ux_mode="popup"
             data-callback="handleGoogleLogin"
             data-auto_prompt="false">
        </div>
        <div class="g_id_signin"
             data-type="standard"
             data-shape="rectangular"
             data-theme="outline"
             data-text="signin_with"
             data-size="large"
             data-logo_alignment="center"
             style="display:flex; justify-content:center;">
        </div>
      </form>
      
      <form method="POST" action="" id="google-login-form" style="display:none;">
          <input type="hidden" name="google_id_token" id="google_id_token">
      </form>

      <p class="login-footnote">
        Accès réservé aux agents et administrateurs municipaux.
      </p>
    </div>
  </div>
</div>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
function handleGoogleLogin(response) {
    document.getElementById('google_id_token').value = response.credential;
    document.getElementById('google-login-form').submit();
}
</script>
</body>
</html>
