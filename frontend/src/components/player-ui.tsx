import { motion } from "motion/react";
import { useState, type ReactNode } from "react";
import type { Id, KitColor, LineupSlot, PublicPlayer } from "@/api/types";
import { KIT_LABEL, KIT_STYLE, initials, shirtFor, shortName } from "@/lib/kit";
import { sized } from "@/lib/img";
import { ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

/** Chiave del trascinamento (drag & drop) di un giocatore sul campo o in panchina. */
export const DRAG_MIME = "text/player-id";

/** Foto del giocatore in un cerchio; senza foto (o se non carica) restano le iniziali. */
export function PlayerPhoto({
  player,
  size = 48,
  className,
}: {
  player: Pick<PublicPlayer, "full_name" | "photo_url">;
  size?: number;
  className?: string;
}) {
  const [broken, setBroken] = useState(false);
  return (
    <span
      className={cn(
        "relative grid shrink-0 place-items-center overflow-hidden rounded-full bg-gradient-to-br from-zinc-700 to-zinc-900 font-display text-white",
        className,
      )}
      style={{ width: size, height: size, fontSize: size * 0.38 }}
    >
      {player.photo_url && !broken ? (
        <img
          src={sized(player.photo_url, size)}
          alt=""
          crossOrigin="anonymous"
          onError={() => setBroken(true)}
          className="h-full w-full object-cover object-top"
        />
      ) : (
        initials(player.full_name)
      )}
    </span>
  );
}

/** Numero di maglia in una pastiglia colorata come la divisa (rossa o bianca). */
export function ShirtBadge({
  number,
  kit,
  size = 24,
  className,
}: {
  number: string | null;
  kit: KitColor | null;
  size?: number;
  className?: string;
}) {
  const s = KIT_STYLE[kit ?? "red"];
  return (
    <span
      className={cn(
        "grid shrink-0 place-items-center rounded-full font-display leading-none num shadow",
        className,
      )}
      style={{
        width: size,
        height: size,
        fontSize: size * 0.52,
        background: s.bg,
        color: s.fg,
        boxShadow: `0 0 0 2px ${s.ring}`,
      }}
    >
      {number ?? "–"}
    </span>
  );
}

/** Scelta della divisa (rossa / bianca): decide quale numero di maglia si vede. */
export function KitPicker({
  value,
  onChange,
  disabled,
}: {
  value: KitColor | null;
  onChange: (k: KitColor) => void;
  disabled?: boolean;
}) {
  return (
    <div role="group" aria-label="Divisa" className="inline-flex overflow-hidden rounded-lg border">
      {(["red", "white"] as const).map((k) => (
        <button
          key={k}
          type="button"
          disabled={disabled}
          aria-pressed={value === k}
          onClick={() => onChange(k)}
          className={cn(
            "flex min-h-11 items-center gap-2 px-3 text-sm font-semibold transition-colors disabled:opacity-60",
            value === k ? "bg-accent text-foreground" : "text-muted-foreground hover:bg-accent/50",
          )}
        >
          <span
            className="h-4 w-4 rounded-full border border-black/30"
            style={{ background: KIT_STYLE[k].bg }}
          />
          {KIT_LABEL[k]}
        </button>
      ))}
    </div>
  );
}

interface PitchProps {
  slots: LineupSlot[];
  getPlayer: (id: Id) => PublicPlayer | undefined;
  kit: KitColor | null;
  /** Evidenzia le posizioni libere (es. quando si è scelto un giocatore da piazzare). */
  armed?: boolean;
  onSlotClick?: (slot: LineupSlot) => void;
  onSlotDrop?: (slot: number, playerId: Id) => void;
  className?: string;
  /** Contenuto sovrapposto in alto a sinistra (es. il modulo). */
  corner?: ReactNode;
  /** Le linee del campo si tracciano e i giocatori entrano uno alla volta: solo per la pagina pubblica (l'export a immagine non deve catturare un'animazione a metà). */
  animate?: boolean;
}

/**
 * Campo da calcio con i giocatori come "segnaposto" a foto: cerchio con la faccia, pastiglia del
 * numero di maglia (rosso o bianco a seconda della divisa) e nome. Serve sia all'editor dello staff
 * sia alla pagina pubblica, e si esporta come immagine (le foto arrivano dallo stesso server, con CORS).
 */
export function Pitch({
  slots,
  getPlayer,
  kit,
  armed,
  onSlotClick,
  onSlotDrop,
  className,
  corner,
  animate = false,
}: PitchProps) {
  const interactive = !!onSlotClick || !!onSlotDrop;
  /** Una linea del campo che si disegna (ritardo crescente), oppure ferma se l'animazione è spenta. */
  const line = (n: number) =>
    animate
      ? {
          initial: { pathLength: 0 },
          whileInView: { pathLength: 1 },
          viewport: { once: true },
          transition: { duration: 1.1, ease: ease.out, delay: 0.1 + n * 0.07 },
        }
      : {};
  /** Un giocatore che «salta» in campo; il ritardo cresce con la posizione. */
  const enter = (n: number) =>
    animate
      ? {
          initial: { scale: 0, opacity: 0 },
          whileInView: { scale: 1, opacity: 1 },
          viewport: { once: true },
          transition: { ...spring.pop, delay: 0.55 + n * 0.07 },
        }
      : {};
  return (
    <div
      className={cn(
        "relative mx-auto aspect-[2/3] w-full max-w-[460px] overflow-hidden rounded-2xl shadow-2xl ring-1 ring-black/40",
        className,
      )}
      style={{ background: "#1b6b38" }}
    >
      {/* erba a strisce, luce dall'alto e bordi scuri */}
      <div
        aria-hidden
        className="absolute inset-0"
        style={{
          background:
            "repeating-linear-gradient(to top, rgba(255,255,255,.05) 0 10%, rgba(0,0,0,0) 10% 20%)",
        }}
      />
      <div
        aria-hidden
        className="absolute inset-0"
        style={{
          background:
            "linear-gradient(to bottom, rgba(255,255,255,.12), rgba(255,255,255,0) 35%), radial-gradient(ellipse at center, rgba(0,0,0,0) 55%, rgba(0,0,0,.4) 100%)",
        }}
      />
      <svg viewBox="0 0 100 150" className="absolute inset-0 h-full w-full" aria-hidden>
        <g fill="none" stroke="rgba(255,255,255,.7)" strokeWidth=".55">
          <motion.rect x="3" y="3" width="94" height="144" rx=".5" {...line(0)} />
          <motion.line x1="3" y1="75" x2="97" y2="75" {...line(1)} />
          <motion.circle cx="50" cy="75" r="11" {...line(2)} />
          <motion.rect x="28" y="3" width="44" height="18" {...line(3)} />
          <motion.rect x="28" y="129" width="44" height="18" {...line(3)} />
          <motion.rect x="39" y="3" width="22" height="7" {...line(4)} />
          <motion.rect x="39" y="140" width="22" height="7" {...line(4)} />
          <motion.path d="M 42.9 21 A 10 10 0 0 0 57.1 21" {...line(5)} />
          <motion.path d="M 42.9 129 A 10 10 0 0 1 57.1 129" {...line(5)} />
          <motion.rect x="43" y="0.4" width="14" height="2.6" strokeWidth=".4" {...line(6)} />
          <motion.rect x="43" y="147" width="14" height="2.6" strokeWidth=".4" {...line(6)} />
        </g>
        <g fill="rgba(255,255,255,.75)">
          <circle cx="50" cy="75" r=".9" />
          <circle cx="50" cy="14" r=".8" />
          <circle cx="50" cy="136" r=".8" />
        </g>
      </svg>
      <img
        src="/stemma-amir.png"
        alt=""
        aria-hidden
        className="pointer-events-none absolute left-1/2 top-1/2 w-[20%] -translate-x-1/2 -translate-y-1/2 opacity-[.16]"
      />
      {corner && <div className="absolute left-3 top-3 z-10">{corner}</div>}

      {slots.map((s, n) => {
        const p = s.player_id !== null ? getPlayer(s.player_id) : undefined;
        const style = { left: `${s.x}%`, bottom: `${s.y}%`, width: "21%" };
        const body = p ? (
          <>
            <motion.span className="relative block w-[70%] max-w-[78px]" {...enter(n)}>
              <PlayerPhoto
                player={p}
                size={78}
                className="!h-auto aspect-square !w-full border-[3px] border-white shadow-xl"
              />
              <ShirtBadge
                number={shirtFor(p, kit)}
                kit={kit}
                size={26}
                className="absolute -bottom-1 -right-2 !text-[13px]"
              />
            </motion.span>
            <span className="mt-1.5 max-w-full truncate rounded-full bg-black/65 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white backdrop-blur-sm md:text-xs">
              {shortName(p)}
            </span>
          </>
        ) : (
          <>
            <span
              className={cn(
                "grid aspect-square w-[62%] max-w-[64px] place-items-center rounded-full border-2 border-dashed border-white/70 bg-white/10 text-xs font-bold text-white/90 backdrop-blur-[1px]",
                armed && "animate-pulse border-amber-300 bg-amber-300/20 text-amber-200",
              )}
            >
              {s.label}
            </span>
          </>
        );
        const cls =
          "absolute flex -translate-x-1/2 translate-y-1/2 flex-col items-center text-center";
        return interactive ? (
          <button
            key={s.slot}
            type="button"
            style={style}
            className={cn(
              cls,
              "cursor-pointer rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-300",
            )}
            aria-label={`Posizione ${s.label}${p ? `: ${p.full_name}` : " vuota"}`}
            onClick={() => onSlotClick?.(s)}
            onDragOver={(e) => {
              if (onSlotDrop) e.preventDefault();
            }}
            onDrop={(e) => {
              e.preventDefault();
              const id = Number(e.dataTransfer.getData(DRAG_MIME));
              if (id && onSlotDrop) onSlotDrop(s.slot, id);
            }}
          >
            {body}
          </button>
        ) : (
          <div key={s.slot} style={style} className={cls}>
            {body}
          </div>
        );
      })}
    </div>
  );
}
