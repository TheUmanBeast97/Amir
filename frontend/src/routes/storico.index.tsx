import { createFileRoute, Link } from "@tanstack/react-router";
import { useId, useState } from "react";
import { motion } from "motion/react";
import { ArrowRight, Skull, Star, Swords } from "lucide-react";
import { useCareer, useHistory } from "@/api/hooks";
import type {
  CareerRow,
  HeadToHeadMatch,
  HistoryOpponent,
  RecordLine,
  HistorySeason,
} from "@/api/types";
import {
  Card,
  Crest,
  EmptyState,
  ErrorState,
  InfoBanner,
  PageTitle,
  Skeleton,
} from "@/components/ui-kit";
import { CompetitionCard, InsightsSections } from "@/components/history-ui";
import { ActivePill, CountUp, Grow, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Panel } from "@/components/stats-ui";
import { dur, ease, spring, stagger } from "@/lib/motion";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/storico/")({
  head: () => ({
    meta: [
      { title: "Storico — AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Il curriculum di AMIR COSTRUZIONI: stagioni passate, competizioni e record.",
      },
      { property: "og:title", content: "Storico — AMIR COSTRUZIONI" },
      {
        property: "og:description",
        content: "Stagioni passate, competizioni e record della squadra.",
      },
    ],
  }),
  component: HistoryPage,
});

const pct = (r: RecordLine) => (r.played ? Math.round((r.won / r.played) * 1000) / 10 : 0);

function Stat({
  label,
  value,
  cls,
  i = 0,
}: {
  label: string;
  value: string | number;
  cls?: string;
  i?: number;
}) {
  return (
    <Reveal i={i} scale={0.92} className="rounded-xl bg-background/50 p-3">
      <div
        className={cn("whitespace-nowrap font-display text-xl num sm:text-2xl md:text-3xl", cls)}
      >
        {typeof value === "number" ? <CountUp value={value} /> : value}
      </div>
      <div className="text-[11px] uppercase tracking-wider text-muted-foreground">{label}</div>
    </Reveal>
  );
}
const wdl = (r: RecordLine) => `${r.won}-${r.drawn}-${r.lost}`;

function Highlight({ title, m, i = 0 }: { title: string; m: HeadToHeadMatch | null; i?: number }) {
  const opp = m ? (m.home_team === "AMIR COSTRUZIONI" ? m.away_team : m.home_team) : "";
  const [us, them] = m
    ? m.home_team === "AMIR COSTRUZIONI"
      ? [m.home_score, m.away_score]
      : [m.away_score, m.home_score]
    : [0, 0];
  return (
    <Reveal i={i} className="flex items-center gap-3 rounded-xl bg-background/50 p-3">
      <div className="min-w-0 flex-1">
        <div className="text-[11px] uppercase tracking-wider text-muted-foreground">{title}</div>
        {m ? (
          <div className="truncate text-sm font-semibold">
            {opp} · {m.season}
          </div>
        ) : (
          <div className="text-sm">—</div>
        )}
      </div>
      {m && (
        <div className="font-display text-2xl num">
          <CountUp value={us} duration={0.7} />–<CountUp value={them} duration={0.7} />
        </div>
      )}
    </Reveal>
  );
}

function Trend({ seasons }: { seasons: HistorySeason[] }) {
  const data = [...seasons].reverse();
  const maxGoals = Math.max(
    1,
    ...data.flatMap((s) => [s.record.goals_for, s.record.goals_against]),
  );
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <Card>
        <h2 className="mb-4 text-xl">% vittorie per stagione</h2>
        <div className="flex items-end gap-3">
          {data.map((s, idx) => (
            <div key={s.season} className="flex flex-1 flex-col items-center gap-1">
              <span className="text-xs font-bold num">
                <CountUp value={pct(s.record)} decimals={1} />%
              </span>
              <div className="flex h-28 w-full items-end">
                <Grow
                  i={idx}
                  className="w-full rounded-t-md bg-primary"
                  style={{ height: `${Math.max(4, pct(s.record))}%` }}
                />
              </div>
              <span className="text-[10px] text-muted-foreground num">{s.season.slice(2)}</span>
            </div>
          ))}
        </div>
      </Card>
      <Card>
        <h2 className="mb-1 text-xl">Gol fatti e subiti</h2>
        <p className="mb-3 flex gap-3 text-[11px] text-muted-foreground">
          <span className="inline-flex items-center gap-1">
            <span className="h-2 w-2 rounded-full bg-success" /> fatti
          </span>
          <span className="inline-flex items-center gap-1">
            <span className="h-2 w-2 rounded-full bg-primary" /> subiti
          </span>
        </p>
        <div className="flex items-end gap-3">
          {data.map((s, idx) => (
            <div key={s.season} className="flex flex-1 flex-col items-center gap-1">
              <div className="flex h-28 w-full items-end gap-1">
                <div className="flex h-full flex-1 flex-col items-center justify-end">
                  <span className="text-[10px] font-bold num">
                    <CountUp value={s.record.goals_for} />
                  </span>
                  <Grow
                    i={idx}
                    className="w-full rounded-t-md bg-success"
                    style={{ height: `${Math.max(4, (s.record.goals_for / maxGoals) * 78)}%` }}
                  />
                </div>
                <div className="flex h-full flex-1 flex-col items-center justify-end">
                  <span className="text-[10px] font-bold num">
                    <CountUp value={s.record.goals_against} />
                  </span>
                  <Grow
                    i={idx}
                    delay={0.08}
                    className="w-full rounded-t-md bg-primary"
                    style={{ height: `${Math.max(4, (s.record.goals_against / maxGoals) * 78)}%` }}
                  />
                </div>
              </div>
              <span className="text-[10px] text-muted-foreground num">{s.season.slice(2)}</span>
            </div>
          ))}
        </div>
      </Card>
    </div>
  );
}

