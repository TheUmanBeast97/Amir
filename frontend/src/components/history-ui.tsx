import { Link } from "@tanstack/react-router";
import { Flame, MapPin, Medal, Shield, Star, Target, UserCheck, Zap } from "lucide-react";
import { motion } from "motion/react";
import { useId, useState } from "react";
import type {
  CompetitionKind, GroupedRecord, HatTrick, HeadToHeadMatch, HistoryCompetition, HistoryInsights, PlayerRecord, RecordLine, RosterRow, SplitRow, StreakKind,
} from "@/api/types";
import { ActivePill, CountUp, Draw, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Kpi, Panel, ResultDot } from "@/components/stats-ui";
import { fmtShortDate, kindLabel } from "@/lib/format";
import { dist, dur, ease, spring, stagger } from "@/lib/motion";
import { cn } from "@/lib/utils";

const wdl = (r: RecordLine) => `${r.won}-${r.drawn}-${r.lost}`;
export const winPct = (r: RecordLine) => (r.played ? Math.round((r.won / r.played) * 100) : 0);
const dec = (n: number) => n.toFixed(2).replace(".", ",");
const medal = (p: number | null) => (p === 1 ? "🥇" : p === 2 ? "🥈" : p === 3 ? "🥉" : null);

/** Barra vittorie / pareggi / sconfitte. */
export function WdlBar({ r }: { r: RecordLine }) {
  const t = r.played || 1;
  return (
    <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted" role="img" aria-label={`${r.won} vittorie, ${r.drawn} pareggi, ${r.lost} sconfitte`}>
      {/* la barra si traccia da sinistra a destra come una linea di campo */}
      <Draw className="flex h-full w-full">
        <div className="bg-success" style={{ width: `${(r.won / t) * 100}%` }} />
        <div className="bg-warning" style={{ width: `${(r.drawn / t) * 100}%` }} />
        <div className="bg-primary" style={{ width: `${(r.lost / t) * 100}%` }} />
      </Draw>
    </div>
  );
}

/** Una riga di bilancio: nome, barra V-N-P, risultato e % di vittorie. */
function RecordRow({ label, r, i = 0 }: { label: string; r: RecordLine; i?: number }) {
  return (
    <Reveal as="li" i={i} y={8} className="grid grid-cols-[minmax(5rem,8.5rem)_1fr_auto] items-center gap-3 py-2 text-sm">
      <div className="min-w-0">
        <div className="truncate font-semibold">{label}</div>
        <div className="text-[11px] text-muted-foreground num">{r.played} partite · {r.goals_for}-{r.goals_against}</div>
      </div>
      <WdlBar r={r} />
      <div className="text-right num"><span className="font-bold">{wdl(r)}</span><span className="ml-2 text-xs text-muted-foreground">{winPct(r)}%</span></div>
    </Reveal>
  );
}

// ---------------------------------------------------------------- numeri chiave

export function InsightsKpis({ insights }: { insights: HistoryInsights }) {
  const r = insights.records;
  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
      <Kpi i={0} label="Gol a partita" value={r.goals_per_match} decimals={2} sub={`${dec(r.conceded_per_match)} subiti`} tone="success" icon={<Target className="h-6 w-6" />} />
      <Kpi i={1} label="Punti a partita" value={r.points_per_match} decimals={2} sub="3 la vittoria, 1 il pareggio" />
      <Kpi i={2} label="Porta inviolata" value={r.clean_sheets} sub={`${r.scoreless} volte senza segnare`} tone="warning" icon={<Shield className="h-6 w-6" />} />
      <Kpi i={3} label="Triplette" value={r.hat_tricks_count} sub={`${r.doubles_count} doppiette`} tone="primary" icon={<Flame className="h-6 w-6" />} />
      <Kpi i={4} label="Cartellini" value={<span className="text-3xl md:text-4xl">{r.yellow}<span className="text-warning"> 🟨</span> {r.red}<span className="text-primary"> 🟥</span></span>} sub="dei nostri, nelle distinte lette" className="col-span-2 md:col-span-1" />
    </div>
  );
}

// ---------------------------------------------------------------- serie

const STREAKS: { id: StreakKind; label: string; tone: string }[] = [
  { id: "wins", label: "Vittorie di fila", tone: "text-success" },
  { id: "unbeaten", label: "Senza sconfitte", tone: "text-success" },
  { id: "scoring", label: "Sempre a segno", tone: "text-warning" },
  { id: "clean_sheets", label: "Porta inviolata di fila", tone: "text-warning" },
  { id: "losses", label: "Sconfitte di fila", tone: "text-primary" },
];

