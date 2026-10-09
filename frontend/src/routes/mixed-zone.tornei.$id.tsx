import { createFileRoute, Link } from "@tanstack/react-router";
import {
  ArrowLeft,
  CalendarDays,
  Download,
  ExternalLink,
  FileText,
  Home,
  Shield,
  Trophy,
  Users,
} from "lucide-react";
import { motion } from "motion/react";
import { useId, useMemo, useState } from "react";
import { useZoneTournament, useZoneTournamentMatches, useZoneTournamentStats } from "@/api/zone";
import type { ZoneRound, ZoneTournamentPage, ZoneTournamentStats } from "@/api/zone-types";
import { ActivePill, CountUp, Reveal } from "@/components/motion";
import { Panel } from "@/components/stats-ui";
import { EmptyState, ErrorState, PageTitle, Select, Skeleton } from "@/components/ui-kit";
import { ClubCrest } from "@/components/zone/ClubCrest";
import { MatchRow } from "@/components/zone/MatchRow";
import { StandingsTable } from "@/components/zone/StandingsTable";
import { StatRows } from "@/components/zone/StatsBoard";
import { OWN_CLUB_ID } from "@/lib/area";
import { sized } from "@/lib/img";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { TOURNAMENT_STATUS, sportLabel } from "@/lib/zone";

const TABS = [
  { id: "classifica", label: "Classifica", icon: Trophy },
  { id: "calendario", label: "Calendario", icon: CalendarDays },
  { id: "statistiche", label: "Statistiche", icon: Users },
  { id: "squadre", label: "Squadre", icon: Shield },
  { id: "documenti", label: "Documenti", icon: FileText },
] as const;
type Tab = (typeof TABS)[number]["id"];
const isTab = (v: unknown): v is Tab => TABS.some((t) => t.id === v);

/** `?tab=` sceglie la scheda; senza, si parte dalla classifica. */
export const Route = createFileRoute("/mixed-zone/tornei/$id")({
  validateSearch: (s: Record<string, unknown>): { tab?: Tab } =>
    isTab(s["tab"]) ? { tab: s["tab"] } : {},
  head: () => ({
    meta: [
      { title: "Torneo - Mixed Zone" },
      {
        name: "description",
        content:
          "Classifica, calendario, statistiche, squadre e documenti di un torneo XFive Alessandria.",
      },
      { property: "og:title", content: "Torneo - Mixed Zone" },
      { property: "og:description", content: "Classifica, calendario e statistiche del torneo." },
    ],
  }),
  component: TournamentPage,
});

function TournamentPage() {
  const { id } = Route.useParams();
  const { tab = "classifica" } = Route.useSearch();
  const q = useZoneTournament(Number(id));
  return (
    <div className="space-y-5">
      <Link
        to="/mixed-zone/tornei"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        Tornei
      </Link>
      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-52" />
          <Skeleton className="h-12" />
          <Skeleton className="h-96" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <TournamentView page={q.data} tab={tab} />
      )}
    </div>
  );
}

