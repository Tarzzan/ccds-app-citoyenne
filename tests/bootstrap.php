<?php
/**
 * CCDS — Bootstrap PHPUnit
 * Charge l'autoloader Composer et initialise l'environnement de test.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Définir les constantes de configuration pour les tests
// (surcharge les valeurs de config.php si celui-ci est chargé)
define('TESTING', true);

// Charger les helpers du backend sans démarrer de session
if (!defined('DB_HOST')) {
    define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
    define('DB_NAME',    $_ENV['DB_NAME']    ?? 'ccds_test');
    define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
    define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
    define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? 'test_secret_key_for_phpunit_only');
    define('JWT_EXPIRY', 3600);
    define('UPLOAD_DIR', sys_get_temp_dir() . '/ccds_test_uploads/');
    define('UPLOAD_URL', 'http://localhost/uploads/');
    define('MAX_FILE_SIZE', 5 * 1024 * 1024);
    define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
    define('CORS_ORIGIN', '*');
}

// Créer le dossier d'upload temporaire pour les tests
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
