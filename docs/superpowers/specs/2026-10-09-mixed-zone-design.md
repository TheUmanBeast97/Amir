# Mixed Zone, scambio di area e centro di sincronizzazione

Specifica di progetto, 9 ottobre 2026. Decisioni prese con l'utente: dati XFive nel database Neon senza immagini
(le immagini dal CDN di XFive); solo calcio (niente padel né pallavolo); colore «Notturno» (grafite + ciano); la pagina
d'ingresso resta Amir Hub; figurina per tutti i giocatori XFive in una fase successiva; in pubblico solo ciò che XFive
pubblica già.

## 1. Le tre aree

| Area | Contenuto | Colore | Indirizzi |
|---|---|---|---|
| **Amir Hub** | il sito di oggi: home, calendario, classifica, rosa, pagina giocatore, storico | rosso AMIR (invariato) | `/`, `/calendario`, `/classifica`, `/rosa`, `/storico`... (invariati) |
| **Mixed Zone** | tutto XFive: tornei, squadre, giocatori, partite, statistiche | ciano `#22d3ee` su grafite | `/mixed-zone/...` |
| **Staff Area** | login e area amministrativa | ambra (già usata per lo staff) | `/admin/...` (invariati) |

La scocca (`AppShell`: menu laterale, barra mobile, `PageTransition`) è una sola per le tre aree. L'area corrente si
ricava dall'indirizzo e finisce in `data-area="hub|mixed|staff"` sull'elemento `<html>`; le variabili CSS del tema
(`--primary`, `--sidebar-accent`, la striscia diagonale di `PageTransition`, i colori di `Reveal`/`ActivePill`) cambiano
per area. Nessun componente viene duplicato: le pagine della Mixed Zone riusano `Card`, `PageTitle`, `Reveal`, `CountUp`,
`RollDigits`, le tabelle di classifica e la pagina partita già esistenti, con il colore che arriva dal tema.

