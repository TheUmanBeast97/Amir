import { Link } from "@tanstack/react-router";
import { MapPin } from "lucide-react";
import { motion } from "motion/react";
import type { HeadToHeadMatch, Match, StandingRow } from "@/api/types";
import { Reveal } from "@/components/motion";
import { dur, ease, stagger } from "@/lib/motion";
import type { StandingsView } from "@/lib/standings-view";
import { cn } from "@/lib/utils";
import { fmtDate, fmtDateTime, outcome } from "@/lib/format";
import { Crest, OutcomeBadge, ProvisionalChip, StatusChip, isOurs } from "./ui-kit";

export function MatchRow({
  m,
  showStatus = true,
  admin = false,
  i = 0,
}: {
  m: Match;
  showStatus?: boolean;
  admin?: boolean;
  /** Posizione nell'elenco: le righe arrivano una dopo l'altra (il ritardo si ferma presto). */
  i?: number;
}) {
  const o = outcome(m);
  const body = (
    <div
      className={cn(
        "flex items-center gap-3 rounded-xl border bg-card p-3 hover:border-primary/40",
        (admin && isOurs(m)) || (isOurs(m) && !m.is_provisional) ? "lift" : "transition-colors",
        m.is_provisional && "opacity-60",
      )}
    >
      <div className="flex min-w-0 flex-1 flex-col gap-1.5">
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span className="truncate">
            {m.kickoff_at ? fmtDateTime(m.kickoff_at) : "Data da definire"}
          </span>
          {m.is_friendly && (
            <span className="rounded-full bg-warning/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-warning">
              Amichevole
            </span>
          )}
          {m.is_provisional && <ProvisionalChip />}
          {showStatus && !m.is_provisional && <StatusChip status={m.status} />}
        </div>
        {[m.home_team, m.away_team].map((t, i) => (
          <div key={t.id} className="flex items-center gap-2">
            <Crest team={t} size={24} />
            <span className={cn("flex-1 truncate text-sm", t.is_own && "font-bold")}>{t.name}</span>
            <span className="font-display text-lg num">
              {i === 0 ? (m.home_score ?? "") : (m.away_score ?? "")}
            </span>
          </div>
        ))}
        {m.venue && (
          <div className="flex items-center gap-1 text-xs text-muted-foreground">
            <MapPin className="h-3 w-3" />
            {m.venue}
          </div>
        )}
      </div>
      {o && isOurs(m) && <OutcomeBadge o={o} />}
    </div>
  );
  if (admin && isOurs(m))
    return (
      <Reveal i={i} y={10}>
        <Link to="/admin/partite/$matchId" params={{ matchId: String(m.id) }} className="block">
          {body}
        </Link>
      </Reveal>
    );
  // ogni nostra partita con data ufficiale ha la sua scheda (convocati, formazione, referto)
  return (
    <Reveal i={i} y={10}>
      {isOurs(m) && !m.is_provisional ? (
        <Link to="/partite/$matchId" params={{ matchId: String(m.id) }} className="block">
          {body}
        </Link>
      ) : (
        body
      )}
    </Reveal>
  );
}

export function HistoryMatchItem({ m, i = 0 }: { m: HeadToHeadMatch; i?: number }) {
  return (
    <Reveal i={i} y={10}>
      <div className="flex items-center gap-3 rounded-xl border bg-card p-3">
        <OutcomeBadge o={m.result} />
        <div className="min-w-0 flex-1">
          <div className="truncate text-sm font-semibold">
            {m.home_team} – {m.away_team}
          </div>
          <div className="truncate text-xs text-muted-foreground">
            {m.season} · {m.competition_name}
            {m.kickoff_at ? ` · ${fmtDate(m.kickoff_at)}` : ""}
          </div>
        </div>
        <div className="font-display text-xl num">
          {m.home_score}–{m.away_score}
        </div>
      </div>
    </Reveal>
  );
}

/** Le colonne della classifica, nell'ordine: i punti per primi, poi G (partite giocate), V, N, P; l'estesa aggiunge gol fatti, subiti e differenza reti. */
const STANDINGS_COLS: Record<
  StandingsView,
  { label: string; title: string; value: (r: StandingRow) => number }[]