function TournamentView({ page, tab }: { page: ZoneTournamentPage; tab: Tab }) {
  const t = page.tournament;
  const s = TOURNAMENT_STATUS[t.status];
  const pillId = useId();
  const ours = page.teams.some((x) => x.club.id === OWN_CLUB_ID);
  return (
    <>
      <section className="card-cut relative overflow-hidden rounded-xl bg-hero p-5 md:p-8">
        <div className="relative flex flex-col gap-5 md:flex-row md:items-end">
          {t.flyer_url && (
            <motion.img
              src={sized(t.flyer_url, 192)}
              alt={`Locandina di ${t.name}`}
              initial={{ opacity: 0, scale: 0.8, rotate: -3 }}
              animate={{ opacity: 1, scale: 1, rotate: 0 }}
              transition={{ ...spring.soft, delay: 0.05 }}
              className="mx-auto h-40 w-auto max-w-[12rem] rounded-lg object-cover shadow-2xl ring-1 ring-white/10 md:mx-0"
            />
          )}
          <div className="min-w-0 flex-1">
            <PageTitle kicker={`${t.season} · ${sportLabel(t.sport)}`} title={t.name} />
            <div className="-mt-3 flex flex-wrap gap-2 text-xs font-semibold">
              <Reveal
                as="span"
                now
                delay={0.5}
                y={0}
                scale={0.85}
                className={cn("rounded-full px-3 py-1 uppercase tracking-wide", s.cls)}
              >
                {s.label}
              </Reveal>
              {[t.teams_count !== null ? `${t.teams_count} squadre` : null, t.dates, t.category]
                .filter((c): c is string => !!c)
                .map((c, idx) => (
                  <Reveal
                    key={c}
                    as="span"
                    now
                    delay={0.58 + idx * 0.08}
                    y={0}
                    scale={0.85}
                    className="rounded-full bg-background/60 px-3 py-1"
                  >
                    {c}
                  </Reveal>
                ))}
            </div>
            <Reveal now delay={0.8} y={6} className="mt-4 flex items-center gap-3 text-sm">
              <span className="font-display text-3xl text-primary num">
                <CountUp value={t.played} />
                <span className="text-muted-foreground"> / {t.total}</span>
              </span>
              <span className="text-muted-foreground">partite giocate</span>
              <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-background/60">
                <motion.div
                  className="h-full rounded-full bg-primary"
                  initial={{ scaleX: 0 }}
                  animate={{ scaleX: 1 }}
                  style={{
                    transformOrigin: "0% 50%",
                    width: `${t.total ? (t.played / t.total) * 100 : 0}%`,
                  }}
                  transition={{ duration: dur.slow * 1.4, ease: ease.out, delay: 0.9 }}
                />
              </div>
            </Reveal>
          </div>
        </div>
        {ours && (
          <Reveal now delay={1} className="relative mt-4">
            <Link
              to="/"
              className="press inline-flex min-h-10 items-center gap-2 rounded-lg border border-primary/40 px-3 text-sm font-semibold text-primary hover:bg-primary/10"
            >
              <Home className="h-4 w-4" /> Qui gioca AMIR COSTRUZIONI: vai all'Amir Hub
            </Link>
          </Reveal>
        )}
      </section>

      <nav
        role="tablist"
        aria-label="Sezioni del torneo"
        className="-mx-4 flex gap-1 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:px-0"
      >
        {TABS.map(({ id, label, icon: Icon }) => {
          const active = tab === id;
          return (
            <Link
              key={id}
              to="/mixed-zone/tornei/$id"
              params={{ id: String(t.id) }}
              search={id === "classifica" ? {} : { tab: id }}
              replace
              role="tab"
              aria-selected={active}
              className={cn(
                "press relative flex min-h-11 shrink-0 items-center gap-2 rounded-lg px-3.5 text-sm font-semibold transition-colors",
                active
                  ? "text-primary-foreground"
                  : "bg-secondary text-muted-foreground hover:text-foreground",
              )}
            >
              {active && <ActivePill id={pillId} className="rounded-lg bg-primary" />}
              <Icon className="relative h-4 w-4" />
              <span className="relative">{label}</span>
            </Link>
          );
        })}
      </nav>

      <motion.div
        key={tab}
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: dur.base, ease: ease.out }}
      >
        {tab === "classifica" && <StandingsTab page={page} />}
        {tab === "calendario" && <CalendarTab page={page} />}
        {tab === "statistiche" && <StatsTab id={t.id} />}
        {tab === "squadre" && <TeamsTab page={page} />}
        {tab === "documenti" && <DocumentsTab page={page} />}
      </motion.div>
    </>
  );
}

function StandingsTab({ page }: { page: ZoneTournamentPage }) {
  const groups = page.standings.filter((g) => g.rows.length > 0);
  return (
    <div className="space-y-6">
      {groups.length === 0 ? (
        <EmptyState>XFive non ha ancora pubblicato la classifica di questo torneo.</EmptyState>
      ) : (
        groups.map((g, idx) => (
          <StandingsTable key={g.group ?? idx} table={g} showGroup={groups.length > 1} />
        ))
      )}
      <Reveal as="p" delay={0.3} y={6} className="text-xs text-muted-foreground">
        G giocate · V vinte · N pareggiate · P perse · F gol fatti · S gol subiti · DR differenza
        reti · FP fair play · Pt punti · Forma: ultime partite (V N P)
      </Reveal>
      {(page.latest.length > 0 || page.upcoming.length > 0) && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          {page.latest.length > 0 && (
            <section>
              <h2 className="mb-2 text-xl">Ultimi risultati</h2>
              <div className="grid grid-cols-1 gap-2">
                {page.latest.map((m, i) => (
                  <MatchRow key={m.id} m={m} i={i} showTournament={false} />
                ))}
              </div>
            </section>
          )}
          {page.upcoming.length > 0 && (
            <section>
              <h2 className="mb-2 text-xl">Prossime partite</h2>
              <div className="grid grid-cols-1 gap-2">
                {page.upcoming.map((m, i) => (
                  <MatchRow key={m.id} m={m} i={i} showTournament={false} />
                ))}
              </div>
            </section>
          )}
        </div>
      )}
    </div>
  );
}

