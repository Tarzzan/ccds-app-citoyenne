FROM php:8.1-fpm-alpine

# ── Extensions PHP ────────────────────────────────────────────
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    oniguruma-dev \
    libzip-dev \
    icu-dev \
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

# ── Composer ──────────────────────────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ── Configuration PHP ─────────────────────────────────────────
RUN echo "upload_max_filesize = 20M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 25M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "max_execution_time = 60" >> /usr/local/etc/php/conf.d/uploads.ini

# ── Répertoire de travail ─────────────────────────────────────
WORKDIR /var/www/backend

# ── Droits sur les uploads ────────────────────────────────────
RUN mkdir -p /var/www/backend/uploads && \
    chown -R www-data:www-data /var/www/backend/uploads

EXPOSE 9000
CMD ["php-fpm"]
