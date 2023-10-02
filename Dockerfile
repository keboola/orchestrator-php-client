FROM php:5.6-cli

COPY docker/sources.list /etc/apt/sources.list

# Deps
RUN apt-get update -q \
  && apt-get install wget curl git bzip2 time libzip-dev unzip -y --no-install-recommends

# Composer
WORKDIR /root
RUN cd \
  && curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer

# Main
ADD . /code
WORKDIR /code
RUN echo "memory_limit = -1" >> /usr/local/etc/php/php.ini
RUN echo "date.timezone = \"Europe/Prague\"" >> /usr/local/etc/php/php.ini
RUN composer selfupdate && composer install --no-scripts --no-autoloader

RUN composer install
