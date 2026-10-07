# Backend di AMIR Team Manager (API Laravel + database SQLite) pronto per un server.
#
# Il database, gli stemmi e le foto scaricate stanno nella cartella /data: sopra va montato un volume persistente,
# altrimenti a ogni nuova versione i dati ripartirebbero da zero. Vedi docs/DEPLOY.md.
FROM php:8.3-cli-bookworm

# le estensioni che servono (sqlite, mbstring, dom, curl, tokenizer...) sono già nell'immagine ufficiale di PHP
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

# prima solo le dipendenze, così una modifica al codice non le riscarica
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && cp docker/php.ini /usr/local/etc/php/conf.d/zz-amir.ini \
    && chmod +x docker/entrypoint.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=sync \
    FILESYSTEM_DISK=local \
    DATA_DIR=/data \
    PHP_CLI_SERVER_WORKERS=4 \
    PORT=8080

EXPOSE 8080
CMD ["/app/docker/entrypoint.sh"]
