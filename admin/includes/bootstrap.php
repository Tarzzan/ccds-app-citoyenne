<?php
/**
 * CCDS Back-Office — Bootstrap
 * Charge la configuration partagée avec le backend et démarre la session.
 */

// Charger la config et les helpers du backend
require_once __DIR__ . '/../../backend/config/config.php';
require_once __DIR__ . '/../../backend/config/Database.php';
require_once __DIR__ . '/../../backend/config/helpers.php';

// Démarrer la session PHP sécurisée
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // Mettre true en production (HTTPS)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ----------------------------------------------------------------
// Fonctions d'authentification Back-Office (session PHP)
// ----------------------------------------------------------------

/**
 * Vérifie si l'utilisateur est connecté au back-office.
 * Redirige vers la page de login si ce n'est pas le cas.
 */
function require_admin_auth(): array
{
    if (empty($_SESSION['admin_user'])) {
        header('Location: /admin/?page=login');
        exit;
    }
    return $_SESSION['admin_user'];
}

/**
 * Vérifie si l'utilisateur a le rôle 'admin' (et non juste 'agent').
 */
function require_admin_role(): void
{
    $user = require_admin_auth();
    if ($user['role'] !== 'admin') {
        render_error(403, 'Accès réservé aux administrateurs.');
    }
}

/**
 * Retourne l'utilisateur connecté ou null.
 */
function current_admin(): ?array
{
    return $_SESSION['admin_user'] ?? null;
}

// ----------------------------------------------------------------
// Fonctions utilitaires d'affichage
// ----------------------------------------------------------------

function render_error(int $code, string $message): void
{
    http_response_code($code);
    echo "<div style='font-family:sans-serif;padding:40px;color:#ef4444;'>
            <h2>Erreur $code</h2><p>$message</p>
            <a href='/admin/' style='color:#1d4ed8'>← Retour au tableau de bord</a>
          </div>";
    exit;
}

function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function format_date(string $date): string
{
    return (new DateTime($date))->format('d/m/Y à H:i');
}

function format_date_short(string $date): string
{
    return (new DateTime($date))->format('d/m/Y');
}

// Libellés et couleurs des statuts
function status_label(string $status): string
{
    return [
        'submitted'    => 'Soumis',
        'acknowledged' => 'Pris en charge',
        'in_progress'  => 'En cours',
        'resolved'     => 'Résolu',
        'rejected'     => 'Rejeté',
    ][$status] ?? $status;
}

function status_class(string $status): string
{
    return [
        'submitted'    => 'badge-gray',
        'acknowledged' => 'badge-blue',
        'in_progress'  => 'badge-yellow',
        'resolved'     => 'badge-green',
        'rejected'     => 'badge-red',
    ][$status] ?? 'badge-gray';
}

function priority_label(string $p): string
{
    return ['low' => 'Faible', 'medium' => 'Normale', 'high' => 'Haute', 'critical' => 'Critique'][$p] ?? $p;
}

function priority_class(string $p): string
{
    return ['low' => 'badge-gray', 'medium' => 'badge-blue', 'high' => 'badge-yellow', 'critical' => 'badge-red'][$p] ?? 'badge-gray';
}

function role_label(string $r): string
{
    return ['citizen' => 'Citoyen', 'agent' => 'Agent', 'admin' => 'Administrateur'][$r] ?? $r;
}
