import { motion } from "motion/react";
import type { ReactNode } from "react";
import { CountUp, Grow } from "@/components/motion";
import { dist, dur, ease, spring, stagger } from "@/lib/motion";
import { cn } from "@/lib/utils";

export type Result = "W" | "D" | "L";

/** Colori dei risultati (vittoria / pareggio / sconfitta) dal tema: servono anche ai grafici. */
export const RESULT_COLOR: Record<Result, string> = {
  W: "var(--success)",
  D: "var(--warning)",
  L: "var(--primary)",
};
export const RESULT_LETTER: Record<Result, string> = { W: "V", D: "N", L: "P" };

/** Pallino con la lettera del risultato (V, N, P). */
export function ResultDot({
  r,
  className,
  i = 0,
}: {
  r: Result | null;
  className?: string;
  i?: number;
}) {
  if (!r)
    return (
      <span
        className={cn(
          "grid h-6 w-6 place-items-center rounded-full bg-muted text-[11px] font-bold text-muted-foreground",
          className,
        )}
      >
        –
      </span>
    );
  return (
    <motion.span
      initial={{ scale: 0.3, opacity: 0 }}
      animate={{ scale: 1, opacity: 1 }}
      transition={{ ...spring.pop, delay: stagger(i, 0.06) }}
      className={cn(
        "grid h-6 w-6 shrink-0 place-items-center rounded-full text-[11px] font-bold text-black",
        className,
      )}
      style={{ background: RESULT_COLOR[r] }}
    >
      {RESULT_LETTER[r]}
    </motion.span>
  );
}

const TONES = {
  primary: { glow: "from-primary/25 to-primary/0", text: "text-primary" },
  success: { glow: "from-success/25 to-success/0", text: "text-success" },
  warning: { glow: "from-warning/25 to-warning/0", text: "text-warning" },
  neutral: { glow: "from-foreground/10 to-foreground/0", text: "text-foreground" },
} as const;
export type Tone = keyof typeof TONES;

/** Tessera numerica con una luce colorata in alto: il "numero grosso" di una dashboard. */
export function Kpi({
  label,
  value,
  sub,
  tone = "neutral",
  icon,
  className,
  i = 0,
  decimals = 0,
}: {
  label: string;
  value: ReactNode;
  sub?: ReactNode;
  tone?: Tone;
  icon?: ReactNode;
  className?: string;
  /** Posizione fra le tessere vicine: ognuna arriva un po' dopo la precedente. */
  i?: number;
  /** Cifre decimali di un valore numerico che sale da zero (medie come 1,85). */
  decimals?: number;
}) {
  const t = TONES[tone];
  return (
    <motion.div
      initial={{ opacity: 0, y: dist.md, scale: 0.97 }}
      whileInView={{ opacity: 1, y: 0, scale: 1 }}
      viewport={{ once: true, amount: 0.3 }}
      transition={{ duration: dur.slow, ease: ease.out, delay: stagger(i, 0.07) }}
      className={cn("relative overflow-hidden rounded-xl border bg-card p-3 md:p-4", className)}
    >
      <div aria-hidden className={cn("absolute inset-x-0 top-0 h-16 bg-gradient-to-b", t.glow)} />
      <div className="relative flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            {label}
          </div>
          <div className={cn("font-display text-4xl leading-none num md:text-5xl", t.text)}>
            {/* i numeri interi salgono da zero come su un tabellone; il resto (risultati, percentuali) compare com'è */}
            {typeof value === "number" ? <CountUp value={value} decimals={decimals} /> : value}
          </div>
          {sub && <div className="mt-1 text-xs text-muted-foreground">{sub}</div>}
        </div>
        {icon && (
          <motion.div
            initial={{ scale: 0.5, rotate: -12, opacity: 0 }}
            whileInView={{ scale: 1, rotate: 0, opacity: 0.8 }}
            viewport={{ once: true }}
            transition={{ ...spring.pop, delay: 0.25 + stagger(i, 0.07) }}
            className={t.text}
          >
            {icon}
          </motion.div>
        )}
      </div>
    </motion.div>
  );
}

/** Riquadro con titolo per un grafico o una tabella. */
export function Panel({
  title,
  kicker,
  children,
  className,
  action,
}: {
  title: string;
  kicker?: string;
  children: ReactNode;
  className?: string;
  action?: ReactNode;
}) {
  return (
    <motion.section
      initial={{ opacity: 0, y: dist.md }}
      whileInView={{ opacity: 1, y: 0 }}
      viewport={{ once: true, amount: 0.1 }}
      transition={{ duration: dur.slow, ease: ease.out }}
      className={cn("rounded-xl border bg-card p-4", className)}
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          {kicker && (
            <div className="text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
              {kicker}
            </div>
          )}
          <h2 className="text-xl leading-tight">{title}</h2>
        </div>
        {action}
      </div>
      {children}
    </motion.section>
  );
}

/** Confronto tra due valori con barre orizzontali (es. "con lui" / "senza di lui"). */
export function CompareBars({
  label,
  a,
  b,
  max,
  format = (n) => String(n),
  aLabel,
  bLabel,
}: {
  label: string;
  a: number;
  b: number;
  max: number;
  format?: (n: number) => string;
  aLabel: string;
  bLabel: string;
}) {
  const w = (v: number) => `${Math.max(3, Math.min(100, (v / (max || 1)) * 100))}%`;
  const rows: [string, number, string][] = [
    [aLabel, a, "var(--primary)"],
    [bLabel, b, "var(--muted-foreground)"],
  ];
  return (
    <div>
      <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </div>
      <div className="space-y-1">
        {rows.map(([l, v, c], idx) => (
          <div key={l} className="flex items-center gap-2">
            <span className="w-16 shrink-0 text-[11px] text-muted-foreground">{l}</span>
            <div className="h-3 flex-1 overflow-hidden rounded-full bg-muted">
              <Grow
                axis="x"
                i={idx}
                className="h-full rounded-full"
                style={{ width: w(v), background: c }}
              />
            </div>
            <span className="w-12 shrink-0 text-right text-sm font-bold num">{format(v)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