export function StreaksPanel({ streaks }: { streaks: HistoryInsights["streaks"] }) {
  return (
    <Panel title="Le serie più lunghe" kicker="Partite consecutive">
      <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
        {STREAKS.map((s, idx) => {
          const run = streaks[s.id];
          return (
            <Reveal key={s.id} i={idx} scale={0.94} className="rounded-xl bg-background/50 p-3">
              <div className={cn("font-display text-4xl leading-none num", run.length ? s.tone : "text-muted-foreground")}>{run.length ? <CountUp value={run.length} /> : "-"}</div>
              <div className="mt-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{s.label}</div>
              {run.length > 1 && run.from && run.to && <div className="mt-1 text-[11px] text-muted-foreground num">{fmtShortDate(run.from)} → {fmtShortDate(run.to)}</div>}
            </Reveal>
          );
        })}
      </div>
    </Panel>
  );
}

// ---------------------------------------------------------------- partite da record

function MatchLine({ m }: { m: HeadToHeadMatch }) {
  return (
    <Link to="/partite/$matchId" params={{ matchId: String(m.match_id) }} className="flex items-center gap-3 rounded-lg p-2 hover:bg-accent">
      <ResultDot r={m.result} />
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-semibold">{m.home_team} <span className="font-display text-base num">{m.home_score}–{m.away_score}</span> {m.away_team}</div>
        <div className="truncate text-[11px] text-muted-foreground">{m.season} · {m.competition_name}</div>
      </div>
    </Link>
  );
}

export function ExtremesPanel({ records }: { records: HistoryInsights["records"] }) {
  const items: [string, HeadToHeadMatch | null][] = [
    ["Miglior vittoria", records.best_win],
    ["Peggior sconfitta", records.worst_defeat],
    ["Partita con più reti", records.highest_scoring],
    // gli stessi risultati di sopra non si ripetono
    ["Più gol segnati", records.most_scored && records.most_scored.match_id !== records.best_win?.match_id ? records.most_scored : null],
    ["Più gol subiti", records.most_conceded && records.most_conceded.match_id !== records.worst_defeat?.match_id ? records.most_conceded : null],
  ];
  const shown = items.filter(([, m]) => m);
  if (shown.length === 0) return null;
  return (
    <Panel title="Partite da record" kicker="I risultati estremi">
      <ul className="grid grid-cols-1 gap-x-4 gap-y-1 md:grid-cols-2">
        {shown.map(([label, m], idx) => (
          <Reveal as="li" key={label} i={idx} y={10}>
            <div className="px-2 pt-1 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">{label}</div>
            {m && <MatchLine m={m} />}
          </Reveal>
        ))}
      </ul>
    </Panel>
  );
}

// ---------------------------------------------------------------- rendimento

const SPLITS = [
  { id: "home_away", label: "Casa / trasferta" },
  { id: "by_format", label: "Formato" },
  { id: "by_kind", label: "Competizione" },
  { id: "by_slot", label: "Orario" },
  { id: "by_weekday", label: "Giorno" },
  { id: "by_month", label: "Mese" },
] as const;
type SplitId = (typeof SPLITS)[number]["id"];

/** Il gruppo con la % di vittorie più alta, se ci sono abbastanza partite per dirlo. */
function bestOf(rows: SplitRow[]) {
  const ok = rows.filter((r) => r.played >= 4);
  return ok.length >= 2 ? [...ok].sort((a, b) => winPct(b) - winPct(a) || b.played - a.played)[0] : null;
}

