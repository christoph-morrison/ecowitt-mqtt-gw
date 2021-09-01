FROM php:8.0-apache
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html/
RUN apt update && apt install git gnupg2 -y
RUN /usr/local/bin/composer require php-mqtt/client
