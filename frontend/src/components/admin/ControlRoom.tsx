import { AnimatePresence, motion, useReducedMotion } from "motion/react";
import { useEffect, useState } from "react";
import type { SyncProgress } from "@/api/types";
import { RollDigits } from "@/components/motion";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

/**
 * La sala di controllo: mentre un aggiornamento lavora, la pagina mostra un radar che pulsa nel colore della sezione,
 * il messaggio vero del backend che scorre, la barra fatti/totale, i contatori a cifre scorrevoli e il registro delle
 * ultime righe. Tutto il movimento è su transform e opacity (60 fps anche sui telefoni); con «riduci le animazioni»
 * il radar resta fermo e i testi cambiano in dissolvenza.
 */
export interface ControlRoomProps {
  active: boolean;
  /** Cosa sta lavorando (es. «Classifiche»); il colore dice se è Mixed Zone (ciano) o AMIR (rosso). */
  label: string;
  progress: SyncProgress | null;
  /** Le ultime righe (al massimo 20), dalla più vecchia. */
  log: string[];
  /** Colore della sezione, in esadecimale. */
  accent: string;
  /** Le etichette ancora in coda dopo quella in corso. */
  queue?: string[];
  title?: string;
}

/** Secondi trascorsi da quando `active` è diventato vero: l'orologio della sala. */
function useElapsed(active: boolean) {
  const [seconds, setSeconds] = useState(0);
  useEffect(() => {
    if (!active) return;
    setSeconds(0);
    const from = Date.now();
    const id = window.setInterval(() => setSeconds(Math.floor((Date.now() - from) / 1000)), 1000);
    return () => window.clearInterval(id);
  }, [active]);
  return seconds;
}

const RINGS = [0, 0.6, 1.2];

/** Il radar: tre cerchi che pulsano sfalsati, una lancetta che gira e un'eco per ogni elemento già fatto. */
function Radar({
  accent,
  done,
  total,
  still,
}: {
  accent: string;
  done: number;
  total: number;
  still: boolean;
}) {
  // un'eco per ogni elemento fatto, sul quadrante: con tanti elementi si tiene il passo (al massimo 36 punti)
  const echoes = Math.min(done, 36);
  const step = total > 0 ? Math.min(360 / Math.max(total, 1), 360 / 36) : 10;
  return (
    <svg viewBox="0 0 200 200" className="h-full w-full" aria-hidden>
      <defs>
        <linearGradient id="sweep" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor={accent} stopOpacity="0" />
          <stop offset="1" stopColor={accent} stopOpacity="0.55" />
        </linearGradient>
      </defs>
      {/* la griglia fissa: tre anelli e il mirino */}
      {[30, 60, 90].map((r) => (
        <circle
          key={r}
          cx="100"
          cy="100"
          r={r}
          fill="none"
          stroke={accent}
          strokeOpacity="0.18"
          strokeWidth="1"
        />
      ))}
      <line x1="100" y1="8" x2="100" y2="192" stroke={accent} strokeOpacity="0.12" />
      <line x1="8" y1="100" x2="192" y2="100" stroke={accent} strokeOpacity="0.12" />
      {/* tre cerchi che pulsano sfalsati: scale 0,6 → 1,4 e opacità 0,6 → 0 in 1,8 s */}
      {RINGS.map((delay) => (
        <motion.circle
          key={delay}
          cx="100"
          cy="100"
          r="60"
          fill={accent}
          fillOpacity="0.14"
          stroke={accent}
          strokeWidth="2"
          style={{ transformBox: "fill-box", transformOrigin: "center" }}
          initial={{ scale: 0.6, opacity: 0.6 }}
          animate={still ? { scale: 1, opacity: 0.35 } : { scale: [0.6, 1.4], opacity: [0.6, 0] }}
          transition={
            still
              ? { duration: dur.base }
              : { duration: 1.8, ease: "easeOut", repeat: Infinity, delay, repeatDelay: 0 }
          }
        />
      ))}
      {/* la lancetta: un settore sfumato che gira in 3 s */}
      {!still && (
        <motion.g
          style={{ transformBox: "view-box", transformOrigin: "100px 100px" }}
          animate={{ rotate: 360 }}
          transition={{ duration: 3, ease: "linear", repeat: Infinity }}
        >
          <path d="M100 100 L100 10 A90 90 0 0 1 163.6 36.4 Z" fill="url(#sweep)" />
          <line
            x1="100"
            y1="100"
            x2="100"
            y2="10"
            stroke={accent}
            strokeWidth="1.5"
            strokeOpacity="0.9"
          />
        </motion.g>
      )}
      {/* un'eco per ogni elemento fatto, in senso orario */}
      <AnimatePresence>
        {Array.from({ length: echoes }, (_, i) => {
          const a = ((i * step - 90) * Math.PI) / 180;
          const r = 45 + (i % 3) * 15;
          return (
            <motion.circle
              key={i}
              cx={100 + Math.cos(a) * r}
              cy={100 + Math.sin(a) * r}
              r="3.5"
              fill={accent}
              style={{ transformBox: "fill-box", transformOrigin: "center" }}
              initial={{ scale: 0, opacity: 0 }}
              animate={{ scale: 1, opacity: 0.95 }}
              exit={{ scale: 0, opacity: 0 }}
              transition={spring.pop}
            />
          );
        })}
      </AnimatePresence>
      {/* il cuore: batte piano */}
      <motion.circle
        cx="100"
        cy="100"
        r="7"
        fill={accent}
        style={{ transformBox: "fill-box", transformOrigin: "center" }}
        animate={still ? { scale: 1 } : { scale: [1, 1.25, 1] }}
        transition={still ? {} : { duration: 1.8, ease: "easeInOut", repeat: Infinity }}
      />
    </svg>
  );
}

