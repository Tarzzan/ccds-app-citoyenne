# Guide de Déploiement Serveur — CCDS App Citoyenne

> **Stack :** Apache 2.4 + PHP 8.1 + MySQL 8.0 | **OS cible :** Ubuntu 22.04 LTS

Ce guide décrit la procédure complète pour déployer l'API REST et le back-office d'administration sur un serveur dédié ou VPS. Chaque étape est accompagnée des commandes exactes à exécuter.

---

## 1. Prérequis Serveur

### 1.1 Configuration minimale recommandée

| Ressource | Minimum | Recommandé |
|---|---|---|
| CPU | 1 vCPU | 2 vCPU |
| RAM | 1 Go | 2 Go |
| Disque | 20 Go SSD | 40 Go SSD |
| Bande passante | 100 Mbps | 1 Gbps |
| OS | Ubuntu 20.04 LTS | Ubuntu 22.04 LTS |

### 1.2 Ports à ouvrir dans le pare-feu

```bash
sudo ufw allow 22/tcp    # SSH
sudo ufw allow 80/tcp    # HTTP
sudo ufw allow 443/tcp   # HTTPS (SSL)
sudo ufw enable
sudo ufw status
```

---

## 2. Installation de la Stack LAMP

### 2.1 Mise à jour du système

```bash
sudo apt-get update && sudo apt-get upgrade -y
```

### 2.2 Installation d'Apache

```bash
sudo apt-get install -y apache2
sudo systemctl enable apache2
sudo systemctl start apache2

# Activer les modules nécessaires
sudo a2enmod rewrite headers ssl expires deflate
sudo systemctl restart apache2
```

### 2.3 Installation de PHP 8.1

```bash
sudo apt-get install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt-get update
sudo apt-get install -y php8.1 php8.1-mysql php8.1-mbstring php8.1-xml \
    php8.1-curl php8.1-gd php8.1-zip php8.1-intl php8.1-opcache

# Vérifier la version
php -v
```

### 2.4 Configuration PHP pour la production

```bash
sudo nano /etc/php/8.1/apache2/php.ini
```

Modifier les valeurs suivantes :

```ini
; Sécurité
expose_php = Off
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log

; Performance
memory_limit = 256M
max_execution_time = 30
max_input_time = 60

; Upload (photos des signalements)
upload_max_filesize = 10M
post_max_size = 12M
max_file_uploads = 5

; OPcache (performances)
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
```

```bash
sudo mkdir -p /var/log/php && sudo chown www-data:www-data /var/log/php
sudo systemctl restart apache2
```

### 2.5 Installation de MySQL 8.0

```bash
sudo apt-get install -y mysql-server
sudo systemctl enable mysql
sudo mysql_secure_installation  # Suivre les instructions interactives
```

---

## 3. Création de la Base de Données

```bash
sudo mysql -u root -p
```

```sql
-- Créer la base de données et l'utilisateur applicatif
CREATE DATABASE ccds_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ccds_user'@'localhost' IDENTIFIED BY 'VOTRE_MOT_DE_PASSE_FORT';
GRANT ALL PRIVILEGES ON ccds_production.* TO 'ccds_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

```bash
# Importer le schéma
mysql -u ccds_user -p ccds_production < /var/www/ccds/docs/database.sql
```

---

## 4. Déploiement du Code

### 4.1 Cloner le dépôt GitHub

```bash
cd /var/www
sudo git clone https://github.com/Tarzzan/ccds-app-citoyenne.git ccds
sudo chown -R www-data:www-data /var/www/ccds
```

### 4.2 Configurer l'application

```bash
# Copier et éditer le fichier de configuration
sudo cp /var/www/ccds/backend/config/config.example.php /var/www/ccds/backend/config/config.php
sudo nano /var/www/ccds/backend/config/config.php
```

Renseigner les valeurs de production :

```php
<?php
// Base de données
define('DB_HOST',    'localhost');
define('DB_NAME',    'ccds_production');
define('DB_USER',    'ccds_user');
define('DB_PASS',    'VOTRE_MOT_DE_PASSE_FORT');

// JWT — Générer avec : openssl rand -hex 64
define('JWT_SECRET', 'VOTRE_CLE_JWT_ALEATOIRE_LONGUE_ET_SECURISEE');
define('JWT_EXPIRY', 86400); // 24 heures

