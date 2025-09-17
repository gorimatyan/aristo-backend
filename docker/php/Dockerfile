FROM php:8.3-fpm-bullseye
EXPOSE 5173

ENV TZ=Asia/Tokyo \
    # locale
    LANG=ja_JP.UTF-8 \
    LANGUAGE=ja_JP:ja \
    LC_ALL=ja_JP.UTF-8 \
    # composer environment
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer

COPY --from=composer:2.3 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get -y install --no-install-recommends \
    locales \
    git \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    unzip \
    vim \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && locale-gen ja_JP.UTF-8 \
    && localedef -f UTF-8 -i ja_JP ja_JP.UTF-8 \
    && docker-php-ext-install \
    bcmath \
    intl \
    pdo_mysql \
    zip \
    && composer config -g process-timeout 3600 \
    && composer config -g repos.packagist composer https://packagist.org

RUN docker-php-ext-install pcntl
RUN docker-php-ext-configure pcntl --enable-pcntl
RUN curl -fsSL https://deb.nodesource.com/setup_current.x | bash -
RUN apt-get install -y nodejs
# phpredis
RUN pecl install redis && \
    docker-php-ext-enable redis
RUN apt install -y --no-install-recommends default-mysql-client libmariadb-dev

COPY . /var/www/aristo-backend
COPY ./docker/php/php.ini /usr/local/etc/php/php.ini

WORKDIR /var/www/aristo-backend
RUN composer update && \
    composer install --optimize-autoloader --no-dev && \
    chown -R www-data:www-data . && \
    chmod -R 777 ./