const BOARDS = [
  {
    id: "goals",
    label: "Marcatori",
    unit: "gol",
    value: (r: CareerRow) => r.goals,
    note: (r: CareerRow) => `${r.matches} pres.`,
  },
  {
    id: "matches",
    label: "Presenze",
    unit: "pres.",
    value: (r: CareerRow) => r.matches,
    note: (r: CareerRow) => `${r.seasons} ${r.seasons === 1 ? "stagione" : "stagioni"}`,
  },
  {
    id: "mvp",
    label: "Miglior giocatore",
    unit: "★",
    value: (r: CareerRow) => r.mvp,
    note: (r: CareerRow) => `${r.matches} pres.`,
  },
  {
    id: "cards",
    label: "Cartellini",
    unit: "🟨🟥",
    value: (r: CareerRow) => r.yellow + r.red,
    note: (r: CareerRow) => `${r.yellow} gialli · ${r.red} rossi`,
  },
] as const;

/** Albo d'oro di sempre: anche chi non gioca più con noi, con la scheda a un tocco. */
function HallOfFame() {
  const q = useCareer();
  const [board, setBoard] = useState<(typeof BOARDS)[number]["id"]>("goals");
  const [onlyActive, setOnlyActive] = useState(false);
  const pillId = useId();
  const b = BOARDS.find((x) => x.id === board) ?? BOARDS[0];
  const rows = (q.data ?? [])
    .filter((r) => (onlyActive ? r.player.is_active : true) && b.value(r) > 0)
    .sort((x, y) => b.value(y) - b.value(x) || y.matches - x.matches)
    .slice(0, 10);
  const top = Math.max(1, ...rows.map(b.value));
  return (
    <Panel
      title="Albo d'oro di sempre"
      kicker="Dal 2022/23 a oggi"
      action={
        <label className="flex min-h-9 items-center gap-2 text-xs font-semibold">
          <input
            type="checkbox"
            className="h-4 w-4"
            checked={onlyActive}
            onChange={(e) => setOnlyActive(e.target.checked)}
          />{" "}
          Solo rosa attuale
        </label>
      }
    >
      <div role="tablist" aria-label="Classifica" className="mb-3 flex flex-wrap gap-1">
        {BOARDS.map((x) => (
          <button
            key={x.id}
            role="tab"
            aria-selected={board === x.id}
            onClick={() => setBoard(x.id)}
            className={cn(
              "press relative min-h-10 rounded-lg px-3 text-sm font-semibold transition-colors",
              board === x.id
                ? "text-primary-foreground"
                : "bg-secondary text-muted-foreground hover:text-foreground",
            )}
          >
            {board === x.id && <ActivePill id={pillId} className="rounded-lg bg-primary" />}
            <span className="relative">{x.label}</span>
          </button>
        ))}
      </div>
      {q.isPending ? (
        <Skeleton className="h-64" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState>Ancora nessun dato.</EmptyState>
      ) : (
        <ol key={board} className="space-y-1.5">
          {rows.map((r, i) => (
            <Reveal as="li" key={r.player.id} i={i} x={-16} y={0}>
              <Link
                to="/rosa/$playerId"
                params={{ playerId: String(r.player.id) }}
                className="press flex items-center gap-3 rounded-lg bg-background/40 p-2 hover:bg-accent"
              >
                <span
                  className={cn(
                    "w-6 text-center font-display text-xl num",
                    i === 0 ? "text-warning" : "text-muted-foreground",
                  )}
                >
                  {i + 1}
                </span>
                <PlayerPhoto player={r.player} size={36} />
                <div className="min-w-0 flex-1">
                  <div className="truncate text-sm font-semibold">
                    {r.player.full_name}
                    {!r.player.is_active && (
                      <span className="ml-2 rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">
                        ex
                      </span>
                    )}
                  </div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                    <Grow
                      axis="x"
                      i={i}
                      delay={0.15}
                      className="h-full rounded-full bg-primary"
                      style={{ width: `${(b.value(r) / top) * 100}%` }}
                    />
                  </div>
                </div>
                <div className="text-right">
                  <div className="font-display text-2xl leading-none num">
                    <CountUp value={b.value(r)} duration={0.9} />
                  </div>
                  <div className="text-[10px] text-muted-foreground">{b.note(r)}</div>
                </div>
              </Link>
            </Reveal>
          ))}
        </ol>
      )}
    </Panel>
  );
}

