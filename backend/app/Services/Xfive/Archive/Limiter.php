<?php

namespace App\Services\Xfive\Archive;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Il freno dello scarico: XFive è il sito di un'associazione, condiviso con altri campionati, e non va sovraccaricato.
 *
 *   - una richiesta alla volta, con una pausa fissa fra l'una e l'altra (di serie 1 secondo)
 *   - se il sito risponde con un errore suo (429 o 5xx) o non risponde, ci si ferma 60 secondi, poi 120, 240...
 *     e dopo 5 errori di fila si smette del tutto
 *   - se le risposte diventano lente (media oltre i 4 secondi sulle ultime 10) la pausa raddoppia da sola
 *   - una finestra oraria facoltativa (es. 23-6): fuori da quella si aspetta
 *
 * Gli errori «nostri» (404, pagina che non esiste) non contano: si segnano e si va avanti.
 */
final class Limiter
{
    public const MAX_FAILURES = 5;

    private int $failures = 0;

    /** @var array<int, float> durate delle ultime risposte, in secondi */
    private array $recent = [];

    private float $lastAt = 0.0;

    public int $requests = 0;

    public int $slowdowns = 0;

    /**
     * @param  float  $gap  secondi fra una richiesta e l'altra
     * @param  float  $slowAfter  media (secondi) oltre la quale la pausa raddoppia
     * @param  float  $cooldown  attesa dopo il primo errore del sito, poi raddoppia
     * @param  array{0:int,1:int}|null  $window  ore [da, a) in cui si può lavorare; null = sempre
     * @param  Closure(float):void|null  $sleep  per i test: cosa fare al posto di dormire
     * @param  Closure():int|null  $hour  per i test: l'ora corrente
     */
    public function __construct(
        private float $gap = 1.0,
        private float $slowAfter = 4.0,
        private float $cooldown = 60.0,
        private ?array $window = null,
        private ?Closure $sleep = null,
        private ?Closure $hour = null,
    ) {}

    /**
     * Esegue una richiesta rispettando il freno. $isOurFault dice se un errore dipende da noi (404) e non dal sito.
     *
     * @template T
     *
     * @param  Closure():T  $request
     * @param  Closure(Throwable):bool  $isOurFault
     * @return T
     *
     * @throws RuntimeException dopo MAX_FAILURES errori del sito di fila
     */
    public function run(Closure $request, Closure $isOurFault): mixed
    {
        $this->waitForWindow();
        $this->pace();

        $start = microtime(true);
        try {
            $result = $request();
        } catch (Throwable $e) {
            $this->lastAt = microtime(true);
            $this->requests++;
            if ($isOurFault($e)) {
                throw $e;
            }
            $this->failures++;
            if ($this->failures >= self::MAX_FAILURES) {
                throw new RuntimeException('XFive ha risposto male '.self::MAX_FAILURES.' volte di fila: mi fermo per non insistere. Riprova più tardi.', 0, $e);
            }
            $wait = $this->cooldown * (2 ** ($this->failures - 1));
            $this->doSleep($wait);

            return $this->run($request, $isOurFault);
        }

        $elapsed = microtime(true) - $start;
        $this->lastAt = microtime(true);
        $this->requests++;
        $this->failures = 0;
        $this->recent[] = $elapsed;
        if (count($this->recent) > 10) {
            array_shift($this->recent);
        }
        if (count($this->recent) >= 5 && array_sum($this->recent) / count($this->recent) > $this->slowAfter) {
            $this->gap = min($this->gap * 2, 30.0);
            $this->slowdowns++;
            $this->recent = [];
        }

        return $result;
    }

    public function gap(): float
    {
        return $this->gap;
    }

    private function pace(): void
    {
        if ($this->lastAt <= 0) {
            return;
        }
        $wait = $this->gap - (microtime(true) - $this->lastAt);
        if ($wait > 0) {
            $this->doSleep($wait);
        }
    }

    private function waitForWindow(): void
    {
        if ($this->window === null) {
            return;
        }
        [$from, $to] = $this->window;
        for ($i = 0; $i < 48; $i++) {
            $h = $this->hour ? ($this->hour)() : (int) date('G');
            $inside = $from <= $to ? ($h >= $from && $h < $to) : ($h >= $from || $h < $to);
            if ($inside) {
                return;
            }
            $this->doSleep(1800.0); // mezz'ora, poi si ricontrolla
        }
    }

    private function doSleep(float $seconds): void
    {
        if ($this->sleep) {
            ($this->sleep)($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