function CalendarTab({ page }: { page: ZoneTournamentPage }) {
  const q = useZoneTournamentMatches(page.tournament.id);
  const [club, setClub] = useState("");
  const teams = useMemo(
    () =>
      [...page.teams]
        .filter((t) => t.club.id !== null)
        .sort((a, b) => a.name.localeCompare(b.name, "it")),
    [page.teams],
  );
  const rounds: ZoneRound[] = useMemo(() => {
    const id = club ? Number(club) : null;
    return (q.data ?? [])
      .map((r) => ({
        ...r,
        matches: id ? r.matches.filter((m) => m.home.id === id || m.away.id === id) : r.matches,
      }))
      .filter((r) => r.matches.length > 0);
  }, [q.data, club]);

  if (q.isPending)
    return (
      <div className="space-y-3">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
    );
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  return (
    <div className="space-y-6">
      {teams.length > 0 && (
        <div className="max-w-xs">
          <Select value={club} onChange={setClub} label="Squadra">
            <option value="">Tutte le squadre</option>
            {teams.map((t) => (
              <option key={t.id} value={String(t.club.id)}>
                {t.name}
              </option>
            ))}
          </Select>
        </div>
      )}
      {rounds.length === 0 ? (
        <EmptyState>Nessuna partita in calendario.</EmptyState>
      ) : (
        rounds.map((r) => (
          <section key={`${r.round ?? "x"}-${r.label}`}>
            <Reveal x={-12} y={0}>
              <h3 className="mb-2 flex items-center gap-2 text-xl">
                <motion.span
                  className="h-5 w-1.5 origin-bottom rounded bg-primary"
                  initial={{ scaleY: 0 }}
                  whileInView={{ scaleY: 1 }}
                  viewport={{ once: true, amount: 1 }}
                  transition={{ duration: dur.base, ease: ease.out, delay: 0.1 }}
                />
                {r.label}
              </h3>
            </Reveal>
            <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
              {r.matches.map((m, idx) => (
                <MatchRow
                  key={m.id}
                  m={m}
                  i={idx}
                  showTournament={false}
                  highlightClub={club ? Number(club) : OWN_CLUB_ID}
                />
              ))}
            </div>
          </section>
        ))
      )}
    </div>
  );
}

const TABLE_META: Record<
  ZoneTournamentStats["tables"][number]["type"],
  { title: string; kicker: string; unit?: string }
> = {
  score: { title: "Marcatori", kicker: "La classifica XFive" },
  "top-player": { title: "Miglior giocatore", kicker: "La classifica XFive", unit: "punti" },
  discipline: { title: "Disciplina", kicker: "La classifica XFive", unit: "gialli" },
};

function StatsTab({ id }: { id: number }) {
  const q = useZoneTournamentStats(id);
  if (q.isPending)
    return (
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <Skeleton key={i} className="h-80" />
        ))}
      </div>
    );
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const { tables, from_reports: fr } = q.data;
  const anyRows = tables.some((t) => t.rows.length > 0);
  return (
    <div className="space-y-6">
      {!anyRows ? (
        <EmptyState>XFive non ha ancora pubblicato le statistiche di questo torneo.</EmptyState>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          {tables.map((t) => {
            const meta = TABLE_META[t.type];
            return (
              <Panel key={t.type} title={meta.title} kicker={meta.kicker}>
                <StatRows
                  rows={t.rows}
                  columns={t.columns}
                  limit={10}
                  {...(meta.unit ? { unit: meta.unit } : {})}
                />
              </Panel>
            );
          })}
        </div>
      )}
      {fr && (fr.scorers.length > 0 || fr.mvp.length > 0 || fr.cards.length > 0) && (
        <section className="space-y-3">
          <Reveal x={-12} y={0}>
            <h2 className="flex items-center gap-2 text-xl">
              <span className="h-5 w-1.5 rounded bg-primary" />
              Ricalcolate dai referti
              <span className="text-sm font-normal normal-case tracking-normal text-muted-foreground">
                con il link al profilo di ogni giocatore
              </span>
            </h2>
          </Reveal>
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Panel title="Marcatori" kicker="Dai referti">
              <StatRows rows={fr.scorers} columns={["Gol"]} limit={10} />
            </Panel>
            <Panel title="Miglior giocatore" kicker="Dai referti">
              <StatRows rows={fr.mvp} columns={["Stelle"]} unit="★" limit={10} />
            </Panel>
            <Panel title="Disciplina" kicker="Dai referti">
              <StatRows
                rows={fr.cards}
                columns={["Ammonizioni", "Espulsioni"]}
                unit="gialli"
                limit={10}
              />
            </Panel>
          </div>
        </section>
      )}
    </div>
  );
}

