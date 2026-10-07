<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Services\Ai\TextGenerator;
use App\Services\Stats\PlayerStatsService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * La scheda scout di un giocatore: poche righe scritte dall'IA (Gemini) a partire dai suoi numeri veri.
 *
 * L'IA riceve solo fatti verificati e la regola di non inventare altro (niente piede, velocità, carattere).
 * Senza chiave o se la chiamata fallisce si scrive un testo da un modello, e la risposta lo dichiara
 * (source = "template"). Il testo si salva sul giocatore, così la pagina pubblica non chiama mai l'IA.
 */
final class ScoutProfileGenerator
{
    private const SYSTEM = <<<'TXT'
Sei l'addetto scout di AMIR COSTRUZIONI, una squadra amatoriale di calcio a 8. Scrivi la scheda scout di UN giocatore per i suoi compagni di squadra.

Regole:
- 3 o 4 frasi, al massimo 70 parole, in italiano, tono positivo ma onesto.
- Usa SOLO i dati forniti. Non inventare caratteristiche tecniche o fisiche (piede, velocità, carattere), episodi o statistiche che non ci sono.
- Cita 2 o 3 numeri chiave (presenze, gol, miglior giocatore, effetto sulla squadra).
- Se il giocatore ha meno di 5 presenze di' semplicemente che i dati sono ancora pochi.
- Niente emoji, hashtag, elenchi o markdown. Rispondi solo con il testo della scheda.
TXT;

    public function __construct(private readonly TextGenerator $ai, private readonly PlayerStatsService $stats) {}

    /**
     * @return array{text: string, source: 'ai'|'template', model: ?string, note: ?string}
     */
    public function generate(Player $player, Team $own): array
    {
        $profile = $this->stats->profile($player, $own);

        if (! $this->ai->isConfigured()) {
            return $this->template($player, $profile, "Scheda scritta da un modello di testo: per farla scrivere all'IA aggiungi GEMINI_API_KEY nel file .env del backend.");
        }

        try {
            $text = $this->ai->generate(
                self::SYSTEM,
                "Scrivi la scheda scout di questo giocatore.\n\nDATI (JSON):\n".json_encode($this->facts($player, $profile), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                400,
                0.5,
            );
            $text = trim((string) preg_replace('/\s+/', ' ', trim($text, " \t\n\r\0\x0B\"“”")));

            return ['text' => $text, 'source' => 'ai', 'model' => (string) config('amir.ai.gemini.model'), 'note' => null];
        } catch (RuntimeException $e) {
            return $this->template($player, $profile, "L'IA non ha risposto, ho usato un modello di testo. ".mb_strimwidth($e->getMessage(), 0, 200, '…'));
        }
    }

    /**
     * I fatti dati all'IA: poche cose, tutte verificabili.
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function facts(Player $player, array $p): array
    {
        $t = $p['totals'];

        return array_filter([
            'nome' => $player->nickname ? "{$player->full_name} (detto {$player->nickname})" : $player->full_name,
            'ruolo' => $player->role,
            'eta' => $p['player']['age'],
            'stagioni_con_noi' => $p['player']['seasons_count'],
            'prima_stagione' => $p['player']['first_season'],
            'presenze' => $t['matches'],
            'gol' => $t['goals'],
            'gol_a_partita' => $t['goals_per_match'],
            'volte_miglior_giocatore' => $t['mvp'],
            'gialli' => $t['yellow'],
            'rossi' => $t['red'],
            'punti_a_partita_della_squadra_con_lui' => $t['points_per_match'],
            'posizione_per_presenze_nella_rosa_storica' => $p['rank']['appearances'] ? "{$p['rank']['appearances']}° su {$p['rank']['of']}" : null,
            'posizione_per_gol_nella_rosa_storica' => $p['rank']['goals'] ? "{$p['rank']['goals']}° su {$p['rank']['of']}" : null,
            'squadra_con_lui_senza_di_lui' => $p['impact'] ? [
                'punti_a_partita_con_lui' => $p['impact']['with']['points_per_match'],
                'punti_a_partita_senza_di_lui' => $p['impact']['without']['points_per_match'],
                'partite_senza_di_lui' => $p['impact']['without']['played'],
            ] : null,
            'triplette' => $p['records']['hat_tricks'],
            'serie_record_di_partite_a_segno' => $p['records']['scoring_streak'],
            'partite_di_fila_a_segno_adesso' => $p['streaks']['scoring_now'],
            'partite_di_fila_senza_segnare_adesso' => $p['streaks']['drought_now'],
            'compagni_con_cui_si_vince_di_piu' => array_map(fn (array $m) => "{$m['player']['full_name']} ({$m['played']} partite insieme)", array_slice($p['partners'], 0, 2)),
            'squadra_a_cui_ha_segnato_di_piu' => $p['victims'] ? "{$p['victims'][0]['team']['name']} ({$p['victims'][0]['goals']} gol)" : null,
            'prossimo_traguardo' => $p['milestones']['goals'] ? "mancano {$p['milestones']['goals']['missing']} gol ai {$p['milestones']['goals']['next']}" : null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /**
     * Il testo di riserva: frasi composte dai numeri veri.
     *
     * @param  array<string, mixed>  $p
     * @return array{text: string, source: 'template', model: null, note: string}
     */
    private function template(Player $player, array $p, string $note): array
    {
        $t = $p['totals'];
        $name = $player->nickname ?: $player->first_name;
        $role = $player->role ? ', '.Str::lower($player->role).',' : '';
        $dec = fn (float $n) => str_replace('.', ',', (string) $n);

        if ($t['matches'] === 0) {
            return ['text' => "{$name} non ha ancora presenze registrate con AMIR: i dati sono troppo pochi per una scheda.", 'source' => 'template', 'model' => null, 'note' => $note];
        }

        $parts = ["{$name}{$role} ha {$t['matches']} ".($t['matches'] === 1 ? 'presenza' : 'presenze')." con AMIR e {$t['goals']} gol".($t['goals'] > 0 ? ' ('.$dec($t['goals_per_match']).' a partita)' : '').'.'];

        if ($t['matches'] < 5) {
            $parts[] = 'Ancora pochi dati per un giudizio.';
        } else {
            if ($t['mvp'] > 0) {
                $parts[] = "È stato il migliore in campo {$t['mvp']} ".($t['mvp'] === 1 ? 'volta' : 'volte').'.';
            }
            $imp = $p['impact'];
            if ($imp && $imp['without']['played'] >= 3 && abs($imp['with']['points_per_match'] - $imp['without']['points_per_match']) >= 0.3) {
                $parts[] = 'Con lui in campo la squadra fa '.$dec($imp['with']['points_per_match']).' punti a partita, senza '.$dec($imp['without']['points_per_match']).'.';
            }
            if ($p['streaks']['scoring_now'] >= 2) {
                $parts[] = "Arriva da {$p['streaks']['scoring_now']} partite consecutive a segno.";
            }
            if ($p['partners']) {
                $parts[] = "Rende al meglio in coppia con {$p['partners'][0]['player']['full_name']}.";
            }
        }

        return ['text' => implode(' ', $parts), 'source' => 'template', 'model' => null, 'note' => $note];
    }
}