export function SplitsPanel({ splits }: { splits: HistoryInsights["splits"] }) {
  const [tab, setTab] = useState<SplitId>("home_away");
  const pillId = useId();
  const rows: { label: string; r: RecordLine }[] = tab === "home_away"
    ? [{ label: "Prima nominata", r: splits.home_away.home }, { label: "Seconda nominata", r: splits.home_away.away }]
    : splits[tab].map((s) => ({ label: tab === "by_kind" ? (kindLabel[s.label as CompetitionKind] ?? s.label) : s.label, r: s }));
  const best = tab === "home_away" ? null : bestOf(splits[tab]);
  return (
    <Panel title="Come rendiamo" kicker="Il rendimento diviso per…">
      <div role="tablist" aria-label="Divisione del rendimento" className="mb-2 flex flex-wrap gap-1">
        {SPLITS.map((s) => (
          <button key={s.id} role="tab" aria-selected={tab === s.id} onClick={() => setTab(s.id)}
            className={cn("press relative min-h-10 rounded-lg px-3 text-sm font-semibold transition-colors", tab === s.id ? "text-primary-foreground" : "bg-secondary text-muted-foreground hover:text-foreground")}>
            {tab === s.id && <ActivePill id={pillId} className="rounded-lg bg-primary" />}
            <span className="relative">{s.label}</span>
          </button>
        ))}
      </div>
      {rows.length === 0 ? <p className="py-4 text-sm text-muted-foreground">Dati non sufficienti.</p> : <ul key={tab} className="divide-y">{rows.map((x, idx) => <RecordRow key={x.label} label={x.label} r={x.r} i={idx} />)}</ul>}
      {tab === "home_away" && <p className="mt-2 text-[11px] text-muted-foreground">Su XFive «casa» è la prima squadra nominata: non vuol dire che si giochi sul nostro campo.</p>}
      {best && (
        <motion.p key={tab} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: dur.base, ease: ease.out, delay: 0.25 }} className="mt-2 rounded-lg bg-success/10 p-2 text-xs">
          <span className="font-bold">Il nostro migliore:</span> {tab === "by_kind" ? (kindLabel[best.label as CompetitionKind] ?? best.label) : best.label} · {winPct(best)}% di vittorie in {best.played} partite.
        </motion.p>
      )}
    </Panel>
  );
}

// ---------------------------------------------------------------- arbitri e campi

