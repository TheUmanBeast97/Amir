import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { motion } from "motion/react";
import { CalendarDays, List } from "lucide-react";
import { useHome, useMatches } from "@/api/hooks";
import type { Match } from "@/api/types";
import { EmptyState, ErrorState, InfoBanner, PageTitle, Skeleton } from "@/components/ui-kit";
import { MatchRow } from "@/components/match";
import { ActivePill, Reveal } from "@/components/motion";
import { MonthCalendar } from "@/components/month-calendar";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/calendario")({
  head: () => ({
    meta: [
      { title: "Calendario - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Tutte le partite di AMIR COSTRUZIONI giornata per giornata, in lista o su calendario, con stato e orari.",
      },
      { property: "og:title", content: "Calendario - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Tutte le partite giornata per giornata." },
    ],
  }),
  component: CalendarPage,
});

/** Campionato per primo, poi coppe e tornei, in fondo le amichevoli. */
const kindOrder = (m: Match) =>
  m.competition.kind === "campionato" ? 0 : m.competition.kind === "amichevole" ? 2 : 1;

function CalendarPage() {
  const home = useHome();
  const [all, setAll] = useState(false);
  const [view, setView] = useState<"lista" | "calendario">("lista");
  // senza competition_id arrivano tutte le competizioni in corso (campionato, coppe, amichevoli)
  const q = useMatches({ scope: all ? "all" : "own" });
  const info = home.data?.calendar_info;

  const groups = new Map<
    number,
    { head: Match["competition"]; order: number; rounds: Map<number, Match[]> }
  >();
  q.data?.forEach((m) => {
    const g = groups.get(m.competition.id) ?? {
      head: m.competition,
      order: kindOrder(m),
      rounds: new Map<number, Match[]>(),
    };
    const r = m.round ?? 0;
    g.rounds.set(r, [...(g.rounds.get(r) ?? []), m]);
    groups.set(m.competition.id, g);
  });
  const ordered = [...groups.values()].sort(
    (a, b) => a.order - b.order || a.head.name.localeCompare(b.head.name),
  );

  return (
    <div>
      <PageTitle kicker="Stagione 2026/2027" title="Calendario" />
      <div className="mb-5 flex flex-wrap items-end gap-3">
        <div
          role="tablist"
          aria-label="Vista"
          className="flex min-h-11 overflow-hidden rounded-lg border text-sm font-semibold"
        >
          {(
            [
              ["lista", "Lista", List],
              ["calendario", "Calendario", CalendarDays],
            ] as const
          ).map(([key, label, Icon]) => (
            <button
              key={key}
              role="tab"
              aria-selected={view === key}
              onClick={() => setView(key)}
              className={cn(
                "press relative flex items-center gap-2 px-3",
                view === key ? "text-primary-foreground" : "hover:bg-accent",
              )}
            >
              {view === key && <ActivePill id="calendar-view" className="bg-primary" />}
              <Icon className="relative h-4 w-4" /> <span className="relative">{label}</span>
            </button>
          ))}
        </div>
        <button
          role="switch"
          aria-checked={all}
          onClick={() => setAll(!all)}
          className="press flex min-h-11 items-center gap-3 rounded-lg border px-3 text-sm font-semibold"
        >
          <span
            className={cn(
              "relative h-6 w-10 rounded-full transition-colors duration-200",
              all ? "bg-primary" : "bg-muted",
            )}
          >
            <motion.span
              className="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-foreground"
              animate={{ x: all ? 16 : 0 }}
              transition={spring.snappy}
            />
          </span>
          Tutte le partite del girone
        </button>
      </div>
      {info && !info.is_complete && info.note && (
        <div className="mb-5">
          <InfoBanner>{info.note}</InfoBanner>
        </div>
      )}
      {q.isPending ? (
        <div className="space-y-3">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : ordered.length === 0 ? (
        <EmptyState>Nessuna partita in calendario.</EmptyState>
      ) : view === "calendario" ? (
        <motion.div
          key="calendario"
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: dur.base, ease: ease.out }}
        >
          <MonthCalendar matches={q.data ?? []} />
        </motion.div>
      ) : (
        <motion.div
          key="lista"
          className="space-y-8"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: dur.fast, ease: ease.out }}
        >
          {ordered.map(({ head, rounds }) => (
            <div key={head.id} className="space-y-5">
              {ordered.length > 1 && (
                <Reveal as="div">
                  <h2 className="text-3xl">{head.name}</h2>
                </Reveal>
              )}
              {[...rounds.entries()]
                .sort((a, b) => a[0] - b[0])
                .map(([round, ms]) => (
                  <section key={round}>
                    <Reveal x={-12} y={0}>
                      <h3 className="mb-2 flex items-center gap-2 text-xl">
                        <motion.span
                          className="h-5 w-1.5 origin-bottom rounded bg-primary"
                          initial={{ scaleY: 0 }}
                          whileInView={{ scaleY: 1 }}
                          viewport={{ once: true, amount: 1 }}
                          transition={{ duration: dur.base, ease: ease.out, delay: 0.1 }}
                        />
                        {round > 0 && head.kind === "campionato"
                          ? `Giornata ${round}`
                          : (ms[0]?.round_label ?? head.name)}
                      </h3>
                    </Reveal>
                    <div className="grid gap-2 md:grid-cols-2">
                      {ms.map((m, idx) => (
                        <MatchRow key={m.id} m={m} i={idx} />
                      ))}
                    </div>
                  </section>
                ))}
            </div>
          ))}
        </motion.div>
      )}
    </div>
  );
}