/** Il messaggio del backend: il nuovo sale dal basso, il vecchio esce verso l'alto. */
function Ticker({ message, still }: { message: string; still: boolean }) {
  return (
    <div className="relative h-14 overflow-hidden md:h-12" role="status" aria-live="polite">
      <AnimatePresence initial={false} mode="sync">
        <motion.p
          key={message}
          className="absolute inset-0 flex items-center text-base font-semibold leading-tight text-white md:text-lg"
          initial={still ? { opacity: 0 } : { y: 28, opacity: 0 }}
          animate={{ y: 0, opacity: 1 }}
          exit={still ? { opacity: 0 } : { y: -28, opacity: 0 }}
          transition={{ duration: dur.base, ease: ease.out }}
        >
          <span className="line-clamp-2">{message}</span>
        </motion.p>
      </AnimatePresence>
    </div>
  );
}

/** La barra fatti/totale (solo `scaleX`); senza un totale scorre un lampo avanti e indietro. */
function Bar({
  accent,
  done,
  total,
  still,
}: {
  accent: string;
  done: number;
  total: number;
  still: boolean;
}) {
  const ratio = total > 0 ? Math.min(1, done / total) : 0;
  return (
    <div className="relative h-2 overflow-hidden rounded-full bg-white/10">
      {total > 0 ? (
        <motion.div
          className="h-full w-full rounded-full"
          style={{ background: accent, transformOrigin: "0% 50%" }}
          initial={{ scaleX: 0 }}
          animate={{ scaleX: ratio }}
          transition={spring.soft}
        />
      ) : (
        <motion.div
          className="h-full w-1/3 rounded-full"
          style={{ background: accent }}
          animate={still ? { x: "100%" } : { x: ["-100%", "300%"] }}
          transition={still ? {} : { duration: 1.4, ease: "easeInOut", repeat: Infinity }}
        />
      )}
    </div>
  );
}

function Counter({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0">
      <div className="font-display text-2xl leading-none text-white md:text-3xl">{children}</div>
      <div className="mt-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-white/55">
        {label}
      </div>
    </div>
  );
}

