import { useEffect, useState, type ReactNode } from "react";
import { AlertTriangle, Info } from "lucide-react";
import { motion } from "motion/react";
import type { Match, MatchStatus, Team } from "@/api/types";
import { RollDigits } from "@/components/motion";
import { dist, dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { outcomeLabel, type Outcome } from "@/lib/format";

export function Crest({ team, size = 40 }: { team: Team; size?: number }) {
  const [broken, setBroken] = useState(false);
  const kit = [team.kit1_color, team.kit2_color].filter((c): c is string => !!c);
  if (team.badge_url && !broken)
    return (
      // cerchio bianco con lo stemma intero (non tagliato): funziona con stemmi tondi, a scudo o allungati
      <span
        className="grid shrink-0 place-items-center overflow-hidden rounded-full bg-white shadow-md ring-1 ring-black/10"
        style={{ width: size, height: size, padding: size * 0.08 }}
      >
        <img
          src={team.badge_url}
          alt={`Stemma di ${team.name}`}
          crossOrigin="anonymous"
          onError={() => setBroken(true)}
          className="h-full w-full object-contain"
        />
      </span>
    );
  const initials = team.name
    .replace(/[^A-ZÀ-Ü0-9 ]/gi, "")
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0])
    .join("");
  return (
    <div
      aria-label={team.name}
      className={cn(
        "relative grid shrink-0 place-items-center overflow-hidden rounded-full border-2 font-display num",
        team.is_own
          ? "border-primary bg-primary text-primary-foreground"
          : "border-border bg-secondary text-secondary-foreground",
      )}
      style={{ width: size, height: size, fontSize: size * 0.38 }}
    >
      {!team.is_own && kit.length > 0 && (
        <span
          className="absolute inset-x-0 bottom-0 h-1/4 opacity-80"
          style={{
            background:
              kit.length > 1 ? `linear-gradient(90deg, ${kit[0]} 50%, ${kit[1]} 50%)` : kit[0],
          }}
        />
      )}
      <span className="relative">{initials}</span>
    </div>
  );
}

const statusMeta: Record<MatchStatus, { label: string; cls: string }> = {
  scheduled: { label: "Programmata", cls: "bg-primary/15 text-primary" },
  to_schedule: { label: "Provvisoria", cls: "bg-warning/15 text-warning" },
  played: { label: "Giocata", cls: "bg-success/15 text-success" },
  postponed: { label: "Rinviata", cls: "bg-muted text-muted-foreground" },
  cancelled: { label: "Annullata", cls: "bg-muted text-muted-foreground" },
};
export function StatusChip({ status }: { status: MatchStatus }) {
  const s = statusMeta[status];
  return (
    <span
      className={cn(
        "rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide",
        s.cls,
      )}
    >
      {s.label}
    </span>
  );
}
export function ProvisionalChip() {
  return (
    <span className="rounded-full border border-warning/40 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-warning">
      Provvisoria
    </span>
  );
}

export function OutcomeBadge({ o, className }: { o: Outcome; className?: string }) {
  return (
    <motion.span
      initial={{ scale: 0.4, opacity: 0 }}
      animate={{ scale: 1, opacity: 1 }}
      transition={spring.pop}
      className={cn(
        "grid h-7 w-7 place-items-center rounded-full text-xs font-bold num",
        o === "W"
          ? "bg-success text-success-foreground"
          : o === "D"
            ? "bg-warning text-warning-foreground"
            : "bg-primary text-primary-foreground",
        className,
      )}
    >
      {outcomeLabel[o]}
    </motion.span>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden className={cn("shimmer rounded-lg bg-muted", className)} />;
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <motion.div
      role="alert"
      initial={{ opacity: 0, x: 0 }}
      animate={{ opacity: 1, x: [0, -7, 6, -4, 2, 0] }}
      transition={{ duration: 0.5, ease: ease.out }}
      className="rounded-xl border border-primary/30 bg-primary/10 p-5 text-sm"
    >
      <div className="flex items-center gap-2 font-semibold">
        <AlertTriangle className="h-4 w-4 text-primary" /> Qualcosa è andato storto
      </div>
      <p className="mt-1 text-muted-foreground">
        {error instanceof Error ? error.message : "Errore imprevisto."}
      </p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="press mt-3 min-h-11 rounded-lg bg-primary px-4 font-semibold text-primary-foreground"
        >
          Riprova
        </button>
      )}
    </motion.div>
  );
}

