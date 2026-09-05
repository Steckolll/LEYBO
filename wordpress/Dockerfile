# PHP 8.2 + Apache для локального стенда leybo.store
# Требования WooCommerce 10.7 / WP 7.1: mysqli, gd, intl, mbstring, zip, curl, exif, imagick(опц.), xml
FROM php:8.2-apache

# ---- системные пакеты ----
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    libsodium-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo_mysql \
        gd \
        intl \
        mbstring \
        zip \
        exif \
        bcmath \
        opcache \
        soap \
        xml \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

# ---- настройки PHP для WP (распространяемо: шут макс)
RUN { \
      echo 'upload_max_filesize = 128M'; \
      echo 'post_max_size = 128M'; \
      echo 'memory_limit = 256M'; \
      echo 'max_execution_time = 300'; \
      echo 'max_input_vars = 6000'; \
    } > /usr/local/etc/php/conf.d/wp.ini

# Opcache
RUN { \
      echo 'opcache.enable = 1'; \
      echo 'opcache.enable_cli = 0'; \
      echo 'opcache.memory_consumption = 128'; \
      echo 'opcache.interned_strings_buffer = 16'; \
      echo 'opcache.max_accelerated_files = 20000'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html
