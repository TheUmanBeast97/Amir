# Mixed Zone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Una terza area del sito («Mixed Zone») che mostra tutti i dati XFive di calcio (tornei, squadre, giocatori, partite, statistiche), uno scambio di area animato in 3D e un centro di sincronizzazione con sala di controllo.

**Architecture:** Il backend Laravel riceve tabelle `xf_*` separate da quelle AMIR, popolate una volta dall'archivio locale (export a pezzi + import) e poi aggiornate da XFive a pezzi da 40 s con un cursore (`XfiveRoutine` scope `zone-*`); una API pubblica `/public/zone/*` con `PublicCache` serve il frontend. Il frontend TanStack Start ha una scocca sola con tema per area (`data-area`), le pagine `/mixed-zone/*`, il selettore/portale di area e la pagina staff `/admin/sincronizzazione`.

**Tech Stack:** Laravel 12 (SQLite in locale, Postgres Neon online), PHPUnit; TanStack Start + React 19 + Tailwind 4 + motion/react + Recharts, bun, vitest.

**Spec:** `docs/superpowers/specs/2026-10-09-mixed-zone-design.md`

## Global Constraints

- Lingua di codice, commenti, test e testi: italiano; mai il trattino lungo; commit senza coautori.
- Solo calcio: `sport` che inizia per «Calcio»; padel e pallavolo mai importati.
- Nessuna immagine nel database: `badge_url`, `photo_url`, `flyer_url` sono indirizzi del CDN XFive (`cdn.enjore.com`), serviti via `sized()` di `frontend/src/lib/img.ts`.
- Richieste a Vercel sotto i 4,5 MB; lavori lunghi a pezzi entro `config('amir.sync.budget')` (40 s) con `remaining` e cursore.
- Dati pubblici = solo ciò che XFive pubblica (nomi, foto, età, statistiche); niente date di nascita né dati dell'area amministrativa.
- Tutti i test backend passano su SQLite (`php artisan test`) e su Postgres (`php -d extension=pdo_pgsql -d extension=pgsql vendor/bin/phpunit`, Postgres incorporato: vedi memoria/README); frontend `bun x tsc --noEmit` silenzioso e `bun run test` verde; file toccati formattati con `bun x prettier --write`.
- Commit piccoli e descrittivi, `git push` alla fine di ogni task (Vercel pubblica da `main`).
- Nomi delle aree: «Mixed Zone», «Amir Hub», «Staff Area»; colore Mixed Zone ciano `#22d3ee` (`oklch(0.82 0.14 200)`), Staff Area ambra (`--warning` esistente).

## Review Focus

1. Un torneo con gironi (più tabelle in `standings.json`): la classifica va mostrata per girone, non fusa. Test in Task 2 (`test_standings_keep_their_groups`).
2. Referto con un giocatore che non è nella rosa della squadra (`ref` sconosciuto): la riga va comunque salvata con `player_id` null e deve comparire nel referto. Test in Task 2 (`test_reports_fill_lineups_and_keep_unknown_players`).
3. `kickoff_raw` senza anno («lun 12/10 21:00»): l'anno si ricava dalla stagione (mesi 7-12 = primo anno, 1-6 = secondo). Test in Task 2 (`test_kickoff_is_dated_with_the_season`).
4. Ricerca con accenti e maiuscole («caffe km0» deve trovare «CAFFÈ KM0»): confronto senza accenti. Test in Task 7.
5. Sync a pezzi interrotta a metà torneo: al giro dopo riparte dal cursore senza rifare i tornei già letti e senza perderne uno. Test in Task 9.

---

## Struttura dei file

