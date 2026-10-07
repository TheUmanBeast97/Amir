# AMIR Team Manager

Gestionale e sito per **AMIR COSTRUZIONI** (calcio a 8 e a 7, campionati XFive di Alessandria).
Prende da XFive calendario, risultati e storico; ci aggiunge presenze, formazioni, pagamenti,
classifiche interne e grafiche per i social.

```
gestionale/
├── backend/   API REST (Laravel 12 + SQLite) - già funzionante e testata
├── docs/
│   └── api-types.ts        contratto dell'API (tipi TypeScript, snake_case): è lo stesso del frontend
├── frontend/  sito pubblico + area staff (TanStack Start + React): gira in locale con bun
├── avvia-tutto.bat      doppio clic: avvia backend (:8000) + frontend (:8080) e apre il browser
└── avvia-backend.bat    avvia solo il backend
```

## Avvio in locale (sito + API)

Doppio clic su **`avvia-tutto.bat`**: apre due finestre (backend su `:8000`, frontend su `:8080`) e il browser
su <http://localhost:8080>. Oppure a mano, in due terminali:

```bash
cd backend && php artisan serve --host=127.0.0.1 --port=8000
```

```bash
cd frontend && bun run dev
```

- `localhost:8000` è **solo l'API** (risponde in JSON): il sito è `localhost:8080`.
- Il frontend legge `frontend/.env` (`VITE_API_BASE_URL`): serve il backend acceso, non esiste più la modalità demo.
- Login area staff: tasto **Accedi** nel menu del sito (o direttamente `/admin/login`), con nome utente (l'email) e password.
  Gli utenti stanno nel database; la password non è mai in `.env` né nel codice (nel database c'è solo l'hash).
  Per creare un amministratore o cambiare una password: `cd backend && php artisan amir:admin tua@email.it`
  (la password la digiti lì, almeno 10 caratteri; rilanciarlo su un'email esistente cambia la password e chiude le sessioni aperte).
- Prima volta: `cd frontend && bun install --frozen-lockfile` (rispetta il lockfile e la guida di sicurezza
  che blocca pacchetti pubblicati da meno di 24 ore).

## Avvio rapido (solo backend)

```bash
cd backend
php artisan migrate
php artisan db:seed              # crea la squadra AMIR e l'utente admin (vedi .env)
php artisan xfive:sync current   # stagione in corso
php artisan xfive:sync history   # stagioni passate (circa 1 minuto)
php artisan serve                # http://127.0.0.1:8000/api/v1
php artisan xfive:matches        # referti: presenze, marcatori, arbitro (circa 3 minuti)
php artisan test                 # tutti i test (SQLite)
```

Opzionale, per provare l'app con **dati inventati** (non reali): `php artisan db:seed --class=DemoSeeder`.

Variabili in `backend/.env`: `FRONTEND_URL` (serve a costruire i link personali dei giocatori),
`XFIVE_THROTTLE_MS` (pausa fra le richieste a XFive, default 1200), `XFIVE_USER_AGENT` (come ci si presenta a XFive,
con un contatto). Nessuna password: l'amministratore si crea con `php artisan amir:admin`. In locale il database è SQLite
(`backend/database/database.sqlite`) e contiene tutto, utenti compresi; online (Vercel) è Postgres: lo stesso codice gira su
entrambi, e i test si possono lanciare anche su Postgres (`DB_CONNECTION=pgsql DB_HOST=... php artisan test`).

## Come arrivano i dati da XFive

XFive non ha API: si leggono le **pagine pubbliche**, una richiesta alla volta e con una pausa.

| Dato | Fonte pubblica |
|---|---|
| Calendario + risultati di un torneo | `/t-printable.php?t={id}&sk=calendar` (la pagina "Anteprima di stampa") |
| Tornei disputati dal club in una stagione | `POST /system/include/ajax/public/team.php` (`tmid=159`, `op=1`, `sid`) |

- Gli id nei calendari (`team-159`) sono gli id del **club**, gli stessi dell'area amministrazione.
- Il calendario di una stagione viene pubblicato **a pezzi** (oggi solo la 1ª giornata del girone
  CITTADELLA ha data e ora). Le altre partite restano `to_schedule` ("provvisorie"): niente data,
  niente evento, niente voce nel calendario del telefono. Quando XFive le completa o rigenera il
  calendario (anche con id nuovi) la sincronizzazione le riaggancia per giornata + squadre; le
  partite sparite e non giocate diventano `cancelled`, ma solo se la lettura ha restituito partite.
- La **classifica è calcolata** dai risultati (3-1-0). Non applica spareggi per scontri diretti:
  a pari punti, differenza reti e gol fatti le squadre condividono la posizione.
- Rose, date di nascita, certificati e tesseramenti stanno solo nell'area admin di XFive (serve il
  login): si importano a mano da `POST /players/import` (incolla o CSV, intestazioni anche italiane).

### Stemmi, profili e statistiche dei giocatori

```bash
php artisan xfive:badges            # stemmi delle squadre del girone (500 px quando c'è); --all per lo storico
php artisan xfive:players           # profili (foto, nazionalità, carriera) + statistiche per torneo
php artisan xfive:players --stats-only   # solo statistiche (è quello che gira di notte)
```

- **Stemmi e foto** si scaricano dal CDN di XFive e si servono dalla nostra API
  (`/public/badges/{team}`, `/public/players/{id}/photo`): stessa origine, CORS aperto, quindi le grafiche
  si esportano in PNG. I segnaposto di XFive ("ph_…") non vengono salvati.
- **Abbinamento ai profili XFive**: nome che combacia **e** profilo con il nostro club; se i candidati sono
  più d'uno decide l'età (dalla data di nascita). Per un tesserato nuovo, senza storia con noi, basta il
  nome esatto **con** l'età uguale. Nel dubbio il giocatore resta non abbinato e il comando lo segnala.
- **Statistiche**: gol, punti "miglior giocatore", ammonizioni ed espulsioni per torneo, dalle classifiche
  pubbliche di XFive, abbinate per cognome + iniziale + nome squadra. "Tornei disputati" = tornei a cui il
  giocatore risulta iscritto.

### Referti delle partite (presenze, marcatori, arbitro)

```bash
php artisan xfive:matches                  # legge arbitro, distinta, gol e cartellini delle partite giocate (≈3 minuti)
php artisan xfive:matches --all            # le rilegge tutte
php artisan xfive:matches --include-excluded   # anche la Serie A a 8
```

La **pagina di ogni partita** su XFive elenca la distinta di entrambe le squadre, con gol (icona + "x2"),
ammonizioni, espulsioni, la stella "miglior giocatore" e, se la società l'ha inserito, l'arbitro. Da lì si
ricavano le **presenze** (= in distinta) di ogni giocatore, anche di chi non gioca più con noi: gli "ex giocatori"
vengono creati come inattivi (con la loro foto) e compaiono nello Storico e nelle classifiche di sempre.

- I dati che lo staff ha inserito a mano (`source = manual`) non vengono mai riscritti dall'importazione.
- Il comando gira ogni giorno alle 06:00 (`--recent`: partite nuove o degli ultimi 10 giorni).
- Dove XFive non ha pubblicato la distinta (alcuni tabelloni) restano solo risultato e punteggio; per i gol
  e i cartellini vale il valore più alto tra la distinta e le classifiche ufficiali del torneo.
- **Tornei esclusi** (`config/amir.php` → `excluded_tournaments`): Serie A a 8 di 100GRIGIO 2025/26 (139) e la
  sua Coppa di Lega (158) restano nel database ma non contano in storico, statistiche, presenze e scontri diretti.
- **Amichevoli**: partite organizzate da noi (area staff → Amichevoli), in una competizione "Amichevoli" per
  stagione; compaiono nel calendario ma non contano per statistiche e storico.

### Testi scritti dall'IA (Gemini)

Tre funzioni usano **Gemini** di Google (piano gratuito, chiave da <https://aistudio.google.com/apikey>):

- le **didascalie** dello Studio grafiche (da fatti verificati: risultato, marcatori, precedenti, classifica);
- la **scheda scout** di un giocatore (area staff → Giocatori → Modifica → «Scrivi con l'IA»): si scrive dai suoi
  numeri veri, si salva sul giocatore e compare sulla sua pagina pubblica; si può correggere a mano;
- le **domande sui documenti XFive** (area staff → Documenti XFive → «Chiedi ai documenti»).

La chiave va in `backend/.env` come `GEMINI_API_KEY` (modello: `GEMINI_MODEL`, predefinito `gemini-3.5-flash`; `gemini-3.8-flash` il 7/10/2026 era sovraccarico).
Il piano gratuito concede **5 richieste al minuto per modello**: se il principale è saturo l'app riprova da sola con
`GEMINI_FALLBACK_MODEL` (predefinito `gemini-3.5-flash-lite`, quota separata) e, se falliscono entrambi, usa il testo di riserva.
I test non usano mai la chiave vera (`phpunit.xml` la svuota).
Senza chiave, o se la chiamata fallisce, si usano dei modelli di testo (o, per i documenti, i passaggi più
pertinenti) e la risposta lo dichiara (`source: "template"` / `"search"`). All'IA arrivano solo dati pubblici di
XFive e il testo dei documenti: sul piano gratuito Google può usare i contenuti inviati per migliorare i suoi prodotti.

### Documenti XFive (modulistica)

I 15 documenti del menu «Modulistica» dell'area amministrazione di XFive (guida al portale, privacy Libertas e CONI,
domanda di tesseramento, tabella sanzioni, checklist, prestito, contratto, convenzioni, Squad List, note tecniche
2026/27…) sono in `backend/resources/modulistica/`: gli originali in `files/` e la lettura in italiano in
`manifest.json` (riassunto, punti chiave, importi, cosa fare, sanzioni voce per voce, regole articolo per articolo,
checklist, listino costi, incongruenze da chiarire con XFive). Nessun import nel database: per aggiornare un documento
si sostituisce il file e si corregge la sua voce nel manifest. Si consultano in **area staff → Documenti XFive**, e la
checklist «Prima della partita» è anche nella dashboard.

Pianificazione: `routes/console.php` aggiorna la stagione corrente ogni 3 ore e lo storico ogni
lunedì. In produzione serve il cron di Laravel: `* * * * * php artisan schedule:run`.

## API

Contratto completo in [`docs/api-types.ts`](docs/api-types.ts). Risposte sempre `{ "data": ... }`.

- **Pubblico (senza login):** `GET /public/home`, `/public/matches`, `/public/standings`,
  `/public/roster`, `/public/calendar.ics`, `/public/history`, `/public/history/competitions/{id}`,
  `/public/head-to-head/{teamId}`, `/public/players/{id}` (scheda personale: KPI, stagioni, forma, impatto sulla
  squadra, record, registro partite, compagni d'oro, vittime, traguardi, scheda scout), `/public/players/{id}/photo`,
  `/public/badges/{teamId}`,
  `/public/stats/career` (classifica di sempre, ex giocatori inclusi), `/public/matches/{id}` (convocati e
  formazione se pubblicati, referto a partita giocata).
- **Giocatore (link personale, nessuna password):** `GET /me/{token}`, `POST /me/{token}/events/{id}/rsvp`.
- **Admin (Bearer token):** dashboard, giocatori (numero maglia rossa e bianca), partite (divisa, convocati,
  formazione, pubblicazione sul sito, statistiche post-partita), amichevoli, presenze e solleciti WhatsApp,
  quote e pagamenti (contanti, Satispay, PayPal, Revolut, bonifico), classifica presenze, sincronizzazione,
  didascalie, scheda scout dei giocatori (`POST/PUT /players/{id}/scout`), documenti XFive (`GET /documents`,
  `/documents/{slug}/file`, `POST /documents/ask`).

### Storico

`/public/history` dà, oltre a stagioni e avversarie, record e curiosità calcolati da partite e distinte: serie più
lunghe, partite da record, rendimento per casa/trasferta, formato, competizione, orario, giorno e mese, arbitri
(con i cartellini dei nostri), campi, triplette, primati in una stagione e la rosa di ogni stagione. Le stesse sezioni
sono nella **scheda stagione** (`/storico/stagione/2025-2026`) e nella pagina di ogni competizione, dove nei campionati
si aggiungono primo in classifica, miglior attacco e miglior difesa.

## Collegare il frontend al backend

In `frontend/.env`: `VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1` (in produzione l'indirizzo pubblico
del backend). CORS è già aperto in sviluppo.

## Metterlo online

Tutto su Vercel: il sito (cartella `frontend`) e il backend (cartella `backend`, funzione PHP con database Postgres).
Passo passo, variabili da inserire e come portare i dati già inseriti con il backup (Impostazioni, Dati e backup):
[docs/DEPLOY.md](docs/DEPLOY.md). Il `backend/Dockerfile` resta come alternativa per un server con disco.

## Sicurezza e privacy

- Il sito pubblico mostra solo nomi, ruoli, numeri e foto della rosa. Date di nascita, telefono, email,
  certificati medici e pagamenti sono solo dietro login (o nel link personale del singolo giocatore).
- Dei certificati medici si salva **solo la scadenza**, mai il documento.
- Nessun codice fiscale viene salvato. I pagamenti sono un **registro**: l'app non incassa denaro.
- Online: `APP_ENV=production` e `APP_DEBUG=false` sono già impostati (in `backend/api/index.php` e nel `Dockerfile`), e il server si fida del proxy
  HTTPS della piattaforma. Per far chiamare l'API solo dal sito, `CORS_ALLOWED_ORIGINS` (vedi `backend/config/cors.php`).
- Il backup del database contiene anche dati personali e gli utenti: va conservato con cura.

## Limiti noti e prossimi passi

- La squadra a 7 (**Gazzetta c7**, club 530) non è ancora importata: oggi la sincronizzazione segue il
  club 159. Per aggiungerla serve estendere la configurazione a più club.
- Le **presenze** storiche arrivano dalle distinte delle partite (vedi sopra): valgono dal 2022/23, cioè da
  quando il club 159 ha partite su XFive. Prima non c'è nulla da importare.
- Alcune partite (soprattutto del 2022) hanno una distinta incompleta o assente: lì i numeri sono un minimo.
- Le posizioni finali storiche sono calcolate: a pari punti possono differire dalla classifica ufficiale.
- **Coppe e tornei**: XFive pubblica solo «Nª giornata», senza finali né semifinali, quindi non si può ricavare chi
  ha vinto una coppa. Nello Storico i premi (primo in classifica, miglior attacco/difesa) esistono solo per i campionati.
- I **compleanni** (dashboard staff) sono dati personali: non compaiono nelle pagine pubbliche.
- Le immagini dei giocatori sui social vanno usate solo se hanno dato il consenso B nel modulo di tesseramento Libertas
  (vedi Documenti XFive, documento 4): l'app non lo registra ancora.
- Le grafiche (immagini per i social) si generano nel frontend, con sfondo stadio e logo XFive.
