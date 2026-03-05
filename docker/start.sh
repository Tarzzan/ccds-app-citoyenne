#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# CCDS — Script de démarrage Railway
# Génère config.php depuis les variables d'environnement, puis lance supervisord
# Variables Railway attendues : DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
# ─────────────────────────────────────────────────────────────────────────────

set -e

echo "🌿 CCDS — Démarrage du backend..."

# ── Résoudre les variables de connexion (DB_PASS ou DB_PASSWORD) ─────────────
RESOLVED_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
RESOLVED_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
RESOLVED_NAME="${MYSQLDATABASE:-${DB_NAME:-ccds_db}}"
RESOLVED_USER="${MYSQLUSER:-${DB_USER:-root}}"
RESOLVED_PASS="${MYSQLPASSWORD:-${DB_PASS:-${DB_PASSWORD:-}}}"

# ── Générer config.php depuis les variables d'environnement ──────────────────
cat > /var/www/backend/config/config.php << EOF
<?php
/**
 * CCDS — Configuration de production (générée automatiquement au démarrage)
 * Ne pas modifier manuellement — éditer les variables d'environnement Railway.
 */

// =============================================================
// Base de données
// =============================================================
define('DB_HOST',     '${RESOLVED_HOST}');
define('DB_PORT',     ${RESOLVED_PORT});
define('DB_NAME',     '${RESOLVED_NAME}');
define('DB_USER',     '${RESOLVED_USER}');
define('DB_PASSWORD', '${RESOLVED_PASS}');
define('DB_CHARSET',  'utf8mb4');

// =============================================================
// JWT
// =============================================================
define('JWT_SECRET',    getenv('JWT_SECRET')    ?: 'changez-cette-cle-en-production-railway');
define('JWT_EXPIRY',    (int)(getenv('JWT_EXPIRY')    ?: 86400));
define('JWT_ALGORITHM', 'HS256');

