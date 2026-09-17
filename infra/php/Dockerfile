FROM php:8.2-fpm-alpine

RUN set -eux; \
    apk add --no-cache \
        freetype \
        libjpeg-turbo \
        libpng \
        libwebp \
        mariadb-connector-c \
        oniguruma \
        tzdata; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        linux-headers \
        mariadb-connector-c-dev \
        oniguruma-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(getconf _NPROCESSORS_ONLN)" gd mbstring opcache pdo_mysql; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps

COPY infra/php/php.ini /usr/local/etc/php/conf.d/99-production.ini
COPY infra/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY app/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
USER www-data

EXPOSE 9000
CMD ["php-fpm", "-F"]
