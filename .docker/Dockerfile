FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    wget \
    gnupg \
    lsb-release \
    postgresql-client \
    libpq-dev \
    zip \
    unzip \
    gzip \
    git \
    curl \
    libicu-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    intl \
    zip

RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y libaio1t64 libmecab2 libtinfo6; \
    wget https://dev.mysql.com/get/Downloads/MySQL-8.0/mysql-community-client-plugins_8.0.40-1debian12_amd64.deb; \
    wget https://dev.mysql.com/get/Downloads/MySQL-8.0/mysql-community-client-core_8.0.40-1debian12_amd64.deb; \
    wget https://dev.mysql.com/get/Downloads/MySQL-8.0/mysql-community-client_8.0.40-1debian12_amd64.deb; \
    dpkg -i mysql-community-client-plugins_8.0.40-1debian12_amd64.deb || true; \
    dpkg -i mysql-community-client-core_8.0.40-1debian12_amd64.deb || true; \
    dpkg -i mysql-community-client_8.0.40-1debian12_amd64.deb || true; \
    apt-get install -f -y; \
    rm -f mysql-community-*.deb; \
    mysqldump --version; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    apt-get update; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug; \
    echo "xdebug.mode=develop,debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.max_nesting_level=500" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["bash", "-c", "tail -f /dev/null"]