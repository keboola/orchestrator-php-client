FROM php:5.6-cli

# Deps
RUN apt-get update
RUN apt-get install -y wget curl git bzip2 time libzip-dev unzip

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