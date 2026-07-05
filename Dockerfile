FROM php:8.3-apache

# README states PHP 8.1+ as the minimum; pin to php:8.1-apache instead if you
# need to match an older legacy host exactly.

# pdo_mysql needs no extra apt packages (built against the bundled mysqlnd
# driver). curl/mbstring need headers at build time only -- purge just the
# named -dev/header packages afterwards, WITHOUT --auto-remove, so the
# runtime shared libs (libcurl4, libonig5) that curl.so/mbstring.so link
# against at runtime are not swept away too.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql curl mbstring \
    && apt-get purge -y libcurl4-openssl-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/*
# ext-openssl ships enabled by default in the official php image.

RUN a2enmod headers expires
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html
# config.php / .installed are intentionally excluded via .dockerignore --
# bin/docker-install.php generates them at runtime into the /config volume.

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
