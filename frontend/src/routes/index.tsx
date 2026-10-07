import type { ReactNode } from "react";
import { createFileRoute, Link } from "@tanstack/react-router";
import { CalendarPlus, MapPin } from "lucide-react";
import { motion } from "motion/react";
import { CountUp, Reveal } from "@/components/motion";
import { dist, dur, ease, spring } from "@/lib/motion";
import { useHome } from "@/api/hooks";
import { api } from "@/api/client";
import type { Match } from "@/api/types";
import { fmtDateLong, fmtTime, outcome } from "@/lib/format";
import {
  Card,
  Countdown,
  Crest,
  ErrorState,
  InfoBanner,
  OutcomeBadge,
  Skeleton,
} from "@/components/ui-kit";
import { MatchRow, StandingsTable } from "@/components/match";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "AMIR COSTRUZIONI - Prossima partita e classifica" },
      {
        name: "description",
        content:
          "Prossima partita, risultati e classifica di AMIR COSTRUZIONI nel campionato XFive CITTADELLA di Alessandria.",
      },
      { property: "og:title", content: "AMIR COSTRUZIONI - Match day" },
      {
        property: "og:description",
        content: "Prossima partita, risultati e classifica della squadra.",
      },
    ],
  }),
  component: HomePage,
});

function addCalendar() {
  window.location.href = api.calendarIcsUrl();
}

const pad2 = (n: number) => String(Math.round(n)).padStart(2, "0");

/** Riquadro con lo sfondo stadio (sfondo_match) dietro ai dati della partita: il testo è sempre chiaro. */
function HeroShell({ children }: { children: ReactNode }) {
  return (
    <motion.section
      initial={{ opacity: 0, scale: 0.985 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ duration: dur.hero, ease: ease.out }}
      className="card-cut relative isolate overflow-hidden rounded-xl bg-[#0b0b0d] text-white"
    >
      {/* lo stadio «respira» lentamente, e ogni tanto un faro lo attraversa */}
      <div
        aria-hidden
        className="animate-kenburns absolute inset-0 -z-20 bg-cover bg-center"
        style={{ backgroundImage: "url(/sfondo_match.jpg)" }}
      />
      <div
        aria-hidden
        className="absolute inset-0 -z-10 bg-gradient-to-b from-black/10 via-black/20 to-black/65"
      />
      <div
        aria-hidden
        className="animate-floodlight pointer-events-none absolute inset-y-[-10%] left-0 -z-[5] w-[18%]"
        style={{
          background:
            "linear-gradient(90deg, transparent, rgb(255 255 255 / 0.13) 50%, transparent)",
        }}
      />
      <div className="p-5 md:p-10">{children}</div>
    </motion.section>
  );
}

function Hero({ m }: { m: Match | null }) {
  if (!m)
    return (
      <HeroShell>
        <div className="py-10 text-center">
          <span className="rounded-full bg-primary px-3 py-1 text-xs font-bold uppercase tracking-[0.2em]">
            Prossima partita
          </span>
          <p className="mt-5 font-display text-4xl">Data da definire</p>
          <p className="mt-1 text-sm text-white/70">
            Il calendario ufficiale non è ancora stato pubblicato.
          </p>
        </div>
      </HeroShell>
    );
  return (
    <HeroShell>
      <motion.div
        initial={{ opacity: 0, y: -dist.sm }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: dur.slow, ease: ease.out, delay: 0.1 }}
        className="flex flex-wrap items-center justify-between gap-2 text-xs font-bold uppercase tracking-[0.2em]"
      >
        <span className="rounded-full bg-primary px-3 py-1">Prossima partita</span>
        <span className="text-white/75">
          {m.competition.name} · {m.round_label}
        </span>
      </motion.div>
      <div className="my-8 grid grid-cols-[1fr_auto_1fr] items-center gap-3 md:my-10">
        {[m.home_team, m.away_team].map((t, i) => (
          <motion.div
            key={t.id}
            // le due squadre entrano dai lati come in campo, poi si assestano
            initial={{ opacity: 0, x: i === 0 ? -72 : 72, scale: 0.82 }}
            animate={{ opacity: 1, x: 0, scale: 1 }}
            transition={{ ...spring.soft, delay: 0.2 }}
            className={`flex flex-col items-center gap-3 text-center ${i === 1 ? "order-3" : ""}`}
          >
            <span className="md:hidden">
              <Crest team={t} size={84} />
            </span>
            <span className="hidden md:block">
              <Crest team={t} size={128} />
            </span>
            <span className="block overflow-hidden">
              <motion.span
                initial={{ y: "115%" }}
                animate={{ y: "0%" }}
                transition={{ duration: dur.slow, ease: ease.out, delay: 0.6 }}
                className="block font-display text-lg leading-tight drop-shadow md:text-3xl"
              >
                {t.name}
              </motion.span>
            </span>
          </motion.div>
        ))}
        <motion.span
          initial={{ opacity: 0, scale: 0.2, rotate: -24 }}
          animate={{ opacity: 1, scale: 1, rotate: 0 }}
          transition={{ ...spring.pop, delay: 0.5 }}
          className="order-2 font-display text-4xl text-white/80 drop-shadow md:text-6xl"
        >
          VS
        </motion.span>
      </div>
      <div className="flex flex-col items-center gap-3 text-center">
        <div>
          <div className="overflow-hidden">
            <motion.div
              initial={{ y: "115%" }}
              animate={{ y: "0%" }}
              transition={{ duration: dur.slow, ease: ease.out, delay: 0.72 }}
              className="font-display text-2xl capitalize drop-shadow md:text-4xl"
            >
              {m.kickoff_at ? fmtDateLong(m.kickoff_at) : "Data da definire"}
            </motion.div>
          </div>
          {m.kickoff_at && (
            <div className="font-display text-5xl text-primary num drop-shadow md:text-6xl">
              {/* l'ora sale fino al valore giusto, come sul tabellone */}
              <CountUp
                value={Number(fmtTime(m.kickoff_at).slice(0, 2))}
                duration={0.9}
                format={pad2}
              />
              :
              <CountUp
                value={Number(fmtTime(m.kickoff_at).slice(3, 5))}
                duration={0.9}
                format={pad2}
              />
            </div>
          )}
        </div>
        {m.venue && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: dur.slow, delay: 0.9 }}
            className="flex items-center gap-1 text-sm text-white/80"
          >
            <MapPin className="h-4 w-4" />
            {m.venue}
          </motion.div>
        )}
        {m.kickoff_at && <Countdown to={m.kickoff_at} />}
        <Link
          to="/partite/$matchId"
          params={{ matchId: String(m.id) }}
          className="group text-sm font-semibold text-white underline-offset-4 hover:underline"
        >
          Convocati, formazione e precedenti{" "}
          <span className="inline-block transition-transform duration-200 group-hover:translate-x-1">
            →
          </span>
        </Link>
      </div>
    </HeroShell>
  );
}