// =============================================================
// Upload de fichiers
// =============================================================
define('UPLOAD_DIR',      '/var/www/backend/uploads/');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('UPLOAD_ALLOWED',  ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('UPLOAD_BASE_URL', getenv('APP_URL') ? getenv('APP_URL') . '/uploads/' : 'https://ccds-app-citoyenne-production.up.railway.app/uploads/');

// =============================================================
// Application
// =============================================================
define('APP_NAME',    'CCDS — Application Citoyenne');
define('APP_VERSION', '1.6.2');
define('APP_ENV',     'production');
define('APP_DEBUG',   false);
define('APP_URL',     getenv('APP_URL') ?: 'https://ccds-app-citoyenne-production.up.railway.app');

// =============================================================
// CORS
// =============================================================
define('CORS_ORIGINS', getenv('CORS_ORIGINS') ?: '*');

// =============================================================
// Push Notifications (Expo)
// =============================================================
define('EXPO_ACCESS_TOKEN', getenv('EXPO_ACCESS_TOKEN') ?: '');
EOF

echo "✅ config.php généré (host=${RESOLVED_HOST}, db=${RESOLVED_NAME}, user=${RESOLVED_USER})"

# ── Vérifier la connexion à la base de données ────────────────────────────────
echo "🔌 Vérification de la connexion MySQL (${RESOLVED_HOST}:${RESOLVED_PORT})..."
MAX_RETRIES=30
RETRY=0
until php8.1 -r "
    try {
        new PDO('mysql:host=${RESOLVED_HOST};port=${RESOLVED_PORT};dbname=${RESOLVED_NAME}', '${RESOLVED_USER}', '${RESOLVED_PASS}');
        echo 'OK';
    } catch (Exception \$e) {
        fwrite(STDERR, \$e->getMessage() . PHP_EOL);
        exit(1);
    }
" 2>/dev/null | grep -q "OK"; do
    RETRY=$((RETRY + 1))
    if [ $RETRY -ge $MAX_RETRIES ]; then
        echo "⚠️  MySQL non disponible après $MAX_RETRIES tentatives — démarrage quand même"
        break
    fi
    echo "⏳ Attente MySQL... ($RETRY/$MAX_RETRIES)"
    sleep 2
done

# ── Initialiser phinxlog si nécessaire ────────────────────────────────────────
# Si phinxlog n'existe pas mais que les tables existent déjà (migration manuelle),
# on crée phinxlog et on marque toutes les migrations précédentes comme appliquées.
echo "📋 Vérification de l'état des migrations Phinx..."
php8.1 -r "
    try {
        \$pdo = new PDO('mysql:host=${RESOLVED_HOST};port=${RESOLVED_PORT};dbname=${RESOLVED_NAME}', '${RESOLVED_USER}', '${RESOLVED_PASS}');
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Vérifier si phinxlog existe
        \$tables = \$pdo->query(\"SHOW TABLES LIKE 'phinxlog'\")->fetchAll();
        if (empty(\$tables)) {
            // Créer phinxlog
            \$pdo->exec(\"CREATE TABLE IF NOT EXISTS phinxlog (
                version BIGINT NOT NULL,
                migration_name VARCHAR(100) DEFAULT NULL,
                start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                end_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                breakpoint TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\");

            // Marquer les migrations déjà appliquées (tables existent déjà)
            \$migrations = [
                [20260101000001, 'InitialSchema'],
                [20260304000002, 'V11VotesPushNotifications'],
                [20260304000003, 'V12TwoFactorAuth'],
                [20260304000004, 'V13GamificationI18n'],
                [20260304000005, 'V14PhotosCommentsThreading'],
                [20260304000006, 'V15TwoFactorAuth'],
                [20260304000007, 'V16WebhooksPollsEvents'],
            ];
            \$stmt = \$pdo->prepare(\"INSERT IGNORE INTO phinxlog (version, migration_name, start_time, end_time) VALUES (?, ?, NOW(), NOW())\");
            foreach (\$migrations as \$m) {
                // Vérifier si la table users existe (migrations déjà appliquées)
                \$exists = \$pdo->query(\"SHOW TABLES LIKE 'users'\")->fetchAll();
                if (!empty(\$exists)) {
                    \$stmt->execute([\$m[0], \$m[1]]);
                    echo 'Marqué migration ' . \$m[0] . ' comme appliquée' . PHP_EOL;
                }
            }
            echo 'phinxlog initialisé avec les migrations existantes' . PHP_EOL;
        } else {
            echo 'phinxlog existe déjà' . PHP_EOL;
        }
    } catch (Exception \$e) {
        echo 'Erreur phinxlog: ' . \$e->getMessage() . PHP_EOL;
    }
" 2>&1

# ── Lancer les migrations Phinx ───────────────────────────────────────────────
echo "🗄️  Exécution des migrations..."
cd /var/www/backend && \
    PHINX_DBHOST="${RESOLVED_HOST}" \
    PHINX_DBPORT="${RESOLVED_PORT}" \
    PHINX_DBNAME="${RESOLVED_NAME}" \
    PHINX_DBUSER="${RESOLVED_USER}" \
    PHINX_DBPASS="${RESOLVED_PASS}" \
    vendor/bin/phinx migrate -e production 2>&1 || echo "⚠️  Migrations ignorées (peut-être déjà appliquées)"

# ── Lancer le seeder si première installation ─────────────────────────────────
echo "🌱 Vérification du seeder..."
cd /var/www/backend && \
    PHINX_DBHOST="${RESOLVED_HOST}" \
    PHINX_DBPORT="${RESOLVED_PORT}" \
    PHINX_DBNAME="${RESOLVED_NAME}" \
    PHINX_DBUSER="${RESOLVED_USER}" \
    PHINX_DBPASS="${RESOLVED_PASS}" \
    vendor/bin/phinx seed:run -e production 2>&1 || echo "⚠️  Seeder ignoré (données déjà présentes)"

echo "🚀 Démarrage de Nginx + PHP-FPM (port 80)..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
