FROM php:8.2-fpm-alpine

# Install build dependencies and system libraries for GD & pdo_mysql
RUN apk add --no-cache \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    bash

# Configure and install GD and other extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        mbstring \
        opcache \
        intl \
        zip

# Set working directory
WORKDIR /var/www/html

# Adjust permissions for uploads
RUN mkdir -p /var/www/html/uploads/admins \
             /var/www/html/uploads/staff \
             /var/www/html/uploads/students \
    && chown -R www-data:www-data /var/www/html

CMD ["php-fpm"]
