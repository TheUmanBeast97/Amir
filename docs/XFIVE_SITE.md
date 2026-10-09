# Come è fatto il sito di XFive (e come si legge)

Mappa del sito pubblico di XFive, ricavata leggendo le pagine l'8 ottobre 2026 (le copie grezze stanno nell'archivio
locale, cartella `raw/`). Serve a non rifare il giro di scoperta: ogni sezione dice che indirizzo chiamare, cosa
risponde e quale lettore nostro lo capisce.

XFive è una personalizzazione della piattaforma **Enjore** (`www.enjore.com`): le pagine girano su `www.xfivesport.it`
(Apache/PHP su Google Cloud), le immagini e i documenti su `cdn.enjore.com/wl/xfivesport_it/...`. Enjore **non
pubblica API** per sviluppatori: cercato l'8/10/2026 sul sito principale, su PRO Manager e nelle FAQ, niente; l'unico
export documentato è quello Excel/CSV dei tesserati nell'area amministrazione. Quello che segue sono le chiamate che le
pagine fanno da sole (AJAX) e le pagine lette direttamente.

Il `robots.txt` non vieta nulla a un robot generico (vieta solo alcuni nomi: GPTBot, Ahrefs, Semrush...). I termini di
XFive vietano i robot «per scopi non autorizzati»: il responsabile di XFive ha dato l'ok a voce; un ok scritto sarebbe meglio.

## Regole di comportamento

- Una richiesta alla volta, almeno 1 secondo fra l'una e l'altra (`Limiter`). Mai scansionare id oltre l'ultimo noto.
- User agent con un contatto (`XFIVE_USER_AGENT` in `.env`).
- Le risposte arrivano a volte in Windows-1252: `PageParsers::utf8()` le riporta in UTF-8.
- Gli id inesistenti **bassi** danno 404 (si saltano); gli id **oltre l'ultimo** fanno rispondere male il server (5xx)
  a ripetizione.
- Le statistiche (`op=19`) di certi tornei (padel) rispondono 500: è normale, si segnano come mancanti.
- Il calendario stampabile va chiesto passando i parametri a parte (`XfiveClient::printableCalendar`): se si mette
  `?t=..&sk=..` nell'indirizzo, la libreria HTTP di Laravel li cancella e torna la home.

## Identificativi

| Cosa | Esempio | Dove si vede |
|---|---|---|
| Stagione (`sid`) | 8 = 2026/2027, 7 = 2025/2026, 6 = 2024/2025, 5 = 2023/2024, 4 = 2022/2023 | link `season/{sid}/{aaaaAAAA}/` nell'intestazione dei tornei; `config/amir.php` |
| Torneo (`tid`) | 187 = CITTADELLA 2026/27, 33 = Uispic Serie A 2022/23 | `/it/tournament/{tid}/...` |
| Club | 159 = AMIR COSTRUZIONI | `/it/team-h/{clubId}/...`, `tmid` nelle chiamate |
| Squadra in un torneo (iscrizione) | 3604 = AMIR nel torneo 187 | `/it/team/{teamId}/...` (cambia a ogni torneo) |
| Giocatore in un torneo | 62631 | `/it/player/{tpid}/...` (cambia a ogni torneo) |
| Giocatore globale | 2364 = Fracchia | `/it/player-info/{pid}/...`, nome del file foto `player/q/2364-....png` |
| Partita | 14584 | `/it/match/{mid}/...` |
| Classifica (teamtable) | 647 = CITTADELLA | `/it/teamtable/{ttid}/...`, tendina `changeTeamtable` in home |

Gli slug negli indirizzi sono decorativi: `/it/tournament/187/x/stream/` funziona come con lo slug vero.

## Chiamate interne (POST, risposta JSON `{html: "..."}` oppure `{errors: ...}`)

Tutte su `https://www.xfivesport.it/system/include/ajax/public/`, con `lid=1` (la lega XFive) sempre aggiunto.

