# Mettere online AMIR Team Manager

## Come è fatto

Tutto sta su Vercel, in due progetti dello stesso repository GitHub:

- **Il sito** (cartella `frontend`): progetto `amir`, https://amir-taupe.vercel.app.
- **Il backend** (cartella `backend`, l'API): un secondo progetto, per esempio `amir-api`. Gira come funzione PHP
  (runtime `vercel-php`) e tiene i dati in un database **Postgres** (Neon, dal Marketplace di Vercel). Non c'è nessun server da gestire.
- I due si trovano grazie a **una sola variabile** nel progetto del sito: `VITE_API_BASE_URL`.

In locale non cambia nulla: il backend usa il file SQLite `backend/database/database.sqlite`. Il passaggio a Postgres è automatico
quando gira su Vercel.

Finché il backend non è online il sito si apre ma mostra «Il server dei dati non risponde».

## 1. Creare il progetto del backend

1. Vercel, **Add New**, **Project**, scegli il repository `TheUmanBeast97/Amir`.
2. **Root Directory**: `backend`. **Framework Preset**: Other (il file `vercel.json` lo dice già).
3. Prima di premere **Deploy** apri **Environment Variables** e inserisci quelle della tabella qui sotto.
4. Premi **Deploy**. Il primo deploy si ferma con «Manca il database»: è normale, il database si collega al punto 2.

### Variabili del backend

| Variabile | Valore | A cosa serve |
| --- | --- | --- |
| `APP_KEY` | una chiave, vedi sotto | cifratura dei dati dell'app (obbligatoria) |
| `AMIR_ADMIN_EMAIL` | la tua email di accesso | primo amministratore |
| `AMIR_ADMIN_HASH` | l'impronta della tua password (vedi sotto) | primo amministratore: **non è la password** |
| `FRONTEND_URL` | `https://amir-taupe.vercel.app` | link personali dei giocatori |
| `CRON_SECRET` | una stringa lunga a caso | accende gli aggiornamenti notturni da XFive |
| `GEMINI_API_KEY` | la tua chiave Gemini | facoltativa: didascalie, scheda scout, domande sui documenti |
| `XFIVE_USER_AGENT` | `AmirTeamManager/0.1 (gestionale squadra; contatto: la tua email)` | facoltativa: come ci si presenta a XFive |
| `CORS_ALLOWED_ORIGINS` | `https://amir-taupe.vercel.app` | facoltativa: solo il tuo sito può chiamare l'API |

Le variabili del database (`DATABASE_URL` e simili) le crea l'integrazione Neon da sola: non vanno scritte a mano. Tutto il resto
(`APP_ENV`, `LOG_CHANNEL`, cache, sessioni, percorsi in `/tmp`) è già impostato in `backend/api/index.php`.

**La chiave** si crea sul tuo computer e si incolla com'è (comincia con `base64:`):

```bash
cd backend
php artisan key:generate --show
```

**L'impronta della password** si calcola sul tuo computer, la password non viene salvata da nessuna parte:

```bash
cd backend
php artisan amir:hash
```

Digita la password due volte e copia la riga che inizia con `$2y$`: quella è `AMIR_ADMIN_HASH`. L'amministratore viene creato
solo se non esiste già, quindi dopo il primo avvio le due variabili `AMIR_ADMIN_*` si possono anche togliere.

## 2. Collegare il database

1. Nel progetto del backend: **Storage**, **Create Database**, scegli **Neon** (Postgres), piano gratuito.
2. Collegalo al progetto per **Production** (e **Preview** se le usi). Vercel aggiunge le variabili del database.
3. **Deployments**, sull'ultimo **Redeploy**.

A ogni pubblicazione lo script `amir:deploy` crea le tabelle, la squadra e l'amministratore se mancano, e applica le migrazioni
nuove. Se per qualunque motivo non partisse, lo fa il backend stesso alla prima richiesta.

Poi in **Settings**, **Domains** trovi l'indirizzo del backend, per esempio `https://amir-api.vercel.app`. Aprendo
`https://amir-api.vercel.app/api/v1/public/home` deve comparire un testo in formato JSON.

## 3. Collegare il sito al backend

1. Vercel, progetto `amir`, **Settings**, **Environment Variables**.
2. `VITE_API_BASE_URL` = `https://IL-TUO-BACKEND.vercel.app/api/v1`, per **Production** (e **Preview** se la usi).
   Se c'è già un valore con `tuo-backend`, è il segnaposto: va sostituito.
3. **Deployments**, sull'ultimo **Redeploy**. Le variabili che iniziano con `VITE_` entrano nel sito al momento della
   costruzione, quindi senza un nuovo deploy il cambio non ha effetto.

## 4. Portare i dati che hai già

I giocatori, i numeri di maglia, la Squad List, i pagamenti e il resto che hai inserito a mano stanno nel database del tuo computer
e non si ricostruiscono da XFive. Si portano sul server così:

1. Apri il sito online e accedi con l'email e la password che hai scelto al punto 1.
2. **Impostazioni**, **Dati e backup**, **Ripristina da un backup**.
3. Scegli il file `backend/database/database.sqlite` del tuo computer (va bene anche un backup scaricato dal gestionale, `.json.gz`),
   scrivi `RIPRISTINA` e conferma.
4. Ti chiede di accedere di nuovo: usa lo stesso nome utente e la stessa password che usi in locale, perché i dati contengono gli utenti.
5. Premi **Stemmi e foto** (sempre in **Impostazioni**): le immagini non stanno nei backup e si riscaricano da XFive. Il sito
   richiama da solo finché ha finito, un paio di minuti.

Il backup contiene i dati e gli utenti, **non** le immagini, le sessioni, i token di accesso e la cache: per questo dopo ogni
ripristino tutti devono accedere di nuovo. Il ripristino è fatto in una sola operazione: se qualcosa non va, i dati di prima restano
com'erano. Non resta però nessuna copia dei dati sostituiti, quindi **scarica un backup prima di ripristinare**.

## 5. Controlli finali

- Apri https://amir-taupe.vercel.app: devono comparire la prossima partita e la classifica.
- Uno stemma e una foto si vedono (se mancano: **Impostazioni**, **Stemmi e foto**).
- **Accedi** in alto o nel menu, entra nell'area staff.
- Una figurina si apre e il pulsante **Scarica PNG** funziona.

## Aggiornamenti da XFive

- **Ogni notte**, da soli: Vercel lancia gli indirizzi `/api/v1/cron/...` elencati in `backend/vercel.json` (calendario, partite giocate,
  stemmi e foto, statistiche; lo storico il lunedì). Servono `CRON_SECRET` e il piano gratuito li fa partire una volta al giorno,
  in un'ora qualunque della fascia indicata.
- **Quando vuoi**: **Impostazioni**, i pulsanti sotto **XFive**.
- **Più spesso**: un servizio esterno (per esempio cron-job.org) che chiama `GET https://IL-TUO-BACKEND.vercel.app/api/v1/cron/current`
  con l'intestazione `Authorization: Bearer IL-TUO-CRON_SECRET`.

Una richiesta su Vercel dura al massimo un minuto. Gli aggiornamenti lunghi (partite, immagini, statistiche) lavorano a pezzi da circa
40 secondi e dicono quanto manca: il sito li richiama da solo, mentre quelli notturni finiscono nei giorni successivi.

## Rosa e tesseramenti da XFive (facoltativo, spento di serie)

Il backend può leggere dall'area amministrazione di XFive, con il tuo account, la **rosa**: Squad List, scadenze dei certificati medici e
stato dei tesseramenti. Solo lettura: non modifica nulla su XFive. Vale XFive per la Squad List e per la scadenza del certificato; la data
di nascita e il ruolo si completano solo se mancano.

Per accenderlo, nel progetto del **backend** su Vercel, **Settings**, **Environment Variables**, per Production:

| Variabile | Valore |
| --- | --- |
| `XFIVE_ADMIN_EMAIL` | l'email del tuo account XFive |
| `XFIVE_ADMIN_PASSWORD` | la password, **come variabile sensibile** |
| `XFIVE_ADMIN_ENABLED` | `1` |

Poi **Redeploy**. In **Impostazioni**, scheda **Area amministrazione XFive**, premi **Prova accesso** e poi **Leggi rosa da XFive**.

Come è protetto:
- Email e password stanno solo qui, nelle variabili del server: non nel database, non nel codice, non nei backup. Vanno solo a XFive, in HTTPS.
- Non si conserva nessuna sessione: a ogni lettura si fa un accesso nuovo. Per questo non esistono token che scadono.
- Se XFive rifiuta l'accesso, il backend non riprova per 2 ore (`XFIVE_ADMIN_COOLDOWN_MINUTES`), così una password sbagliata non fa bloccare l'account.
- Senza `XFIVE_ADMIN_ENABLED=1` non parte nessun accesso: nemmeno dagli aggiornamenti automatici.

Prima di accenderlo ti consiglio di chiedere a XFive un ok scritto: i loro termini d'uso vietano i robot che raccolgono informazioni sugli
utenti «per scopi non autorizzati» e non parlano dell'automazione del proprio account amministratore. L'aggiornamento notturno automatico
della rosa non è attivo: la lettura parte quando premi il pulsante, o da un servizio esterno che chiama
`GET /api/v1/cron/admin` con `Authorization: Bearer IL-TUO-CRON_SECRET`.

## Cose da sapere

- **Piano gratuito di Vercel (Hobby)**: è pensato per uso personale e senza scopo di lucro. Per una squadra amatoriale va bene;
  se un giorno servisse altro, il piano Pro non richiede nessuna modifica al codice.
- **Neon gratuito** si «addormenta» dopo qualche minuto senza richieste: la prima apertura può essere più lenta di un secondo o due.
- Le richieste hanno un limite di **4,5 MB**: il database di una squadra pesa meno di 1 MB, quindi i backup ci stanno largamente.
- I **log** del backend si leggono nel progetto Vercel, scheda **Logs**.

## Alternativa: un server con disco (Docker)

Il `backend/Dockerfile` resta valido per un server con un **volume** montato su `/data` (Railway, Fly.io e simili): lì il database
è un file SQLite e lo scheduler interno aggiorna da XFive ogni tre ore. Le variabili sono quelle della tabella sopra, più `DATA_DIR`
se non usi `/data`; `APP_KEY` si crea da sola e resta nel volume.

## Se qualcosa non va

- **Il primo deploy del backend fallisce con «Manca il database»**: collega Neon al progetto (punto 2) e premi Redeploy.
- **Il build dice che manca il database ma Neon è già creato**: controlla che sia collegato al progetto del **backend** (Root Directory
  `backend`), non a quello del sito. Dalla scheda **Storage** apri il database, **Projects**, e collega anche il progetto del backend.
  Poi premi Redeploy sull'ultimo deploy del backend.
- **Il build elenca variabili del database con un prefisso (per esempio `STORAGE_DATABASE_URL_UNPOOLED`) ma dice che manca il database**:
  nel collegamento di Neon è stato scelto un prefisso. Il backend legge solo i nomi standard, per sicurezza. Crea una variabile `DB_URL`
  con lo stesso indirizzo (meglio quello «UNPOOLED») e premi Redeploy. Oppure ricollega il database senza prefisso.
- **Il sito è vuoto o dice che il server non risponde**: nel browser apri gli strumenti di sviluppo, scheda Rete, e guarda dove va la
  richiesta `public/home`. Se l'indirizzo contiene `tuo-backend`, la variabile su Vercel non è stata cambiata, oppure manca il Redeploy.
- **L'indirizzo del backend risponde con un errore 500**: guarda **Logs** nel progetto del backend. Se parla di chiave di cifratura,
  manca `APP_KEY`.
- **Errore di accesso dal sito ma non dall'API**: controlla `CORS_ALLOWED_ORIGINS`, deve essere l'indirizzo esatto del sito, senza `/` in fondo.
- **Foto e stemmi non compaiono**: **Impostazioni**, **Stemmi e foto**, e ripeti finché non dice che ha finito.
- **Gli aggiornamenti notturni non partono**: manca `CRON_SECRET` nel progetto del backend (poi serve un Redeploy).
