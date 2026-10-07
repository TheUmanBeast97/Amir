import { Link } from "@tanstack/react-router";
import { motion } from "motion/react";
import { Flame, Snowflake, Sparkles } from "lucide-react";
import type { Milestone, PlayerPage } from "@/api/types";
import { CountUp, Grow, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Panel } from "@/components/stats-ui";
import { Crest } from "@/components/ui-kit";
import { fmtShortDate } from "@/lib/format";
import { spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

const SOURCE_LABEL = {
  ai: "Scritta dall'IA",
  template: "Scritta da un modello di testo",
  manual: "Scritta dallo staff",
} as const;

/** Le tappe, come nel backend (PlayerStatsService::MILESTONES): servono a disegnare la barra dalla tappa precedente alla prossima. */
const TIERS = [0, 10, 25, 50, 75, 100, 150, 200, 300];

/** La scheda scout: poche righe sul giocatore, scritte dallo staff (con l'aiuto dell'IA). */
export function ScoutPanel({ scout }: { scout: NonNullable<PlayerPage["scout"]> }) {
  return (
    <Panel title="La scheda scout" kicker="Il parere dello staff" className="lg:col-span-2">
      <Reveal as="p" blur y={6} delay={0.1} className="text-base leading-relaxed">
        {scout.text}
      </Reveal>
      <p className="mt-3 inline-flex items-center gap-1.5 text-[11px] text-muted-foreground">
        {scout.source === "ai" && (
          <motion.span
            className="inline-flex"
            initial={{ scale: 0, rotate: -90 }}
            whileInView={{ scale: 1, rotate: 0 }}
            viewport={{ once: true }}
            transition={{ ...spring.pop, delay: 0.5 }}
          >
            <Sparkles className="h-3.5 w-3.5 text-primary" />
          </motion.span>
        )}
        {SOURCE_LABEL[scout.source]}
        {scout.generated_at && ` · ${fmtShortDate(scout.generated_at)}`}
      </p>
    </Panel>
  );
}

/** I compagni con cui la squadra rende meglio quando giocano insieme. */
export function PartnersPanel({ partners }: { partners: PlayerPage["partners"] }) {
  if (partners.length === 0) return null;
  return (
    <Panel title="Con chi si vince" kicker="I compagni d'oro">
      <ul className="space-y-1.5">
        {partners.map((m, i) => (
          <Reveal as="li" key={m.player.id} i={i} x={-14} y={0}>
            <Link
              to="/rosa/$playerId"
              params={{ playerId: String(m.player.id) }}
              className="press flex items-center gap-3 rounded-lg bg-background/40 p-2 hover:bg-accent"
            >
              <span
                className={cn(
                  "w-5 text-center font-display text-xl num",
                  i === 0 ? "text-warning" : "text-muted-foreground",
                )}
              >
                {i + 1}
              </span>
              <PlayerPhoto player={m.player} size={36} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">{m.player.full_name}</div>
                <div className="text-[11px] text-muted-foreground num">
                  {m.played} partite insieme · {m.won}V {m.drawn}N {m.lost}P
                </div>
              </div>
              <div className="text-right">
                <div className="font-display text-2xl leading-none text-success num">
                  <CountUp value={m.points_per_match} decimals={2} />
                </div>
                <div className="text-[10px] text-muted-foreground">punti a partita</div>
              </div>
            </Link>
          </Reveal>
        ))}
      </ul>
      <p className="mt-2 text-[11px] text-muted-foreground">
        Punti della squadra nelle partite in cui giocano entrambi (almeno 6). Dice come va quando ci
        sono insieme, non chi dei due conta di più.
      </p>
    </Panel>
  );
}

/** Le squadre a cui ha segnato di più. */
export function VictimsPanel({ victims }: { victims: PlayerPage["victims"] }) {
  if (victims.length === 0) return null;
  return (
    <Panel title="Le sue vittime" kicker="A chi ha segnato di più">
      <ul className="space-y-1.5">
        {victims.map((v, i) => (
          <Reveal
            as="li"
            key={v.team.id}
            i={i}
            x={-14}
            y={0}
            className="flex items-center gap-3 rounded-lg bg-background/40 p-2"
          >
            <Crest team={v.team} size={36} />
            <div className="min-w-0 flex-1">
              <div className="truncate text-sm font-semibold">{v.team.name}</div>
              <div className="text-[11px] text-muted-foreground num">
                in {v.matches} {v.matches === 1 ? "partita" : "partite"}
              </div>
            </div>
            <div className="text-right">
              <span className="font-display text-3xl leading-none text-primary num">
                <CountUp value={v.goals} duration={0.8} />
              </span>
              <span className="ml-1 text-[10px] uppercase text-muted-foreground">gol</span>
            </div>
          </Reveal>
        ))}
      </ul>
    </Panel>
  );
}

function Goal({ label, m, unit }: { label: string; m: Milestone; unit: string }) {
  const from = TIERS[TIERS.indexOf(m.next) - 1] ?? 0;
  const pct = Math.min(100, Math.max(4, ((m.current - from) / (m.next - from)) * 100));
  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between text-sm">
        <span className="font-semibold">{label}</span>
        <span className="text-xs text-muted-foreground num">
          {m.current} / {m.next}
        </span>
      </div>
      <div
        className="h-2.5 overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={m.next}
        aria-valuenow={m.current}
        aria-label={`${label}: ${m.current} su ${m.next}`}
      >
        <Grow
          axis="x"
          delay={0.2}
          className="h-full rounded-full bg-primary"
          style={{ width: `${pct}%` }}
        />
      </div>
      <div className="mt-1 text-xs text-muted-foreground">
        {m.missing === 1 ? `Manca 1 ${unit}` : `Mancano ${m.missing} ${unit}`} al traguardo dei{" "}
        {m.next}
      </div>
    </div>
  );
}

/** Il prossimo traguardo di presenze e gol, e come sta andando adesso. */
export function MilestonesPanel({ page }: { page: PlayerPage }) {
  const { milestones: m, streaks: s } = page;
  const form =
    s.scoring_now >= 2
      ? {
          Icon: Flame,
          text: `${s.scoring_now} partite di fila a segno`,
          cls: "bg-success/15 text-success",
        }
      : s.drought_now >= 3
        ? {
            Icon: Snowflake,
            text: `${s.drought_now} partite senza segnare`,
            cls: "bg-warning/15 text-warning",
          }
        : null;
  if (!m.matches && !m.goals && !form) return null;
  return (
    <Panel title="Prossimi traguardi" kicker="E come sta andando adesso">
      <div className="space-y-4">
        {m.matches && <Goal label="Presenze" m={m.matches} unit="presenze" />}
        {m.goals && <Goal label="Gol" m={m.goals} unit="gol" />}
        {form && (
          <motion.p
            initial={{ opacity: 0, scale: 0.85 }}
            whileInView={{ opacity: 1, scale: 1 }}
            viewport={{ once: true }}
            transition={{ ...spring.pop, delay: 0.45 }}
            className={cn(
              "inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold",
              form.cls,
            )}
          >
            <form.Icon className="h-4 w-4" /> Adesso: {form.text}
          </motion.p>
        )}
      </div>
    </Panel>
  );
}