export function GroupedTable({ title, kicker, rows, nameLabel, icon, withCards = false, minForHighlights = 4 }: {
  title: string; kicker: string; rows: GroupedRecord[]; nameLabel: string; icon: "ref" | "venue"; withCards?: boolean; minForHighlights?: number;
}) {
  const [all, setAll] = useState(false);
  if (rows.length === 0) return null;
  const shown = all ? rows : rows.slice(0, 8);
  const eligible = rows.filter((r) => r.played >= minForHighlights);
  const best = eligible.length >= 2 ? [...eligible].sort((a, b) => winPct(b) - winPct(a) || b.played - a.played)[0] : null;
  const worst = eligible.length >= 2 ? [...eligible].sort((a, b) => winPct(a) - winPct(b) || b.played - a.played)[0] : null;
  const Icon = icon === "ref" ? UserCheck : MapPin;
  return (
    <Panel title={title} kicker={kicker}>
      {best && worst && best.name !== worst.name && (
        <div className="mb-3 flex flex-wrap gap-2 text-xs">
          <motion.span initial={{ opacity: 0, scale: 0.85 }} whileInView={{ opacity: 1, scale: 1 }} viewport={{ once: true }} transition={{ ...spring.pop, delay: 0.1 }} className="inline-flex items-center gap-1.5 rounded-full bg-success/15 px-3 py-1 font-semibold text-success"><Medal className="h-3.5 w-3.5" /> Meglio con: {best.name} ({winPct(best)}% in {best.played})</motion.span>
          <motion.span initial={{ opacity: 0, scale: 0.85 }} whileInView={{ opacity: 1, scale: 1 }} viewport={{ once: true }} transition={{ ...spring.pop, delay: 0.2 }} className="inline-flex items-center gap-1.5 rounded-full bg-primary/15 px-3 py-1 font-semibold text-primary"><Zap className="h-3.5 w-3.5" /> Peggio con: {worst.name} ({winPct(worst)}% in {worst.played})</motion.span>
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[460px] text-sm num">
          <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr><th className="py-2 text-left">{nameLabel}</th><th>G</th><th>V</th><th>N</th><th>P</th><th>GF-GS</th>{withCards && <><th>🟨</th><th>🟥</th></>}</tr>
          </thead>
          <tbody>
            {shown.map((r, idx) => (
              <motion.tr key={r.name} initial={{ opacity: 0 }} whileInView={{ opacity: 1 }} viewport={{ once: true, amount: 0.2 }} transition={{ duration: dur.base, ease: ease.out, delay: stagger(idx, 0.035) }} className="border-t">
                <td className="py-1.5"><div className="flex items-center gap-2"><Icon className="h-4 w-4 shrink-0 text-muted-foreground" /><span className="max-w-[12rem] truncate font-semibold md:max-w-none">{r.name}</span></div></td>
                <td className="text-center">{r.played}</td>
                <td className="text-center text-success">{r.won}</td>
                <td className="text-center text-warning">{r.drawn}</td>
                <td className="text-center text-primary">{r.lost}</td>
                <td className="text-center text-muted-foreground">{r.goals_for}-{r.goals_against}</td>
                {withCards && <><td className="text-center">{r.yellow ?? 0}</td><td className="text-center">{r.red ?? 0}</td></>}
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>
      {rows.length > 8 && (
        <button type="button" onClick={() => setAll(!all)} className="press mt-2 min-h-11 w-full rounded-lg border text-sm font-semibold hover:bg-accent">
          {all ? "Mostra meno" : `Mostra tutti (${rows.length})`}
        </button>
      )}
    </Panel>
  );
}

// ---------------------------------------------------------------- giocatori

export function HatTricksPanel({ list, total }: { list: HatTrick[]; total: number }) {
  if (list.length === 0) return null;
  return (
    <Panel title="Triplette e oltre" kicker={`${total} volte con 3 o più gol in una partita`}>
      <ul className="space-y-1">
        {list.slice(0, 8).map((h, idx) => (
          <Reveal as="li" key={`${h.player.id}-${h.match.match_id}`} i={idx} x={-14} y={0}>
            <Link to="/rosa/$playerId" params={{ playerId: String(h.player.id) }} className="press flex items-center gap-3 rounded-lg p-2 hover:bg-accent">
              <PlayerPhoto player={h.player} size={36} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">{h.player.full_name}</div>
                <div className="truncate text-[11px] text-muted-foreground">{h.match.home_team} {h.match.home_score}–{h.match.away_score} {h.match.away_team} · {h.match.season}</div>
              </div>
              <div className="text-right"><span className="font-display text-3xl leading-none text-primary num"><CountUp value={h.goals} duration={0.8} /></span><span className="ml-1 text-[10px] uppercase text-muted-foreground">gol</span></div>
            </Link>
          </Reveal>
        ))}
      </ul>
    </Panel>
  );
}

export function PlayerRecordsPanel({ records }: { records: HistoryInsights["player_records"] }) {
  const tiles: [string, string, PlayerRecord | null][] = [
    ["Capocannoniere", "gol", records.goals],
    ["Più presenze", "pres.", records.apps],
    ["Più volte migliore in campo", "★", records.mvp],
  ];
  if (tiles.every(([, , r]) => !r)) return null;
  return (
    <Panel title="Primati individuali" kicker="In una singola stagione">
      <div className="grid grid-cols-1 gap-2 md:grid-cols-3">
        {tiles.map(([label, unit, r], idx) => r && (
          <Reveal key={label} i={idx} scale={0.95}>
            <Link to="/rosa/$playerId" params={{ playerId: String(r.player.id) }} className="lift flex h-full items-center gap-3 rounded-xl bg-background/50 p-3 hover:bg-accent">
              <PlayerPhoto player={r.player} size={44} />
              <div className="min-w-0 flex-1">
                <div className="text-[11px] font-bold uppercase tracking-wider text-warning">{label}</div>
                <div className="truncate text-sm font-semibold">{r.player.full_name}</div>
                <div className="text-[11px] text-muted-foreground num">{r.season}</div>
              </div>
              <div className="text-right"><div className="font-display text-3xl leading-none num"><CountUp value={r.value} /></div><div className="text-[10px] text-muted-foreground">{unit}</div></div>
            </Link>
          </Reveal>
        ))}
      </div>
    </Panel>
  );
}

const SORTS = [
  { id: "apps", label: "Presenze" },
  { id: "goals", label: "Gol" },
  { id: "goals_per_match", label: "Gol a partita" },
  { id: "mvp", label: "Migliore in campo" },
] as const;

/** Tutti i giocatori scesi in campo, con presenze, gol e cartellini. */
export function RosterTable({ rows, title = "Chi ha giocato", kicker }: { rows: RosterRow[]; title?: string; kicker?: string }) {
  const [sort, setSort] = useState<(typeof SORTS)[number]["id"]>("apps");
  if (rows.length === 0) return null;
  const sorted = [...rows].sort((a, b) => b[sort] - a[sort] || b.apps - a.apps || b.goals - a.goals);
  return (
    <Panel title={title} kicker={kicker ?? `${rows.length} giocatori`}
      action={
        <label className="flex items-center gap-2 text-xs font-semibold">
          <span className="sr-only sm:not-sr-only">Ordina per</span>
          <select value={sort} onChange={(e) => setSort(e.target.value as typeof sort)} className="min-h-9 rounded-lg border border-input bg-background px-2 text-xs">
            {SORTS.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
          </select>
        </label>
      }>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[480px] text-sm num">
          <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr><th className="py-2 text-left">Giocatore</th><th>Pres.</th><th>Gol</th><th>G/P</th><th><Star className="mx-auto h-3.5 w-3.5" aria-label="Migliore in campo" /></th><th>🟨</th><th>🟥</th></tr>
          </thead>
          <tbody>
            {sorted.map((r, idx) => (
              <motion.tr key={r.player.id} layout="position" initial={{ opacity: 0, y: dist.sm }} whileInView={{ opacity: 1, y: 0 }} viewport={{ once: true, amount: 0.2 }} transition={{ ...spring.soft, delay: stagger(idx, 0.025) }} className="border-t">
                <td className="py-1.5">
                  <Link to="/rosa/$playerId" params={{ playerId: String(r.player.id) }} className="flex items-center gap-2 hover:text-primary">
                    <PlayerPhoto player={r.player} size={28} />
                    <span className="max-w-[10rem] truncate font-semibold md:max-w-none">{r.player.full_name}</span>
                    {!r.player.is_active && <span className="rounded-full bg-secondary px-1.5 py-0.5 text-[10px] font-semibold uppercase text-muted-foreground">ex</span>}
                  </Link>
                </td>
                <td className="text-center">{r.apps}</td>
                <td className="text-center font-bold">{r.goals || "–"}</td>
                <td className="text-center text-muted-foreground">{r.goals ? dec(r.goals_per_match) : "–"}</td>
                <td className="text-center">{r.mvp || "–"}</td>
                <td className="text-center">{r.yellow || "–"}</td>
                <td className="text-center">{r.red || "–"}</td>
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

// ---------------------------------------------------------------- competizioni

/** La scheda di una competizione con la posizione finale (solo nei campionati), il bilancio e il link alla pagina. */
export function CompetitionCard({ c, i = 0 }: { c: HistoryCompetition; i?: number }) {
  return (
    <Link to="/storico/$competitionId" params={{ competitionId: String(c.id) }} className="lift flex items-center gap-3 rounded-xl border bg-card p-3 hover:border-primary/40">
      <motion.div
        className="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-secondary text-2xl"
        initial={{ scale: 0.6, rotate: -8, opacity: 0 }}
        whileInView={{ scale: 1, rotate: 0, opacity: 1 }}
        viewport={{ once: true }}
        transition={{ ...spring.pop, delay: 0.1 + stagger(i, 0.05) }}
      >
        {medal(c.final_position) ?? (c.final_position ? <span className="font-display text-xl num">{c.final_position}°</span> : <span className="text-xs text-muted-foreground">-</span>)}
      </motion.div>
      <div className="min-w-0 flex-1">
        <div className="truncate font-semibold">{c.name}</div>
        <div className="text-xs text-muted-foreground">{kindLabel[c.kind]} · a {c.format}{c.teams_count ? ` · ${c.teams_count} squadre` : ""}</div>
      </div>
      <div className="text-right text-xs num">
        <div className="font-bold">{wdl(c.record)}</div>
        <div className="text-muted-foreground">{c.record.goals_for}-{c.record.goals_against}</div>
      </div>
    </Link>
  );
}

type Part = "kpis" | "streaks" | "extremes" | "splits" | "player_records" | "hat_tricks" | "roster" | "referees" | "venues";

/** Le sezioni di un gruppo di partite, nell'ordine in cui si leggono meglio. */
export function InsightsSections({ insights, parts }: { insights: HistoryInsights; parts: Part[] }) {
  const has = (p: Part) => parts.includes(p);
  return (
    <>
      {has("kpis") && <InsightsKpis insights={insights} />}
      {has("roster") && <RosterTable rows={insights.roster} />}
      {has("streaks") && <StreaksPanel streaks={insights.streaks} />}
      {has("extremes") && <ExtremesPanel records={insights.records} />}
      {has("splits") && <SplitsPanel splits={insights.splits} />}
      {has("player_records") && <PlayerRecordsPanel records={insights.player_records} />}
      {has("hat_tricks") && <HatTricksPanel list={insights.hat_tricks} total={insights.records.hat_tricks_count} />}
      {(has("referees") || has("venues")) && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {has("referees") && <GroupedTable title="Gli arbitri" kicker="Chi ci ha arbitrato" rows={insights.referees} nameLabel="Arbitro" icon="ref" withCards />}
          {has("venues") && <GroupedTable title="I campi" kicker="Dove abbiamo giocato" rows={insights.venues} nameLabel="Campo" icon="venue" minForHighlights={6} />}
        </div>
      )}
    </>
  );
}
