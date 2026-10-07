<?php

namespace App\Services\Xfive;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Support\PersonName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Completa le nostre partite giocate con quello che XFive pubblica nella pagina
 * della partita: arbitro, distinta, marcatori, cartellini e "miglior giocatore".
 *
 * - le statistiche dei nostri giocatori finiscono in match_player_stats con
 *   source = 'xfive'; se lo staff le ha inserite a mano (source = 'manual') non si toccano;
 * - chi compare in distinta ma non è nella rosa attuale diventa un ex giocatore
 *   (inattivo), così presenze e gol storici non si perdono;
 * - l'intera pagina (anche la squadra avversaria) è conservata in matches.details.
 */
final class MatchDetailsSyncer
{
    /** @var array<string, Player> chiave nome => giocatore */
    private array $byKey = [];

    /** @var array<int, Player> */
    private array $players = [];

    /** @var array<int, string> id giocatore => url della foto da scaricare */
    private array $pendingPhotos = [];

    private bool $indexed = false;

    public function __construct(
        private readonly XfiveClient $client,
        private readonly MatchPageParser $parser,
        private readonly ImageDownloader $images,
    ) {}

    /**
     * Le nostre partite giocate di cui leggere il referto (comando xfive:matches e aggiornamento dal sito).
     *
     * @param  bool  $all  anche quelle di cui abbiamo già i dettagli
     * @param  bool  $recent  solo senza dettagli o giocate negli ultimi 10 giorni (aggiornamento automatico)
     * @param  bool  $includeExcluded  anche i tornei esclusi dalle statistiche
     */
    public function pendingGames(Team $own, bool $all = false, bool $recent = false, bool $includeExcluded = false): Builder
    {
        return Game::with(['competition', 'home', 'away'])
            ->involving($own->id)
            ->where('status', Game::PLAYED)
            ->whereNotNull('xfive_match_id')
            ->whereHas('competition', function ($c) use ($includeExcluded) {
                $c->where('kind', '!=', Competition::KIND_FRIENDLY);
                if (! $includeExcluded) {
                    $c->where('is_excluded', false);
                }
            })
            ->when(! $all, function ($q) use ($recent) {
                $q->where(function ($w) use ($recent) {
                    $w->whereNull('details_synced_at');
                    if ($recent) {
                        $w->orWhere('kickoff_at', '>=', now()->subDays(10));
                    }
                });
            })
            ->orderBy('kickoff_at');
    }

    /**
     * @return array{status:string, stats:int, created:int, mismatch:bool}
     */
    public function syncGame(Game $game, Team $own): array
    {
        $this->index($own);

        $html = $this->client->get("/it/match/{$game->xfive_match_id}/partita/");
        $page = $this->parser->parse($html);

        $ownSide = $game->home_team_id === $own->id ? 'home' : 'away';
        $oppSide = $ownSide === 'home' ? 'away' : 'home';
        $mismatch = $page['home_score'] !== null
            && ($page['home_score'] !== $game->home_score || $page['away_score'] !== $game->away_score);

        // XFive usa dei segnaposto ("00 00") per chi non è ancora registrato: non sono persone
        $isPerson = fn (array $p) => (bool) preg_match('/\pL/u', (string) $p['name']);
        foreach (['home', 'away'] as $side) {
            $page[$side]['lineup'] = array_values(array_filter($page[$side]['lineup'], $isPerson));
            $page[$side]['scorers'] = array_values(array_filter($page[$side]['scorers'], $isPerson));
        }

        $created = 0;
        $statsRows = 0;
        $motm = null;
        $detailsOwn = [];
        $entries = [];

        DB::transaction(function () use ($game, $own, $page, $ownSide, &$created, &$statsRows, &$motm, &$detailsOwn, &$entries) {
            $scorerGoals = [];
            foreach ($page[$ownSide]['scorers'] as $s) {
                $scorerGoals[$s['ref']] = $s;
            }

            $manual = MatchPlayerStat::where('match_id', $game->id)->where('source', 'manual')->exists();
            $keep = [];

            // chi è in distinta + chi ha segnato ma non è in distinta (dato incompleto di XFive)
            $entries = $page[$ownSide]['lineup'];
            $listed = array_column($entries, 'ref');
            foreach ($page[$ownSide]['scorers'] as $s) {
                if (! in_array($s['ref'], $listed, true)) {
                    $entries[] = ['ref' => $s['ref'], 'name' => $s['name'], 'slug' => null, 'goals' => 0, 'yellow' => 0, 'red' => 0, 'mvp' => false, 'photo' => null, 'other' => []];
                }
            }

            foreach ($entries as $entry) {
                [$player, $isNew] = $this->resolve($entry, $own);
                $created += $isNew ? 1 : 0;

                $goals = max((int) $entry['goals'], (int) ($scorerGoals[$entry['ref']]['goals'] ?? 0));
                $detailsOwn[] = ['player_id' => $player->id, 'name' => $entry['name'], 'goals' => $goals, 'yellow' => $entry['yellow'], 'red' => $entry['red'], 'mvp' => $entry['mvp']];
                $keep[] = $player->id;

                if ($entry['mvp']) {
                    $motm ??= $player->id;
                }

                if (! $manual) {
                    MatchPlayerStat::updateOrCreate(
                        ['match_id' => $game->id, 'player_id' => $player->id],
                        ['played' => true, 'goals' => $goals, 'yellow' => (int) $entry['yellow'], 'red' => (int) $entry['red'], 'is_mvp' => (bool) $entry['mvp'], 'source' => 'xfive'],
                    );
                    $statsRows++;
                }
            }

            if (! $manual) {
                MatchPlayerStat::where('match_id', $game->id)->where('source', 'xfive')->whereNotIn('player_id', $keep)->delete();
            }
        });

        $this->downloadPendingPhotos();

        $slim = fn (array $side) => [
            'lineup' => array_map(fn (array $p) => array_intersect_key($p, array_flip(['name', 'goals', 'yellow', 'red', 'mvp'])), $side['lineup']),
            'scorers' => array_map(fn (array $p) => array_intersect_key($p, array_flip(['name', 'goals'])), $side['scorers']),
        ];

        $details = [
            'own_side' => $ownSide,
            $ownSide => ['lineup' => $detailsOwn, 'scorers' => $slim($page[$ownSide])['scorers']],
            $oppSide => $slim($page[$oppSide]),
        ];

        $game->forceFill([
            'referee' => $page['referee'] ?? $game->referee,
            'venue' => $game->venue ?: $page['venue'],
            'man_of_the_match_id' => $game->man_of_the_match_id ?? $motm,
            'details' => $details,
            'details_synced_at' => now(),
        ])->save();

        return [
            'status' => $entries === [] ? 'no_lineup' : 'ok',
            'stats' => $statsRows,
            'created' => $created,
            'mismatch' => $mismatch,
        ];
    }

