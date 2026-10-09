import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, Flame, Home, Shield, Swords, Target, Trophy, Users } from "lucide-react";
import { motion } from "motion/react";
import { useMemo, useState } from "react";
import { useZoneClub, useZoneClubs, useZoneHeadToHead } from "@/api/zone";
import type { ZoneClubPage, ZoneTeamPlayer } from "@/api/zone-types";
import { CountUp, Grow, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Kpi, Panel } from "@/components/stats-ui";
import { EmptyState, ErrorState, Select, Skeleton } from "@/components/ui-kit";
import { ClubCrest } from "@/components/zone/ClubCrest";
import { MatchRow } from "@/components/zone/MatchRow";
import { OWN_CLUB_ID } from "@/lib/area";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { sportLabel } from "@/lib/zone";

/** `?torneo=` sceglie di quale torneo mostrare la rosa (di serie l'ultimo). */
export const Route = createFileRoute("/mixed-zone/squadre/$id")({
  validateSearch: (s: Record<string, unknown>): { torneo?: number } => {
    const n = Number(s["torneo"]);
    return Number.isInteger(n) && n > 0 ? { torneo: n } : {};
  },
  head: () => ({
    meta: [
      { title: "Squadra - Mixed Zone" },
      {
        name: "description",
        content:
          "Stagioni, tornei, rosa, record e scontri diretti di una squadra di XFive Alessandria.",
      },
      { property: "og:title", content: "Squadra - Mixed Zone" },
      { property: "og:description", content: "Stagioni, rosa, record e scontri diretti." },
    ],
  }),
  component: ClubPage,
});

function ClubPage() {
  const { id } = Route.useParams();
  const { torneo } = Route.useSearch();
  const q = useZoneClub(Number(id), torneo);
  return (
    <div className="space-y-5">
      <Link
        to="/mixed-zone/squadre"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        Squadre
      </Link>
      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-56" />
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
          <Skeleton className="h-72" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <ClubView page={q.data} />
      )}
    </div>
  );
}

const medal = (p: number | null) => (p === 1 ? "🥇" : p === 2 ? "🥈" : p === 3 ? "🥉" : null);