| Script e parametri | Cosa dà | Lettore |
|---|---|---|
| `league.php` `op=23&sid={sid}&status=ongoing|incoming|previous` | elenco tornei della stagione (nome, sport, n. squadre, date, locandina, link) | `PageParsers::tournamentList` |
| `league.php` `op=20&tid={tid}` | classifica: tabella nomi a sinistra, numeri a destra (Pt G V N P F S +/- FP), più tabelle se ci sono gironi | `PageParsers::standings` |
| `league.php` `op=19&tid={tid}&type=score|top-player|discipline` | marcatori, miglior giocatore, disciplina (tabella) | `StatsTableParser` |
| `league.php` `op=21&tid={tid}` | squadre iscritte: link `/it/team/{teamId}/`, nome, stemma | `PageParsers::teamList` |
| `team.php` `op=1&tmid={clubId}&sid={sid}` | tornei di un club in una stagione (frammento HTML) | `ClubHistoryParser` |
| `finder.php` `term={testo}` | ricerca globale (tornei, squadre, giocatori): JSON con `url`, `labelt`, `avatar` | `XfiveClient::finder` |
| `league.php` `op=8&tid&poid` | foto di un torneo (album) | non usato |

`op=23` risponde «Non ci sono ancora dati» per le stagioni fino alla 5 (2023/24): per quelle si trovano i tornei
provando gli id da 1 a 97 (sotto il primo id della stagione 6) e leggendo l'intestazione.

## Pagine (GET, HTML)

| Indirizzo | Cosa dà | Lettore |
|---|---|---|
| `/it/tournament/{tid}/x/stream/` | intestazione del torneo: `.tournament-title`, `.category-title`, `.season-title a` (sid), `.tournament-sport`, `.tournament-flyer img`; menu con `t-calendar`, `t-teamtable`, `t-player-stats`, `t-team-list`, `docs` | `PageParsers::tournamentHeader` |
| `/t-printable.php?t={tid}&sk=calendar` | calendario completo con risultati (id partita, giornata, squadre con club id e stemma, data/ora, campo) | `PrintableCalendarParser` |
| `/it/t-calendar/`, `/it/t-teamtable/`, `/it/t-player-stats/`, `/it/t-team-list/{tid}/x/` | solo contenitori: i dati arrivano dalle chiamate interne sopra | (niente) |
| `/it/docs/{tid}/x/` | modulistica: link a PDF/immagini su `cdn.enjore.com/.../doc/tournament_doc/` | `PageParsers::docs` |
| `/it/team/{teamId}/x/` | squadra nel torneo (pagina già completa): nome, stemma, `Presidente:`/`Allenatore:` in `.team-staff-container .info`, link club `a#linkToHistory`, rosa in `.player-container` (nome, `.player-role`, `.player-number`, foto `img.round-img`, nazione `img.player-country-img@title`), ultime partite | `PageParsers::teamPage` |
| `/it/team-h/{clubId}/x/` | storico del club: link `/it/player-info/{pid}/` dei giocatori, sottopagine `/staff/` e `/tournament/` | `PageParsers::clubPage` |
| `/it/player-info/{pid}/x/` | profilo globale: età, nazionalità, club e tornei giocati | `PlayerInfoParser` |
| `/it/player/{tpid}/x/` | giocatore nel torneo: partite, gol, autogol (pochi dati, non archiviato) | (niente) |
| `/it/match/{mid}/x/` | referto: arbitro, campo, distinte, marcatori, cartellini, miglior giocatore | `MatchPageParser` |
| `/it/league/1/xfive/season/{sid}/{aaaaAAAA}/tournament-list/` | contenitore dell'elenco stagioni (i dati da `op=23`) | (niente) |
| `/it/tournament-activity/{sid}/{attività}/{livello}/` | tornei per sport e livello (es. `8-calcio-a-8/2-dilettanti`), con tendina `sid` | (niente, basta `op=23`) |

Immagini: stemmi `cdn.enjore.com/wl/xfivesport_it/img/team/badge/{q|s|b}/...` (q 50 px, s 200 px, b 500 px), foto
giocatori `img/player/{q|s}/{pid}-....`, locandine `img/tournament/{q|b}/...`. I segnaposto hanno `ph_` nel nome.

## Area amministrazione (con il nostro account, vedi `XfiveAdminClient`)

