#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# CCDS — Script de démarrage Railway
# Génère config.php depuis les variables d'environnement, puis lance supervisord
# ─────────────────────────────────────────────────────────────────────────────

set -e

echo "🌿 CCDS — Démarrage du backend..."

# ── Générer config.php depuis les variables d'environnement ──────────────────
cat > /var/www/backend/config/config.php << EOF
<?php
/**
 * CCDS — Configuration de production (générée automatiquement au démarrage)
 * Ne pas modifier manuellement — éditer les variables d'environnement Railway.
 */

// =============================================================
// Base de données (variables Railway MySQL)
// =============================================================
define('DB_HOST',    getenv('MYSQLHOST')    ?: getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',    (int)(getenv('MYSQLPORT')    ?: getenv('DB_PORT')     ?: 3306));
define('DB_NAME',    getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'ccds_db');
define('DB_USER',    getenv('MYSQLUSER')    ?: getenv('DB_USER')     ?: 'root');
define('DB_PASSWORD',getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET', 'utf8mb4');

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

echo "✅ config.php généré"

# ── Vérifier la connexion à la base de données ────────────────────────────────
echo "🔌 Vérification de la connexion MySQL..."
MAX_RETRIES=30
RETRY=0
until php8.1 -r "
    \$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
    \$port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
    \$name = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'ccds_db';
    \$user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
    \$pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
    try {
        new PDO(\"mysql:host=\$host;port=\$port;dbname=\$name\", \$user, \$pass);
        echo 'OK';
    } catch (Exception \$e) {
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

# ── Lancer les migrations Phinx ───────────────────────────────────────────────
echo "🗄️  Exécution des migrations..."
cd /var/www/backend && \
    PHINX_DBHOST="${MYSQLHOST:-${DB_HOST:-localhost}}" \
    PHINX_DBPORT="${MYSQLPORT:-${DB_PORT:-3306}}" \
    PHINX_DBNAME="${MYSQLDATABASE:-${DB_NAME:-ccds_db}}" \
    PHINX_DBUSER="${MYSQLUSER:-${DB_USER:-root}}" \
    PHINX_DBPASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}" \
    vendor/bin/phinx migrate -e production 2>&1 || echo "⚠️  Migrations ignorées (peut-être déjà appliquées)"

# ── Lancer le seeder si première installation ─────────────────────────────────
echo "🌱 Vérification du seeder..."
cd /var/www/backend && \
    vendor/bin/phinx seed:run -e production 2>&1 || echo "⚠️  Seeder ignoré (données déjà présentes)"

# ── Configurer le port Nginx depuis $PORT (Railway) ─────────────────────────
PORT=${PORT:-80}
echo "🔧 Configuration Nginx sur le port $PORT..."
sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/http.d/default.conf

echo "🚀 Démarrage de Nginx + PHP-FPM..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
