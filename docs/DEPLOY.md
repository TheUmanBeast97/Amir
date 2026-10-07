# Mettere online AMIR Team Manager

## Come è fatto

- **Il sito** (cartella `frontend`) sta su Vercel: https://amir-taupe.vercel.app, progetto `amir`.
- **Il backend** (cartella `backend`, l'API con il database) sta su un piccolo server con un **disco che non si cancella**.
  Vercel non va bene per questo: il database è un file SQLite e stemmi, foto e backup sono file.
- I due si trovano grazie a **una sola variabile su Vercel**: `VITE_API_BASE_URL`.

Finché il backend non è online il sito si apre ma resta vuoto: le sue pagine chiedono i dati all'API.

## 1. Il backend su Railway

Costa circa 5 dollari al mese (piano Hobby). Questa procedura segue la documentazione di Railway: l'avvio del backend è
stato provato su un Linux vero, ma non con un account Railway.

1. Vai su railway.app e accedi con GitHub.
2. **New Project**, poi **Deploy from GitHub repo**, poi `TheUmanBeast97/Amir`.
3. Apri il servizio creato, **Settings**, **Root Directory**: scrivi `backend`. Railway trova da solo il `Dockerfile`.
4. Aggiungi un **Volume** (Add Volume) con **Mount Path** `/data`. Senza volume i dati si perdono a ogni nuova versione.
5. **Variables**: inserisci quelle della tabella qui sotto.
6. **Settings**, **Networking**, **Generate Domain**. Annota l'indirizzo, per esempio `https://amir-production.up.railway.app`.
7. Guarda **Deploy Logs**: devono comparire "Nuovo database", le tabelle create, "Server in ascolto sulla porta" e
   "Primo avvio: scarico da XFive…". Lo scarico dei dati pubblici dura qualche minuto e il server intanto risponde.

### Variabili del backend

| Variabile | Valore | A cosa serve |
| --- | --- | --- |
| `AMIR_ADMIN_EMAIL` | la tua email di accesso | primo amministratore |
| `AMIR_ADMIN_HASH` | l'impronta della tua password (vedi sotto) | primo amministratore: **non è la password** |
| `FRONTEND_URL` | `https://amir-taupe.vercel.app` | link personali dei giocatori |
| `CORS_ALLOWED_ORIGINS` | `https://amir-taupe.vercel.app` | facoltativa: solo il tuo sito può chiamare l'API |
| `GEMINI_API_KEY` | la tua chiave Gemini | facoltativa: didascalie, scheda scout, domande sui documenti |
| `XFIVE_USER_AGENT` | `AmirTeamManager/0.1 (gestionale squadra; contatto: la tua email)` | facoltativa: come ci si presenta a XFive |

Non servono `APP_KEY` (si crea da sola e resta nel volume), né `APP_ENV`, `DB_DATABASE` e simili: sono già nell'immagine.

**L'impronta della password** si calcola sul tuo computer, la password non viene salvata da nessuna parte:

```bash
cd backend
php artisan amir:hash
```

Digita la password due volte e copia la riga che inizia con `$2y$`: quella è `AMIR_ADMIN_HASH`. L'amministratore viene creato solo
se non esiste già, quindi dopo il primo avvio le due variabili `AMIR_ADMIN_*` si possono anche togliere. Per cambiare la password
in seguito: dalla riga di comando del server `php artisan amir:admin tua@email.it`.

## 2. Il sito su Vercel

1. Vercel, progetto `amir`, **Settings**, **Environment Variables**.
2. `VITE_API_BASE_URL` = `https://IL-TUO-DOMINIO-RAILWAY/api/v1`, per **Production** (e **Preview** se la usi).
   Se c'è già un valore con `tuo-backend`, è il segnaposto: va sostituito.
3. **Deployments**, sull'ultimo **Redeploy**. Le variabili che iniziano con `VITE_` entrano nel sito al momento della
   costruzione, quindi senza un nuovo deploy il cambio non ha effetto.

## 3. Portare i dati che hai già

I giocatori, i numeri di maglia, la Squad List, i pagamenti e il resto che hai inserito a mano stanno nel database del tuo computer
e non si ricostruiscono da XFive. Si portano sul server con un backup:

1. Sul tuo computer, con il sito in locale: **Impostazioni**, **Dati e backup**, **Scarica backup**.
2. Sul sito online: accedi, **Impostazioni**, **Ripristina da un backup**, scegli il file, scrivi `RIPRISTINA`.
3. Ti chiede di accedere di nuovo: usa lo stesso nome utente e la stessa password di prima, perché il backup contiene anche gli utenti.

La versione di prima del ripristino resta sul server come `database.sqlite.prima-del-ripristino`, accanto al database, per tornare indietro.

## 4. Controlli finali

- Apri https://amir-taupe.vercel.app: devono comparire la prossima partita e la classifica.
- Una foto o uno stemma si vede (se manca, controlla che il backend risponda in HTTPS).
- **Accedi** in alto o nel menu, entra nell'area staff.
- Una figurina si apre e il pulsante **Scarica PNG** funziona.

## Dopo

- **Backup regolari**: ogni tanto **Scarica backup**. Il volume è l'unico posto dove stanno i dati.
- **Aggiornamenti**: ogni push su GitHub rifà il deploy di sito e backend. I dati nel volume non si toccano.
- **XFive**: lo scheduler del server aggiorna calendario, stemmi, statistiche e referti da solo.

## Altre piattaforme

L'immagine è la stessa ovunque: serve un volume montato su `/data` e la porta si legge da `PORT`. Su Fly.io, per esempio,
`fly launch` dalla cartella `backend` con un volume su `/data`; Render richiede un disco a pagamento. Non le ho provate.

## Se qualcosa non va

- **Il sito è vuoto**: nel browser apri gli strumenti di sviluppo, scheda Rete, e guarda dove va la richiesta `public/home`.
  Se l'indirizzo contiene `tuo-backend`, la variabile su Vercel non è stata cambiata, oppure manca il Redeploy.
- **Errore di accesso dal sito ma non dall'API**: controlla `CORS_ALLOWED_ORIGINS`, deve essere l'indirizzo esatto del sito, senza `/` in fondo.
- **Foto e stemmi non compaiono**: aspetta la fine dello scarico iniziale; se restano vuoti guarda i Deploy Logs.
- **Dopo un nuovo deploy i dati sono spariti**: il volume non è montato su `/data`.
