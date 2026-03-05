# ─────────────────────────────────────────────────────────────────────────────
# CCDS Citoyen — Dockerfile de production (Railway)
# PHP 8.1-FPM + Nginx dans un seul conteneur (supervisord)
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.1-fpm-alpine

# ── Dépendances système ───────────────────────────────────────────────────────
RUN apk add --no-cache \
    nginx \
    supervisor \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    oniguruma-dev \
    libzip-dev \
    icu-dev \
    curl \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        gd \
        zip \
        intl \
        opcache

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ── Configuration PHP ─────────────────────────────────────────────────────────
RUN echo "upload_max_filesize = 20M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 25M"    >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 256M"   >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 60" >> /usr/local/etc/php/conf.d/uploads.ini

# ── Répertoire de travail ─────────────────────────────────────────────────────
WORKDIR /var/www

# ── Copier le code source ─────────────────────────────────────────────────────
COPY backend/ ./backend/
COPY admin/    ./admin/

# ── Installer les dépendances Composer ───────────────────────────────────────
RUN cd /var/www/backend && \
    composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-interaction

# ── Créer les dossiers nécessaires ────────────────────────────────────────────
RUN mkdir -p /var/www/backend/uploads \
    && chown -R www-data:www-data /var/www/backend/uploads \
    && chmod 755 /var/www/backend/uploads

# ── Configuration Nginx ───────────────────────────────────────────────────────
COPY docker/nginx/railway.conf /etc/nginx/http.d/default.conf

# ── Configuration Supervisord ─────────────────────────────────────────────────
COPY docker/supervisord.conf /etc/supervisord.conf

# ── Script de démarrage ───────────────────────────────────────────────────────
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# ── Port exposé (Railway utilise $PORT) ──────────────────────────────────────
EXPOSE 80

CMD ["/start.sh"]
