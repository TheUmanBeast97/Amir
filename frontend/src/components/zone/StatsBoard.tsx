import { Link } from "@tanstack/react-router";
import { useId, useState, type ReactNode } from "react";
import type { ZoneClubRef, ZoneStatRow, ZoneStatsBoard } from "@/api/zone-types";
import { ActivePill, CountUp, Grow, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { EmptyState } from "@/components/ui-kit";
import { cn } from "@/lib/utils";
import { ClubCrest } from "./ClubCrest";

/** Il valore che conta di una riga (la prima colonna), con i decimali se servono. */
const mainValue = (r: ZoneStatRow) => r.values[0] ?? 0;

/**
 * Una classifica di giocatori come su XFive: posizione, foto, nome (con il link al profilo quando è
 * abbinato), squadra, barra proporzionale e il numero che sale. `columns` sono le intestazioni di XFive:
 * la prima è il valore grande, le altre finiscono nella riga piccola («3 Ammonizioni · 1 Espulsioni»).
 */
export function StatRows({
  rows,
  columns,
  unit,
  limit,
  decimals = 0,
}: {
  rows: ZoneStatRow[];
  columns: string[];
  /** Un'unità corta accanto al numero (es. «gol»); se manca si usa la prima colonna. */
  unit?: string;
  limit?: number;
  decimals?: number;
}) {
  const [all, setAll] = useState(false);
  const shown = limit && !all ? rows.slice(0, limit) : rows;
  const top = Math.max(1, ...rows.map(mainValue));
  if (rows.length === 0) return <EmptyState>Ancora nessun dato.</EmptyState>;
  return (
    <div>
      <ol className="space-y-1.5">
        {shown.map((r, i) => {
          const extra = columns
            .slice(1)
            .map((c, idx) => `${r.values[idx + 1] ?? 0} ${c.toLowerCase()}`)
            .join(" · ");
          const inner = (
            <>
              <span
                className={cn(
                  "w-6 text-center font-display text-xl num",
                  i === 0 ? "text-warning" : "text-muted-foreground",
                )}
              >
                {r.position}
              </span>
              <PlayerPhoto player={{ full_name: r.name, photo_url: r.photo_url }} size={36} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">{r.name}</div>
                <div className="truncate text-[11px] text-muted-foreground">
                  {[r.team, extra].filter(Boolean).join(" · ")}
                </div>
                <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                  <Grow
                    axis="x"
                    i={i}
                    delay={0.15}
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${(mainValue(r) / top) * 100}%` }}
                  />
                </div>
              </div>
              <div className="text-right">
                <div className="font-display text-2xl leading-none num">
                  <CountUp value={mainValue(r)} decimals={decimals} duration={0.9} />
                </div>
                <div className="text-[10px] text-muted-foreground">
                  {unit ?? columns[0]?.toLowerCase() ?? ""}
                </div>
              </div>
            </>
          );
          const cls = "flex items-center gap-3 rounded-lg bg-background/40 p-2";
          return (
            <Reveal as="li" key={`${r.position}-${r.name}`} i={i} x={-16} y={0}>
              {r.player_id !== null ? (
                <Link
                  to="/mixed-zone/giocatori/$id"
                  params={{ id: String(r.player_id) }}
                  className={cn(cls, "press hover:bg-accent")}
                >
                  {inner}
                </Link>
              ) : (
                <div
                  className={cls}
                  title="Profilo non abbinato: XFive pubblica solo il nome abbreviato"
                >
                  {inner}
                </div>
              )}
            </Reveal>
          );
        })}
      </ol>
      {limit && rows.length > limit && (
        <button
          type="button"
          onClick={() => setAll(!all)}
          className="press mt-2 min-h-11 w-full rounded-lg border text-sm font-semibold hover:bg-accent"
        >
          {all ? "Mostra meno" : `Mostra tutti i ${rows.length}`}
        </button>
      )}
    </div>
  );
}

/** Una classifica di club (squadre più vincenti, difese meno battute). */
export function ClubRows<T extends { club: ZoneClubRef }>({
  rows,
  value,
  note,
  unit,
  decimals = 2,
  invert = false,
}: {
  rows: T[];
  value: (r: T) => number;
  note: (r: T) => string;
  unit: string;
  decimals?: number;
  /** Le barre si allungano per i valori più bassi (difese: meno gol subiti, barra più lunga). */
  invert?: boolean;
}) {
  if (rows.length === 0) return <EmptyState>Ancora nessun dato.</EmptyState>;
  const vals = rows.map(value);
  const max = Math.max(...vals, 0.0001);
  const min = Math.min(...vals);
  const width = (v: number) =>
    invert ? Math.max(6, ((max - v) / (max - min || 1)) * 100) : Math.max(6, (v / max) * 100);
  return (
    <ol className="space-y-1.5">
      {rows.map((r, i) => {
        const inner = (
          <>
            <span
              className={cn(
                "w-6 text-center font-display text-xl num",
                i === 0 ? "text-warning" : "text-muted-foreground",
              )}
            >
              {i + 1}
            </span>
            <ClubCrest club={r.club} size={36} />
            <div className="min-w-0 flex-1">
              <div className="truncate text-sm font-semibold">{r.club.name}</div>
              <div className="truncate text-[11px] text-muted-foreground num">{note(r)}</div>
              <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <Grow
                  axis="x"
                  i={i}
                  delay={0.15}
                  className="h-full rounded-full bg-primary"
                  style={{ width: `${width(value(r))}%` }}
                />
              </div>
            </div>
            <div className="text-right">
              <div className="font-display text-2xl leading-none num">
                <CountUp value={value(r)} decimals={decimals} duration={0.9} />
              </div>
              <div className="text-[10px] text-muted-foreground">{unit}</div>
            </div>
          </>
        );
        const cls = "flex items-center gap-3 rounded-lg bg-background/40 p-2";
        return (
          <Reveal as="li" key={r.club.id ?? r.club.name} i={i} x={-16} y={0}>
            {r.club.id !== null ? (
              <Link
                to="/mixed-zone/squadre/$id"
                params={{ id: String(r.club.id) }}
                className={cn(cls, "press hover:bg-accent")}
              >
                {inner}
              </Link>
            ) : (
              <div className={cls}>{inner}</div>
            )}
          </Reveal>
        );
      })}
    </ol>
  );
}

const BOARDS = [
  { id: "scorers", label: "Marcatori", kicker: "Gol nei referti" },
  { id: "appearances", label: "Presenze", kicker: "Partite in distinta" },
  { id: "mvp", label: "Miglior giocatore", kicker: "Stelle di partita" },
  { id: "cards", label: "Cartellini", kicker: "Ammonizioni ed espulsioni" },
  { id: "best_teams", label: "Squadre più vincenti", kicker: "Punti a partita, almeno 10 partite" },
  {
    id: "best_defenses",
    label: "Difese meno battute",
    kicker: "Gol subiti a partita, almeno 10 partite",
  },
] as const;
type BoardId = (typeof BOARDS)[number]["id"];

/** Le classifiche di sempre della Mixed Zone, una scheda per tabella. */
export function StatsBoard({ board, header }: { board: ZoneStatsBoard; header?: ReactNode }) {
  const [tab, setTab] = useState<BoardId>("scorers");
  const pillId = useId();
  const meta = BOARDS.find((b) => b.id === tab) ?? BOARDS[0];
  return (
    <section className="rounded-xl border bg-card p-4">
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
          <div className="text-[11px] font-bold uppercase tracking-[0.18em] text-primary">
            {meta.kicker}
          </div>
          <h2 className="text-xl leading-tight">{meta.label}</h2>
        </div>
        {header}
      </div>
      <div
        role="tablist"
        aria-label="Classifica"
        className="-mx-4 mb-3 flex gap-1 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:flex-wrap md:px-0"
      >
        {BOARDS.map((b) => (
          <button
            key={b.id}
            role="tab"
            aria-selected={tab === b.id}
            onClick={() => setTab(b.id)}
            className={cn(
              "press relative min-h-10 shrink-0 rounded-lg px-3 text-sm font-semibold transition-colors",
              tab === b.id
                ? "text-primary-foreground"
                : "bg-secondary text-muted-foreground hover:text-foreground",
            )}
          >
            {tab === b.id && <ActivePill id={pillId} className="rounded-lg bg-primary" />}
            <span className="relative">{b.label}</span>
          </button>
        ))}
      </div>
      <div key={tab}>
        {tab === "scorers" && <StatRows rows={board.scorers} columns={["Gol"]} limit={10} />}
        {tab === "appearances" && (
          <StatRows rows={board.appearances} columns={["Presenze"]} unit="pres." limit={10} />
        )}
        {tab === "mvp" && <StatRows rows={board.mvp} columns={["Stelle"]} unit="★" limit={10} />}
        {tab === "cards" && (
          <StatRows
            rows={board.cards}
            columns={["Ammonizioni", "Espulsioni"]}
            unit="gialli"
            limit={10}
          />
        )}
        {tab === "best_teams" && (
          <ClubRows
            rows={board.best_teams}
            value={(r) => r.points_per_match}
            note={(r) => `${r.won} vittorie in ${r.played} partite`}
            unit="punti a partita"
          />
        )}
        {tab === "best_defenses" && (
          <ClubRows
            rows={board.best_defenses}
            value={(r) => r.conceded_per_match}
            note={(r) => `in ${r.played} partite`}
            unit="subiti a partita"
            invert
          />
        )}
      </div>
    </section>
  );
}
