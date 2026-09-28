FROM php:8.3-apache

RUN docker-php-ext-install mysqli

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["bash", "-lc", "a2dismod mpm_event mpm_worker 2>/dev/null || true; rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*; a2enmod mpm_prefork; apache2ctl -t; exec apache2-foreground"]