function HomePage() {
  const q = useHome();
  if (q.isPending)
    return (
      <div className="space-y-4">
        <Skeleton className="h-96" />
        <div className="grid gap-4 md:grid-cols-2">
          <Skeleton className="h-40" />
          <Skeleton className="h-40" />
        </div>
      </div>
    );
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const h = q.data;
  const last = h.last_match;
  const lo = last ? outcome(last) : null;
  return (
    <div className="space-y-5">
      {!h.calendar_info?.is_complete && h.calendar_info?.note && (
        <InfoBanner>{h.calendar_info?.note}</InfoBanner>
      )}
      <Hero m={h.next_match} />
      <Reveal now delay={0.9} y={dist.sm}>
        <button
          onClick={addCalendar}
          className="press group flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border border-primary/40 font-semibold text-primary hover:bg-primary/10"
        >
          <CalendarPlus className="h-5 w-5 transition-transform duration-200 group-hover:-rotate-6 group-hover:scale-110" />{" "}
          Aggiungi il calendario al telefono
        </button>
      </Reveal>
      <div className="grid gap-5 md:grid-cols-2">
        <Card>
          <h2 className="mb-3 text-xl">Ultima partita</h2>
          {last && lo ? (
            <div className="flex items-center gap-3">
              <OutcomeBadge o={lo} className="h-10 w-10 text-base" />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">
                  {last.home_team.name} – {last.away_team.name}
                </div>
                <div className="truncate text-xs text-muted-foreground">
                  {last.competition.name}
                </div>
              </div>
              <div className="font-display text-4xl num">
                <CountUp value={last.home_score ?? 0} duration={0.8} />–
                <CountUp value={last.away_score ?? 0} duration={0.8} />
              </div>
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">Nessuna partita giocata.</p>
          )}
        </Card>
        {h.standing && (
          <Card>
            <h2 className="mb-3 text-xl">La nostra posizione</h2>
            <StandingsTable
              rows={(() => {
                const i = h.standings.findIndex((r) => r.team.is_own);
                return h.standings.slice(Math.max(0, i - 2), i + 3);
              })()}
              compact
            />
            <Link to="/classifica" className="mt-3 inline-block text-sm font-semibold text-primary">
              Classifica completa →
            </Link>
          </Card>
        )}
      </div>
      <section>
        <div className="mb-3 flex items-end justify-between">
          <h2 className="text-2xl">Prossime partite</h2>
          <Link to="/calendario" className="text-sm font-semibold text-primary">
            Calendario →
          </Link>
        </div>
        {h.upcoming.length ? (
          <div className="grid gap-2 md:grid-cols-2">
            {h.upcoming.map((m, i) => (
              <MatchRow key={m.id} m={m} i={i} />
            ))}
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Nessuna partita in programma.</p>
        )}
      </section>
    </div>
  );
}