function TeamsTab({ page }: { page: ZoneTournamentPage }) {
  const teams = [...page.teams].sort((a, b) => {
    const oa = a.club.id === OWN_CLUB_ID ? 0 : 1;
    const ob = b.club.id === OWN_CLUB_ID ? 0 : 1;
    return oa - ob || a.name.localeCompare(b.name, "it");
  });
  if (teams.length === 0)
    return <EmptyState>XFive non ha ancora pubblicato le squadre iscritte.</EmptyState>;
  return (
    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {teams.map((t, i) => {
        const ours = t.club.id === OWN_CLUB_ID;
        const staff = Object.entries(t.staff);
        const body = (
          <>
            <ClubCrest club={{ ...t.club, badge_url: t.badge_url ?? t.club.badge_url }} size={56} />
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className="line-clamp-2 font-display text-xl leading-tight">{t.name}</span>
                {ours && (
                  <span className="shrink-0 rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary-foreground">
                    La nostra
                  </span>
                )}
              </div>
              {staff.length > 0 ? (
                <div className="mt-1 space-y-0.5 text-xs text-muted-foreground">
                  {staff.map(([role, name]) => (
                    <div key={role} className="truncate">
                      <span className="font-semibold text-foreground/80">{role}:</span> {name}
                    </div>
                  ))}
                </div>
              ) : (
                <div className="mt-1 text-xs text-muted-foreground">Rosa e staff nella scheda</div>
              )}
            </div>
          </>
        );
        const cls = cn(
          "flex h-full items-center gap-3 rounded-xl border bg-card p-3",
          ours ? "border-primary bg-highlight" : "hover:border-primary/40",
        );
        return (
          <Reveal as="li" key={t.id} i={i % 3} y={14} scale={0.96}>
            {t.club.id !== null ? (
              <Link
                to="/mixed-zone/squadre/$id"
                params={{ id: String(t.club.id) }}
                className={cn(cls, "lift")}
              >
                {body}
              </Link>
            ) : (
              <div className={cls}>{body}</div>
            )}
          </Reveal>
        );
      })}
    </ul>
  );
}

/** Il titolo di un documento: XFive scrive spesso solo «Scarica», allora si legge il nome del file. */
function docTitle(url: string, title: string | null): string {
  if (title && title.trim().toLowerCase() !== "scarica") return title;
  const file = url.split("/").pop() ?? "";
  const name = file.replace(/^\d+-[A-Za-z0-9]+-/, "").replace(/\.[a-z0-9]+$/i, "");
  return name ? name.replace(/[-_]+/g, " ") : "Documento";
}

function DocumentsTab({ page }: { page: ZoneTournamentPage }) {
  if (page.documents.length === 0)
    return <EmptyState>Nessun documento pubblicato per questo torneo.</EmptyState>;
  return (
    <ul className="grid grid-cols-1 gap-2 md:grid-cols-2">
      {page.documents.map((d, i) => {
        const ext = (d.url.split(".").pop() ?? "").toUpperCase().slice(0, 4);
        return (
          <Reveal as="li" key={d.url} i={i} y={10}>
            <a
              href={d.url}
              target="_blank"
              rel="noopener noreferrer"
              className="lift flex items-center gap-3 rounded-xl border bg-card p-3 hover:border-primary/40"
            >
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-primary/15 text-primary">
                <Download className="h-5 w-5" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold capitalize">
                  {docTitle(d.url, d.title)}
                </span>
                <span className="block text-xs text-muted-foreground">
                  {ext ? `${ext} · ` : ""}si apre su XFive
                </span>
              </span>
              <ExternalLink className="h-4 w-4 shrink-0 text-muted-foreground" />
            </a>
          </Reveal>
        );
      })}
    </ul>
  );
}
