FROM php:8.3-apache

# pdo_pgsql  - PostgreSQL
# zip        - a Word (.docx) import bontja ki vele a fajlt (ZipArchive)
# gd         - a feltoltott kepek meretenek meghatarozasa
# A libpq-dev/libzip-dev a forditashoz kell, a futasideju konyvtarak (libpq5,
# libzip4) ezek fuggosegei - ezert NEM purge-oljuk, kulonben a pdo_pgsql nem
# talalja a libpq.so.5-ot.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql zip gd; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers

# A Word-fajlok nagyok lehetnek (kepekkel egyutt 50-100 MB is)
RUN { \
      echo 'upload_max_filesize = 128M'; \
      echo 'post_max_size = 128M'; \
      echo 'memory_limit = 512M'; \
      echo 'max_execution_time = 300'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/help.ini

COPY help.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p /srv/media