Backend (nuovi):
- `database/migrations/2026_10_12_000001_create_xfive_zone_tables.php`
- `app/Models/Zone/{XfSeason,XfTournament,XfClub,XfTeam,XfPlayer,XfTeamPlayer,XfMatch,XfMatchPlayer,XfStanding,XfPlayerStat,XfDocument,XfSyncState}.php`
- `app/Services/Zone/ZoneImporter.php` (dai JSON dell'archivio o dai pezzi caricati alle tabelle, idempotente)
- `app/Services/Zone/ZoneExporter.php` (dall'archivio locale ai pezzi gzip)
- `app/Services/Zone/ZoneSync.php` (aggiornamenti dal vivo per sezione, con cursore)
- `app/Services/Zone/ZoneQueries.php` (letture per l'API pubblica)
- `app/Services/Zone/ZoneStats.php` (statistiche di sempre, profilo giocatore, record club)
- `app/Support/Kickoff.php` (data da `kickoff_raw` + stagione)
- `app/Http/Controllers/Api/ZoneController.php` (pubblico), `ZoneImportController.php` (staff)
- `app/Console/Commands/XfiveZoneExport.php` (`xfive:zone-export`), `XfiveZoneImport.php` (`xfive:zone-import`, per i test locali)
- `tests/Fixtures/zone/` (JSON sintetici: un torneo con 2 gironi, 3 squadre, 2 referti, classifiche, statistiche, 1 club, 2 profili)
- `tests/Feature/Zone{Import,Api,Sync,Export}Test.php`, `tests/Unit/KickoffTest.php`

Backend (modificati): `routes/api.php`, `app/Services/Xfive/XfiveRoutine.php` (scope `zone*`), `vercel.json` (cron `zone`), `app/Http/Controllers/Api/SyncController.php` (`progress`).

Frontend (nuovi):
- `src/lib/area.ts`, `src/test/area.test.ts`
- `src/components/area/AreaSwitch.tsx`, `src/components/area/AreaPortal.tsx`
- `src/api/zone-types.ts` (contratto API della Mixed Zone), `src/api/zone.ts` (client + hook)
- `src/components/zone/` (`SeasonChips`, `SportChips`, `MatchRow`, `StandingsTable`, `TournamentCard`, `ClubCrest`, `ZoneSearch`, `StatsBoard`)
- `src/routes/mixed-zone.tsx` (layout), `mixed-zone.index.tsx`, `mixed-zone.tornei.index.tsx`, `mixed-zone.tornei.$id.tsx`, `mixed-zone.squadre.index.tsx`, `mixed-zone.squadre.$id.tsx`, `mixed-zone.giocatori.index.tsx`, `mixed-zone.giocatori.$id.tsx`, `mixed-zone.partite.index.tsx`, `mixed-zone.partite.$id.tsx`, `mixed-zone.statistiche.tsx`
- `src/routes/admin.sincronizzazione.tsx`, `src/components/admin/ControlRoom.tsx`

Frontend (modificati): `src/components/AppShell.tsx`, `src/styles.css`, `src/routes/__root.tsx` (data-area), `src/components/admin/sync.ts` (progress + nuovi scope), `src/api/types.ts` (SyncScope), `src/routes/admin.impostazioni.tsx` (collegamento), `src/routes/rosa.$playerId.tsx` e `classifica.tsx` (link incrociati), `src/components/admin/AdminShell.tsx` (voce di menu).

---

### Task 1: Tabelle e modelli `xf_*`

**Files:**
- Create: `backend/database/migrations/2026_10_12_000001_create_xfive_zone_tables.php`
- Create: `backend/app/Models/Zone/*.php` (12 modelli)
- Test: `backend/tests/Feature/ZoneImportTest.php` (solo il test della migrazione)

**Interfaces:**
- Produces: le tabelle e i modelli Eloquent (`$guarded = []`, cast `array` per i json, `datetime` per le date). Chiavi primarie NON autoincrementali per `xf_seasons`, `xf_tournaments`, `xf_clubs`, `xf_teams`, `xf_players`, `xf_matches` (sono gli id XFive): `public $incrementing = false;`.

- [ ] **Step 1: Scrivere la migrazione**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** I dati pubblici di XFive per la Mixed Zone: tabelle separate da quelle di AMIR, con gli id di XFive come chiavi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xf_seasons', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // sid XFive
            $t->string('label', 9); // 2026/2027
            $t->timestamps();
        });
        Schema::create('xf_tournaments', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('season_id')->index();
            $t->string('name');
            $t->string('slug');
            $t->string('sport'); // «Calcio a 8 - Maschile»
            $t->unsignedTinyInteger('format')->nullable(); // 5, 7, 8, 9, 11
            $t->string('gender', 20)->nullable();
            $t->string('category')->nullable();
            $t->string('flyer_url', 500)->nullable();
            $t->unsignedSmallInteger('teams_count')->nullable();
            $t->string('dates')->nullable();
            $t->string('status', 12)->default('previous'); // ongoing, incoming, previous
            $t->timestamp('calendar_synced_at')->nullable();
            $t->timestamp('standings_synced_at')->nullable();
            $t->timestamp('stats_synced_at')->nullable();
            $t->timestamp('teams_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('xf_clubs', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('badge_url', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('xf_teams', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // id della squadra nel torneo
            $t->unsignedInteger('tournament_id')->index();
            $t->unsignedInteger('club_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('badge_url', 500)->nullable();
            $t->json('staff')->nullable();
            $t->timestamps();
        });
        Schema::create('xf_players', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // pid globale
            $t->string('name');
            $t->string('slug')->nullable()->index();
            $t->string('photo_url', 500)->nullable();
            $t->unsignedSmallInteger('age')->nullable();
            $t->string('nationality')->nullable();
            $t->json('profile')->nullable(); // club e tornei dal profilo
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('xf_team_players', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('team_id')->index();
            $t->unsignedInteger('tpid'); // id del giocatore nel torneo
            $t->unsignedInteger('player_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('role')->nullable();
            $t->string('number', 4)->nullable();
            $t->string('photo_url', 500)->nullable();
            $t->string('country')->nullable();
            $t->unique(['team_id', 'tpid']);
        });
        Schema::create('xf_matches', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // mid
            $t->unsignedInteger('tournament_id')->index();
            $t->unsignedSmallInteger('round')->nullable();
            $t->string('round_label')->nullable();
            $t->unsignedInteger('round_id')->nullable();
            $t->unsignedInteger('home_club_id')->nullable()->index();
            $t->unsignedInteger('away_club_id')->nullable()->index();
            $t->string('home_name');
            $t->string('away_name');
            $t->timestamp('kickoff_at')->nullable()->index();
            $t->string('kickoff_raw')->nullable();
            $t->string('venue')->nullable();
            $t->unsignedTinyInteger('home_score')->nullable();
            $t->unsignedTinyInteger('away_score')->nullable();
            $t->boolean('played')->default(false);
            $t->string('referee')->nullable();
            $t->boolean('has_report')->default(false);
            $t->timestamps();
        });
        Schema::create('xf_match_players', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('match_id')->index();
            $t->string('side', 4); // home, away
            $t->unsignedInteger('tpid');
            $t->unsignedInteger('player_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->unsignedTinyInteger('goals')->default(0);
            $t->unsignedTinyInteger('yellow')->default(0);
            $t->unsignedTinyInteger('red')->default(0);
            $t->boolean('mvp')->default(false);
            $t->string('photo_url', 500)->nullable();
            $t->unique(['match_id', 'tpid']);
        });
        Schema::create('xf_standings', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tournament_id')->index();
            $t->string('group')->nullable();
            $t->unsignedSmallInteger('position');
            $t->unsignedInteger('club_id')->nullable()->index();
            $t->string('name');
            $t->string('badge_url', 500)->nullable();
            $t->json('values'); // Pt, G, V, N, P, F, S, +/-, FP
            $t->unique(['tournament_id', 'group', 'position']);
        });
        Schema::create('xf_player_stats', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tournament_id')->index();
            $t->string('type', 12); // score, top-player, discipline
            $t->unsignedSmallInteger('position');
            $t->string('name');
            $t->string('team')->nullable();
            $t->string('photo_url', 500)->nullable();
            $t->json('values');
            $t->unique(['tournament_id', 'type', 'position']);
        });
        Schema::create('xf_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tournament_id')->index();
            $t->string('url', 500);
            $t->string('title')->nullable();
            $t->unique(['tournament_id', 'url']);
        });
        Schema::create('xf_sync_state', function (Blueprint $t) {
            $t->string('section', 32)->primary();
            $t->timestamp('synced_at')->nullable();
            $t->json('counts')->nullable();
            $t->json('cursor')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['xf_sync_state', 'xf_documents', 'xf_player_stats', 'xf_standings', 'xf_match_players', 'xf_matches', 'xf_team_players', 'xf_players', 'xf_teams', 'xf_clubs', 'xf_tournaments', 'xf_seasons'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

Nota Postgres: l'indice unico su `xf_standings(tournament_id, group, position)` con `group` null non impedisce doppioni (i NULL sono distinti): l'importer scrive sempre `group` come stringa (`''` quando non c'è) e l'API lo rimanda come `null`.

- [ ] **Step 2: Scrivere i modelli** in `app/Models/Zone/` (uno per tabella; esempio):

```php
<?php

namespace App\Models\Zone;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un torneo XFive (id = tid di XFive). */
class XfTournament extends Model
{
    protected $table = 'xf_tournaments';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['calendar_synced_at' => 'datetime', 'standings_synced_at' => 'datetime', 'stats_synced_at' => 'datetime', 'teams_synced_at' => 'datetime'];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(XfMatch::class, 'tournament_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(XfTeam::class, 'tournament_id');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(XfStanding::class, 'tournament_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(XfPlayerStat::class, 'tournament_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(XfDocument::class, 'tournament_id');
    }
}
```
`XfSyncState`: `$primaryKey = 'section'`, `$keyType = 'string'`, `$incrementing = false`, `$timestamps = false`, cast `counts`/`cursor` array, `synced_at` datetime. `XfTeamPlayer`, `XfMatchPlayer`, `XfStanding`, `XfPlayerStat`, `XfDocument`: `$timestamps = false`; `XfStanding`/`XfPlayerStat` cast `values` array; `XfTeam` cast `staff` array; `XfPlayer` cast `profile` array, `synced_at` datetime; `XfMatch` cast `kickoff_at` datetime, `played`/`has_report` boolean.

- [ ] **Step 3: Test della migrazione** in `tests/Feature/ZoneImportTest.php`:

```php
public function test_the_zone_tables_exist_with_xfive_ids_as_keys(): void
{
    $t = XfTournament::create(['id' => 187, 'season_id' => 8, 'name' => 'CITTADELLA', 'slug' => 'cittadella', 'sport' => 'Calcio a 8 - Maschile', 'format' => 8]);
    $this->assertSame(187, $t->refresh()->id);
    XfSyncState::create(['section' => 'zone-calendar', 'cursor' => ['tournament' => 187]]);
    $this->assertSame(['tournament' => 187], XfSyncState::find('zone-calendar')->cursor);
}
```

- [ ] **Step 4: `php artisan migrate` in locale e `php artisan test --filter=ZoneImportTest`**: verde.
- [ ] **Step 5: Commit** `Mixed Zone: tabelle e modelli xf_*`.

---

### Task 2: `Kickoff` e `ZoneImporter` (dai JSON dell'archivio alle tabelle)

**Files:**
- Create: `backend/app/Support/Kickoff.php`, `backend/app/Services/Zone/ZoneImporter.php`
- Create: `backend/tests/Fixtures/zone/{tournaments.json, tournaments/901/tournament.json, tournaments/901/calendar.json, tournaments/901/standings.json, tournaments/901/player-stats.json, tournaments/901/teams.json, tournaments/901/docs.json, teams/5001.json, teams/5002.json, clubs/301.json, players/7001.json, matches/90001.json, matches/90002.json}` (sintetici, stessa forma dell'archivio vero: vedi `docs/XFIVE_SITE.md` e gli esempi sotto)
- Test: `backend/tests/Unit/KickoffTest.php`, `backend/tests/Feature/ZoneImportTest.php`

**Forma dei JSON dell'archivio (vera, da rispettare nelle fixture):**
- `tournament.json`: `{id, exists, slug, name, category, season_id, season: "2026/2027", sport: "Calcio a 8 - Maschile", flyer_url, teams, dates, status}`.
- `calendar.json`: lista di `{xfive_match_id, round_id, round, round_label, tournament_id, played, home: {club_id, name, badge_url}, away: {...}, kickoff_raw: "lun 12/10 21:00", venue, home_score, away_score}`.
- `standings.json`: lista di gruppi `{group: null|"Girone A", columns: [{key: "Pt", label: "Punti Classifica"}, ...], rows: [{position, name, badge_url, values: {Pt, G, V, N, P, F, S, "+/-", FP}}]}`.
- `player-stats.json`: `{"score": {columns: ["Goal"], rows: [{position, name: "Natale M.", team, photo, values: [13]}]}, "top-player": {...}, "discipline": {columns: ["Ammonizioni", "Espulsioni"], rows: [...]}}`.
- `teams.json` (del torneo): lista `{id, slug, name, badge_url}`. `docs.json`: lista `{url, title}`.
- `teams/{teamId}.json`: `{id, tournament_id, slug, name, badge_url, club_id, club_slug, staff: {"Presidente": "...", "Allenatore": "..."}, players: [{id (tpid), slug, name: "Cognome Nome", role, number, photo_url, country}]}`.
- `clubs/{clubId}.json`: `{id, slug, name, badge_url, players: [{id (pid), slug, name}]}`.
- `players/{pid}.json`: `{id, name: "Fracchia Edoardo Giovanni 10", slug, photo_url, age, nationality, clubs: [{club_id, name, role, tournaments: [{id, name, season, format}]}]}`.
- `matches/{mid}.json`: `{id, tournament_id, round_label, kickoff_raw, referee, venue, home_score, away_score, home: {lineup: [{ref (tpid), name, slug, goals, yellow, red, mvp, photo, other: []}]}, away: {lineup: [...]}}`.

**Interfaces:**
- Produces:
  - `Kickoff::parse(?string $raw, string $seasonLabel): ?Carbon` (es. `'lun 12/10 21:00'`, `'2026/2027'` → 2026-10-12 21:00 Europe/Rome; mese 7-12 → primo anno, 1-6 → secondo; null se non si capisce).
  - `ZoneImporter` con metodi idempotenti, ognuno restituisce `array<string,int>` di conteggi:
    - `tournament(array $header): ?XfTournament` (null e nessuna scrittura se lo sport non inizia per «Calcio» o `exists` è false; crea la stagione `xf_seasons` da `season_id` + `season`; `format` dal numero in «Calcio a N»; `gender` dalla parte dopo « - »).
    - `calendar(int $tid, array $rows, string $seasonLabel): array` (upsert `xf_matches`; `played` = entrambi i punteggi non null; crea `xf_clubs` mancanti da `home/away` con `club_id`, `name`, `badge_url`).
    - `standings(int $tid, array $groups): array` (cancella e riscrive le righe del torneo; `club_id` cercando in `xf_clubs` per nome normalizzato).
    - `playerStats(int $tid, array $tables): array`, `documents(int $tid, array $docs): array`.
    - `team(array $team): array` (upsert `xf_teams` + righe `xf_team_players`; `player_id` abbinato se un'altra riga dello stesso club con lo stesso slug ha già `player_id`).
    - `club(array $club): array` (upsert club + per ogni giocatore `{id, slug}` aggiorna `player_id` nelle rose delle squadre con quel `club_id` e quello `slug`).
    - `player(array $profile): array` (upsert `xf_players`; `name` ripulito del numero finale: «Fracchia Edoardo Giovanni 10» → «Fracchia Edoardo Giovanni»).
    - `report(array $match): array` (upsert `xf_match_players` per entrambi i lati; `referee`, `has_report` = true, punteggi e `played`; `player_id` dalla riga `xf_team_players` con lo stesso `tpid` fra le squadre del torneo).
    - `isFootball(string $sport): bool` (static), `normalize(string $s): string` (static, ascii minuscolo senza spazi doppi).

- [ ] **Step 1: Test di `Kickoff`** (`tests/Unit/KickoffTest.php`):

```php
public function test_kickoff_is_dated_with_the_season(): void
{
    $this->assertSame('2026-10-12 21:00', Kickoff::parse('lun 12/10 21:00', '2026/2027')?->format('Y-m-d H:i'));
    $this->assertSame('2027-02-03 20:30', Kickoff::parse('mer 03/02 20:30', '2026/2027')?->format('Y-m-d H:i'));
    $this->assertSame('2026-10-12 00:00', Kickoff::parse('12/10', '2026/2027')?->format('Y-m-d H:i'), 'senza ora vale mezzanotte');
    $this->assertNull(Kickoff::parse('da definire', '2026/2027'));
    $this->assertNull(Kickoff::parse(null, '2026/2027'));
}
```

- [ ] **Step 2: Implementare `Kickoff`** (regex `~(\d{1,2})/(\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?~`, fuso `Europe/Rome`, `Carbon::create`), far passare il test.

- [ ] **Step 3: Fixture** in `tests/Fixtures/zone/`. Torneo 901 «Torneo di prova» stagione 8 (2026/2027), sport «Calcio a 7 - Maschile», 3 squadre (club 301 «CAFFÈ KM0», 302 «ROSSI», 303 «BIANCHI»), due gironi in `standings.json` («Girone A» con 301 e 302, «Girone B» con 303), calendario con 3 partite (90001 giocata 3-1 fra 301 e 302, 90002 giocata 2-2, 90003 non giocata, `kickoff_raw` «lun 12/10 21:00», «mar 13/10 21:00», «lun 19/10 21:00»), `player-stats.json` con un marcatore «Verdi L.» di «CAFFÈ KM0», `docs.json` con un PDF, `teams/5001.json` (club 301, 2 giocatori: tpid 61001 «Verdi Luca» slug `luca-verdi`, tpid 61002 «Neri Marco» slug `marco-neri`), `teams/5002.json` (club 302, un giocatore tpid 61003), `clubs/301.json` (giocatori pid 7001 `luca-verdi`, 7002 `marco-neri`), `players/7001.json` (name «Verdi Luca 9», età 30, Italia, clubs con torneo 901), `matches/90001.json` (lineup home con ref 61001 goals 2 mvp true, 61002 goals 1; away con ref 61999 «Sconosciuto Ugo» che NON è in nessuna rosa, goals 1), `matches/90002.json`. In `tournaments.json` anche un torneo 902 con sport «Padel doppio - Maschile».

- [ ] **Step 4: Test dell'importer** (`ZoneImportTest`), con un helper `private function fixture(string $rel): array { return json_decode((string) file_get_contents(base_path("tests/Fixtures/zone/{$rel}")), true); }`:

```php
public function test_only_football_tournaments_are_imported(): void
{
    $imp = app(ZoneImporter::class);
    $this->assertNotNull($imp->tournament($this->fixture('tournaments/901/tournament.json')));
    $this->assertNull($imp->tournament(['id' => 902, 'exists' => true, 'name' => 'Padel', 'slug' => 'padel', 'season_id' => 8, 'season' => '2026/2027', 'sport' => 'Padel doppio - Maschile']));
    $this->assertSame([901], XfTournament::pluck('id')->all());
    $this->assertSame(['format' => 7, 'gender' => 'Maschile'], XfTournament::find(901)->only('format', 'gender'));
    $this->assertSame('2026/2027', XfSeason::find(8)->label);
}

public function test_calendar_standings_stats_and_docs_are_imported_and_rerunnable(): void
{
    $imp = app(ZoneImporter::class);
    $imp->tournament($this->fixture('tournaments/901/tournament.json'));
    foreach ([1, 2] as $run) {
        $imp->calendar(901, $this->fixture('tournaments/901/calendar.json'), '2026/2027');
        $imp->standings(901, $this->fixture('tournaments/901/standings.json'));
        $imp->playerStats(901, $this->fixture('tournaments/901/player-stats.json'));
        $imp->documents(901, $this->fixture('tournaments/901/docs.json'));
    }
    $this->assertSame(3, XfMatch::count());
    $this->assertSame(3, XfClub::count(), 'i club nascono dal calendario');
    $this->assertSame('2026-10-12 21:00', XfMatch::find(90001)->kickoff_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
    $this->assertTrue(XfMatch::find(90001)->played);
    $this->assertFalse(XfMatch::find(90003)->played);
    $this->assertSame(['Girone A' => 2, 'Girone B' => 1], XfStanding::where('tournament_id', 901)->get()->countBy('group')->all());
    $this->assertSame(301, XfStanding::where('name', 'CAFFÈ KM0')->first()->club_id);
    $this->assertSame(1, XfPlayerStat::where('type', 'score')->count());
    $this->assertSame(1, XfDocument::count());
}

public function test_teams_clubs_and_profiles_link_tournament_players_to_global_profiles(): void
{
    $imp = app(ZoneImporter::class);
    $imp->tournament($this->fixture('tournaments/901/tournament.json'));
    $imp->team($this->fixture('teams/5001.json'));
    $this->assertNull(XfTeamPlayer::where('tpid', 61001)->first()->player_id, 'senza il club non si conosce il profilo');
    $imp->club($this->fixture('clubs/301.json'));
    $this->assertSame(7001, XfTeamPlayer::where('tpid', 61001)->first()->player_id);
    $imp->player($this->fixture('players/7001.json'));
    $p = XfPlayer::find(7001);
    $this->assertSame(['Verdi Luca', 30, 'Italia'], [$p->name, $p->age, $p->nationality]);
}

public function test_reports_fill_lineups_and_keep_unknown_players(): void
{
    $imp = app(ZoneImporter::class);
    $imp->tournament($this->fixture('tournaments/901/tournament.json'));
    $imp->calendar(901, $this->fixture('tournaments/901/calendar.json'), '2026/2027');
    $imp->team($this->fixture('teams/5001.json'));
    $imp->club($this->fixture('clubs/301.json'));
    $imp->report($this->fixture('matches/90001.json'));
    $imp->report($this->fixture('matches/90001.json'));

    $m = XfMatch::find(90001);
    $this->assertTrue($m->has_report);
    $rows = XfMatchPlayer::where('match_id', 90001)->get();
    $this->assertCount(3, $rows);
    $this->assertSame(7001, $rows->firstWhere('tpid', 61001)->player_id);
    $this->assertNull($rows->firstWhere('tpid', 61999)->player_id, 'il giocatore fuori rosa resta nel referto');
    $this->assertTrue($rows->firstWhere('tpid', 61001)->mvp);
}
```

- [ ] **Step 5: Implementare `ZoneImporter`** (classe `final`, costruttore vuoto; `upsert` di Eloquent sulle chiavi uniche; `group` salvato come `''` se null). Far passare i test.
- [ ] **Step 6: Test su Postgres** (`php -d extension=pdo_pgsql -d extension=pgsql vendor/bin/phpunit --filter=Zone`).
- [ ] **Step 7: Commit** `Mixed Zone: importazione dei dati XFive dall'archivio (ZoneImporter)`.

---

### Task 3: Export a pezzi e import via API/comando

**Files:**
- Create: `backend/app/Services/Zone/ZoneExporter.php`, `backend/app/Console/Commands/XfiveZoneExport.php`, `backend/app/Console/Commands/XfiveZoneImport.php`, `backend/app/Http/Controllers/Api/ZoneImportController.php`
- Modify: `backend/routes/api.php` (dopo `/backup/import-local`): `Route::post('/zone/import', [ZoneImportController::class, 'chunk'])->middleware('throttle:restore'); Route::get('/zone/status', [ZoneImportController::class, 'status']);`
- Test: `backend/tests/Feature/ZoneExportTest.php`

**Interfaces:**
- `ZoneExporter::export(string $jsonDir, string $outDir, int $maxBytes = 3_500_000): array{files: array<int,string>, counts: array<string,int>}`. `$jsonDir` è la cartella che contiene `tournaments.json`, `tournaments/`, `teams/`, `clubs/`, `players/`, `matches/` (nell'archivio vero è `<archivio>/json`). Scrive `zone-001-tournaments.json.gz`, `zone-002-clubs.json.gz`, ... numerati nell'ordine di import: `tournaments` → `clubs` → `teams` → `players` → `calendar` → `tables` (standings, stats, docs per torneo) → `reports`; ogni file è un JSON `{"section": "reports", "items": [...]}` compresso con `gzencode`, spezzato in più file quando il JSON supera `$maxBytes` prima della compressione. Solo tornei di calcio (`ZoneImporter::isFootball`) e solo squadre, partite, referti di quei tornei; club citati nei loro calendari o rose; profili con almeno un torneo di calcio.
- Forma degli `items` per sezione: `tournaments` = intestazioni (`tournament.json`); `clubs` = `clubs/{id}.json`; `teams` = `teams/{id}.json`; `players` = `players/{id}.json`; `calendar` = `{tournament_id, season, rows}`; `tables` = `{tournament_id, standings, player_stats, docs}`; `reports` = `matches/{id}.json`.
- `xfive:zone-export {--dir=} {--out=}`: dir di serie quella di `XfiveArchive::dir()` + `/json` (riusare la stessa logica: `XFIVE_ARCHIVE_DIR` o Desktop\AMIR\xfive-archive), out di serie `<archivio>/export`.
- `POST /zone/import` (multipart `file` = un pezzo .json.gz, max 4096 KB): `gzdecode`, legge `section` e `items`, chiama l'importer per sezione; risposta `{section, items: n, counts}`; aggiorna `xf_sync_state` della sezione (`synced_at`, `counts`).
- `GET /zone/status`: `{tournaments, clubs, teams, players, matches, reports, sections: [{section, synced_at, counts}]}`.
- `xfive:zone-import {dir}`: importa in locale tutti i pezzi di una cartella in ordine di nome (per provare con i dati veri).

- [ ] **Step 1: Test** (`ZoneExportTest`): esporta dalle fixture (`tests/Fixtures/zone` ha già la struttura di `json/`) in una cartella temporanea con `maxBytes` piccolissimo (es. 300) per forzare più pezzi; verifica che i file siano numerati, che il padel non ci sia, poi li manda a `POST /zone/import` in ordine (Sanctum) e verifica i conteggi e `GET /zone/status` (`tournaments` 1, `matches` 3, `reports` 2).
- [ ] **Step 2: Implementare** exporter, comandi e controller; far passare i test (SQLite e Postgres).
- [ ] **Step 3: Prova reale in locale**: `php artisan xfive:zone-export` sull'archivio vero, poi `php artisan xfive:zone-import <archivio>/export` sul DB SQLite locale; riportare conteggi, numero e peso dei pezzi, peso di `database.sqlite` prima/dopo.
- [ ] **Step 4: Commit** `Mixed Zone: export dell'archivio a pezzi e caricamento online`.

---

### Task 4: Scocca a tre aree, tema per area e `lib/area.ts`

**Files:**
- Create: `frontend/src/lib/area.ts`, `frontend/src/test/area.test.ts`, `frontend/src/routes/mixed-zone.tsx`, `frontend/src/routes/mixed-zone.index.tsx` (provvisoria)
- Modify: `frontend/src/styles.css`, `frontend/src/components/AppShell.tsx`, `frontend/src/routes/__root.tsx`

**Interfaces:**
- `export type AreaId = "hub" | "mixed" | "staff";`
- `export const AREAS: Record<AreaId, { id: AreaId; name: string; tagline: string; preview: [string, string, string]; entry: string; accent: string }>` (`hub`: «Amir Hub», «La casa di AMIR COSTRUZIONI», [«Calendario», «Rosa», «Storico»], `/`, il rosso attuale `#d4342c`; `mixed`: «Mixed Zone», «Tutto XFive Alessandria», [«Tornei», «Squadre», «Statistiche»], `/mixed-zone`, `#22d3ee`; `staff`: «Staff Area», «Gestione della squadra», [«Convocazioni», «Quote», «Grafiche»], `/admin`, `#f59e0b`).
- `export function areaOf(pathname: string): AreaId` (`/admin...` → staff, `/mixed-zone...` → mixed, altrimenti hub; `/p/...` resta hub).
- `export const ZONE_NAV = [{ to: "/mixed-zone", label: "Home", icon: Home }, { to: "/mixed-zone/tornei", label: "Tornei", icon: Trophy }, { to: "/mixed-zone/squadre", label: "Squadre", icon: Shield }, { to: "/mixed-zone/giocatori", label: "Giocatori", icon: Users }, { to: "/mixed-zone/partite", label: "Partite", icon: CalendarDays }, { to: "/mixed-zone/statistiche", label: "Statistiche", icon: BarChart3 }] as const` (icone lucide).

- [ ] **Step 1: Test** `area.test.ts`: `areaOf("/")`, `areaOf("/rosa/3")`, `areaOf("/p/abc")` → hub; `areaOf("/mixed-zone")`, `areaOf("/mixed-zone/tornei/187")` → mixed; `areaOf("/admin/login")` → staff; `AREAS.mixed.entry === "/mixed-zone"`.
- [ ] **Step 2: `lib/area.ts`**, test verde.
- [ ] **Step 3: Tema** in `styles.css` (controllare prima quali variabili esistono in `:root` e `.light`, usare solo quelle):

```css
/* Mixed Zone: ciano su grafite, niente rosso; Staff Area: ambra. I valori di base restano quelli di Amir Hub. */
:root[data-area="mixed"] {
  --primary: oklch(0.82 0.14 200);
  --primary-foreground: oklch(0.15 0.02 220);
  --ring: oklch(0.82 0.14 200);
  --sidebar-accent: oklch(0.26 0.03 220);
}
:root[data-area="mixed"].light {
  --primary: oklch(0.6 0.13 200);
  --primary-foreground: oklch(0.98 0 0);
}
:root[data-area="staff"] {
  --primary: oklch(0.78 0.16 70);
  --primary-foreground: oklch(0.2 0.03 70);
  --ring: oklch(0.78 0.16 70);
}
:root[data-area="staff"].light {
  --primary: oklch(0.62 0.15 70);
  --primary-foreground: oklch(0.98 0 0);
}
```
- [ ] **Step 4: `__root.tsx`**: effetto che imposta `document.documentElement.dataset.area = areaOf(pathname)` a ogni cambio di indirizzo; nello script inline di avvio (quello che legge `amir_theme`, se c'è) impostare anche `data-area` dal `location.pathname` prima del primo disegno, così non c'è il lampo rosso.
- [ ] **Step 5: `AppShell.tsx`**: `const area = areaOf(pathname)`; `nav` = `area === "mixed" ? ZONE_NAV : HUB_NAV`; il logo in Mixed Zone mostra «Mixed Zone» / «XFive Alessandria» (stesso stemma, link a `/mixed-zone`); la barra mobile ha `grid-cols-6` in Mixed Zone; `StaffAccess` resta per ora (lo sostituisce il Task 5); la scritta «Stagione 2026/27» resta.
- [ ] **Step 6: `routes/mixed-zone.tsx`**: `createFileRoute("/mixed-zone")({ component: () => <Outlet /> })`; `mixed-zone.index.tsx` provvisoria con `<PageTitle kicker="Mixed Zone" title="Tutto XFive Alessandria" />`.
- [ ] **Step 7: `tsc`, test, prova nel browser** (http://localhost:8080/mixed-zone: menu ciano, nessun rosso; `/` resta rosso; `/admin` ambra).
- [ ] **Step 8: Commit** `Tre aree: tema per area e menu della Mixed Zone`.

---

### Task 5: «Cambia area»: selettore 3D e portale

**Files:**
- Create: `frontend/src/components/area/AreaSwitch.tsx`, `frontend/src/components/area/AreaPortal.tsx`
- Modify: `frontend/src/components/AppShell.tsx` (sostituisce `StaffAccess` con `AreaSwitch` nel menu laterale e `compact` nell'intestazione mobile), `frontend/src/components/admin/AdminShell.tsx` (stesso pulsante in fondo al menu staff)

**Interfaces:**
- `<AreaSwitch compact?: boolean />`: pulsante «Cambia area» (icona `LayoutGrid`, stesso stile del vecchio `StaffAccess`), apre il selettore in un `Dialog` Radix (già usato dalla figurina) a tutto schermo con fondo `bg-background/70 backdrop-blur-md`; tre carte con inclinazione al puntatore (calcolo `rotateX/rotateY` dal puntatore come in `player-card.tsx`; componente locale `TiltCard`), ognuna con nome, tagline, 3 voci di anteprima, bordo e alone nel colore dell'area, «Sei qui» su quella corrente; frecce sinistra/destra spostano il fuoco, Invio sceglie, Esc chiude.
- `<AreaPortal area={AreaId} onNavigate={() => void} onDone={() => void} />`: overlay `fixed inset-0 z-[100]` con: 6 cornici in `perspective: 1200px` che scalano da 0.2 a 3 con ritardo a scalare (`motion.div` con `translateZ`), un `<canvas>` con al massimo 120 particelle (requestAnimationFrame, 1,2 s, colore dell'area), «Benvenuto in» + nome lettera per lettera (`motion.span` con ritardo 0.04 s a lettera). `onNavigate` a 0,6 s, `onDone` a 1,2 s. Con `useReducedMotion()` true: solo dissolvenza 0,3 s con la scritta, `onNavigate` subito, `onDone` a 0,3 s.
- Flusso in `AreaSwitch`: scelta → `router.preloadRoute({ to: entry })` → monta `AreaPortal` → `onNavigate` → `router.navigate({ to })` (per staff: `/admin` se `localStorage.getItem(TOKEN_KEY)`, altrimenti `/admin/login`) → `onDone` smonta l'overlay. Se si sceglie l'area corrente: chiude e basta.

- [ ] **Step 1: Scrivere `AreaPortal`** e **`AreaSwitch`** (codice completo, commenti in italiano, `aria-label`, `role="dialog"` dal Dialog).
- [ ] **Step 2: Collegare** in `AppShell` e `AdminShell` (`StaffAccess` si elimina).
- [ ] **Step 3: Prova nel browser** (desktop e 375 px): aprire, inclinare, scegliere Mixed Zone, vedere il portale e arrivare su `/mixed-zone`; tornare all'Hub; Esc chiude; nessun errore in console; 2 screenshot nello scratchpad.
- [ ] **Step 4: `tsc`, prettier, commit** `Cambia area: selettore in 3D e portale di benvenuto`.

---

### Task 6: Contratto API della Mixed Zone (tipi) e client

**Files:**
- Create: `frontend/src/api/zone-types.ts`, `frontend/src/api/zone.ts`
- Modify: `frontend/src/api/client.ts` (esportare `request` se non lo è già)

**Interfaces** (`zone-types.ts`, da copiare tali e quali; il backend del Task 7 risponde con questi stessi campi):

```ts
export type ZoneId = number;
export interface ZoneSeason { id: ZoneId; label: string; tournaments: number }
export interface ZoneClubRef { id: ZoneId | null; name: string; badge_url: string | null }
export interface ZoneTournamentRef { id: ZoneId; name: string; season: string; sport: string; format: number | null }
export interface ZoneTournament extends ZoneTournamentRef {
  slug: string; season_id: ZoneId; gender: string | null; category: string | null; flyer_url: string | null;
  teams_count: number | null; dates: string | null; status: "ongoing" | "incoming" | "previous";
  played: number; total: number; // partite giocate / in calendario
}
export interface ZoneMatch {
  id: ZoneId; tournament: ZoneTournamentRef; round: number | null; round_label: string | null;
  home: ZoneClubRef; away: ZoneClubRef; kickoff_at: string | null; kickoff_raw: string | null; venue: string | null;
  home_score: number | null; away_score: number | null; played: boolean; has_report: boolean;
}
export interface ZoneStandingRow { position: number; club: ZoneClubRef; values: Record<string, number>; form: ("W" | "D" | "L")[] }
export interface ZoneStandings { group: string | null; columns: { key: string; label: string }[]; rows: ZoneStandingRow[] }
export interface ZoneStatRow { position: number; name: string; team: string | null; photo_url: string | null; player_id: ZoneId | null; values: number[] }
export interface ZoneStatTable { type: "score" | "top-player" | "discipline"; columns: string[]; rows: ZoneStatRow[] }
export interface ZoneDocument { url: string; title: string | null }
export interface ZoneTeam { id: ZoneId; name: string; badge_url: string | null; club: ZoneClubRef; staff: Record<string, string> }
export interface ZoneTeamPlayer { tpid: ZoneId; player_id: ZoneId | null; name: string; role: string | null; number: string | null; photo_url: string | null; country: string | null }
export interface ZoneHome {
  seasons: ZoneSeason[]; season: string; latest: ZoneMatch[]; upcoming: ZoneMatch[];
  tournaments: { sport: string; items: ZoneTournament[] }[];
  totals: { tournaments: number; clubs: number; players: number; matches: number; reports: number };
}
export interface ZoneSearchResult { clubs: ZoneClubRef[]; players: { id: ZoneId; name: string; photo_url: string | null }[]; tournaments: ZoneTournamentRef[] }
export interface ZoneTournamentPage {
  tournament: ZoneTournament; standings: ZoneStandings[]; teams: ZoneTeam[]; documents: ZoneDocument[];
  latest: ZoneMatch[]; upcoming: ZoneMatch[];
}
export interface ZoneRound { round: number | null; label: string; matches: ZoneMatch[] }
export interface ZoneTournamentStats { tables: ZoneStatTable[]; from_reports: { scorers: ZoneStatRow[]; mvp: ZoneStatRow[]; cards: ZoneStatRow[] } | null }
export interface ZoneClubSeason { season: string; tournaments: { tournament: ZoneTournamentRef; position: number | null; teams: number | null; team_id: ZoneId | null }[] }
export interface ZoneClubPage {
  club: ZoneClubRef; seasons: ZoneClubSeason[];
  roster: { team_id: ZoneId; tournament: ZoneTournamentRef; players: ZoneTeamPlayer[] } | null;
  record: { played: number; won: number; drawn: number; lost: number; goals_for: number; goals_against: number; biggest_win: ZoneMatch | null; best_streak: number };
  latest: ZoneMatch[];
}
export interface ZoneHeadToHead { played: number; wins_a: number; wins_b: number; draws: number; matches: ZoneMatch[] }
export interface ZonePlayerRef { id: ZoneId; name: string; photo_url: string | null; nationality: string | null }
export interface ZonePlayerPage {
  player: ZonePlayerRef & { age: number | null; clubs: { club: ZoneClubRef; tournaments: ZoneTournamentRef[] }[] };
  totals: { matches: number; goals: number; yellow: number; red: number; mvp: number; wins: number; draws: number; losses: number; goals_per_match: number };
  by_season: { season: string; matches: number; goals: number; yellow: number; red: number; mvp: number }[];
  partners: { player: ZonePlayerRef; played: number; won: number; points_per_match: number }[];
  victims: { club: ZoneClubRef; goals: number; matches: number }[];
  recent: (ZoneMatch & { goals: number; mvp: boolean })[];
}
export interface ZoneMatchPlayer { tpid: ZoneId; player_id: ZoneId | null; name: string; goals: number; yellow: number; red: number; mvp: boolean; photo_url: string | null }
export interface ZoneMatchPage { match: ZoneMatch; referee: string | null; home: { lineup: ZoneMatchPlayer[] }; away: { lineup: ZoneMatchPlayer[] } }
export interface ZoneStatsBoard {
  seasons: ZoneSeason[]; sports: string[];
  scorers: ZoneStatRow[]; appearances: ZoneStatRow[]; cards: ZoneStatRow[]; mvp: ZoneStatRow[];
  best_teams: { club: ZoneClubRef; played: number; won: number; points_per_match: number }[];
  best_defenses: { club: ZoneClubRef; played: number; conceded_per_match: number }[];
}
export interface ZoneDay { date: string; matches: ZoneMatch[] }
export interface ZoneStatus {
  tournaments: number; clubs: number; teams: number; players: number; matches: number; reports: number;
  sections: { section: string; synced_at: string | null; counts: Record<string, number> }[];
}
```

`zone.ts`: `zoneApi` con `home(season?)`, `search(q)`, `tournaments({season, sport})`, `tournament(id)`, `tournamentMatches(id): ZoneRound[]`, `tournamentStats(id)`, `clubs(q?)`, `club(id, tournament?)`, `clubMatches(id, tournament?)`, `headToHead(a, b)`, `players({q, club})`, `player(id)`, `matches({date, tournament}): ZoneDay[]`, `match(id)`, `stats({season, sport})`; tutti via `request()` di `client.ts` su `/public/zone/...` con `qs()`; hook `useZone*` con `useQuery` e `queryKey: ["zone", ...]`, `staleTime` 60 s.

- [ ] **Step 1: Scrivere i due file**, `tsc` verde, commit `Mixed Zone: contratto API e client`.

---

### Task 7: API pubblica `/public/zone/*` (backend)

**Files:**
- Create: `backend/app/Services/Zone/ZoneQueries.php`, `backend/app/Services/Zone/ZoneStats.php`, `backend/app/Http/Controllers/Api/ZoneController.php`, `backend/database/migrations/2026_10_12_000002_add_search_columns_to_zone_tables.php`
- Modify: `backend/routes/api.php` (dentro il gruppo `public` con `PublicCache`): le 15 rotte della spec, sezione 4, tutte su `ZoneController`; `backend/app/Services/Zone/ZoneImporter.php` (riempie `search`)
- Test: `backend/tests/Feature/ZoneApiTest.php` (importa le fixture del Task 2 in `setUp` e prova ogni indirizzo)

**Interfaces:** le risposte hanno ESATTAMENTE la forma dei tipi del Task 6 (`zone-types.ts`), dentro `{ data: ... }` (`$this->ok(...)`). `kickoff_at` in ISO 8601 (`toIso8601String()`).
- Colonna `search` (string, index) su `xf_clubs`, `xf_players`, `xf_tournaments`, riempita dall'importer con `ZoneImporter::normalize(name)`; le ricerche usano `where('search', 'like', '%'.normalize($q).'%')`.
- `ZoneQueries`: `home(?string $season)`, `search(string $q)`, `tournaments(?string $season, ?string $sport)`, `tournament(int $id)` (404 se non esiste), `rounds(int $tid)`, `clubs(?string $q)`, `club(int $id, ?int $tournament)`, `clubMatches(int $id, ?int $tournament)`, `headToHead(int $a, int $b)`, `players(?string $q, ?int $club)`, `match(int $id)`, `days(?string $date, ?int $tournament)` (7 giorni a partire da `$date`, di serie oggi; partite ordinate per orario).
- `ZoneStats`: `tournamentStats(int $tid)`, `player(int $pid)`, `board(?string $season, ?string $sport)`, `clubRecord(int $clubId)`, `form(int $tid, int $clubId, int $n = 5)`.
- `latest` = giocate con `kickoff_at` più recente; `upcoming` = non giocate con `kickoff_at >= now()` (null in coda); limite 12 (home) o 6 (torneo/club).
- Statistiche dai referti: `xf_match_players` con `player_id` non null raggruppati; `name` dal profilo `xf_players`; `cards` = gialli + rossi.
- `board`: filtri stagione e sport sui tornei; `appearances` = righe per `player_id`; `best_teams` per club con punti 3/1/0, almeno 10 partite; `best_defenses` gol subiti a partita, almeno 10 partite; massimo 25 righe per tabella.
- `partners`/`victims` del giocatore: compagni con almeno 6 partite insieme ordinati per punti a partita; club contro cui ha segnato di più.

- [ ] **Step 1: Test** `ZoneApiTest` con un caso per indirizzo (status 200, campi, un valore controllato): `home.totals.tournaments === 1`; `tournaments/901` con 2 gironi e 3 squadre; `tournaments/901/matches` con 2 giornate; `search?q=caffe` trova «CAFFÈ KM0» (Review Focus 4); `players/7001.totals.goals === 2`; `matches/90001.home.lineup` con 2 righe e `away.lineup` con «Sconosciuto Ugo» senza `player_id`; `stats.scorers[0].player_id === 7001`; `tournaments/902` → 404; `clubs/301.record.played === 2`.
- [ ] **Step 2: Implementare** migrazione, `ZoneQueries`, `ZoneStats`, `ZoneController`, rotte. Niente N+1: `with()` e join; al massimo 4 query per indirizzo di lista.
- [ ] **Step 3: Test verdi su SQLite e Postgres**; con il DB locale importato (Task 3) misurare `curl -s -o /dev/null -w '%{time_total}'` su `/api/v1/public/zone/stats` e `/zone/home`: sotto 0,5 s in locale.
- [ ] **Step 4: Commit** `Mixed Zone: API pubblica (home, tornei, squadre, giocatori, partite, statistiche)`.

---

### Task 8: Pagine della Mixed Zone (frontend)

**Files:**
- Create: `frontend/src/components/zone/{SeasonChips,SportChips,MatchRow,StandingsTable,TournamentCard,ClubCrest,ZoneSearch,StatsBoard}.tsx`
- Create/Modify: le 11 rotte `routes/mixed-zone.*.tsx` della struttura dei file
- Modify: `frontend/src/routes/rosa.$playerId.tsx`, `frontend/src/routes/classifica.tsx`, `backend/app/Support/Present.php` (`xfive_tournament_id` in `competition`), `backend/app/Services/Stats/PlayerStatsService.php` (`xfive_person_id` nel `player` del profilo), `frontend/src/api/types.ts` (i due campi)

**Interfaces:** consuma `zone.ts` (Task 6). Condivisi: `PageTitle`, `Card`, `Skeleton`, `ErrorState`, `EmptyState`, `Select` da `@/components/ui-kit`; `Reveal`, `CountUp`, `RollDigits`, `OnView` da `@/components/motion`; `sized()` per le immagini; `PlayerPhoto` da `player-ui` (prop `player: { full_name, photo_url }`).

- [ ] **Step 1: Componenti**: `MatchRow` (stemmi via `ClubCrest`, nomi, punteggio o orario `fmtDateTime`, chip torneo, link a `/mixed-zone/partite/$id`, `has_report` → icona referto), `StandingsTable` (per girone; posizione, stemma, nome, Pt G V N P F S +/- e forma a pallini W/D/L; scorre in orizzontale su mobile; riga evidenziata se `highlightClub`), `TournamentCard` (locandina via `sized()`, nome, stagione, sport, stato con chip, «12 di 90 partite»), `ClubCrest` (stemma con iniziali di riserva), `SeasonChips`/`SportChips`, `ZoneSearch` (campo con risultati a tendina raggruppati per tipo, debounce 250 ms, tastiera), `StatsBoard` (tabelle con foto e link ai giocatori/club).
- [ ] **Step 2: Rotte**: Home; Tornei (elenco con filtri + dettaglio a schede Classifica/Calendario/Statistiche/Squadre/Documenti con `?tab=` in `validateSearch`); Squadre (elenco con ricerca + dettaglio con selettore torneo per la rosa); Giocatori (ricerca + profilo con grafici Recharts per stagione come in `rosa.$playerId.tsx`); Partite (per giorno con selettore data + dettaglio referto con le due distinte, gol, cartellini, stella MVP, arbitro, campo); Statistiche (filtri + `StatsBoard`). Ogni rotta con `head()` («... - Mixed Zone»), `Skeleton`, `ErrorState` con riprova, `EmptyState` sensato («Nessun referto per questa partita», «Nessuna partita in questi giorni»).
- [ ] **Step 3: Collegamenti**: in `rosa.$playerId.tsx` un link «Carriera XFive» a `/mixed-zone/giocatori/$id` se `player.xfive_person_id`; in `classifica.tsx` «Il torneo nella Mixed Zone» se `competition.xfive_tournament_id`; nelle pagine Mixed Zone del club 159 (`config('amir.own.club_id')`, esposto come `own_club_id` in `ZoneHome.totals`? no: costante `OWN_CLUB_ID = 159` in `lib/area.ts`) un rimando «Vai all'Amir Hub».
- [ ] **Step 4: Prova nel browser** con il DB locale importato: tutte le pagine, desktop e 375 px; nessun rosso; screenshot nello scratchpad; `tsc`, prettier.
- [ ] **Step 5: Commit** per gruppi: `Mixed Zone: home e tornei`, `Mixed Zone: squadre e giocatori`, `Mixed Zone: partite e statistiche`, `Collegamenti fra Amir Hub e Mixed Zone`.

---

### Task 9: Sincronizzazione dal vivo `zone-*` (backend)

**Files:**
- Create: `backend/app/Services/Zone/ZoneSync.php`, `backend/database/migrations/2026_10_12_000003_add_progress_to_sync_runs.php`
- Modify: `backend/app/Services/Xfive/XfiveRoutine.php` (SCOPES + `zone-*` + `zone`), `backend/app/Http/Controllers/Api/SyncController.php` (`progress` nella risposta), `backend/app/Http/Controllers/Api/CronController.php` (nulla: `whereIn SCOPES` basta), `backend/vercel.json` (cron `/api/v1/cron/zone` «30 3 * * *»), `backend/app/Models/SyncRun.php` (cast `progress` array)
- Test: `backend/tests/Feature/ZoneSyncTest.php`

**Interfaces:**
- `ZoneSync` (costruttore: `XfiveClient`, `PrintableCalendarParser`, `StatsTableParser`, `MatchPageParser`, `PlayerInfoParser`, `ZoneImporter`; `PageParsers` static). Metodi `(float $deadline, Closure $progress): array{...conteggi, remaining:int}`; ogni metodo legge e scrive il cursore in `xf_sync_state` (`section` = nome dello scope) e aggiorna `synced_at`/`counts` quando finisce il giro completo (`remaining` 0):
  - `tournaments()`: `league.php op=23` per ogni stagione in `config('amir.xfive.seasons')` e status ongoing/incoming/previous (`PageParsers::tournamentList`); intestazioni (`/it/tournament/{id}/x/stream/` → `PageParsers::tournamentHeader`) solo dei tornei non ancora in `xf_tournaments`; aggiorna `status` di quelli esistenti.
  - `calendar()`: tornei con `status` in (ongoing, incoming), prima `calendar_synced_at` null poi i più vecchi; `XfiveClient::printableCalendar($tid)` → `PrintableCalendarParser` → `ZoneImporter::calendar`; segna `calendar_synced_at`.
  - `standings()`, `stats()`: stesso giro con `op=20` (`PageParsers::standings`) / `op=19` x3 (`StatsTableParser`).
  - `teams()`: `op=21` (`PageParsers::teamList`) + pagina squadra (`PageParsers::teamPage`) per ogni squadra del torneo; poi pagina club (`PageParsers::clubPage`) per i club nuovi.
  - `reports()`: `xf_matches` con `played` e non `has_report` (prima i tornei attivi) → `/it/match/{id}/x/` → `MatchPageParser` → `ZoneImporter::report`.
  - `players()`: `xf_players` con `synced_at` null o più vecchio di 30 giorni, più i `player_id` presenti in `xf_team_players` ma assenti in `xf_players` (`/it/player-info/{pid}/x/` → `PlayerInfoParser`).
  - `$progress(string $message, int $done, int $total)` chiamato a ogni elemento («Leggo il calendario di CITTADELLA 2026/27 (37 di 157)»).
- `XfiveRoutine::SCOPES` += `zone-tournaments, zone-calendar, zone-standings, zone-stats, zone-teams, zone-reports, zone-players, zone`; `zone` fa in fila tournaments, calendar, standings, stats, reports e, il lunedì, anche teams e players, fermandosi al budget con `remaining`. `XfiveRoutine::run` salva `progress` in `SyncRun` (`{section, message, done, total}`) al massimo ogni 2 s.
- `SyncController::present()` aggiunge `'progress' => $run->progress`.
- Freno: riusare `App\Services\Xfive\Archive\Limiter` con gap 1 s.

- [ ] **Step 1: Test** `ZoneSyncTest` con `Http::fake` (pattern per `league.php`, `t-printable.php`, `tournament/`, `player-info`, `match/`, `team/`): dopo `tournaments()` il torneo di calcio c'è e quello di padel no; `calendar()` con budget 0 lascia `remaining > 0` e un cursore; il secondo giro riprende dal cursore senza richiamare il torneo già letto (`Http::assertSentCount`) (Review Focus 5); `reports()` salva un referto e mette `has_report`; `POST /sync/xfive {scope: zone-calendar}` risponde con `progress.message` valorizzato; `GET /cron/zone` con il segreto risponde ok.
- [ ] **Step 2: Implementare**; test verdi (SQLite e Postgres); i test esistenti di `OnlineUpdatesTest` restano verdi.
- [ ] **Step 3: Commit** `Mixed Zone: aggiornamenti dal vivo da XFive per sezione, con cursore e avanzamento`.

---

### Task 10: Centro di sincronizzazione con sala di controllo (frontend)

**Files:**
- Create: `frontend/src/routes/admin.sincronizzazione.tsx`, `frontend/src/components/admin/ControlRoom.tsx`
- Modify: `frontend/src/components/admin/sync.ts` (`syncLabel` per i nuovi scope, `progress`, `log`), `frontend/src/api/types.ts` (`SyncScope` + `progress` in `SyncRun`), `frontend/src/api/client.ts` (`uploadZoneChunk(file): Promise<{section; items; counts}>`, `getZoneStatus(): Promise<ZoneStatus>`), `frontend/src/components/admin/AdminShell.tsx` (voce «Sincronizzazione», icona `RefreshCw`), `frontend/src/routes/admin.impostazioni.tsx` (riquadro XFive: link «Apri il centro di sincronizzazione»; i pulsanti restano come scorciatoie)

**Interfaces:**
- `useSyncFlow()` restituisce anche `progress: { scope: SyncScope; message: string; done: number; total: number } | null` e `log: string[]` (ultime 20 righe: ogni risposta aggiunge `message`), aggiornati a ogni risposta/polling.
- `ControlRoom` props `{ active: boolean; scope: SyncScope | null; progress; log; accent: string }`: radar SVG con 3 cerchi che pulsano sfalsati (`motion.circle`, `scale` 0.6→1.4, `opacity` 0.6→0, 1,8 s, ripetizione infinita), striscia con `message` (`AnimatePresence`, scorre verso l'alto), barra `done/total`, `RollDigits` per i contatori, lista `log` sbiadita in alto.
- Pagina: in alto «Sincronizza tutto» (`start(["current","details","media","stats","roster","zone-tournaments","zone-calendar","zone-standings","zone-stats","zone-teams","zone-reports","zone-players"])`) e «Carica archivio» (input file multiplo `.gz`, pezzi inviati in ordine di nome uno alla volta con `uploadZoneChunk`, testo «Pezzo 3 di 14: referti», poi `invalidateQueries(["zone"])`); griglia di carte per sezione (AMIR e Mixed Zone) con ultimo aggiornamento (da `sync-runs`), conteggi (`getZoneStatus`), pulsante; `ControlRoom` sopra la griglia quando `busy`.

- [ ] **Step 1: Implementare** sync.ts, client, types, `ControlRoom`, pagina, voce di menu.
- [ ] **Step 2: Prova nel browser** in locale: lanciare `zone-calendar` (con `Http` vero verso XFive, pochi tornei attivi) e vedere radar + messaggi; caricare 2 pezzi dell'export vero; screenshot.
- [ ] **Step 3: `tsc`, prettier, commit** `Centro di sincronizzazione con sala di controllo e caricamento dell'archivio`.

---

### Task 11: Documentazione, memoria e caricamento online

**Files:**
- Modify: `README.md` (sezione «Mixed Zone»), `docs/DEPLOY.md` (cron `zone`, caricamento dell'archivio dalla Staff Area, limite Neon), `docs/XFIVE_SITE.md` (rimando a `ZoneSync` per sezione)

- [ ] **Step 1: Scrivere la documentazione** (cosa c'è, come si carica l'archivio: `php artisan xfive:zone-export`, poi Staff Area → Sincronizzazione → Carica archivio; cosa gira di notte; come aggiungere una sezione).
- [ ] **Step 2: `php artisan test` completo + Postgres, `tsc`, `bun run test`, `bun run build`**.
- [ ] **Step 3: Commit e push**; poi l'utente carica l'export online (io non ho accesso all'area staff online).