export function ControlRoom({
  active,
  label,
  progress,
  log,
  accent,
  queue = [],
  title = "Sala di controllo",
}: ControlRoomProps) {
  const reduce = useReducedMotion();
  const still = !active || !!reduce;
  const elapsed = useElapsed(active);
  const done = progress?.done ?? 0;
  const total = progress?.total ?? 0;
  const pct = total > 0 ? Math.round((done / total) * 100) : 0;
  const message = progress?.message ?? (active ? `Avvio: ${label}` : "In attesa");
  const lines = log.slice(-20);

  return (
    <motion.section
      aria-label={title}
      aria-busy={active}
      className="relative overflow-hidden rounded-2xl p-4 text-white shadow-2xl md:p-6"
      style={{
        background:
          "radial-gradient(120% 80% at 100% 0%, color-mix(in oklab, var(--accent-sync) 22%, transparent), transparent 60%), oklch(0.17 0.012 250)",
        ["--accent-sync" as string]: accent,
      }}
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -12 }}
      transition={{ duration: dur.slow, ease: ease.out }}
    >
      {/* la griglia da sala operativa, ferma */}
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 opacity-[0.07]"
        style={{
          backgroundImage:
            "linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px)",
          backgroundSize: "28px 28px",
        }}
      />
      <div className="relative grid gap-5 md:grid-cols-[200px_1fr] md:gap-7">
        <div className="mx-auto h-44 w-44 md:h-[200px] md:w-[200px]">
          <Radar accent={accent} done={done} total={total} still={still} />
        </div>
        <div className="flex min-w-0 flex-col gap-4">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span
              className="text-[11px] font-bold uppercase tracking-[0.22em]"
              style={{ color: accent }}
            >
              {title}
            </span>
            <span className="inline-flex items-center gap-2 text-sm font-semibold text-white/85">
              <motion.span
                aria-hidden
                className="inline-block h-2 w-2 rounded-full"
                style={{ background: accent }}
                animate={still ? { opacity: 1 } : { opacity: [1, 0.25, 1] }}
                transition={still ? {} : { duration: 1.2, repeat: Infinity, ease: "easeInOut" }}
              />
              {label}
            </span>
            {queue.length > 0 && (
              <span className="text-xs text-white/50">
                poi {queue.slice(0, 3).join(", ")}
                {queue.length > 3 ? ` e altre ${queue.length - 3}` : ""}
              </span>
            )}
          </div>

          <Ticker message={message} still={still} />
          <Bar accent={accent} done={done} total={total} still={still} />

          <div className="grid grid-cols-3 gap-3 md:grid-cols-4">
            <Counter label="Fatti">
              <RollDigits value={done} pad={total >= 100 ? 3 : 2} />
            </Counter>
            <Counter label="Totale">
              {total > 0 ? (
                <RollDigits value={total} pad={total >= 100 ? 3 : 2} />
              ) : (
                <span className="text-white/40">--</span>
              )}
            </Counter>
            <Counter label="Fatto">
              <span className="inline-flex items-baseline">
                <RollDigits value={pct} pad={pct >= 100 ? 3 : 2} />
                <span className="ml-0.5 text-base text-white/60">%</span>
              </span>
            </Counter>
            <Counter label="Tempo">
              <span className="inline-flex items-baseline">
                <RollDigits value={Math.floor(elapsed / 60)} pad={2} />
                <span className="mx-0.5 text-white/60">:</span>
                <RollDigits value={elapsed % 60} pad={2} />
              </span>
            </Counter>
          </div>
        </div>
      </div>

      {/* il registro: le righe più vecchie sbiadiscono verso l'alto */}
      <div
        className="relative mt-5 flex h-28 flex-col justify-end overflow-hidden font-mono text-[11px] leading-5 text-white/85 md:h-32"
        style={{
          maskImage: "linear-gradient(to bottom, transparent, black 45%)",
          WebkitMaskImage: "linear-gradient(to bottom, transparent, black 45%)",
        }}
        aria-label="Registro"
      >
        {lines.length === 0 ? (
          <p className="text-white/40">Nessuna riga ancora.</p>
        ) : (
          lines.map((line, i) => (
            <motion.p
              key={`${i}-${line}`}
              className={cn("truncate", i === lines.length - 1 ? "text-white" : "")}
              style={{ opacity: 0.35 + (0.65 * (i + 1)) / lines.length }}
              initial={still ? false : { opacity: 0, y: 8 }}
              animate={{ opacity: 0.35 + (0.65 * (i + 1)) / lines.length, y: 0 }}
              transition={{ duration: dur.fast, ease: ease.out }}
            >
              <span style={{ color: accent }}>›</span> {line}
            </motion.p>
          ))
        )}
      </div>
    </motion.section>
  );
}
