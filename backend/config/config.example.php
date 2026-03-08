<?php
/**
 * Ma Commune — Configuration du Backend
 * Copiez ce fichier en config.php et renseignez vos valeurs.
 * IMPORTANT : Ne jamais commiter config.php sur Git.
 *
 * Pour générer un JWT_SECRET fort :
 *   openssl rand -base64 64
 */
// =============================================================
// Base de données
// =============================================================
define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME',     getenv('DB_NAME')     ?: 'ma_commune_db');
define('DB_USER',     getenv('DB_USER')     ?: 'ma_commune_user');
define('DB_PASSWORD', getenv('DB_PASS')     ?: 'CHANGEZ_CE_MOT_DE_PASSE');
define('DB_CHARSET',  'utf8mb4');
// =============================================================
// JWT (JSON Web Token)
// =============================================================
// Générez une clé secrète forte : openssl rand -base64 64
define('JWT_SECRET',     getenv('JWT_SECRET')  ?: 'CHANGEZ_CETTE_CLE_SECRETE_AVEC_UNE_VALEUR_ALEATOIRE_LONGUE');
define('JWT_EXPIRY',     86400);   // Durée de validité du token en secondes (24h)
define('JWT_ALGORITHM',  'HS256');
// =============================================================
// Upload de fichiers
// =============================================================
define('UPLOAD_DIR',      __DIR__ . '/../uploads/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5 Mo max par fichier
define('UPLOAD_ALLOWED',  ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('UPLOAD_BASE_URL', rtrim(getenv('APP_URL') ?: 'https://votre-domaine.com', '/') . '/uploads/');
// =============================================================
// Identité de l'application
// =============================================================
define('APP_NAME',             getenv('APP_NAME')             ?: 'Ma Commune');
define('APP_SHORT_NAME',       getenv('APP_SHORT_NAME')       ?: 'MaCommune');
define('APP_SLUG',             getenv('APP_SLUG')             ?: 'ma_commune');
define('APP_SUBTITLE',         getenv('APP_SUBTITLE')         ?: 'Votre commune — Administration');
define('APP_REFERENCE_PREFIX', getenv('APP_REFERENCE_PREFIX') ?: 'MC');
define('APP_EMAIL_FROM',       getenv('APP_EMAIL_FROM')       ?: 'noreply@votre-domaine.com');
define('APP_VERSION', '1.2.0');
define('APP_ENV',     getenv('APP_ENV')   ?: 'development'); // 'development' ou 'production'
define('APP_DEBUG',   (bool)(getenv('APP_DEBUG') ?: true));  // Mettre à false en production
define('APP_URL',     getenv('APP_URL')   ?: 'https://votre-domaine.com');
// =============================================================
// CORS (Cross-Origin Resource Sharing)
// =============================================================
// Origines autorisées à appeler l'API
// En production, remplacez '*' par votre domaine exact : 'https://votre-domaine.com'
define('CORS_ORIGINS', getenv('CORS_ORIGINS') ?: 'https://votre-domaine.com');
