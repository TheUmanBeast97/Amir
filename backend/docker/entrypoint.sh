#!/bin/sh
# Avvio del backend su un server. Ad ogni accensione:
#   1. collega al volume (/data) il database e i file scaricati
#   2. prepara il database (tabelle, squadra) e, se serve, crea il primo amministratore
#   3. al primissimo avvio scarica da XFive i dati pubblici (calendario, storico, stemmi, referti)
#   4. accende lo scheduler (aggiornamenti automatici) e il server
set -eu

cd "$(dirname "$0")/.."

DATA="${DATA_DIR:-/data}"
DB="${DB_DATABASE:-$DATA/database.sqlite}"
export DB_DATABASE="$DB"

mkdir -p "$DATA/storage/app/private" "$(dirname "$DB")" \
  storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# stemmi, foto e altri file scaricati vivono nel volume, non nell'immagine
rm -rf storage/app/private
ln -s "$DATA/storage/app/private" storage/app/private

# chiave di cifratura: se non è stata data, se ne crea una la prima volta e si conserva nel volume
if [ -z "${APP_KEY:-}" ]; then
  [ -s "$DATA/app.key" ] || php -r 'echo "base64:" . base64_encode(random_bytes(32));' > "$DATA/app.key"
  APP_KEY="$(cat "$DATA/app.key")"
  export APP_KEY
fi

[ -f "$DB" ] || { echo "Nuovo database in $DB"; : > "$DB"; }

php artisan migrate --force
php artisan db:seed --force

# primo amministratore: serve solo l'email e l'impronta (hash) della password, mai la password.
# Si genera con `php artisan amir:hash`. Se l'utente c'è già non cambia nulla, quindi le due variabili si possono togliere.
if [ -n "${AMIR_ADMIN_EMAIL:-}" ] && [ -n "${AMIR_ADMIN_HASH:-}" ]; then
  php artisan amir:admin "$AMIR_ADMIN_EMAIL" --hash="$AMIR_ADMIN_HASH" --if-missing || echo "Attenzione: amministratore non creato, controlla AMIR_ADMIN_HASH."
fi

# primo avvio: i dati pubblici si scaricano da XFive in sottofondo (qualche minuto), il sito intanto risponde.
# Il segno nel volume impedisce di rifarlo ad ogni riavvio; se si interrompe, riparte al prossimo avvio.
if [ ! -f "$DATA/.dati-xfive-importati" ] && [ "${AMIR_SKIP_IMPORT:-0}" != "1" ]; then
  (
    echo "Primo avvio: scarico da XFive calendario, storico, stemmi e referti..."
    if php artisan xfive:sync current && php artisan xfive:sync history && php artisan xfive:badges --all && php artisan xfive:matches; then
      : > "$DATA/.dati-xfive-importati"
      echo "Dati XFive importati."
    else
      echo "Importazione XFive non riuscita: si ripete al prossimo avvio."
    fi
  ) &
fi

# aggiornamenti automatici (calendario ogni 3 ore, stemmi, statistiche, referti)
php artisan schedule:work &

echo "Server in ascolto sulla porta ${PORT:-8080}"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}" --no-reload
