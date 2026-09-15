FROM php:8.3-fpm

# pdo_mysql - MariaDB
# zip       - a Word (.docx) import bontja ki vele a fajlt (ZipArchive)
# gd        - a feltoltott kepek meretenek meghatarozasa
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev; \
    docker-php-ext-configure gd --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd; \
    rm -rf /var/lib/apt/lists/*

# PDF-motor a valodi (oldalszamos, futo lablecű) PDF-exporthoz.
# A bongeszo "Nyomtatas -> Mentes PDF-kent" utja enelkul is mukodik, de a
# bongeszo nem tud oldalszamot es futo lablecet tenni a lapokra.
RUN set -eux; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends weasyprint; \
    rm -rf /var/lib/apt/lists/*; \
    weasyprint --version

# A Word-fajlok nagyok lehetnek (a valodi utmutato kepekkel egyutt 27 MB),
# az oktatovideok pedig meg nagyobbak - ezert bo limitek.
RUN { \
      echo 'upload_max_filesize = 512M'; \
      echo 'post_max_size = 512M'; \
      echo 'memory_limit = 1024M'; \
      echo 'max_execution_time = 600'; \
      echo 'max_file_uploads = 60'; \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
    } > /usr/local/etc/php/conf.d/help.ini

RUN mkdir -p /srv/media && chown www-data:www-data /srv/media
