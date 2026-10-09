import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, Clock, MapPin, Star, Trophy, UserRound } from "lucide-react";
import { motion } from "motion/react";
import { useZoneMatch } from "@/api/zone";
import type { ZoneClubRef, ZoneMatchPage, ZoneMatchPlayer } from "@/api/zone-types";
import { CountUp, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Panel } from "@/components/stats-ui";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui-kit";
import { ClubCrest } from "@/components/zone/ClubCrest";
import { OWN_CLUB_ID } from "@/lib/area";
import { spring } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { kickoffLabel } from "@/lib/zone";

export const Route = createFileRoute("/mixed-zone/partite/$id")({
  head: () => ({
    meta: [
      { title: "Partita - Mixed Zone" },
      {
        name: "description",
        content:
          "Il referto di una partita XFive: distinte, marcatori, cartellini, miglior giocatore e arbitro.",
      },
      { property: "og:title", content: "Partita - Mixed Zone" },
      { property: "og:description", content: "Referto completo della partita." },
    ],
  }),
  component: MatchRoute,
});

function MatchRoute() {
  const { id } = Route.useParams();
  const q = useZoneMatch(Number(id));
  return (
    <div className="space-y-5">
      <Link
        to="/mixed-zone/partite"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        Partite
      </Link>
      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-64" />
          <Skeleton className="h-72" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <MatchView data={q.data} />
      )}
    </div>
  );
}

function MatchView({ data }: { data: ZoneMatchPage }) {
  const { match: m, referee } = data;
  const played = m.played && m.home_score !== null && m.away_score !== null;
  const lineups = data.home.lineup.length + data.away.lineup.length;
  const mvp = [...data.home.lineup, ...data.away.lineup].find((p) => p.mvp) ?? null;
  const mvpSide = mvp ? (data.home.lineup.includes(mvp) ? m.home : m.away) : null;

  return (
    <>
      <section className="card-cut relative isolate overflow-hidden rounded-xl bg-[#0b0b0d] p-5 text-white md:p-8">
        <div
          aria-hidden
          className="animate-kenburns absolute inset-0 -z-20 bg-cover bg-center"
          style={{ backgroundImage: "url(/sfondo_match.jpg)" }}
        />
        <div
          aria-hidden
          className="absolute inset-0 -z-10 bg-gradient-to-b from-black/30 via-black/40 to-black/75"
        />
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs font-bold uppercase tracking-[0.2em]">
          <Link
            to="/mixed-zone/tornei/$id"
            params={{ id: String(m.tournament.id) }}
            className="press inline-flex items-center gap-1.5 rounded-full bg-primary px-3 py-1 text-primary-foreground hover:bg-primary/90"
          >
            <Trophy className="h-3.5 w-3.5" /> {m.tournament.name}
          </Link>
          <span className="text-white/75">
            {m.round_label ?? ""}
            {m.round_label ? " · " : ""}
            <span className="num">{m.tournament.season}</span>
          </span>
        </div>
        <div className="my-6 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
          {[m.home, m.away].map((c, i) => (
            <motion.div
              key={i}
              className={cn("flex flex-col items-center gap-2 text-center", i === 1 && "order-3")}
              initial={{ opacity: 0, x: i === 0 ? -48 : 48 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ ...spring.soft, delay: 0.1 }}
            >
              <ClubLink club={c}>
                <span className="md:hidden">
                  <ClubCrest club={c} size={72} />
                </span>
                <span className="hidden md:block">
                  <ClubCrest club={c} size={104} />
                </span>
                <span className="font-display text-lg leading-tight drop-shadow md:text-2xl">
                  {c.name}
                </span>
              </ClubLink>
            </motion.div>
          ))}
          <motion.span
            className="order-2 font-display text-5xl drop-shadow md:text-7xl num"
            initial={{ opacity: 0, scale: 0.4 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ ...spring.pop, delay: 0.35 }}
          >
            {played ? (
              <>
                <CountUp value={m.home_score ?? 0} duration={0.9} />–
                <CountUp value={m.away_score ?? 0} duration={0.9} />
              </>
            ) : (
              "VS"
            )}
          </motion.span>
        </div>
        <Reveal
          now
          delay={0.55}
          className="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-sm text-white/85"
        >
          <span className="inline-flex items-center gap-1 capitalize">
            <Clock className="h-4 w-4" /> {kickoffLabel(m)}
          </span>
          {m.venue && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="h-4 w-4" /> {m.venue}
            </span>
          )}
          {referee && (
            <span className="inline-flex items-center gap-1">
              <UserRound className="h-4 w-4" /> Arbitro: <strong>{referee}</strong>
            </span>
          )}
        </Reveal>
      </section>

      {lineups === 0 ? (
        <EmptyState>
          {played
            ? "Nessun referto per questa partita: XFive ha pubblicato solo il risultato."
            : "La partita non si è ancora giocata: il referto arriverà dopo il fischio finale."}
        </EmptyState>
      ) : (
        <>
          {mvp && mvpSide && (
            <Reveal scale={0.96}>
              <PlayerLink
                p={mvp}
                className="press flex items-center gap-4 rounded-xl border border-warning/40 bg-warning/10 p-3 hover:bg-warning/20"
              >
                <PlayerPhoto
                  player={{ full_name: mvp.name, photo_url: mvp.photo_url }}
                  size={64}
                  className="border-2 border-warning"
                />
                <div className="min-w-0">
                  <div className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wider text-warning">
                    <motion.span
                      className="inline-flex"
                      initial={{ scale: 0, rotate: -90 }}
                      whileInView={{ scale: 1, rotate: 0 }}
                      viewport={{ once: true }}
                      transition={{ ...spring.pop, delay: 0.35 }}
                    >
                      <Star className="h-3.5 w-3.5 fill-warning" />
                    </motion.span>{" "}
                    Miglior giocatore
                  </div>
                  <div className="truncate font-display text-2xl leading-tight">{mvp.name}</div>
                  <div className="truncate text-xs text-muted-foreground">{mvpSide.name}</div>
                </div>
              </PlayerLink>
            </Reveal>
          )}
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Lineup club={m.home} rows={data.home.lineup} score={m.home_score} />
            <Lineup club={m.away} rows={data.away.lineup} score={m.away_score} />
          </div>
          <Reveal as="p" delay={0.3} y={6} className="text-xs text-muted-foreground">
            ⚽ gol · 🟨 ammonizione · 🟥 espulsione · ★ miglior giocatore. I nomi con il link hanno
            un profilo XFive abbinato.
          </Reveal>
        </>
      )}
    </>
  );
}

