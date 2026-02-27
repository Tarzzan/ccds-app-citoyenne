# Guide de Déploiement Serveur — CCDS Citoyen

> **Public Cible :** Administrateur Système
> **Objectif :** Installer, configurer et sécuriser le backend et le back-office de l'application CCDS Citoyen sur un serveur de production.

---

## 1. Prérequis

Ce guide suppose que vous opérez sur un serveur **Ubuntu 22.04 LTS** avec un accès `root` ou `sudo`.

### 1.1. Stack Technique

Assurez-vous que les composants suivants sont installés et fonctionnels :

| Composant | Version | Commande d'installation |
|---|---|---|
| **Apache** | 2.4+ | `sudo apt install apache2` |
| **MySQL** | 8.0+ | `sudo apt install mysql-server` |
| **PHP** | 8.1+ | `sudo apt install php8.1 libapache2-mod-php8.1 php8.1-mysql php8.1-mbstring php8.1-xml php8.1-curl` |
| **Git** | 2.34+ | `sudo apt install git` |
| **Composer** | 2.x | `sudo apt install composer` |
| **Certbot** | (Let's Encrypt) | `sudo apt install certbot python3-certbot-apache` |

### 1.2. Utilisateur de Déploiement

Pour des raisons de sécurité, il est recommandé de ne pas utiliser l'utilisateur `root`. Créez un utilisateur dédié pour le déploiement :

```bash
sudo adduser deploy
sudo usermod -aG www-data deploy
```

---

## 2. Script d'Installation Automatisé

Ce script `deploy.sh` clone le dépôt, configure la base de données, installe les dépendances et définit les permissions.

Créez le fichier `deploy.sh` :

```bash
#!/bin/bash
set -e

# --- Variables (à personnaliser) ---
DB_NAME="ccds_prod"
DB_USER="ccds_user"
DB_PASS="CHANGEME_password_solide"
APP_DIR="/var/www/ccds-app-citoyenne"
REPO_URL="https://github.com/Tarzzan/ccds-app-citoyenne.git"

# --- Déploiement ---
echo "[1/5] Clonage du dépôt..."
git clone "$REPO_URL" "$APP_DIR"
cd "$APP_DIR"

echo "[2/5] Configuration de la base de données..."
mysql -u root -p <<MYSQL_SCRIPT
CREATE DATABASE $DB_NAME;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
MYSQL_SCRIPT

# Importer le schéma de base
mysql -u $DB_USER -p$DB_PASS $DB_NAME < docs/database.sql

echo "[3/5] Installation des dépendances Composer..."
composer install --no-dev --optimize-autoloader

echo "[4/5] Création des fichiers de configuration..."
# Backend API
cp backend/config/config.example.php backend/config/config.php
sed -i "s/'DB_NAME', 'ccds'/'DB_NAME', '$DB_NAME'/" backend/config/config.php
sed -i "s/'DB_USER', 'root'/'DB_USER', '$DB_USER'/" backend/config/config.php
sed -i "s/'DB_PASS', ''/'DB_PASS', '$DB_PASS'/" backend/config/config.php
sed -i "s/'JWT_SECRET', 'your-secret-key'/'JWT_SECRET', '$(openssl rand -hex 32)'/" backend/config/config.php

# Back-Office Admin
cp admin/includes/config.example.php admin/includes/config.php

echo "[5/5] Définition des permissions..."
sudo chown -R www-data:www-data "$APP_DIR"
sudo find "$APP_DIR" -type f -exec chmod 664 {} \;
sudo find "$APP_DIR" -type d -exec chmod 775 {} \;
sudo chmod -R 775 "$APP_DIR/backend/uploads"

echo "\n✅ Déploiement terminé !"
echo "N'oubliez pas de configurer votre VirtualHost Apache."
```

**Utilisation :**
1.  Rendez le script exécutable : `chmod +x deploy.sh`
2.  Lancez-le : `sudo ./deploy.sh`

---

## 3. Configuration Apache & SSL

Créez un fichier de configuration VirtualHost pour le back-office.

**Fichier :** `/etc/apache2/sites-available/admin.ccds-guyane.fr.conf`

```apache
<VirtualHost *:80>
    ServerName admin.ccds-guyane.fr
    DocumentRoot /var/www/ccds-app-citoyenne/admin

    <Directory /var/www/ccds-app-citoyenne/admin>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
```

**Activation :**

```bash
# Activer le site et le module rewrite
sudo a2ensite admin.ccds-guyane.fr
sudo a2enmod rewrite
sudo systemctl restart apache2

# Générer le certificat SSL avec Let's Encrypt
sudo certbot --apache -d admin.ccds-guyane.fr
```

Certbot modifiera automatiquement votre configuration pour gérer la redirection HTTPS.

---

## 4. Sécurité Post-Installation

### 4.1. Fail2Ban

Pour protéger votre serveur contre les attaques par force brute, installez et configurez Fail2Ban :

```bash
sudo apt install fail2ban
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

Créez un fichier de configuration local pour surveiller les tentatives de connexion SSH et Apache :

**Fichier :** `/etc/fail2ban/jail.local`

```ini
[DEFAULT]
bantime = 1h

[sshd]
enabled = true

[apache-auth]
enabled = true
```

Relancez Fail2Ban : `sudo systemctl restart fail2ban`

### 4.2. Sauvegardes Nocturnes

Configurez une tâche cron pour sauvegarder la base de données chaque nuit à 2h du matin.

```bash
# Ouvrir l'éditeur de cron
sudo crontab -e

# Ajouter cette ligne (adaptez les chemins et identifiants)
0 2 * * * /usr/bin/mysqldump -u ccds_user -p'CHANGEME_password_solide' ccds_prod | gzip > /var/backups/ccds_db_$(date +\%Y-\%m-\%d).sql.gz
```

---

## 5. Procédure de Mise à Jour

Pour mettre à jour l'application avec les dernières modifications du dépôt Git :

```bash
cd /var/www/ccds-app-citoyenne

# Récupérer les dernières modifications
sudo -u deploy git pull

# Mettre à jour les dépendances PHP
sudo -u deploy composer install --no-dev --optimize-autoloader

# Redéfinir les permissions au cas où
sudo chown -R www-data:www-data .
sudo find . -type f -exec chmod 664 {} \;
sudo find . -type d -exec chmod 775 {} \;
sudo chmod -R 775 backend/uploads

echo "Mise à jour terminée."
```