function ClubView({ page }: { page: ZoneClubPage }) {
  const { club, record: r } = page;
  const ours = club.id === OWN_CLUB_ID;
  const tournaments = page.seasons.reduce((n, s) => n + s.tournaments.length, 0);
  const winPct = r.played ? Math.round((r.won / r.played) * 100) : 0;
  const titles = page.seasons.flatMap((s) => s.tournaments).filter((t) => t.position === 1).length;

  return (
    <>
      <section className="card-cut relative overflow-hidden rounded-xl bg-hero p-5 md:p-8">
        <div className="relative flex flex-col items-center gap-5 md:flex-row md:items-end">
          <motion.div
            initial={{ opacity: 0, scale: 0.7, rotate: -6 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            transition={{ ...spring.soft, delay: 0.05 }}
          >
            <ClubCrest club={club} size={128} className="shadow-2xl" />
          </motion.div>
          <div className="min-w-0 text-center md:text-left">
            <Reveal now delay={0.2} y={8}>
              <div className="text-xs font-bold uppercase tracking-[0.2em] text-primary">
                {ours ? "La nostra squadra" : "Squadra"}
              </div>
            </Reveal>
            <h1 className="text-4xl leading-none md:text-6xl">
              <span className="block overflow-hidden pb-1">
                <motion.span
                  className="block"
                  initial={{ y: "105%" }}
                  animate={{ y: "0%" }}
                  transition={{ duration: dur.hero, ease: ease.out, delay: 0.25 }}
                >
                  {club.name}
                </motion.span>
              </span>
            </h1>
            <div className="mt-3 flex flex-wrap justify-center gap-2 text-xs font-semibold md:justify-start">
              {[
                `${page.seasons.length} ${page.seasons.length === 1 ? "stagione" : "stagioni"}`,
                `${tournaments} ${tournaments === 1 ? "torneo" : "tornei"}`,
                titles > 0 ? `${titles} ${titles === 1 ? "primo posto" : "primi posti"}` : null,
              ]
                .filter((c): c is string => !!c)
                .map((c, idx) => (
                  <Reveal
                    key={c}
                    as="span"
                    now
                    delay={0.6 + idx * 0.08}
                    y={0}
                    scale={0.85}
                    className="rounded-full bg-background/60 px-3 py-1 num"
                  >
                    {c}
                  </Reveal>
                ))}
            </div>
          </div>
        </div>
        {ours && (
          <Reveal now delay={0.9} className="relative mt-5 flex justify-center md:justify-start">
            <Link
              to="/"
              className="press inline-flex min-h-11 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
            >
              <Home className="h-4 w-4" /> Vai all'Amir Hub: calendario, rosa e storico
            </Link>
          </Reveal>
        )}
      </section>

      {r.played === 0 ? (
        <EmptyState>Nessuna partita giocata registrata per questa squadra.</EmptyState>
      ) : (
        <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
          <Kpi
            i={0}
            label="Partite"
            value={r.played}
            sub={`${r.won}V · ${r.drawn}N · ${r.lost}P`}
            tone="neutral"
            icon={<Swords className="h-5 w-5" />}
          />
          <Kpi
            i={1}
            label="Vittorie"
            value={
              <>
                <CountUp value={winPct} />%
              </>
            }
            sub={`${r.won} su ${r.played}`}
            tone="success"
            icon={<Trophy className="h-5 w-5" />}
          />
          <Kpi
            i={2}
            label="Gol fatti"
            value={r.goals_for}
            sub={`${(r.goals_for / r.played).toFixed(2)} a partita`}
            tone="primary"
            icon={<Target className="h-5 w-5" />}
          />
          <Kpi
            i={3}
            label="Gol subiti"
            value={r.goals_against}
            sub={`${(r.goals_against / r.played).toFixed(2)} a partita`}
            tone="warning"
            icon={<Shield className="h-5 w-5" />}
          />
          <Kpi
            i={4}
            label="Vittorie di fila"
            value={r.best_streak}
            sub="la serie più lunga"
            tone="success"
            icon={<Flame className="h-5 w-5" />}
          />
          <Kpi
            i={5}
            label="Differenza reti"
            value={
              <>
                {r.goals_for - r.goals_against > 0 ? "+" : ""}
                <CountUp value={r.goals_for - r.goals_against} />
              </>
            }
            tone={r.goals_for >= r.goals_against ? "success" : "neutral"}
          />
          {r.biggest_win && (
            <Reveal
              i={6}
              scale={0.96}
              className="col-span-2 flex items-center gap-3 rounded-xl border bg-card p-3 md:p-4"
            >
              <div className="min-w-0 flex-1">
                <div className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  La vittoria più larga
                </div>
                <div className="truncate text-sm font-semibold">
                  {r.biggest_win.home.name} - {r.biggest_win.away.name}
                </div>
                <div className="truncate text-xs text-muted-foreground">
                  {r.biggest_win.tournament.name} · {r.biggest_win.tournament.season}
                </div>
              </div>
              <Link
                to="/mixed-zone/partite/$id"
                params={{ id: String(r.biggest_win.id) }}
                className="font-display text-3xl text-success num hover:underline"
              >
                <CountUp value={r.biggest_win.home_score ?? 0} duration={0.7} />–
                <CountUp value={r.biggest_win.away_score ?? 0} duration={0.7} />
              </Link>
            </Reveal>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SeasonsPanel page={page} />
        <RosterPanel page={page} />
      </div>

      <section>
        <Reveal x={-14} y={0}>
          <h2 className="mb-2 text-2xl">Ultime partite</h2>
        </Reveal>
        {page.latest.length === 0 ? (
          <EmptyState>Nessuna partita giocata.</EmptyState>
        ) : (
          <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
            {page.latest.map((m, i) => (
              <MatchRow key={m.id} m={m} i={i} highlightClub={club.id} />
            ))}
          </div>
        )}
      </section>

      {club.id !== null && <HeadToHeadPanel clubId={club.id} clubName={club.name} />}
    </>
  );
}

function SeasonsPanel({ page }: { page: ZoneClubPage }) {
  return (
    <Panel title="Stagioni e tornei" kicker="Dove ha giocato">
      <div className="space-y-4">
        {page.seasons.map((s) => (
          <div key={s.season}>
            <div className="mb-1 text-xs font-bold uppercase tracking-wider text-muted-foreground num">
              {s.season}
            </div>
            <ul className="divide-y">
              {s.tournaments.map((t, i) => (
                <Reveal as="li" key={t.tournament.id} i={i} y={6}>
                  <Link
                    to="/mixed-zone/tornei/$id"
                    params={{ id: String(t.tournament.id) }}
                    className="flex items-center gap-3 py-2 text-sm hover:text-primary"
                  >
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-semibold">{t.tournament.name}</span>
                      <span className="block text-xs text-muted-foreground">
                        {sportLabel(t.tournament.sport)}
                        {t.teams ? ` · ${t.teams} squadre` : ""}
                      </span>
                    </span>
                    {t.position !== null ? (
                      <span
                        className={cn(
                          "shrink-0 rounded-full px-2.5 py-0.5 text-xs font-bold num",
                          t.position === 1
                            ? "bg-warning/20 text-warning"
                            : "bg-secondary text-foreground",
                        )}
                      >
                        {medal(t.position) ?? ""} {t.position}°
                      </span>
                    ) : (
                      <span className="shrink-0 text-xs text-muted-foreground">
                        senza classifica
                      </span>
                    )}
                  </Link>
                </Reveal>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </Panel>
  );
}

const ROLE_ORDER = ["portiere", "difensore", "centrocampista", "attaccante"];
const roleRank = (p: ZoneTeamPlayer) => {
  const r = (p.role ?? "").toLowerCase();
  const i = ROLE_ORDER.findIndex((x) => r.startsWith(x));
  return i === -1 ? ROLE_ORDER.length : i;
};

function RosterPanel({ page }: { page: ZoneClubPage }) {
  const navigate = Route.useNavigate();
  const options = page.seasons.flatMap((s) =>
    s.tournaments
      .filter((t) => t.team_id !== null)
      .map((t) => ({ id: t.tournament.id, label: `${t.tournament.name} · ${s.season}` })),
  );
  const current = page.roster?.tournament.id ?? options[0]?.id;
  const players = useMemo(
    () =>
      [...(page.roster?.players ?? [])].sort(
        (a, b) => roleRank(a) - roleRank(b) || a.name.localeCompare(b.name, "it"),
      ),
    [page.roster],
  );
  return (
    <Panel
      title="La rosa"
      kicker={page.roster ? `${players.length} giocatori` : "Rosa del torneo"}
      action={
        options.length > 1 ? (
          <div className="w-44 sm:w-56">
            <Select
              value={String(current ?? "")}
              onChange={(v) => void navigate({ search: { torneo: Number(v) }, replace: true })}
              label="Torneo"
            >
              {options.map((o) => (
                <option key={o.id} value={String(o.id)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
        ) : undefined
      }
    >
      {!page.roster || players.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          XFive non ha pubblicato la rosa di questa squadra per il torneo scelto.
        </p>
      ) : (
        <>
          <p className="mb-2 text-xs text-muted-foreground">
            {page.roster.tournament.name} · {page.roster.tournament.season}
          </p>
          <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-2">
            {players.map((p, idx) => {
              const body = (
                <>
                  <span className="relative">
                    <PlayerPhoto player={{ full_name: p.name, photo_url: p.photo_url }} size={44} />
                    {p.number && (
                      <span className="absolute -bottom-1 -right-1 grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground num shadow">
                        {p.number}
                      </span>
                    )}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold">{p.name}</span>
                    <span className="block truncate text-[11px] text-muted-foreground">
                      {p.role ?? p.country ?? "Giocatore"}
                    </span>
                  </span>
                </>
              );
              const cls = "flex items-center gap-2.5 rounded-xl bg-background/40 p-2";
              return (
                <Reveal as="li" key={p.tpid} i={idx % 6} scale={0.93} y={10}>
                  {p.player_id !== null ? (
                    <Link
                      to="/mixed-zone/giocatori/$id"
                      params={{ id: String(p.player_id) }}
                      className={cn(cls, "lift hover:bg-accent")}
                    >
                      {body}
                    </Link>
                  ) : (
                    <div className={cls} title="Profilo XFive non abbinato">
                      {body}
                    </div>
                  )}
                </Reveal>
              );
            })}
          </ul>
        </>
      )}
    </Panel>
  );
}

function HeadToHeadPanel({ clubId, clubName }: { clubId: number; clubName: string }) {
  const clubs = useZoneClubs();
  const [other, setOther] = useState<number | undefined>(undefined);
  const q = useZoneHeadToHead(clubId, other);
  const list = useMemo(
    () =>
      (clubs.data ?? [])
        .filter((c): c is typeof c & { id: number } => c.id !== null && c.id !== clubId)
        .sort((a, b) => a.name.localeCompare(b.name, "it")),
    [clubs.data, clubId],
  );
  const otherName = list.find((c) => c.id === other)?.name ?? "";
  const h = q.data;
  const total = h?.played || 1;
  return (
    <Panel
      title="Scontri diretti"
      kicker="Contro un'altra squadra"
      action={
        <div className="w-48 sm:w-64">
          <Select
            value={other ? String(other) : ""}
            onChange={(v) => setOther(v ? Number(v) : undefined)}
            label="Avversaria"
          >
            <option value="">Scegli una squadra</option>
            {list.map((c) => (
              <option key={c.id} value={String(c.id)}>
                {c.name}
              </option>
            ))}
          </Select>
        </div>
      }
    >
      {!other ? (
        <p className="text-sm text-muted-foreground">
          Scegli un'avversaria per vedere il bilancio e tutte le partite fra le due squadre.
        </p>
      ) : q.isPending ? (
        <Skeleton className="h-40" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : !h || h.played === 0 ? (
        <EmptyState>Le due squadre non si sono mai incontrate.</EmptyState>
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-2 text-center">
            {(
              [
                [clubName, h.wins_a, "text-success"],
                ["Pareggi", h.draws, "text-warning"],
                [otherName, h.wins_b, "text-foreground"],
              ] as const
            ).map(([label, v, cls], i) => (
              <Reveal key={label} i={i} scale={0.92} className="rounded-xl bg-background/50 p-3">
                <div className={cn("font-display text-3xl num", cls)}>
                  <CountUp value={v} />
                </div>
                <div className="truncate text-[11px] uppercase tracking-wider text-muted-foreground">
                  {label}
                </div>
              </Reveal>
            ))}
          </div>
          <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-muted">
            <Grow
              axis="x"
              className="h-full bg-success"
              style={{ width: `${(h.wins_a / total) * 100}%` }}
            />
            <Grow
              axis="x"
              delay={0.1}
              className="h-full bg-warning"
              style={{ width: `${(h.draws / total) * 100}%` }}
            />
            <Grow
              axis="x"
              delay={0.2}
              className="h-full bg-foreground/40"
              style={{ width: `${(h.wins_b / total) * 100}%` }}
            />
          </div>
          <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
            {h.matches.map((m, i) => (
              <MatchRow key={m.id} m={m} i={i} highlightClub={clubId} />
            ))}
          </div>
        </div>
      )}
    </Panel>
  );
}