/** Bilancio contro le avversarie più incontrate. */
function Opponents({ rows }: { rows: HistoryOpponent[] }) {
  const [all, setAll] = useState(false);
  const shown = all ? rows : rows.slice(0, 10);
  // dalle squadre incontrate almeno 3 volte: quella con cui vinciamo di più e quella con cui perdiamo di più
  const regulars = rows.filter((o) => o.played >= 3);
  const score = (o: HistoryOpponent) => (o.won * 3 + o.drawn) / o.played;
  const victim =
    regulars.length >= 2
      ? [...regulars].sort((a, b) => score(b) - score(a) || b.played - a.played)[0]
      : null;
  const nemesis =
    regulars.length >= 2
      ? [...regulars].sort((a, b) => score(a) - score(b) || b.played - a.played)[0]
      : null;
  return (
    <Panel title="Le nostre avversarie" kicker="Bilancio negli scontri diretti">
      {victim && nemesis && victim.team.id !== nemesis.team.id && (
        <div className="mb-3 grid grid-cols-1 gap-2 md:grid-cols-2">
          {(
            [
              ["Vittima preferita", victim, Swords, "bg-success/10 text-success"],
              ["Bestia nera", nemesis, Skull, "bg-primary/10 text-primary"],
            ] as const
          ).map(([label, o, Icon, cls], idx) => (
            <Reveal
              key={label}
              i={idx}
              scale={0.95}
              className={cn("flex items-center gap-3 rounded-xl p-3", cls)}
            >
              <Crest team={o.team} size={36} />
              <div className="min-w-0 flex-1">
                <div className="text-[11px] font-bold uppercase tracking-wider">{label}</div>
                <div className="truncate text-sm font-semibold text-foreground">{o.team.name}</div>
                <div className="text-[11px] text-muted-foreground num">
                  {o.won}V {o.drawn}N {o.lost}P in {o.played} sfide · {o.goals_for}-
                  {o.goals_against}
                </div>
              </div>
              <motion.span
                className="shrink-0"
                initial={{ scale: 0.4, rotate: -14, opacity: 0 }}
                whileInView={{ scale: 1, rotate: 0, opacity: 0.7 }}
                viewport={{ once: true }}
                transition={{ ...spring.pop, delay: 0.3 + stagger(idx, 0.1) }}
              >
                <Icon className="h-6 w-6" />
              </motion.span>
            </Reveal>
          ))}
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[420px] text-sm num">
          <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="py-2 text-left">Squadra</th>
              <th>G</th>
              <th>V</th>
              <th>N</th>
              <th>P</th>
              <th>GF-GS</th>
            </tr>
          </thead>
          <tbody>
            {shown.map((o, idx) => (
              <motion.tr
                key={o.team.id}
                initial={{ opacity: 0 }}
                whileInView={{ opacity: 1 }}
                viewport={{ once: true, amount: 0.2 }}
                transition={{ duration: dur.base, ease: ease.out, delay: stagger(idx, 0.035) }}
                className="border-t"
              >
                <td className="py-1.5">
                  <div className="flex items-center gap-2">
                    <Crest team={o.team} size={24} />
                    <span className="max-w-[10rem] truncate font-semibold md:max-w-none">
                      {o.team.name}
                    </span>
                  </div>
                </td>
                <td className="text-center">{o.played}</td>
                <td className="text-center text-success">{o.won}</td>
                <td className="text-center text-warning">{o.drawn}</td>
                <td className="text-center text-primary">{o.lost}</td>
                <td className="text-center text-muted-foreground">
                  {o.goals_for}-{o.goals_against}
                </td>
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>
      {rows.length > 10 && (
        <button
          type="button"
          onClick={() => setAll(!all)}
          className="press mt-2 min-h-11 w-full rounded-lg border text-sm font-semibold hover:bg-accent"
        >
          {all ? "Mostra meno" : `Mostra tutte le ${rows.length} avversarie`}
        </button>
      )}
    </Panel>
  );
}

function HistoryPage() {
  const q = useHistory();
  return (
    <div className="space-y-6">
      <PageTitle kicker="Archivio" title="Storico" />
      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-72" />
          <Skeleton className="h-48" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.data.seasons.length === 0 ? (
        <EmptyState>Nessuna stagione in archivio per questo formato.</EmptyState>
      ) : (
        (() => {
          const { summary: s, seasons } = q.data;
          const r = s.record;
          return (
            <>
              <Card className="bg-hero">
                <h2 className="mb-4 text-2xl">Il nostro curriculum</h2>
                <div className="grid grid-cols-3 gap-2 md:grid-cols-6">
                  <Stat i={0} label="Stagioni" value={s.seasons_count} />
                  <Stat i={1} label="Giocate" value={r.played} />
                  <Stat i={2} label="V-N-P" value={wdl(r)} />
                  <Stat i={3} label="GF-GS" value={`${r.goals_for}-${r.goals_against}`} />
                  <Stat i={4} label="Punti" value={r.points} />
                  <Stat i={5} label="% vittorie" value={`${pct(r)}%`} cls="text-success" />
                </div>
                <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-3">
                  <Highlight i={0} title="Miglior vittoria" m={s.best_win} />
                  <Highlight i={1} title="Peggior sconfitta" m={s.worst_defeat} />
                  <Reveal i={2} className="flex items-center gap-3 rounded-xl bg-background/50 p-3">
                    {s.most_frequent_opponent && (
                      <Crest team={s.most_frequent_opponent.team} size={36} />
                    )}
                    <div className="min-w-0">
                      <div className="text-[11px] uppercase tracking-wider text-muted-foreground">
                        Avversario più affrontato
                      </div>
                      <div className="truncate text-sm font-semibold">
                        {s.most_frequent_opponent
                          ? `${s.most_frequent_opponent.team.name} · ${s.most_frequent_opponent.played} volte`
                          : "—"}
                      </div>
                    </div>
                  </Reveal>
                </div>
                <p className="mt-3 text-xs text-muted-foreground">
                  Differenza reti complessiva:{" "}
                  <span className="font-bold num">
                    {r.goals_for - r.goals_against > 0 ? "+" : ""}
                    <CountUp value={r.goals_for - r.goals_against} />
                  </span>
                </p>
              </Card>
              <InfoBanner>
                Nei conteggi entrano campionati, coppe e tornei ufficiali di XFive. Non contano le
                amichevoli né la Serie A a 8 di 100GRIGIO 2025/26.
              </InfoBanner>
              <Trend seasons={seasons} />
              <InsightsSections insights={q.data.insights} parts={["kpis"]} />
              <HallOfFame />
              <InsightsSections
                insights={q.data.insights}
                parts={[
                  "streaks",
                  "extremes",
                  "splits",
                  "player_records",
                  "hat_tricks",
                  "referees",
                  "venues",
                ]}
              />
              <Opponents rows={q.data.opponents} />
              {seasons.map((season) => (
                <section key={season.season}>
                  <Reveal
                    x={-14}
                    y={0}
                    className="mb-2 flex flex-wrap items-end justify-between gap-2"
                  >
                    <h2 className="text-3xl num">{season.season}</h2>
                    <span className="text-sm text-muted-foreground num">
                      {wdl(season.record)} · {season.record.goals_for}-{season.record.goals_against}
                    </span>
                  </Reveal>
                  <Link
                    to="/storico/stagione/$season"
                    params={{ season: season.season.replace("/", "-") }}
                    className="press group mb-3 inline-flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm font-semibold hover:border-primary/40"
                  >
                    Scheda della stagione: rosa, record, arbitri e campi{" "}
                    <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" />
                  </Link>
                  {season.top_scorers.length > 0 && (
                    <div className="mb-2 flex flex-wrap items-center gap-2 text-xs">
                      <span className="inline-flex items-center gap-1 font-bold uppercase tracking-wider text-warning">
                        <Star className="h-3.5 w-3.5 fill-warning" /> Marcatori
                      </span>
                      {season.top_scorers.map((s, idx) => (
                        <Reveal as="span" key={s.player.id} i={idx} scale={0.85} y={0}>
                          <Link
                            to="/rosa/$playerId"
                            params={{ playerId: String(s.player.id) }}
                            className="press inline-block rounded-full bg-secondary px-2.5 py-1 font-semibold hover:bg-accent"
                          >
                            {s.player.full_name} <span className="text-primary num">{s.goals}</span>
                          </Link>
                        </Reveal>
                      ))}
                    </div>
                  )}
                  <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                    {season.competitions.map((c, idx) => (
                      <Reveal key={c.id} i={idx} y={12}>
                        <CompetitionCard c={c} i={idx} />
                      </Reveal>
                    ))}
                  </div>
                </section>
              ))}
            </>
          );
        })()
      )}
    </div>
  );
}
