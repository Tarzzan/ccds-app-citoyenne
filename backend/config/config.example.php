<?php
/**
 * CCDS — Configuration du Backend
 * Copiez ce fichier en config.php et renseignez vos valeurs.
 * IMPORTANT : Ne jamais commiter config.php sur Git.
 */

// =============================================================
// Base de données
// =============================================================
define('DB_HOST',     'localhost');
define('DB_PORT',     3306);
define('DB_NAME',     'ccds_db');
define('DB_USER',     'ccds_user');
define('DB_PASSWORD', 'VotreMotDePasseIci');
define('DB_CHARSET',  'utf8mb4');

// =============================================================
// JWT (JSON Web Token)
// =============================================================
// Générez une clé secrète forte : openssl rand -base64 64
define('JWT_SECRET',     'CHANGEZ_CETTE_CLE_SECRETE_AVEC_UNE_VALEUR_ALEATOIRE_LONGUE');
define('JWT_EXPIRY',     86400);   // Durée de validité du token en secondes (24h)
define('JWT_ALGORITHM',  'HS256');

// =============================================================
// Upload de fichiers
// =============================================================
define('UPLOAD_DIR',      __DIR__ . '/../uploads/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5 Mo max par fichier
define('UPLOAD_ALLOWED',  ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('UPLOAD_BASE_URL', 'https://votre-domaine.com/uploads/'); // URL publique des uploads

// =============================================================
// Application
// =============================================================
define('APP_NAME',    'CCDS — Application Citoyenne');
define('APP_VERSION', '1.0.0');
define('APP_ENV',     'development'); // 'development' ou 'production'
define('APP_DEBUG',   true);          // Mettre à false en production
define('APP_URL',     'https://votre-domaine.com');

// =============================================================
// CORS (Cross-Origin Resource Sharing)
// =============================================================
// Origines autorisées à appeler l'API (séparées par des virgules)
define('CORS_ORIGINS', '*'); // En production, remplacez par votre domaine exact