/** Il nome di un club che apre la sua scheda (se il club ha un id). */
function ClubLink({ club, children }: { club: ZoneClubRef; children: React.ReactNode }) {
  const cls = "flex flex-col items-center gap-2";
  return club.id !== null ? (
    <Link
      to="/mixed-zone/squadre/$id"
      params={{ id: String(club.id) }}
      className={cn(cls, "press hover:underline")}
    >
      {children}
    </Link>
  ) : (
    <span className={cls}>{children}</span>
  );
}

function PlayerLink({
  p,
  className,
  children,
}: {
  p: ZoneMatchPlayer;
  className: string;
  children: React.ReactNode;
}) {
  return p.player_id !== null ? (
    <Link to="/mixed-zone/giocatori/$id" params={{ id: String(p.player_id) }} className={className}>
      {children}
    </Link>
  ) : (
    <div className={className} title="Profilo XFive non abbinato">
      {children}
    </div>
  );
}

/** La distinta di una squadra: chi ha segnato per primo, poi gli altri, con gol, cartellini e stella. */
function Lineup({
  club,
  rows,
  score,
}: {
  club: ZoneClubRef;
  rows: ZoneMatchPlayer[];
  score: number | null;
}) {
  const sorted = [...rows].sort(
    (a, b) =>
      Number(b.mvp) - Number(a.mvp) || b.goals - a.goals || a.name.localeCompare(b.name, "it"),
  );
  const goals = rows.reduce((n, p) => n + p.goals, 0);
  const ours = club.id === OWN_CLUB_ID;
  return (
    <Panel
      title={club.name}
      kicker={`${rows.length} in distinta${score !== null ? ` · ${score} gol` : ""}`}
      className={cn(ours && "border-primary/50")}
      action={<ClubCrest club={club} size={40} />}
    >
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">Distinta non pubblicata.</p>
      ) : (
        <ul className="divide-y">
          {sorted.map((p, idx) => (
            <Reveal as="li" key={p.tpid} i={idx} x={-10} y={0}>
              <PlayerLink
                p={p}
                className={cn(
                  "flex items-center gap-3 py-2",
                  p.player_id !== null && "press rounded-lg hover:bg-accent/60",
                )}
              >
                <PlayerPhoto player={{ full_name: p.name, photo_url: p.photo_url }} size={36} />
                <span className="min-w-0 flex-1">
                  <span
                    className={cn(
                      "block truncate text-sm",
                      p.goals > 0 || p.mvp ? "font-semibold" : "",
                    )}
                  >
                    {p.name}
                  </span>
                </span>
                <span className="flex items-center gap-1.5 text-sm">
                  {p.goals > 0 && (
                    <span className="font-bold text-primary num">
                      {[...Array(Math.min(p.goals, 4))].map((_, b) => (
                        <motion.span
                          key={b}
                          className="inline-block"
                          initial={{ scale: 0, y: -10 }}
                          whileInView={{ scale: 1, y: 0 }}
                          viewport={{ once: true }}
                          transition={{ ...spring.pop, delay: 0.2 + b * 0.09 }}
                        >
                          ⚽
                        </motion.span>
                      ))}
                      {p.goals > 4 ? ` ×${p.goals}` : ""}
                    </span>
                  )}
                  {p.yellow > 0 && (
                    <span title="Ammonizione">{"🟨".repeat(Math.min(p.yellow, 2))}</span>
                  )}
                  {p.red > 0 && <span title="Espulsione">🟥</span>}
                  {p.mvp && (
                    <Star
                      className="h-4 w-4 fill-warning text-warning"
                      aria-label="Miglior giocatore"
                    />
                  )}
                </span>
              </PlayerLink>
            </Reveal>
          ))}
        </ul>
      )}
      {score !== null && goals !== score && rows.length > 0 && (
        <p className="mt-2 text-[11px] text-muted-foreground">
          I gol in distinta ({goals}) non tornano con il risultato ({score}): autogol o referto
          incompleto su XFive.
        </p>
      )}
    </Panel>
  );
}
