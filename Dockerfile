FROM ubuntu:focal

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y \
        git \
        unzip \
        make \
        mysql-client \
    && rm -rf /var/lib/apt/lists/* \
    ;

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y \
        php-cli \
        php-curl \
        php-dom \
        php-iconv \
        php-intl \
        php-json \
        php-mbstring \
        php-mysql \
        php-sqlite3 \
        php-xdebug \
        php-xml \
        php-zip \
    && rm -rf /var/lib/apt/lists/* \
    ;

WORKDIR /source

ARG COMPOSER_PHAR_VERSION=1.9.1
ARG COMPOSER_PHAR_CHECKSUM=1f210b9037fcf82670d75892dfc44400f13fe9ada7af9e787f93e50e3b764111

WORKDIR /root

# install composer
RUN php -r "copy('https://getcomposer.org/download/${COMPOSER_PHAR_VERSION}/composer.phar', 'composer.phar');" ; \
    php -r "if (hash_file('sha256', 'composer.phar') === '${COMPOSER_PHAR_CHECKSUM}') { echo 'Installer verified', PHP_EOL; exit(0); } echo 'Installer corrupt', PHP_EOL; unlink('composer.phar'); exit(1);" ; \
    chmod +x composer.phar ; \
    mv composer.phar /usr/local/bin/composer

RUN echo 'error_reporting = E_ALL' > /etc/php/7.4/mods-available/error_reporting_all.ini ; \
    phpenmod error_reporting_all

WORKDIR /source

ENV PATH="/source/vendor/bin:${PATH}"