export function EmptyState({ children }: { children: ReactNode }) {
  return (
    <motion.div
      initial={{ opacity: 0, scale: 0.98 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ duration: dur.slow, ease: ease.out }}
      className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
    >
      {children}
    </motion.div>
  );
}

export function InfoBanner({ children }: { children: ReactNode }) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm">
      <Info className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
      <span>{children}</span>
    </div>
  );
}

export function PageTitle({
  kicker,
  title,
  children,
}: {
  kicker?: string | undefined;
  title: string;
  children?: ReactNode;
}) {
  return (
    <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div>
        {kicker && (
          <motion.div
            initial={{ opacity: 0, x: -dist.sm }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: dur.slow, ease: ease.out, delay: 0.05 }}
            className="mb-1 text-xs font-bold uppercase tracking-[0.2em] text-primary"
          >
            {kicker}
          </motion.div>
        )}
        {/* il titolo sale da una finestra tagliata, come il numero su un tabellone */}
        <div className="overflow-hidden pb-1">
          <motion.h1
            initial={{ y: "105%" }}
            animate={{ y: "0%" }}
            transition={{ duration: dur.slow * 1.2, ease: ease.out }}
            className="text-4xl md:text-5xl"
          >
            {title}
          </motion.h1>
        </div>
      </div>
      {children}
    </header>
  );
}

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <motion.section
      initial={{ opacity: 0, y: dist.md }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.12 }}
      transition={{ duration: dur.slow, ease: ease.out }}
      className={cn(
        "card-cut min-w-0 rounded-xl bg-card p-4 text-card-foreground md:p-5",
        className,
      )}
    >
      {children}
    </motion.section>
  );
}

export function Countdown({ to }: { to: string }) {
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
    const t = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(t);
  }, []);
  if (now === null) return <div className="h-14" />;
  const diff = Math.max(0, Date.parse(to) - now);
  const parts = [
    [Math.floor(diff / 864e5), "giorni"],
    [Math.floor(diff / 36e5) % 24, "ore"],
    [Math.floor(diff / 6e4) % 60, "min"],
    [Math.floor(diff / 1e3) % 60, "sec"],
  ] as const;
  return (
    <div className="flex gap-2">
      {parts.map(([v, l], i) => (
        <motion.div
          key={l}
          initial={{ opacity: 0, y: dist.md, scale: 0.9 }}
          animate={{ opacity: 1, y: 0, scale: 1 }}
          transition={{ ...spring.soft, delay: 0.5 + i * 0.07 }}
          className="min-w-14 rounded-lg bg-background/60 px-2 py-1.5 text-center"
        >
          {/* ogni cifra scorre da sola quando cambia, come su un tabellone */}
          <RollDigits value={v} className="font-display text-2xl" />
          <div className="text-[10px] uppercase tracking-wider text-muted-foreground">{l}</div>
        </motion.div>
      ))}
    </div>
  );
}

export function Select({
  value,
  onChange,
  children,
  label,
}: {
  value: string;
  onChange: (v: string) => void;
  children: ReactNode;
  label: string;
}) {
  return (
    <label className="flex flex-col gap-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
      {label}
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="min-h-11 rounded-lg border border-input bg-card px-3 text-sm normal-case tracking-normal text-foreground"
      >
        {children}
      </select>
    </label>
  );
}

export const isOurs = (m: Match) => m.is_own_match;
