<?php
/**
 * Ma Commune Back-Office — Gestion des utilisateurs
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$admin      = require_admin_auth();
$page_title = 'Utilisateurs';
$active_nav = 'users';
$db         = Database::getInstance();
$lead_photo_select = admin_incident_first_photo_select($db, 'i');
$usersPasswordColumn = admin_db_has_column($db, 'users', 'password_hash') ? 'password_hash' : 'password';
$serviceTablesReady = admin_db_has_table($db, 'services') && admin_db_has_table($db, 'user_service_memberships');
$serviceScopeReady = $serviceTablesReady
    && admin_db_has_table($db, 'service_category_map')
    && admin_db_has_table($db, 'intervention_plans');
$agentServiceScopeIds = $serviceScopeReady ? admin_allowed_service_ids($admin) : [];
$agentIsScoped = $serviceScopeReady && admin_is_service_scoped_agent($admin);
$scopeNotice = null;
$services = $serviceTablesReady ? intervention_get_services($db) : [];
$gamificationReady = admin_db_has_table($db, 'user_gamification') && admin_db_has_column($db, 'user_gamification', 'points');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_agent' && $admin['role'] === 'admin') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role     = in_array($_POST['role'] ?? '', ['agent', 'admin'], true) ? $_POST['role'] : 'agent';
        $serviceId = $serviceTablesReady ? (int)($_POST['service_id'] ?? 0) : 0;

        if (!$fullName || !$email || !$password) {
            $_SESSION['flash_error'] = 'Tous les champs sont obligatoires. Le compte n est pas créé tant que l identité, l email et le mot de passe temporaire restent incomplets.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Adresse email invalide. Vérifier le format avant d ouvrir un nouveau compte.';
        } else {
            $exists = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $exists->execute([$email]);

            if ($exists->fetch()) {
                $_SESSION['flash_error'] = 'Cet email est déjà utilisé. Réutiliser un compte existant évite de fragmenter le suivi agent.';
            } else {
                $db->prepare("
                    INSERT INTO users (full_name, email, {$usersPasswordColumn}, role, is_active, created_at)
                    VALUES (?, ?, ?, ?, 1, NOW())
                ")->execute([$fullName, $email, password_hash($password, PASSWORD_DEFAULT), $role]);

                $userId = (int)$db->lastInsertId();
                if ($serviceTablesReady && $serviceId > 0) {
                    intervention_upsert_user_membership(
                        $db,
                        $userId,
                        $serviceId,
                        $role === 'admin' ? 'manager' : 'agent',
                        true
                    );
                }
                $_SESSION['flash_success'] = "Compte de {$fullName} créé. Le poste peut maintenant être rattaché à un service et intégré au suivi opérationnel.";
            }
        }

        header('Location: /admin/?page=users');
        exit;
    }

    if ($action === 'toggle_active' && $admin['role'] === 'admin') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $isActive = (int)($_POST['is_active'] ?? 0);

        if ($userId && $userId !== (int)$admin['id']) {
            $db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$isActive, $userId]);
            $_SESSION['flash_success'] = $isActive ? 'Compte activé. Le profil redevient utilisable dans le dispositif.' : 'Compte désactivé. Le profil reste visible en historique mais ne peut plus agir.';
        }

        $back = $_POST['back'] ?? '/admin/?page=users';
        header('Location: ' . $back);
        exit;
    }

    if ($action === 'change_role' && $admin['role'] === 'admin') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', ['citizen', 'agent', 'admin'], true) ? $_POST['role'] : 'citizen';

        if ($userId && $userId !== (int)$admin['id']) {
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
            $_SESSION['flash_success'] = 'Rôle modifié. Vérifier ensuite le périmètre service et les droits induits par ce changement.';
        }

        header('Location: /admin/?page=users');
        exit;
    }

    if ($action === 'save_service_membership' && $admin['role'] === 'admin' && $serviceTablesReady) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $roleInService = in_array($_POST['role_in_service'] ?? '', ['manager', 'agent', 'viewer'], true)
            ? $_POST['role_in_service']
            : 'agent';
        $isPrimary = !empty($_POST['is_primary']);

        $userStmt = $db->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$userId]);
        $membershipUser = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$membershipUser || !in_array($membershipUser['role'], ['agent', 'admin'], true)) {
            $_SESSION['flash_error'] = 'Seuls les agents et administrateurs peuvent etre rattaches a un service. Un compte citoyen reste hors de cette chaîne interne.';
        } elseif (!$serviceId || !intervention_get_service_by_id($db, $serviceId)) {
            $_SESSION['flash_error'] = 'Service invalide. Choisir un service existant avant d enregistrer le rattachement.';
        } else {
            intervention_upsert_user_membership($db, $userId, $serviceId, $roleInService, $isPrimary);
            intervention_ensure_primary_membership($db, $userId);
            $_SESSION['flash_success'] = 'Rattachement service enregistré. Le compte est maintenant relié à la bonne file de traitement.';
        }

        header('Location: /admin/?page=users&detail=' . $userId);
        exit;
    }

    if ($action === 'remove_service_membership' && $admin['role'] === 'admin' && $serviceTablesReady) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);

        intervention_remove_user_membership($db, $userId, $serviceId);
        $_SESSION['flash_success'] = 'Rattachement service retiré. Vérifier qu un autre point d entrée principal existe encore pour ce compte.';

        header('Location: /admin/?page=users&detail=' . $userId);
        exit;
    }

    if ($action === 'set_primary_service' && $admin['role'] === 'admin' && $serviceTablesReady) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);

        $db->prepare('UPDATE user_service_memberships SET is_primary = 0 WHERE user_id = ?')->execute([$userId]);
        $db->prepare('UPDATE user_service_memberships SET is_primary = 1 WHERE user_id = ? AND service_id = ?')
            ->execute([$userId, $serviceId]);
        intervention_ensure_primary_membership($db, $userId);
        $_SESSION['flash_success'] = 'Service principal mis à jour. Les prochaines lectures du compte partiront désormais de ce rattachement.';

        header('Location: /admin/?page=users&detail=' . $userId);
        exit;
    }
}

$userScopeSql = '1=1';
$userScopeParams = [];

if ($agentIsScoped) {
    if (!empty($agentServiceScopeIds)) {
        $servicePlaceholdersMembership = implode(',', array_fill(0, count($agentServiceScopeIds), '?'));
        $servicePlaceholdersCitizen = implode(',', array_fill(0, count($agentServiceScopeIds), '?'));
        $userScopeSql = "
            (
                u.id = ?
                OR (
                    u.role IN ('agent', 'admin')
                    AND EXISTS (
                        SELECT 1
                        FROM user_service_memberships usm
                        WHERE usm.user_id = u.id
                          AND usm.service_id IN ($servicePlaceholdersMembership)
                    )
                )
                OR (
                    u.role = 'citizen'
                    AND EXISTS (
                        SELECT 1
                        FROM incidents scoped_i
                        JOIN categories scoped_cat ON scoped_cat.id = scoped_i.category_id
                        LEFT JOIN service_category_map scoped_scm
                          ON scoped_scm.category_id = scoped_cat.id
                         AND scoped_scm.is_default = 1
                        LEFT JOIN intervention_plans scoped_plan ON scoped_plan.id = (
                            SELECT p2.id
                            FROM intervention_plans p2
                            WHERE p2.incident_id = scoped_i.id
                            ORDER BY p2.created_at DESC, p2.id DESC
                            LIMIT 1
                        )
                        WHERE scoped_i.user_id = u.id
                          AND COALESCE(scoped_plan.service_id, scoped_scm.service_id) IN ($servicePlaceholdersCitizen)
                    )
                )
            )
        ";
        $userScopeParams = array_merge([(int)$admin['id']], $agentServiceScopeIds, $agentServiceScopeIds);
        $scopeNotice = $admin['primary_service_name']
            ? 'Cette page est en lecture seule et limitee au service ' . $admin['primary_service_name'] . '.'
            : 'Cette page est en lecture seule et limitee a vos services rattaches.';
    } else {
        $userScopeSql = 'u.id = ?';
        $userScopeParams = [(int)$admin['id']];
        $scopeNotice = 'Aucun service ne vous est encore attribue. Vous ne voyez ici que votre propre compte.';
    }
}

$incidentScopeSql = '';
$incidentScopeParams = [];

if ($agentIsScoped) {
    if (!empty($agentServiceScopeIds)) {
        $incidentScopeSql = "
            AND COALESCE(latest_plan.service_id, scoped_scm.service_id) IN ("
            . implode(',', array_fill(0, count($agentServiceScopeIds), '?'))
            . ')
        ';
        $incidentScopeParams = $agentServiceScopeIds;
    } else {
        $incidentScopeSql = ' AND 1 = 0 ';
    }
}

$detailUser    = null;
$userIncidents = [];
$userActivity  = [];
$userMemberships = [];

if (isset($_GET['detail'])) {
    $detailId = (int)$_GET['detail'];
    $gamificationJoinSql = $gamificationReady ? 'LEFT JOIN user_gamification g ON g.user_id = u.id' : '';
    $gamificationPointsSql = $gamificationReady ? 'COALESCE(g.points, 0)' : '0';

    $stmt = $db->prepare("
        SELECT u.*,
               COUNT(DISTINCT i.id)  AS incidents_count,
               COUNT(DISTINCT v.id)  AS votes_count,
               COUNT(DISTINCT c.id)  AS comments_count,
               {$gamificationPointsSql} AS gamification_points
        FROM users u
        LEFT JOIN incidents i ON i.user_id = u.id
        LEFT JOIN votes v ON v.user_id = u.id
        LEFT JOIN comments c ON c.user_id = u.id
        {$gamificationJoinSql}
        WHERE u.id = ?
          AND {$userScopeSql}
        GROUP BY u.id
    ");
    $stmt->execute(array_merge([$detailId], $userScopeParams));
    $detailUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($detailUser) {
        if ($serviceTablesReady && in_array($detailUser['role'], ['agent', 'admin'], true)) {
            $userMemberships = intervention_get_user_memberships($db, (int)$detailUser['id']);
        }

        $stmtInc = $db->prepare("
            SELECT i.id, i.reference, i.title, i.status, i.votes_count, i.created_at,
                   cat.name AS category_name, cat.icon AS category_icon,
                   {$lead_photo_select}
            FROM incidents i
            JOIN categories cat ON cat.id = i.category_id
            LEFT JOIN service_category_map scoped_scm ON scoped_scm.category_id = i.category_id AND scoped_scm.is_default = 1
            LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
                SELECT p2.id
                FROM intervention_plans p2
                WHERE p2.incident_id = i.id
                ORDER BY p2.created_at DESC, p2.id DESC
                LIMIT 1
            )
            WHERE i.user_id = ?
            {$incidentScopeSql}
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $stmtInc->execute(array_merge([$detailId], $incidentScopeParams));
        $userIncidents = $stmtInc->fetchAll(PDO::FETCH_ASSOC);
        foreach ($userIncidents as &$incident) {
            $incident['lead_photo'] = admin_incident_preview_photo($db, $incident);
        }
        unset($incident);

        $stmtAct = $db->prepare("
            (SELECT 'incident' AS type, i.reference AS ref, COALESCE(i.title, 'Sans titre') AS label, i.created_at AS date
             FROM incidents i
             LEFT JOIN service_category_map scoped_scm ON scoped_scm.category_id = i.category_id AND scoped_scm.is_default = 1
             LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
                 SELECT p2.id
                 FROM intervention_plans p2
                 WHERE p2.incident_id = i.id
                 ORDER BY p2.created_at DESC, p2.id DESC
                 LIMIT 1
             )
             WHERE i.user_id = ? {$incidentScopeSql}
             ORDER BY i.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'comment', i.reference, LEFT(c.comment, 60), c.created_at
             FROM comments c
             JOIN incidents i ON i.id = c.incident_id
             LEFT JOIN service_category_map scoped_scm ON scoped_scm.category_id = i.category_id AND scoped_scm.is_default = 1
             LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
                 SELECT p2.id
                 FROM intervention_plans p2
                 WHERE p2.incident_id = i.id
                 ORDER BY p2.created_at DESC, p2.id DESC
                 LIMIT 1
             )
             WHERE c.user_id = ? {$incidentScopeSql}
             ORDER BY c.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'vote', i.reference, COALESCE(i.title, 'Sans titre'), v.created_at
             FROM votes v
             JOIN incidents i ON i.id = v.incident_id
             LEFT JOIN service_category_map scoped_scm ON scoped_scm.category_id = i.category_id AND scoped_scm.is_default = 1
             LEFT JOIN intervention_plans latest_plan ON latest_plan.id = (
                 SELECT p2.id
                 FROM intervention_plans p2
                 WHERE p2.incident_id = i.id
                 ORDER BY p2.created_at DESC, p2.id DESC
                 LIMIT 1
             )
             WHERE v.user_id = ? {$incidentScopeSql}
             ORDER BY v.created_at DESC LIMIT 10)
            ORDER BY date DESC
            LIMIT 20
        ");
        $stmtAct->execute(array_merge(
            [$detailId],
            $incidentScopeParams,
            [$detailId],
            $incidentScopeParams,
            [$detailId],
            $incidentScopeParams
        ));
        $userActivity = $stmtAct->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($agentIsScoped) {
        render_error(403, 'Cet utilisateur ne fait pas partie de votre perimetre de service.');
    }
}

$search   = trim($_GET['q'] ?? '');
$roleFilt = $_GET['role'] ?? '';
$statFilt = $_GET['status'] ?? '';
$sort     = in_array($_GET['sort'] ?? '', ['full_name', 'email', 'created_at', 'incidents_count'], true) ? $_GET['sort'] : 'created_at';
$dir      = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$perPage  = 20;
$curPage  = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($curPage - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $like = '%' . $search . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    array_push($params, $like, $like, $like);
}

if ($roleFilt && in_array($roleFilt, ['citizen', 'agent', 'admin'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $roleFilt;
}

if ($statFilt === 'active') {
    $where[] = 'u.is_active = 1';
}
if ($statFilt === 'inactive') {
    $where[] = 'u.is_active = 0';
}

$whereSql = implode(' AND ', $where);
$whereSql .= ' AND ' . $userScopeSql;

$stmtCount = $db->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSql}");
$stmtCount->execute(array_merge($params, $userScopeParams));
$total      = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$sortSql = match ($sort) {
    'full_name'       => 'u.full_name',
    'email'           => 'u.email',
    'incidents_count' => 'incidents_count',
    default           => 'u.created_at',
};

$primaryServiceSql = $serviceTablesReady
    ? "(
         SELECT s.name
         FROM user_service_memberships usm
         JOIN services s ON s.id = usm.service_id
         WHERE usm.user_id = u.id
         ORDER BY usm.is_primary DESC, s.name ASC
         LIMIT 1
       )"
    : "NULL";

$stmtUsers = $db->prepare("
    SELECT u.id, u.full_name, u.email, u.phone, u.role, u.is_active, u.created_at,
           COUNT(DISTINCT i.id) AS incidents_count,
           COUNT(DISTINCT v.id) AS votes_count,
           {$primaryServiceSql} AS primary_service_name
    FROM users u
    LEFT JOIN incidents i ON i.user_id = u.id
    LEFT JOIN votes v ON v.user_id = u.id
    WHERE {$whereSql}
    GROUP BY u.id
    ORDER BY {$sortSql} {$dir}
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtUsers->execute(array_merge($params, $userScopeParams));
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$stmtKpis = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(role = 'citizen') AS citizens,
        SUM(role = 'agent') AS agents,
        SUM(role = 'admin') AS admins,
        SUM(is_active = 1) AS active,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new_30d
    FROM users u
    WHERE {$userScopeSql}
");
$stmtKpis->execute($userScopeParams);
$kpis = $stmtKpis->fetch(PDO::FETCH_ASSOC);

function users_role_badge(string $role): string
{
    return match ($role) {
        'admin' => '<span class="users-badge users-badge--admin">Administrateur</span>',
        'agent' => '<span class="users-badge users-badge--agent">Agent</span>',
        default => '<span class="users-badge users-badge--citizen">Citoyen</span>',
    };
}

function users_status_badge(bool $isActive): string
{
    return $isActive
        ? '<span class="users-badge users-badge--active">Actif</span>'
        : '<span class="users-badge users-badge--inactive">Inactif</span>';
}

function users_activity_icon(string $type): string
{
    return match ($type) {
        'incident' => 'SIG',
        'comment'  => 'COM',
        'vote'     => 'SOU',
        default    => '•',
    };
}

function users_sort_url(string $column, string $currentSort, string $currentDir, array $extra = []): string
{
    $nextDir = ($column === $currentSort && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $params = array_merge($extra, ['sort' => $column, 'dir' => $nextDir]);
    return '/admin/?page=users&' . http_build_query($params);
}

require_once __DIR__ . '/../includes/layout.php';
?>
<div class="page-hero page-hero--with-visual">
  <div class="page-hero-copy">
    <div class="page-hero-kicker">Administration des comptes</div>
    <div class="page-hero-title"><?= $agentIsScoped ? 'Annuaire operationnel du service' : 'Suivre les citoyens, agents et administrateurs' ?></div>
    <?php if ($isTrainingMode): ?>
    <div class="page-hero-text">
      <?= $agentIsScoped
          ? 'Cette vue rassemble les comptes utiles a votre perimetre de service. Elle reste consultative pour permettre un suivi terrain sans ouvrir la gouvernance globale.'
          : 'Cette page centralise les comptes actifs du dispositif afin de verifier l activite, suivre l engagement et gerer les acces au service.' ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="page-hero-metrics">
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['total'] ?></span>
      <span class="hero-chip-label">comptes visibles</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value"><?= (int)$kpis['active'] ?></span>
      <span class="hero-chip-label">actifs</span>
    </div>
    <div class="hero-chip">
      <span class="hero-chip-value">+<?= (int)$kpis['new_30d'] ?></span>
      <span class="hero-chip-label">nouveaux sur 30 jours</span>
    </div>
  </div>
  <div class="page-hero-visual">
    <div class="generated-visual-panel generated-visual-panel--hero hero-visual-stack">
      <?= generated_visual_html('ILL-05', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-main', 'label' => 'Lecture annuaire']) ?>
      <?= generated_visual_html('ILL-02', ['class' => 'generated-visual generated-visual--cover hero-visual-stack-inset', 'label' => 'Relais service']) ?>
      <?= generated_visual_html('CHAR-04', ['class' => 'generated-visual generated-visual--portrait hero-visual-stack-agent', 'label' => 'Referent annuaire']) ?>
      <?php if ($isTrainingMode): ?>
      <div class="generated-visual-caption hero-visual-stack-copy">
        <strong>Lecture des comptes</strong>
        <span>Citoyens, agents et administrateurs restent lisibles dans une meme surface de pilotage.</span>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($scopeNotice): ?>
  <div class="alert alert-info users-scope-alert"><?= e($scopeNotice) ?></div>
<?php endif; ?>

<?php if ($isTrainingMode): ?>
<div class="admin-guidance-grid">
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Lecture utile</div>
    <h3>Verifier les acces sans perdre le contexte terrain.</h3>
    <p>
      Cette page sert a suivre les comptes actifs, repérer les agents rattaches aux services et garder une vision claire des profils qui font vivre le dispositif.
    </p>
  </div>
  <div class="admin-guidance-card">
    <div class="admin-guidance-kicker">Reflexe produit</div>
    <h3>Traiter les comptes comme une file d exploitation, pas comme un simple CRUD.</h3>
    <p>
      Avant de modifier un role ou un statut, verifier le rattachement service, l activite recente et l impact sur la chaine de prise en charge.
    </p>
  </div>
</div>
<?php endif; ?>

<div class="page-async-scope" data-async-scope="users-admin">
<?php if ($detailUser): ?>
  <div class="user-detail">
    <div class="user-detail-head">
      <div class="user-avatar"><?= strtoupper(mb_substr($detailUser['full_name'], 0, 1)) ?></div>
      <div>
        <h2 class="user-detail-name"><?= e($detailUser['full_name']) ?></h2>
        <p class="user-detail-email"><?= e($detailUser['email']) ?></p>
        <div class="users-inline-actions users-inline-actions--top">
          <?= users_role_badge($detailUser['role']) ?>
          <?= users_status_badge((bool)$detailUser['is_active']) ?>
        </div>
      </div>
        <div class="users-detail-actions users-detail-actions--push">
        <?php if ($admin['role'] === 'admin' && (int)$detailUser['id'] !== (int)$admin['id']): ?>
          <form method="POST" data-async-form>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="user_id" value="<?= $detailUser['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $detailUser['is_active'] ? 0 : 1 ?>">
            <input type="hidden" name="back" value="/admin/?page=users&detail=<?= $detailUser['id'] ?>">
            <button type="submit" class="btn <?= $detailUser['is_active'] ? 'btn-danger' : 'btn-primary' ?>">
              <?= $detailUser['is_active'] ? 'Désactiver' : 'Activer' ?>
            </button>
          </form>
        <?php endif; ?>
        <a href="/admin/?page=users" class="btn btn-outline" data-async-link data-async-scope="admin-main">Retour à la liste</a>
      </div>
    </div>

    <div class="user-detail-stats">
      <div class="user-detail-stat"><strong><?= (int)$detailUser['incidents_count'] ?></strong>Signalements</div>
      <div class="user-detail-stat"><strong><?= (int)$detailUser['votes_count'] ?></strong>Votes</div>
      <div class="user-detail-stat"><strong><?= (int)$detailUser['comments_count'] ?></strong>Commentaires</div>
      <div class="user-detail-stat"><strong><?= (int)$detailUser['gamification_points'] ?></strong>Points</div>
      <div class="user-detail-stat"><strong><?= format_date_short($detailUser['created_at']) ?></strong>Inscription</div>
    </div>

    <div class="user-detail-grid">
      <div>
        <?php if ($serviceTablesReady && in_array($detailUser['role'], ['agent', 'admin'], true)): ?>
          <div class="user-service-card user-service-card--spaced">
            <div class="user-section-head">
              <div>
                <h3 class="user-section-title">Rattachement service</h3>
                <p class="text-muted text-small user-service-card-copy">
                  Ce bloc fixe le service de rattachement de l agent et prepare la future chaine de prise en charge.
                </p>
              </div>
              <span class="badge badge-gray"><?= count($userMemberships) ?> rattachement<?= count($userMemberships) > 1 ? 's' : '' ?></span>
            </div>

            <?php if ($userMemberships): ?>
              <div class="user-service-list">
                <?php foreach ($userMemberships as $membership): ?>
                  <div class="user-service-item">
                    <div>
                      <div class="user-service-name"><?= e($membership['service_name']) ?></div>
                      <div class="user-service-meta">
                        <?= e(ucfirst($membership['role_in_service'])) ?>
                        <?= !empty($membership['is_primary']) ? ' · service principal' : '' ?>
                      </div>
                    </div>
                    <?php if ($admin['role'] === 'admin'): ?>
                      <div class="users-inline-actions user-inline-actions--end">
                        <?php if (empty($membership['is_primary'])): ?>
                          <form method="POST" data-async-form>
                            <input type="hidden" name="action" value="set_primary_service">
                            <input type="hidden" name="user_id" value="<?= (int)$detailUser['id'] ?>">
                            <input type="hidden" name="service_id" value="<?= (int)$membership['service_id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Principal</button>
                          </form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Retirer ce rattachement service ?')" data-async-form>
                          <input type="hidden" name="action" value="remove_service_membership">
                          <input type="hidden" name="user_id" value="<?= (int)$detailUser['id'] ?>">
                          <input type="hidden" name="service_id" value="<?= (int)$membership['service_id'] ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Retirer</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="text-muted">Aucun service rattaché pour l instant. Le compte existe, mais il n est pas encore branché à une file métier exploitable.</p>
            <?php endif; ?>

            <?php if ($admin['role'] === 'admin'): ?>
              <?php if ($isTrainingMode): ?>
              <div class="admin-form-guide">
                <strong>Ordre conseille</strong>
                <div class="admin-form-guide-list">
                  <div class="admin-form-guide-item">
                    <span class="admin-form-guide-step">01</span>
                    <div>
                      <strong>Choisir le bon service</strong>
                      <span>Le rattachement fixe le point d entree du compte dans la chaine de traitement.</span>
                    </div>
                  </div>
                  <div class="admin-form-guide-item">
                    <span class="admin-form-guide-step">02</span>
                    <div>
                      <strong>Limiter le role au besoin reel</strong>
                      <span>Utiliser `Responsable` seulement quand l agent doit piloter ou prioriser la file du service.</span>
                    </div>
                  </div>
                </div>
              </div>
              <?php endif; ?>
              <form method="POST" class="user-membership-form" data-async-form>
                <input type="hidden" name="action" value="save_service_membership">
                <input type="hidden" name="user_id" value="<?= (int)$detailUser['id'] ?>">
                <div class="form-group">
                  <label class="form-label">Ajouter ou mettre a jour un service</label>
                  <select name="service_id" class="form-control" required>
                    <option value="">Choisir un service</option>
                    <?php foreach ($services as $service): ?>
                      <option value="<?= (int)$service['id'] ?>"><?= e($service['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Rôle dans le service</label>
                  <select name="role_in_service" class="form-control">
                    <option value="agent">Agent</option>
                    <option value="manager">Responsable</option>
                    <option value="viewer">Lecture seule</option>
                  </select>
                </div>
                <label class="users-inline-actions user-service-toggle">
                  <input type="checkbox" name="is_primary" value="1">
                  Définir comme service principal
                </label>
                <button type="submit" class="btn btn-primary">Enregistrer le rattachement</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="user-section-head">
          <div>
            <h3 class="user-section-title">Derniers signalements</h3>
            <p class="admin-section-note">Remonter d abord les derniers dossiers visibles sur le périmètre de ce compte.</p>
          </div>
          <span class="badge badge-gray"><?= count($userIncidents) ?> dossier<?= count($userIncidents) > 1 ? 's' : '' ?></span>
        </div>
        <?php if ($userIncidents): ?>
          <div class="table-wrapper">
            <table class="user-mini-table">
              <thead>
                <tr><th>Cat.</th><th>Réf.</th><th>Titre</th><th>Preuve</th><th>Votes</th></tr>
              </thead>
              <tbody>
                <?php foreach ($userIncidents as $incident): ?>
                  <tr>
                    <td><?= category_visual_html($incident['category_icon'] ?? 'road', $incident['category_name'], 'md') ?></td>
                    <td><a href="/admin/?page=incident_detail&id=<?= $incident['id'] ?>" data-async-link data-async-scope="admin-main"><?= e($incident['reference']) ?></a></td>
                    <td><?= e(mb_strimwidth($incident['title'] ?: 'Sans titre', 0, 32, '...')) ?></td>
                    <td>
                      <?php if (!empty($incident['lead_photo']['url'])): ?>
                        <span class="admin-proof-thumb-wrap">
                          <img src="<?= e($incident['lead_photo']['url']) ?>" alt="Preuve citoyenne" class="admin-proof-thumb admin-proof-thumb--small">
                        </span>
                      <?php else: ?>
                        <span class="text-muted text-small">—</span>
                      <?php endif; ?>
                    </td>
                    <td><?= (int)$incident['votes_count'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="admin-empty-state">
            <strong>Aucun signalement.</strong>
            <span>Aucun dossier récent n est visible pour ce compte dans le périmètre courant.</span>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <div class="user-section-head">
          <div>
            <h3 class="user-section-title">Activité récente</h3>
            <p class="admin-section-note">Incidents, commentaires et soutiens remontent dans un seul flux lisible.</p>
          </div>
          <span class="badge badge-gray"><?= count($userActivity) ?> entree<?= count($userActivity) > 1 ? 's' : '' ?></span>
        </div>
        <ul class="user-activity">
          <?php foreach ($userActivity as $activity): ?>
            <li>
              <span><?= users_activity_icon($activity['type']) ?></span>
              <div>
                <div class="user-activity-title"><?= e(mb_strimwidth($activity['label'], 0, 52, '...')) ?></div>
                <div class="text-muted text-small"><?= e($activity['ref']) ?> · <?= format_date($activity['date']) ?></div>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($userActivity)): ?>
            <li><span class="text-muted"><?= $agentIsScoped ? 'Aucune activite recente visible sur votre perimetre.' : 'Aucune activite recente.' ?></span></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="users-grid">
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['total'] ?></div><div class="users-kpi-label">comptes total</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['citizens'] ?></div><div class="users-kpi-label">citoyens</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['agents'] ?></div><div class="users-kpi-label">agents</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['admins'] ?></div><div class="users-kpi-label">administrateurs</div></div>
    <div class="users-kpi"><div class="users-kpi-value"><?= (int)$kpis['active'] ?></div><div class="users-kpi-label">actifs</div></div>
    <div class="users-kpi"><div class="users-kpi-value">+<?= (int)$kpis['new_30d'] ?></div><div class="users-kpi-label">nouveaux sur 30 jours</div></div>
  </div>

  <div class="users-toolbar">
    <h2><?= $agentIsScoped ? 'Annuaire du perimetre visible' : 'Gestion des utilisateurs' ?></h2>
    <?php if ($admin['role'] === 'admin'): ?>
      <button type="button" onclick="document.getElementById('users-create-modal').style.display='flex'" class="btn btn-primary">Créer un agent</button>
    <?php endif; ?>
  </div>

  <form method="GET" action="/admin/" class="users-filters" data-async-form>
    <input type="hidden" name="page" value="users">
    <?php if ($isTrainingMode): ?>
    <div class="admin-form-guide">
      <strong>Lecture de la liste</strong>
      <div class="admin-form-guide-list">
        <div class="admin-form-guide-item">
          <span class="admin-form-guide-step">01</span>
          <div>
            <strong>Filtrer avant de corriger</strong>
            <span>Commencer par role ou statut pour eviter de modifier un compte hors contexte.</span>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <input type="text" name="q" class="form-control" placeholder="Nom, email, téléphone..." value="<?= e($search) ?>">
    <select name="role" class="form-control">
      <option value="">Tous les rôles</option>
      <option value="citizen" <?= $roleFilt === 'citizen' ? 'selected' : '' ?>>Citoyen</option>
      <option value="agent" <?= $roleFilt === 'agent' ? 'selected' : '' ?>>Agent</option>
      <option value="admin" <?= $roleFilt === 'admin' ? 'selected' : '' ?>>Administrateur</option>
    </select>
    <select name="status" class="form-control">
      <option value="">Tous les statuts</option>
      <option value="active" <?= $statFilt === 'active' ? 'selected' : '' ?>>Actifs</option>
      <option value="inactive" <?= $statFilt === 'inactive' ? 'selected' : '' ?>>Inactifs</option>
    </select>
    <button type="submit" class="btn btn-primary">Filtrer</button>
    <?php if ($search || $roleFilt || $statFilt): ?>
      <a href="/admin/?page=users" class="btn btn-outline" data-async-link>Réinitialiser</a>
    <?php endif; ?>
  </form>

  <?php $extra = ['q' => $search, 'role' => $roleFilt, 'status' => $statFilt, 'p' => $curPage]; ?>

  <div class="table-wrapper">
    <table class="users-table">
      <thead>
        <tr>
          <th><a href="<?= users_sort_url('full_name', $sort, $dir, $extra) ?>" data-async-link>Nom</a></th>
          <th><a href="<?= users_sort_url('email', $sort, $dir, $extra) ?>" data-async-link>Email</a></th>
          <th>Service</th>
          <th>Rôle</th>
          <th>Statut</th>
          <th><a href="<?= users_sort_url('incidents_count', $sort, $dir, $extra) ?>" data-async-link>Signalements</a></th>
          <th>Votes</th>
          <th><a href="<?= users_sort_url('created_at', $sort, $dir, $extra) ?>" data-async-link>Inscription</a></th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
          <tr>
            <td>
              <a href="/admin/?page=users&detail=<?= $user['id'] ?>" class="users-row-link" data-async-link data-async-scope="admin-main"><?= e($user['full_name']) ?></a>
              <?php if ($user['phone']): ?>
                <div class="text-muted text-small"><?= e($user['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($user['email']) ?></td>
            <td class="text-muted text-small"><?= e($user['primary_service_name'] ?: '—') ?></td>
            <td><?= users_role_badge($user['role']) ?></td>
            <td><?= users_status_badge((bool)$user['is_active']) ?></td>
            <td class="text-center"><?= (int)$user['incidents_count'] ?></td>
            <td class="text-center"><?= (int)$user['votes_count'] ?></td>
            <td class="text-muted text-small"><?= format_date_short($user['created_at']) ?></td>
            <td>
              <div class="users-inline-actions">
                <a href="/admin/?page=users&detail=<?= $user['id'] ?>" class="btn btn-outline btn-sm" data-async-link data-async-scope="admin-main">Détail</a>
                <?php if ($admin['role'] === 'admin' && (int)$user['id'] !== (int)$admin['id']): ?>
                  <form method="POST" data-async-form>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $user['is_active'] ? 0 : 1 ?>">
                    <button type="submit" class="btn <?= $user['is_active'] ? 'btn-danger' : 'btn-primary' ?> btn-sm">
                      <?= $user['is_active'] ? 'Désactiver' : 'Activer' ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
          <tr><td colspan="9" class="text-center text-muted users-empty-row">Aucun utilisateur trouvé. Élargir les filtres ou retirer la recherche pour relire l annuaire complet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="users-pagination">
      <?php for ($page = 1; $page <= $totalPages; $page++): ?>
        <?php if ($page === $curPage): ?>
          <span class="users-page active"><?= $page ?></span>
        <?php else: ?>
          <a class="users-page" data-async-link href="/admin/?page=users&q=<?= urlencode($search) ?>&role=<?= urlencode($roleFilt) ?>&status=<?= urlencode($statFilt) ?>&sort=<?= urlencode($sort) ?>&dir=<?= urlencode($dir) ?>&p=<?= $page ?>"><?= $page ?></a>
        <?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>

  <?php if ($admin['role'] === 'admin'): ?>
    <div id="users-create-modal" class="users-modal">
      <div class="users-modal-box">
        <h3 class="users-modal-title">Créer un compte agent</h3>
        <?php if ($isTrainingMode): ?>
        <div class="admin-form-guide">
          <strong>Creation rapide</strong>
          <div class="admin-form-guide-list">
            <div class="admin-form-guide-item">
              <span class="admin-form-guide-step">01</span>
              <div>
                <strong>Creer le compte minimal</strong>
                <span>Nom, email, mot de passe et role suffisent pour ouvrir l acces.</span>
              </div>
            </div>
            <div class="admin-form-guide-item">
              <span class="admin-form-guide-step">02</span>
              <div>
                <strong>Associer le service si connu</strong>
                <span>Renseigner le service principal tout de suite si le perimetre d action est deja defini.</span>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <form method="POST" data-async-form>
          <input type="hidden" name="action" value="create_agent">
          <div class="form-group">
            <label class="form-label">Nom complet</label>
            <input type="text" name="full_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control" required minlength="8">
            <div class="admin-section-note">Utiliser un mot de passe provisoire assez robuste, puis organiser une reprise propre par l agent.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Rôle</label>
            <select name="role" class="form-control">
              <option value="agent">Agent</option>
              <option value="admin">Administrateur</option>
            </select>
          </div>
          <?php if ($serviceTablesReady): ?>
            <div class="form-group">
              <label class="form-label">Service principal</label>
              <select name="service_id" class="form-control">
                <option value="">Aucun service pour l instant</option>
                <?php foreach ($services as $service): ?>
                  <option value="<?= (int)$service['id'] ?>"><?= e($service['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="users-modal-actions">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('users-create-modal').style.display='none'">Annuler</button>
            <button type="submit" class="btn btn-primary">Créer</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