Login `POST /login.php` (`mail`, `password`, `btn=t-btn`, `url`), rosa in
`POST /system/include/ajax/manager/manage_tournament/team.php` (`op=1`, `tmid=159`, DataTables), modulistica in
`manage_tournament.php?tmid=159&sk=team`. Nessuna sessione viene salvata: accesso nuovo a ogni lettura.

## L'archivio locale (`php artisan xfive:archive`)

Cartella `Desktop\AMIR\xfive-archive` (o `XFIVE_ARCHIVE_DIR`): `raw/` copie grezze (cache: quello che c'è non si
richiede più), `json/` dati estratti, `md/` pagine leggibili, `img/` immagini, `manifest.json` tappe fatte.

| Tappa | Cosa fa | Richieste |
|---|---|---|
| `tournaments` | elenco per stagione (`--seasons=6,7,8`) e scansione id (`--scan=1-97`), intestazione di ogni torneo → `json/tournaments.json`, `json/tournaments/{tid}/tournament.json` | 3 per stagione + 1 per torneo |
| `details` | per torneo: `calendar.json`, `standings.json`, `player-stats.json`, `teams.json`, `docs.json` (`--only=calendar,standings,stats,teams,docs` per una sezione sola) | 7 per torneo |
| `teams` | `json/teams/{teamId}.json` (rosa, dirigenti, club) | 1 per squadra |
| `clubs` | `json/clubs/{clubId}.json` (profili giocatore) | 1 per club |
| `players` | `json/players/{pid}.json` | 1 per giocatore |
| `matches` | `json/matches/{mid}.json` per le partite giocate | 1 per partita |
| `images` | `img/...` | 1 per immagine |
| `markdown` | `md/index.md`, `md/tornei/{tid}-{slug}.md` | 0 |

Opzioni: `--limit=N` budget di richieste (si riprende la volta dopo), `--refresh` rilegge anche la cache, `--gap=1.0`
pausa, `--hours=23-6` finestra oraria. `map` e `probe` servono a studiare pagine e chiamate nuove.

Prima passata completa (8/10/2026, stagioni 6-8): 83 tornei, 3.160 partite (2.668 giocate), 857 squadre, 340 club,
880 profili, 2.666 referti, 3.952 immagini, 468 MB, circa 8.600 richieste in 5 giri. La scansione degli id 1-97
(finita il 9/10/2026, giri 6-8) ha trovato altri 74 tornei (stagioni fino alla 2023/24): in tutto 157 tornei,
1.546 squadre, 517 club, 4.810 referti, 6.522 immagini, 157 pagine Markdown, 702 MB. Il 9/10 notte XFive ha servito
per qualche ora un certificato autofirmato (`cURL error 60`): in quel caso il comando si ferma da solo e si rilancia dopo.

### Dal sito online (Mixed Zone)

Le stesse letture, per sezione e a pezzi da 40 secondi con cursore, le fa `App\Services\Zone\ZoneSync` sul server
(scope `zone-tournaments`, `zone-calendar`, `zone-standings`, `zone-stats`, `zone-teams`, `zone-reports`, `zone-players`;
`zone` = le notturne in fila), con gli stessi lettori di questa pagina. Si lanciano dal centro di sincronizzazione, da
`php artisan xfive:zone-sync <sezione>` o dal cron `/api/v1/cron/zone`. L'archivio locale resta la fonte per il primo
caricamento (`xfive:zone-export` → «Carica archivio») e per la modulistica, che dal vivo non si rilegge.

### Come fare un aggiornamento mirato

- Solo risultati e calendari: `php artisan xfive:archive details --only=calendar --refresh` (1 richiesta per torneo).
- Solo classifiche: `details --only=standings --refresh`. Solo statistiche: `--only=stats`.
- Nuove rose: `details --only=teams --refresh` e poi `teams --refresh`.
- Un torneo nuovo: `tournaments` (prende gli elenchi per stagione) e poi `all` (fa solo quello che manca).
- Tutto da capo per un torneo: cancellare `raw/*-{tid}*` e `json/tournaments/{tid}/`, poi `all`.