Il menu della Mixed Zone: Home, Tornei, Squadre, Giocatori, Partite, Statistiche. In fondo al menu di ogni area c'è il
pulsante **«Cambia area»** (prende il posto di «Area staff»; nella barra mobile sta nell'intestazione).

## 2. Scambio di area

1. Click su «Cambia area»: il pulsante si gonfia (`scale` 1.06 con `spring.pop`), il fondo si scurisce e sfoca (`backdrop-filter`).
2. Si apre il selettore: tre carte in prospettiva (`perspective: 1200px`, stessa inclinazione al puntatore della figurina,
   `HoloCard` riusato con un tema per area), ognuna con nome, sottotitolo e tre voci di anteprima:
   - Mixed Zone: «Tutto XFive Alessandria»; tornei, squadre, statistiche.
   - Amir Hub: «La casa di AMIR COSTRUZIONI»; calendario, rosa, storico.
   - Staff Area: «Gestione della squadra»; convocazioni, quote, grafiche.
   La carta dell'area in cui si è porta l'etichetta «Sei qui». Tastiera: frecce per spostarsi, Invio per scegliere, Esc per chiudere.
3. Scelta: la carta vola verso l'osservatore e si apre come un portale; dietro, un tunnel di sei piani prospettici nel colore
   dell'area che scorrono verso l'osservatore, più un canvas 2D con particelle leggere (al massimo 120, nessuna libreria).
   Al centro «Benvenuto in» e il nome dell'area che si compone lettera per lettera (`stagger` 40 ms).
   Durata totale 1,2 s. La nuova pagina si carica durante l'animazione (`router.preloadRoute` + navigazione a 0,6 s), così
   la dissolvenza finale scopre la pagina già pronta.
4. Staff Area: se c'è il token si va alla dashboard, altrimenti al login.
5. Con `prefers-reduced-motion` resta solo una dissolvenza di 0,3 s con la scritta «Benvenuto in ...».
6. La transizione si vede solo al cambio di area, mai tra pagine della stessa area.

Componenti nuovi: `components/area/AreaSwitch.tsx` (pulsante + selettore), `components/area/AreaPortal.tsx` (transizione),
`lib/area.ts` (`areaOf(pathname)`, tema per area, `AREAS` con nomi, sottotitoli, colori e indirizzo d'ingresso).

## 3. Dati: modello e provenienza

### 3.1 Tabelle nuove (prefisso `xf_`, separate dalle tabelle AMIR)

| Tabella | Chiave | Campi principali | Da |
|---|---|---|---|
| `xf_seasons` | `id` (sid XFive) | `label` (2026/2027) | intestazioni tornei |
| `xf_tournaments` | `id` (tid) | `season_id`, `name`, `slug`, `sport` («Calcio a 8 - Maschile»), `format` (5/7/8/9/11), `gender`, `category`, `flyer_url`, `teams_count`, `dates`, `status` (ongoing/incoming/previous), `calendar_synced_at`, `standings_synced_at`, `stats_synced_at`, `teams_synced_at` | `tournament.json` |
| `xf_clubs` | `id` (clubId) | `name`, `slug`, `badge_url` | `clubs/{id}.json`, calendari |
| `xf_teams` | `id` (teamId nel torneo) | `tournament_id`, `club_id`, `name`, `slug`, `badge_url`, `staff` (json) | `teams/{id}.json` |
| `xf_players` | `id` (pid globale) | `name`, `slug`, `photo_url`, `age`, `nationality`, `profile` (json: club e tornei), `synced_at` | `players/{id}.json` |
| `xf_team_players` | (`team_id`, `tpid`) | `player_id` (pid, se abbinato), `name`, `slug`, `role`, `number`, `photo_url`, `country` | `teams/{id}.json` |
| `xf_matches` | `id` (mid) | `tournament_id`, `round`, `round_label`, `round_id`, `home_club_id`, `away_club_id`, `home_name`, `away_name`, `kickoff_at` (da `kickoff_raw` + stagione), `kickoff_raw`, `venue`, `home_score`, `away_score`, `played`, `referee`, `has_report` | `calendar.json` + `matches/{id}.json` |
| `xf_match_players` | (`match_id`, `tpid`) | `side` (home/away), `player_id` (pid, se abbinato), `name`, `slug`, `goals`, `yellow`, `red`, `mvp`, `photo_url` | `matches/{id}.json` |
| `xf_standings` | (`tournament_id`, `group`, `position`) | `club_id` (abbinato per nome), `name`, `badge_url`, `values` (json: Pt, G, V, N, P, F, S, +/-, FP) | `standings.json` |
| `xf_player_stats` | (`tournament_id`, `type`, `position`) | `type` (score/top-player/discipline), `name` (abbreviato «Natale M.»), `team`, `photo_url`, `values` (json) | `player-stats.json` |
| `xf_documents` | `id` | `tournament_id`, `url`, `title` | `docs.json` |
| `xf_sync_state` | `section` | `synced_at`, `counts` (json), `cursor` (json: da dove riprendere) | il sincronizzatore |

Abbinamenti: `xf_team_players.player_id` si ricava da (`club_id`, `slug`) confrontando con `clubs/{id}.json` (stesso slug
del giocatore nella pagina club); i giocatori dei referti (`ref` = tpid) si abbinano alla rosa della squadra nel torneo e
da lì al pid; `xf_standings.club_id` dal nome squadra (identico a quello del calendario, che ha il club id); le righe
delle statistiche restano con il nome abbreviato e la squadra, come su XFive. Tutto è idempotente (`upsert` sulla
chiave): rilanciare non duplica.

Filtro sport: si importano e si sincronizzano solo i tornei con `sport` che inizia per «Calcio»; padel e pallavolo
restano nell'archivio locale e non vanno online.

Peso stimato: 150 tornei di calcio, circa 4.700 referti con 60.000 righe giocatore, 11.000 righe di rosa: 40-60 MB nel
database Neon (limite gratuito 0,5 GB). Nessuna immagine nel database.

### 3.2 Immagini

Stemmi, foto e locandine si servono dal CDN di XFive (`cdn.enjore.com`) attraverso l'ottimizzatore immagini di Vercel
(`sized()` in `lib/img.ts`, host già nella lista). Se un'immagine non carica, i componenti mostrano le iniziali (come
`PlayerPhoto`). L'archivio locale (`img/`) resta la copia di riserva.

### 3.3 Caricamento iniziale e aggiornamenti

- **Export compatto**: `php artisan xfive:archive export --out=...` produce un file per sezione, in JSON compresso
  (gzip), a pezzi sotto i 3,5 MB (limite di Vercel 4,5 MB a richiesta): `tournaments` (con stagioni), `clubs`,
  `teams` (rose), `players`, `calendar` (partite), `reports` (referti, più pezzi), `tables` (classifiche, statistiche, documenti).
- **Caricamento**: Staff Area, pagina **Sincronizzazione**, riquadro «Carica archivio» con `POST /xfive-data/import`
  (multipart, un pezzo alla volta; risposta con i conteggi; il sito manda i pezzi in fila e mostra l'avanzamento).
- **Aggiornamenti dal vivo**: `XfiveZoneSync` (backend) rilegge da XFive con i lettori già esistenti
  (`PageParsers`, `PrintableCalendarParser`, `StatsTableParser`, `MatchPageParser`, `PlayerInfoParser`) e il freno
  `Limiter`, a pezzi da 40 s con `remaining` e un cursore salvato in `xf_sync_state`, per sezione:

| Sezione | Cosa legge | Richieste | Quando di notte |
|---|---|---|---|
| `zone-tournaments` | elenchi per stagione (`op=23`) + intestazioni dei tornei nuovi | 3 + nuovi | ogni notte |
| `zone-calendar` | calendario stampabile dei tornei attivi (`status` ongoing/incoming) | 1 a torneo | ogni notte |
| `zone-standings` | classifiche dei tornei attivi (`op=20`) | 1 a torneo | ogni notte |
| `zone-stats` | marcatori, miglior giocatore, disciplina dei tornei attivi (`op=19` x3) | 3 a torneo | ogni notte |
| `zone-teams` | squadre iscritte e rose dei tornei attivi | 1 + 1 a squadra | settimanale |
| `zone-reports` | referti delle partite giocate senza referto | 1 a partita | ogni notte |
| `zone-players` | profili globali dei giocatori nuovi o non riletti da 30 giorni | 1 a giocatore | settimanale, a turno |

  Cron: un solo nuovo indirizzo `/api/v1/cron/zone` in `backend/vercel.json` che fa in fila le sezioni «ogni notte» col
  budget di 40 s e lascia il resto alla notte dopo (stesso meccanismo di `XfiveRoutine`); le sezioni settimanali si
  accodano il lunedì. Tutto passa da `XfiveRoutine::SCOPES` (nuovi scope `zone-*` e `zone` = tutte in fila), quindi
  `POST /sync/xfive` e `useSyncFlow` funzionano già.

## 4. API pubblica della Mixed Zone (`/api/v1/public/zone/...`, con `PublicCache`)

| Indirizzo | Risposta |
|---|---|
| `GET /zone/home?season=` | stagioni, ultimi 12 risultati, prossime 12 partite, tornei della stagione per sport, numeri totali |
| `GET /zone/search?q=` | club, giocatori, tornei (massimo 10 per tipo) |
| `GET /zone/tournaments?season=&sport=` | elenco tornei |
| `GET /zone/tournaments/{id}` | intestazione + classifica (gironi) + squadre + documenti + prossime/ultime partite |
| `GET /zone/tournaments/{id}/matches` | calendario completo per giornata |
| `GET /zone/tournaments/{id}/stats` | marcatori, miglior giocatore, disciplina (tabelle XFive) e, se ci sono referti, le stesse classifiche ricalcolate dai referti con i link ai giocatori |
| `GET /zone/clubs?q=` | elenco club con stemma e numero di stagioni |
| `GET /zone/clubs/{id}` | club: tornei per stagione con posizione, rosa per torneo, record, ultime partite |
| `GET /zone/clubs/{id}/matches?tournament=` | partite del club |
| `GET /zone/clubs/{id}/head-to-head/{other}` | scontri diretti |
| `GET /zone/players?q=&club=` | ricerca giocatori |
| `GET /zone/players/{id}` | profilo: club e tornei, totali dai referti (presenze, gol, cartellini, premi), per stagione, compagni, vittime (`PlayerStatsService` generalizzato su `xf_match_players`) |
| `GET /zone/matches?date=&tournament=` | partite per giorno |
| `GET /zone/matches/{id}` | referto completo |
| `GET /zone/stats?season=&sport=` | classifiche di sempre: bomber, presenze, cartellini, miglior giocatore, squadre più vincenti, difese meno battute |

I calcoli pesanti (statistiche di sempre, record dei club) si fanno in SQL con indici su `xf_match_players(player_id)`,
`xf_matches(tournament_id, kickoff_at)`, `xf_team_players(player_id)`; la cache pubblica (`s-maxage=60`,
`stale-while-revalidate=3600`) copre il resto.

## 5. Pagine della Mixed Zone (frontend, `routes/mixed-zone.*.tsx`)

1. **Home** `/mixed-zone`: scelta stagione (chip), barra di ricerca globale, «Ultimi risultati» (carte partita con stemmi),
   «Prossime partite», griglia dei tornei per sport con locandina e stato, numeri grandi con `CountUp`.
2. **Tornei** `/mixed-zone/tornei` e `/mixed-zone/tornei/{id}`: schede Classifica (gironi, forma delle ultime 5),
   Calendario e risultati (per giornata, con filtro squadra), Statistiche (tre classifiche + versione dai referti),
   Squadre (griglia con stemmi), Documenti.
3. **Squadre** `/mixed-zone/squadre` e `/mixed-zone/squadre/{clubId}`: intestazione con stemma e numeri, stagioni e
   tornei (con posizione), rosa del torneo scelto, partite, scontri diretti, record.
4. **Giocatori** `/mixed-zone/giocatori` e `/mixed-zone/giocatori/{pid}`: stessa impostazione della nostra pagina
   giocatore (totali, per stagione, grafici Recharts, compagni, vittime, traguardi), senza figurina in questa fase.
5. **Partite** `/mixed-zone/partite` (per giorno, con calendario) e `/mixed-zone/partite/{id}`: referto completo
   (riuso della pagina partita pubblica di AMIR, con i link ai giocatori della Mixed Zone).
6. **Statistiche** `/mixed-zone/statistiche`: classifiche di sempre con filtri stagione e sport.

Collegamenti incrociati: dalla rosa AMIR al profilo Mixed Zone (`players.xfive_person_id` = `xf_players.id`), dalla
classifica AMIR al torneo, dalle pagine Mixed Zone di AMIR COSTRUZIONI un rimando all'Hub.

Grafica: stessa scocca, stessi effetti (Reveal, CountUp, PageTransition), tema ciano; nessun rosso nella Mixed Zone
tranne stemmi e locandine. Mobile: tutte le griglie con `grid-cols-1` di partenza; le tabelle di classifica scorrono
in orizzontale dentro la carta.

## 6. Centro di sincronizzazione (Staff Area, `/admin/sincronizzazione`)

- In alto **«Sincronizza tutto»** (fa in fila AMIR + tutte le sezioni Mixed Zone) e **«Carica archivio»**.
- Una carta per sezione (AMIR: calendario, storico, partite giocate, stemmi e foto, statistiche, profili e foto della
  rosa; Mixed Zone: tornei, calendari, classifiche, statistiche, squadre e rose, referti, profili) con ultimo
  aggiornamento, conteggi (da `xf_sync_state`), «da fare» e il pulsante.
- Durante un aggiornamento la pagina diventa la **sala di controllo**: radar circolare che pulsa nel colore della
  sezione, striscia che scorre con il messaggio vero del backend (`progress.message`, es. «Leggo il calendario di
  CITTADELLA 2026/27 (37 di 157)»), barra di avanzamento (`progress.done / progress.total`), contatori con
  `RollDigits`, registro delle ultime 20 righe. Ogni risposta del backend porta `progress` oltre a `stats`; il sito
  richiama finché `remaining` è 0 (già in `useSyncFlow`, da estendere con `onProgress`).
- Alla fine un riepilogo con i numeri e il tempo impiegato; gli errori restano leggibili (come oggi in `sync_runs`).
- Le voci XFive oggi in Impostazioni restano come collegamento a questa pagina.

## 7. Fuori da questa fase

Figurina per tutti i giocatori XFive; confronti squadra contro squadra e giocatore contro giocatore; padel e pallavolo;
immagini nel nostro database.

## 8. Verifiche

- Backend: test con fixture (un torneo, due squadre, un referto, una classifica, le statistiche) per import, sync a pezzi,
  cursore e ogni indirizzo pubblico; filtro sport; idempotenza; su SQLite e Postgres.
- Frontend: `tsc`, vitest per `lib/area.ts` e per le trasformazioni dei dati; prova nel browser di scambio area,
  home, torneo, club, giocatore, partita, statistiche e sala di controllo, su desktop e telefono.
- Prestazioni: ogni indirizzo pubblico sotto i 300 ms a database caldo; pagine della Mixed Zone con le immagini
  attraverso l'ottimizzatore.

## 9. Ordine di lavoro proposto

1. Dati: migrazioni `xf_*`, export dell'archivio, import a pezzi, test.
2. API pubblica `/public/zone/*`.
3. Scocca a tre aree, tema per area, «Cambia area» con selettore e portale.
4. Pagine Mixed Zone (home, tornei, squadre, giocatori, partite, statistiche).
5. Sincronizzatore dal vivo `zone-*`, cron, centro di sincronizzazione con la sala di controllo.
6. Collegamenti incrociati, documentazione (`README`, `docs/DEPLOY.md`, `docs/XFIVE_SITE.md`), caricamento online.
