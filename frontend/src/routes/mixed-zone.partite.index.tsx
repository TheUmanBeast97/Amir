import { createFileRoute } from "@tanstack/react-router";
import { CalendarDays, ChevronLeft, ChevronRight } from "lucide-react";
import { motion } from "motion/react";
import { useZoneMatches } from "@/api/zone";
import { Reveal } from "@/components/motion";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { MatchRow } from "@/components/zone/MatchRow";
import { dur, ease } from "@/lib/motion";
import { addDays, dayLabel, todayRome } from "@/lib/zone";

const isDay = (v: unknown): v is string => typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v);

/** `?date=AAAA-MM-GG` è il primo dei sette giorni mostrati (di serie oggi); `?torneo=` filtra un torneo. */
export const Route = createFileRoute("/mixed-zone/partite/")({
  validateSearch: (s: Record<string, unknown>): { date?: string; torneo?: number } => {
    const n = Number(s["torneo"]);
    return {
      ...(isDay(s["date"]) ? { date: s["date"] } : {}),
      ...(Number.isInteger(n) && n > 0 ? { torneo: n } : {}),
    };
  },
  head: () => ({
    meta: [
      { title: "Partite - Mixed Zone" },
      {
        name: "description",
        content:
          "Tutte le partite di XFive Alessandria giorno per giorno, con risultati e referti.",
      },
      { property: "og:title", content: "Partite - Mixed Zone" },
      { property: "og:description", content: "Le partite di XFive Alessandria giorno per giorno." },
    ],
  }),
  component: MatchesPage,
});

const shortDay = (iso: string) =>
  new Intl.DateTimeFormat("it-IT", { timeZone: "UTC", day: "numeric", month: "short" }).format(
    new Date(`${iso}T12:00:00Z`),
  );

function MatchesPage() {
  const { date, torneo } = Route.useSearch();
  const navigate = Route.useNavigate();
  const start = date ?? todayRome();
  const end = addDays(start, 6);
  const q = useZoneMatches({ date: start, ...(torneo ? { tournament: torneo } : {}) });
  const today = todayRome();

  const go = (d: string) =>
    void navigate({
      search: { ...(d !== today ? { date: d } : {}), ...(torneo ? { torneo } : {}) },
      replace: true,
    });

  const days = q.data ?? [];
  const total = days.reduce((n, d) => n + d.matches.length, 0);

  return (
    <div className="space-y-5">
      <PageTitle kicker="Mixed Zone" title="Partite">
        {q.data && (
          <span className="text-sm text-muted-foreground num">
            {total} {total === 1 ? "partita" : "partite"} in 7 giorni
          </span>
        )}
      </PageTitle>

      <Reveal now delay={0.1} y={8} className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          onClick={() => go(addDays(start, -7))}
          aria-label="Sette giorni prima"
          className="press grid h-11 w-11 place-items-center rounded-lg border bg-card hover:bg-accent"
        >
          <ChevronLeft className="h-5 w-5" />
        </button>
        <label className="relative flex min-h-11 flex-1 items-center gap-2 rounded-lg border bg-card px-3 text-sm font-semibold sm:flex-none">
          <CalendarDays className="h-4 w-4 text-primary" />
          <span className="num">
            {shortDay(start)} → {shortDay(end)}
          </span>
          <input
            type="date"
            value={start}
            onChange={(e) => isDay(e.target.value) && go(e.target.value)}
            aria-label="Scegli il primo giorno"
            className="absolute inset-0 cursor-pointer opacity-0"
          />
        </label>
        <button
          type="button"
          onClick={() => go(addDays(start, 7))}
          aria-label="Sette giorni dopo"
          className="press grid h-11 w-11 place-items-center rounded-lg border bg-card hover:bg-accent"
        >
          <ChevronRight className="h-5 w-5" />
        </button>
        {start !== today && (
          <button
            type="button"
            onClick={() => go(today)}
            className="press min-h-11 rounded-lg border border-primary/40 px-3 text-sm font-semibold text-primary hover:bg-primary/10"
          >
            Oggi
          </button>
        )}
      </Reveal>

      {q.isPending ? (
        <div className="space-y-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : days.length === 0 ? (
        <EmptyState>
          Nessuna partita in questi giorni. Prova la settimana prima o quella dopo.
        </EmptyState>
      ) : (
        <motion.div
          key={start}
          className="space-y-6"
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: dur.base, ease: ease.out }}
        >
          {days.map((d) => (
            <section key={d.date}>
              <Reveal x={-12} y={0}>
                <h2 className="mb-2 flex items-center gap-2 text-xl capitalize">
                  <span className="h-5 w-1.5 rounded bg-primary" />
                  {dayLabel(d.date)}
                  {d.date === today && (
                    <span className="rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary-foreground">
                      Oggi
                    </span>
                  )}
                  <span className="text-sm font-normal normal-case tracking-normal text-muted-foreground num">
                    {d.matches.length}
                  </span>
                </h2>
              </Reveal>
              <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                {d.matches.map((m, i) => (
                  <MatchRow key={m.id} m={m} i={i} />
                ))}
              </div>
            </section>
          ))}
        </motion.div>
      )}
    </div>
  );
}