> = {
  compact: [
    { label: "Pt", title: "Punti", value: (r) => r.points },
    { label: "G", title: "Partite giocate", value: (r) => r.played },
    { label: "V", title: "Vinte", value: (r) => r.won },
    { label: "N", title: "Pareggiate", value: (r) => r.drawn },
    { label: "P", title: "Perse", value: (r) => r.lost },
  ],
  extended: [
    { label: "Pt", title: "Punti", value: (r) => r.points },
    { label: "G", title: "Partite giocate", value: (r) => r.played },
    { label: "V", title: "Vinte", value: (r) => r.won },
    { label: "N", title: "Pareggiate", value: (r) => r.drawn },
    { label: "P", title: "Perse", value: (r) => r.lost },
    { label: "GF", title: "Gol fatti", value: (r) => r.goals_for },
    { label: "GS", title: "Gol subiti", value: (r) => r.goals_against },
    { label: "DR", title: "Differenza reti", value: (r) => r.goal_diff },
  ],
};

/** Interruttore «Compatta / Estesa» sopra la classifica. */
export function StandingsViewToggle({
  value,
  onChange,
}: {
  value: StandingsView;
  onChange: (v: StandingsView) => void;
}) {
  return (
    <div
      role="group"
      aria-label="Vista della classifica"
      className="inline-flex overflow-hidden rounded-lg border"
    >
      {(["compact", "extended"] as const).map((v) => (
        <button
          key={v}
          type="button"
          aria-pressed={value === v}
          onClick={() => onChange(v)}
          className={cn(
            "min-h-11 px-4 text-sm font-semibold transition-colors",
            value === v
              ? "bg-primary text-primary-foreground"
              : "text-muted-foreground hover:bg-accent/50",
          )}
        >
          {v === "compact" ? "Compatta" : "Estesa"}
        </button>
      ))}
    </div>
  );
}

export function StandingsTable({
  rows,
  view = "extended",
}: {
  rows: StandingRow[];
  view?: StandingsView;
}) {
  const notStarted = rows.length > 0 && rows.every((r) => r.played === 0);
  const cols = STANDINGS_COLS[view];
  return (
    <div>
      {notStarted && (
        <p className="mb-2 text-xs text-muted-foreground">La stagione non è ancora iniziata</p>
      )}
      <div className="overflow-x-auto rounded-xl border">
        <table className="w-full text-sm num">
          <thead className="bg-muted/60 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="w-10 px-2 py-2 text-center">#</th>
              <th className="px-2 py-2 text-left">Squadra</th>
              {cols.map((c) => (
                <th
                  key={c.label}
                  scope="col"
                  title={c.title}
                  aria-label={c.title}
                  className="px-2 py-2 text-center"
                >
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r, rowIndex) => {
              return (
                <motion.tr
                  key={r.team.id}
                  initial={{ opacity: 0, x: -14 }}
                  whileInView={{ opacity: 1, x: 0 }}
                  viewport={{ once: true, amount: 0.3 }}
                  transition={{
                    duration: dur.slow,
                    ease: ease.out,
                    delay: stagger(rowIndex, 0.035, 12),
                  }}
                  className={cn(
                    "border-t transition-colors hover:bg-accent/40",
                    r.team.is_own && "bg-highlight font-bold hover:bg-highlight",
                  )}
                >
                  <td
                    className={cn(
                      "px-2 py-2.5 text-center",
                      r.team.is_own && "border-l-4 border-primary",
                    )}
                  >
                    {notStarted ? "-" : r.position}
                  </td>
                  <td className="px-2 py-2.5">
                    <div className="flex items-center gap-2">
                      <Crest team={r.team} size={22} />
                      <span className="max-w-[9rem] truncate md:max-w-none">{r.team.name}</span>
                    </div>
                  </td>
                  {cols.map((c, i) => {
                    const v = c.value(r);
                    return (
                      <td
                        key={c.label}
                        className={cn(
                          "px-2 py-2.5 text-center",
                          i === 0 && "font-display text-lg font-bold text-primary",
                        )}
                      >
                        {c.label === "DR" && v > 0 ? `+${v}` : v}
                      </td>
                    );
                  })}
                </motion.tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