// Upload
define('UPLOAD_DIR', '/var/www/ccds/backend/uploads/');
define('UPLOAD_URL', 'https://votre-domaine.fr/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 Mo

// CORS — Remplacer par le domaine de l'app mobile en production
define('CORS_ORIGIN', '*');
```

### 4.3 Créer et sécuriser le dossier d'uploads

```bash
sudo mkdir -p /var/www/ccds/backend/uploads
sudo chown www-data:www-data /var/www/ccds/backend/uploads
sudo chmod 755 /var/www/ccds/backend/uploads

# Protéger contre l'exécution de scripts dans uploads
echo "Options -Indexes
<FilesMatch '\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi)$'>
    Deny from all
</FilesMatch>" | sudo tee /var/www/ccds/backend/uploads/.htaccess
```

---

## 5. Configuration Apache (VirtualHost)

### 5.1 VirtualHost HTTP (port 80)

```bash
sudo nano /etc/apache2/sites-available/ccds.conf
```

```apache
<VirtualHost *:80>
    ServerName votre-domaine.fr
    ServerAlias www.votre-domaine.fr

    # Redirection HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName votre-domaine.fr
    ServerAlias www.votre-domaine.fr
    DocumentRoot /var/www/ccds

    # SSL (Let's Encrypt — voir section 6)
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/votre-domaine.fr/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/votre-domaine.fr/privkey.pem

    # En-têtes de sécurité
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

    # API REST (/api/*)
    <Directory /var/www/ccds/backend>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Back-Office Admin (/admin/*)
    <Directory /var/www/ccds/admin>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Restriction d'accès par IP (recommandé)
        # Require ip 192.168.1.0/24
    </Directory>

    # Uploads (lecture seule)
    <Directory /var/www/ccds/backend/uploads>
        Options -Indexes -ExecCGI
        AllowOverride None
        Require all granted
    </Directory>

    # Logs
    ErrorLog  ${APACHE_LOG_DIR}/ccds_error.log
    CustomLog ${APACHE_LOG_DIR}/ccds_access.log combined

    # Compression Gzip
    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE application/json text/html text/css application/javascript
    </IfModule>
</VirtualHost>
```

```bash
sudo a2ensite ccds.conf
sudo a2dissite 000-default.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## 6. Certificat SSL (Let's Encrypt)

```bash
sudo apt-get install -y certbot python3-certbot-apache
sudo certbot --apache -d votre-domaine.fr -d www.votre-domaine.fr

# Renouvellement automatique (déjà configuré par Certbot)
sudo certbot renew --dry-run
```

---

## 7. Script de Déploiement Automatisé

Créer le script `/var/www/ccds/deploy.sh` pour les mises à jour futures :

```bash
sudo nano /var/www/ccds/deploy.sh
```

```bash
#!/bin/bash
# ============================================================
# CCDS — Script de déploiement automatisé
# Usage : sudo bash /var/www/ccds/deploy.sh
# ============================================================

set -e  # Arrêter en cas d'erreur

DEPLOY_DIR="/var/www/ccds"
BACKUP_DIR="/var/backups/ccds"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

echo "🚀 [CCDS Deploy] Démarrage du déploiement — $TIMESTAMP"

# 1. Sauvegarde de la base de données
echo "📦 Sauvegarde de la base de données..."
mkdir -p "$BACKUP_DIR"
mysqldump -u ccds_user -p"$DB_PASS" ccds_production > "$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
echo "   ✅ Sauvegarde créée : db_backup_$TIMESTAMP.sql"

# 2. Sauvegarde du code actuel
echo "📦 Sauvegarde du code..."
tar -czf "$BACKUP_DIR/code_backup_$TIMESTAMP.tar.gz" \
    --exclude="$DEPLOY_DIR/vendor" \
    --exclude="$DEPLOY_DIR/backend/uploads" \
    "$DEPLOY_DIR"
echo "   ✅ Sauvegarde code créée"

# 3. Mise à jour du code depuis GitHub
echo "📥 Récupération des dernières modifications..."
cd "$DEPLOY_DIR"
git fetch origin main
git reset --hard origin/main
echo "   ✅ Code mis à jour"

# 4. Correction des permissions
echo "🔒 Application des permissions..."
chown -R www-data:www-data "$DEPLOY_DIR"
chmod -R 755 "$DEPLOY_DIR"
chmod -R 775 "$DEPLOY_DIR/backend/uploads"
chmod 640 "$DEPLOY_DIR/backend/config/config.php"
echo "   ✅ Permissions appliquées"

# 5. Vider le cache OPcache
echo "🔄 Vidage du cache PHP..."
php -r "opcache_reset();" 2>/dev/null || true
echo "   ✅ Cache vidé"

# 6. Rechargement Apache
echo "🔄 Rechargement d'Apache..."
systemctl reload apache2
echo "   ✅ Apache rechargé"

# 7. Nettoyage des anciennes sauvegardes (garder 7 jours)
find "$BACKUP_DIR" -name "*.sql" -mtime +7 -delete
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +7 -delete

echo ""
echo "✅ [CCDS Deploy] Déploiement terminé avec succès !"
echo "   URL API    : https://votre-domaine.fr/api/"
echo "   URL Admin  : https://votre-domaine.fr/admin/"
```

```bash
sudo chmod +x /var/www/ccds/deploy.sh
```

---

## 8. Vérifications Post-Déploiement

Exécuter ces vérifications après chaque déploiement :

```bash
# Vérifier qu'Apache est actif
sudo systemctl status apache2

# Tester l'API
curl -s https://votre-domaine.fr/api/categories | python3 -m json.tool

# Vérifier les logs d'erreur
sudo tail -50 /var/log/apache2/ccds_error.log

# Vérifier les permissions
ls -la /var/www/ccds/backend/uploads/
ls -la /var/www/ccds/backend/config/config.php
```

---

## 9. Sécurité Complémentaire

### 9.1 Fail2Ban (protection contre les attaques par force brute)

```bash
sudo apt-get install -y fail2ban
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

### 9.2 Sauvegarde automatique quotidienne (cron)

```bash
sudo crontab -e
```

Ajouter :
```
# Sauvegarde BDD CCDS tous les jours à 2h du matin
0 2 * * * mysqldump -u ccds_user -pVOTRE_MOT_DE_PASSE ccds_production | gzip > /var/backups/ccds/daily_$(date +\%Y\%m\%d).sql.gz
```

### 9.3 Monitoring avec logwatch

```bash
sudo apt-get install -y logwatch
sudo logwatch --output mail --mailto admin@votre-domaine.fr --detail high
```

---

*Document maintenu par l'équipe CCDS — Mettre à jour à chaque changement d'infrastructure.*
