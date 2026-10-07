FROM php:8.1-apache

# Installation de pdo_mysql
RUN docker-php-ext-install pdo pdo_mysql

# Activation du module rewrite Apache
RUN a2enmod rewrite

# Configuration du répertoire de travail
WORKDIR /var/www/html