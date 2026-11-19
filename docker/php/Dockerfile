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

# A munkamenetek KULON KOTETBEN: igy egy frissites (a kontener ujraindulasa) nem
# jelentkezteti ki a szerkesztoket, es nem vesz el a felbehagyott munkajuk.
RUN mkdir -p /srv/munkamenet && chown www-data:www-data /srv/munkamenet && chmod 1733 /srv/munkamenet \
    && echo 'session.save_path = "/srv/munkamenet"' > /usr/local/etc/php/conf.d/munkamenet.ini

# A kornyezeti valtozok (HELP_DB_*, APP_COMMIT) eljussanak a PHP-hoz.
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-env.conf

# Tobb worker, mint a kep alapertelmezett 5-je: a gepi forditas percekig foglal egyet,
# es enelkul az egesz oldal sorba all. Indoklas a fajl elejen.
COPY zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

# A VERZIO, amit ez a kepfajl kiszolgal. Az eles frissito (deploy/sugo-frissito.py)
# tolti ki; a Frissites lap es az ellenorzes ebbol tudja, mi fut. Szandekosan az
# utolso reteg: igy a valtozasa nem epiti ujra a fenti, lassu retegeket.
ARG APP_COMMIT=""
ARG APP_BUILT_AT=""
ENV APP_COMMIT=${APP_COMMIT} APP_BUILT_AT=${APP_BUILT_AT}
