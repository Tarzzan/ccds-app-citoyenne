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
define('UPLOAD_BASE_URL', rtrim(getenv('APP_URL') ?: 'https://api.netetfix.com', '/') . '/uploads/');
// =============================================================
// Identité de l'application
// =============================================================
define('APP_NAME',             getenv('APP_NAME')             ?: 'Ma Commune');
define('APP_SHORT_NAME',       getenv('APP_SHORT_NAME')       ?: 'MaCommune');
define('APP_SLUG',             getenv('APP_SLUG')             ?: 'ma_commune');
define('APP_SUBTITLE',         getenv('APP_SUBTITLE')         ?: 'Administration locale');
define('APP_TERRITORY_LABEL',  getenv('APP_TERRITORY_LABEL')  ?: 'Territoire');
define('APP_ADMIN_LOGIN_SUBTITLE', getenv('APP_ADMIN_LOGIN_SUBTITLE') ?: 'Territoire · poste de suivi public');
define('APP_THEME_VARIANT',    getenv('APP_THEME_VARIANT')    ?: 'neutral-civic');
define('APP_THEME_PRIMARY',    getenv('APP_THEME_PRIMARY')    ?: '#355160');
define('APP_THEME_PRIMARY_DARK', getenv('APP_THEME_PRIMARY_DARK') ?: '#223743');
define('APP_THEME_PRIMARY_LIGHT', getenv('APP_THEME_PRIMARY_LIGHT') ?: '#e7eef1');
define('APP_THEME_SECONDARY',  getenv('APP_THEME_SECONDARY')  ?: '#718892');
define('APP_THEME_ACCENT',     getenv('APP_THEME_ACCENT')     ?: '#c3a166');
define('APP_REFERENCE_PREFIX', getenv('APP_REFERENCE_PREFIX') ?: 'MC');
define('APP_EMAIL_FROM',       getenv('APP_EMAIL_FROM')       ?: 'noreply@netetfix.com');
define('APP_VERSION', '1.2.0');
define('APP_ENV',     getenv('APP_ENV')   ?: 'development'); // 'development' ou 'production'
define('APP_DEBUG',   (bool)(getenv('APP_DEBUG') ?: true));  // Mettre à false en production
define('APP_URL',     getenv('APP_URL')   ?: 'https://api.netetfix.com');
// =============================================================
// CORS (Cross-Origin Resource Sharing)
// =============================================================
// Origines autorisées à appeler l'API
// En production, remplacez '*' par vos domaines exacts :
// 'https://netetfix.com,https://admin.netetfix.com'
define('CORS_ORIGINS', getenv('CORS_ORIGINS') ?: 'https://netetfix.com,https://admin.netetfix.com');