    /**
     * Sincronizza un insieme di partite; un errore su una non ferma le altre.
     *
     * @param  iterable<Game>  $games
     * @return array{matches:int, no_lineup:int, stats:int, players_created:int, mismatch:int, errors:array<int,string>}
     */
    public function syncMany(iterable $games, Team $own, ?callable $progress = null): array
    {
        $out = ['matches' => 0, 'no_lineup' => 0, 'stats' => 0, 'players_created' => 0, 'mismatch' => 0, 'errors' => []];

        foreach ($games as $game) {
            try {
                $r = $this->syncGame($game, $own);
                $out['matches']++;
                $out['stats'] += $r['stats'];
                $out['players_created'] += $r['created'];
                $out['no_lineup'] += $r['status'] === 'no_lineup' ? 1 : 0;
                $out['mismatch'] += $r['mismatch'] ? 1 : 0;
            } catch (Throwable $e) {
                $out['errors'][] = "partita {$game->xfive_match_id}: ".$e->getMessage();
            }

            if ($progress) {
                $progress($game, $out);
            }
        }

        return $out;
    }

    private function index(Team $own): void
    {
        if ($this->indexed) {
            return;
        }

        foreach (Player::where('team_id', $own->id)->get() as $p) {
            $this->remember($p);
        }
        $this->indexed = true;
    }

    private function remember(Player $p): void
    {
        $this->players[$p->id] = $p;
        $this->byKey[PersonName::key($p->last_name.' '.$p->first_name)] = $p;
    }

    /**
     * Trova il giocatore dal nome in distinta oppure lo crea come ex giocatore.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0:Player,1:bool}
     */
    private function resolve(array $entry, Team $own): array
    {
        $key = PersonName::key($entry['name']);
        if (isset($this->byKey[$key])) {
            return [$this->byKey[$key], false];
        }

        // nome più corto o più lungo ("Fracchia Edoardo" / "Fracchia Edoardo Giovanni"): vale solo se è un caso unico
        $wanted = PersonName::tokens($entry['name']);
        $candidates = array_filter($this->players, function (Player $p) use ($wanted) {
            $have = PersonName::tokens($p->last_name.' '.$p->first_name);

            return count(array_intersect($wanted, $have)) >= 2
                && (PersonName::subset($wanted, $have) || PersonName::subset($have, $wanted));
        });
        if (count($candidates) === 1) {
            return [reset($candidates), false];
        }

        [$last, $first] = PersonName::split($entry['name'], $entry['slug'] ?? null);

        $player = Player::create([
            'team_id' => $own->id,
            'first_name' => $first,
            'last_name' => $last,
            'is_active' => false,
            'in_squad_list' => false,
            'registration_status' => 'none',
            'notes' => 'Ex giocatore: importato dallo storico XFive.',
            'photo_url' => $entry['photo'] ?? null,
        ]);

        // la foto si scarica a transazione chiusa, per non tenere bloccato il database durante la rete
        if (! empty($entry['photo'])) {
            $this->pendingPhotos[$player->id] = $entry['photo'];
        }

        $this->remember($player);

        return [$player, true];
    }

    private function downloadPendingPhotos(): void
    {
        foreach ($this->pendingPhotos as $playerId => $url) {
            if ($path = $this->images->store($url, 'player', $playerId)) {
                Player::whereKey($playerId)->update(['photo_path' => $path]);
            }
        }
        $this->pendingPhotos = [];
    }
}
