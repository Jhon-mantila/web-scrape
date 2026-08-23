FROM php:8.3-apache

# Instalar dependencias
RUN apt-get update && apt-get install -y \
    git curl zip unzip curl libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    libonig-dev libxml2-dev \
    nodejs npm \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip

# Activar mod_rewrite (Laravel)
RUN a2enmod rewrite

# Importante para Laravel
RUN chown -R www-data:www-data /var/www/html

# Configurar Apache para Laravel
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html