FROM php:8.3-apache

# pdo_pgsql a PostgreSQL-hez, mod_rewrite a "minden kerest az index.php kap" routinghoz.
# A libpq-dev a forditashoz kell, a libpq5 (a fuggosege) futasidoben is - ezert
# NEM purge-oljuk, kulonben a pdo_pgsql nem talalja a libpq.so.5-ot.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers

COPY help.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p /srv/media
