import { createFileRoute, Link } from "@tanstack/react-router";
import { motion } from "motion/react";
import { ArrowLeft, Clock, MapPin, Star, UserRound } from "lucide-react";
import { usePublicMatch } from "@/api/hooks";
import type { KitColor, PublicMatch, PublicPlayer } from "@/api/types";
import { Crest, ErrorState, InfoBanner, Skeleton, StatusChip } from "@/components/ui-kit";
import { HeadToHead } from "@/components/HeadToHead";
import { CountUp, Reveal } from "@/components/motion";
import { Pitch, PlayerPhoto, ShirtBadge } from "@/components/player-ui";
import { Panel } from "@/components/stats-ui";
import { fmtDateTime, opponent } from "@/lib/format";
import { KIT_LABEL, shirtFor, shortName } from "@/lib/kit";
import { spring } from "@/lib/motion";

export const Route = createFileRoute("/partite/$matchId")({
  head: () => ({
    meta: [
      { title: "Partita - AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Convocati, formazione, referto e precedenti della partita di AMIR COSTRUZIONI.",
      },
      { property: "og:title", content: "Partita - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Convocati, formazione e referto." },
    ],
  }),
  component: MatchPage,
});

function MatchPage() {
  const { matchId } = Route.useParams();
  const q = usePublicMatch(Number(matchId));
  return (
    <div className="space-y-5">
      <Link
        to="/calendario"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        Calendario
      </Link>
      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-56" />
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

function MatchView({ data }: { data: PublicMatch }) {
  const { match: m, callups, lineup, report } = data;
  const played = m.status === "played" && m.home_score !== null;
  const kit = m.our_kit;
  const opp = opponent(m);

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
          className="absolute inset-0 -z-10 bg-gradient-to-b from-black/20 via-black/30 to-black/70"
        />
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs font-bold uppercase tracking-[0.2em]">
          <span className="rounded-full bg-primary px-3 py-1">
            {m.is_friendly ? "Amichevole" : m.round_label}
          </span>
          <span className="text-white/75">
            {m.competition.name}
            {!m.is_friendly ? ` · ${m.round_label}` : ""}
          </span>
        </div>
        <div className="my-6 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
          {[m.home_team, m.away_team].map((t, i) => (
            <motion.div
              key={t.id}
              className={`flex flex-col items-center gap-2 text-center ${i === 1 ? "order-3" : ""}`}
              initial={{ opacity: 0, x: i === 0 ? -48 : 48 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ ...spring.soft, delay: 0.1 }}
            >
              <span className="md:hidden">
                <Crest team={t} size={72} />
              </span>
              <span className="hidden md:block">
                <Crest team={t} size={104} />
              </span>
              <span className="font-display text-lg leading-tight drop-shadow md:text-2xl">
                {t.name}
              </span>
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
          <span className="inline-flex items-center gap-1">
            <Clock className="h-4 w-4" /> {fmtDateTime(m.kickoff_at)}
          </span>
          {m.venue && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="h-4 w-4" /> {m.venue}
            </span>
          )}
          {kit && (
            <span className="inline-flex items-center gap-1.5">
              <ShirtBadge number="" kit={kit} size={14} /> Divisa {KIT_LABEL[kit].toLowerCase()}
            </span>
          )}
          <StatusChip status={m.status} />
        </Reveal>
      </section>

      {!played && !callups && !lineup && (
        <InfoBanner>
          Convocati e formazione saranno pubblicati prima della partita: torna a trovarci!
        </InfoBanner>
      )}

      {callups && <Callups callups={callups} kit={kit} />}
      {lineup && <LineupView lineup={lineup} kit={kit} />}
      {report && <Report data={data} />}
      <HeadToHead teamId={opp.id} />
    </>
  );
}

function Callups({
  callups,
  kit,
}: {
  callups: NonNullable<PublicMatch["callups"]>;
  kit: KitColor | null;
}) {
  return (
    <Panel title="Convocati" kicker={`${callups.length} giocatori`}>
      <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
        {callups.map(({ player: p, note }, idx) => (
          <Reveal as="li" key={p.id} i={idx} scale={0.93} y={12}>
            <Link
              to="/rosa/$playerId"
              params={{ playerId: String(p.id) }}
              className="lift flex items-center gap-3 rounded-xl bg-background/40 p-2 hover:bg-accent"
            >
              <span className="relative">
                <PlayerPhoto player={p} size={52} />
                <ShirtBadge
                  number={shirtFor(p, kit)}
                  kit={kit}
                  size={22}
                  className="absolute -bottom-1 -right-1"
                />
              </span>
              <span className="min-w-0">
                <span className="block truncate text-sm font-semibold">{shortName(p)}</span>
                <span className="block truncate text-[11px] text-muted-foreground">
                  {note ?? p.full_name}
                </span>
              </span>
            </Link>
          </Reveal>
        ))}
      </ul>
    </Panel>
  );
}

function LineupView({
  lineup,
  kit,
}: {
  lineup: NonNullable<PublicMatch["lineup"]>;
  kit: KitColor | null;
}) {
  const get = (id: number): PublicPlayer | undefined => lineup.players[String(id)];
  const bench = lineup.bench.map(get).filter((p): p is PublicPlayer => !!p);
  return (
    <Panel title="Formazione" kicker={`Modulo ${lineup.formation}`}>
      <Pitch
        animate
        slots={lineup.slots}
        getPlayer={get}
        kit={kit}
        corner={
          <span className="rounded-full bg-black/55 px-3 py-1 font-display text-xl text-white backdrop-blur">
            {lineup.formation}
          </span>
        }
      />
      {bench.length > 0 && (
        <div className="mt-4">
          <div className="mb-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">
            Panchina
          </div>
          <ul className="flex flex-wrap gap-2">
            {bench.map((p, idx) => (
              <Reveal
                as="li"
                key={p.id}
                i={idx}
                scale={0.8}
                y={0}
                className="flex items-center gap-2 rounded-full bg-secondary py-1 pl-1 pr-3 text-sm font-semibold"
              >
                <PlayerPhoto player={p} size={28} />
                {shortName(p)}
                <span className="text-xs text-muted-foreground num">{shirtFor(p, kit)}</span>
              </Reveal>
            ))}
          </ul>
        </div>
      )}
      {lineup.notes && <p className="mt-3 rounded-lg bg-muted p-3 text-sm">{lineup.notes}</p>}
    </Panel>
  );
}

function Report({ data }: { data: PublicMatch }) {
  const r = data.report;
  if (!r) return null;
  const m = data.match;
  const kit = m.our_kit;
  const cards = r.players.filter((x) => x.yellow > 0 || x.red > 0);
  const hasDetail = r.players.length > 0 || r.scorers.length > 0 || r.referee || r.man_of_the_match;
  return (
    <Panel title="Referto" kicker="Com'è andata">
      {!hasDetail ? (
        <p className="text-sm text-muted-foreground">
          XFive non ha pubblicato la distinta di questa partita: restano solo il risultato e i
          precedenti.
        </p>
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted-foreground">
            {r.referee && (
              <span className="inline-flex items-center gap-1">
                <UserRound className="h-4 w-4" /> Arbitro:{" "}
                <strong className="text-foreground">{r.referee}</strong>
              </span>
            )}
            {r.venue && (
              <span className="inline-flex items-center gap-1">
                <MapPin className="h-4 w-4" /> {r.venue}
              </span>
            )}
          </div>

          {r.man_of_the_match && (
            <Reveal scale={0.96}>
              <Link
                to="/rosa/$playerId"
                params={{ playerId: String(r.man_of_the_match.id) }}
                className="press flex items-center gap-4 rounded-xl border border-warning/40 bg-warning/10 p-3 hover:bg-warning/20"
              >
                <PlayerPhoto
                  player={r.man_of_the_match}
                  size={64}
                  className="border-2 border-warning"
                />
                <div>
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
                  <div className="font-display text-2xl leading-tight">
                    {r.man_of_the_match.full_name}
                  </div>
                </div>
              </Link>
            </Reveal>
          )}

          <div className="grid gap-4 md:grid-cols-2">
            {r.scorers.length > 0 && (
              <div>
                <h3 className="mb-2 text-lg">I nostri marcatori</h3>
                <ul className="space-y-1.5">
                  {r.scorers.map((s, idx) => (
                    <Reveal
                      as="li"
                      key={s.player.id}
                      i={idx}
                      x={-14}
                      y={0}
                      className="flex items-center gap-2"
                    >
                      <PlayerPhoto player={s.player} size={32} />
                      <Link
                        to="/rosa/$playerId"
                        params={{ playerId: String(s.player.id) }}
                        className="flex-1 truncate text-sm font-semibold hover:underline"
                      >
                        {s.player.full_name}
                      </Link>
                      <span className="font-bold num">
                        {[...Array(Math.min(s.goals, 6))].map((_, b) => (
                          <motion.span
                            key={b}
                            className="inline-block"
                            initial={{ scale: 0, y: -10 }}
                            whileInView={{ scale: 1, y: 0 }}
                            viewport={{ once: true }}
                            transition={{ ...spring.pop, delay: 0.3 + b * 0.09 }}
                          >
                            ⚽
                          </motion.span>
                        ))}
                        {s.goals > 6 ? ` ×${s.goals}` : ""}
                      </span>
                    </Reveal>
                  ))}
                </ul>
              </div>
            )}
            {(cards.length > 0 || r.opponent.cards.length > 0 || r.opponent.scorers.length > 0) && (
              <div className="space-y-3">
                {cards.length > 0 && (
                  <div>
                    <h3 className="mb-1 text-lg">Cartellini AMIR</h3>
                    <ul className="text-sm">
                      {cards.map((c) => (
                        <li key={c.player.id}>
                          {c.yellow > 0 && "🟨".repeat(c.yellow)}
                          {c.red > 0 && "🟥".repeat(c.red)} {c.player.full_name}
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
                {r.opponent.scorers.length > 0 && (
                  <div>
                    <h3 className="mb-1 text-lg">Marcatori di {opponent(m).name}</h3>
                    <p className="text-sm text-muted-foreground">
                      {r.opponent.scorers
                        .map((s) => `${s.name}${s.goals > 1 ? ` (${s.goals})` : ""}`)
                        .join(", ")}
                    </p>
                  </div>
                )}
                {r.opponent.cards.length > 0 && (
                  <div>
                    <h3 className="mb-1 text-lg">Cartellini {opponent(m).name}</h3>
                    <p className="text-sm text-muted-foreground">
                      {r.opponent.cards
                        .map((c) => `${c.red ? "🟥" : ""}${c.yellow ? "🟨" : ""} ${c.name}`)
                        .join(" · ")}
                    </p>
                  </div>
                )}
              </div>
            )}
          </div>

          {r.players.length > 0 && (
            <div>
              <h3 className="mb-2 text-lg">In distinta ({r.players.length})</h3>
              <ul className="flex flex-wrap gap-2">
                {r.players.map((x, idx) => (
                  <Reveal as="li" key={x.player.id} i={idx} scale={0.85} y={0}>
                    <Link
                      to="/rosa/$playerId"
                      params={{ playerId: String(x.player.id) }}
                      className="press flex items-center gap-2 rounded-full bg-secondary py-1 pl-1 pr-3 text-sm font-semibold hover:bg-accent"
                    >
                      <PlayerPhoto player={x.player} size={28} />
                      {shortName(x.player)}
                      <span className="text-xs text-muted-foreground num">
                        {shirtFor(x.player, kit)}
                      </span>
                    </Link>
                  </Reveal>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Panel>
  );
}
