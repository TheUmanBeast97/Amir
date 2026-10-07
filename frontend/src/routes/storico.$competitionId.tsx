import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, Shield, Target, Trophy } from "lucide-react";
import { useHistoryCompetition } from "@/api/hooks";
import type { CompetitionAwards } from "@/api/types";
import { InsightsSections } from "@/components/history-ui";
import { Reveal } from "@/components/motion";
import { Panel } from "@/components/stats-ui";
import { Crest, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { MatchRow, StandingsTable } from "@/components/match";

export const Route = createFileRoute("/storico/$competitionId")({
  head: () => ({
    meta: [
      { title: "Competizione passata - AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Classifica finale e risultati di AMIR COSTRUZIONI in una competizione passata.",
      },
      { property: "og:title", content: "Competizione passata - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Classifica finale e risultati." },
    ],
  }),
  component: CompetitionDetail,
});

/** Primo in classifica, miglior attacco e miglior difesa (solo nei campionati). */
function Awards({ a }: { a: CompetitionAwards }) {
  const tiles = [
    {
      label: "Primo in classifica",
      t: a.first_place,
      detail: `${a.first_place.value} punti in ${a.first_place.played} partite`,
      Icon: Trophy,
    },
    {
      label: "Miglior attacco",
      t: a.best_attack,
      detail: `${a.best_attack.value} gol in ${a.best_attack.played} partite`,
      Icon: Target,
    },
    {
      label: "Miglior difesa",
      t: a.best_defence,
      detail: `${a.best_defence.value} subiti in ${a.best_defence.played} partite`,
      Icon: Shield,
    },
  ];
  return (
    <Panel
      title="Il campionato in breve"
      kicker={
        a.own_position
          ? `Noi: ${a.own_position}° su ${a.teams_count} squadre`
          : `${a.teams_count} squadre`
      }
    >
      <div className="grid gap-2 md:grid-cols-3">
        {tiles.map(({ label, t, detail, Icon }, idx) => (
          <Reveal
            key={label}
            i={idx}
            scale={0.95}
            className="flex items-center gap-3 rounded-xl bg-background/50 p-3"
          >
            <Crest team={t.team} size={40} />
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-1 text-[11px] font-bold uppercase tracking-wider text-warning">
                <Icon className="h-3.5 w-3.5" /> {label}
              </div>
              <div className="truncate text-sm font-semibold">{t.team.name}</div>
              <div className="text-[11px] text-muted-foreground num">{detail}</div>
            </div>
          </Reveal>
        ))}
      </div>
      <p className="mt-2 text-[11px] text-muted-foreground">
        Miglior attacco e difesa si confrontano per media a partita. «Primo in classifica» è la
        classifica a punti: eventuali spareggi o fasi finali non sono conteggiati.
      </p>
    </Panel>
  );
}

function CompetitionDetail() {
  const { competitionId } = Route.useParams();
  const q = useHistoryCompetition(Number(competitionId));
  return (
    <div className="space-y-6">
      <Link
        to="/storico"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        Storico
      </Link>
      {q.isPending ? (
        <Skeleton className="h-96" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <>
          <PageTitle
            kicker={`${q.data.competition.season} · a ${q.data.competition.format}`}
            title={q.data.competition.name}
          />
          {q.data.awards && <Awards a={q.data.awards} />}
          {q.data.standings.length > 0 && (
            <section>
              <Reveal x={-14} y={0}>
                <h2 className="mb-2 text-2xl">Classifica finale</h2>
              </Reveal>
              <StandingsTable rows={q.data.standings} />
            </section>
          )}
          <section>
            <Reveal x={-14} y={0}>
              <h2 className="mb-2 text-2xl">Le nostre partite</h2>
            </Reveal>
            {q.data.own_matches.length === 0 ? (
              <EmptyState>Nessuna partita registrata.</EmptyState>
            ) : (
              <div className="grid gap-2 md:grid-cols-2">
                {q.data.own_matches.map((m, idx) => (
                  <MatchRow key={m.id} m={m} i={idx} />
                ))}
              </div>
            )}
          </section>
          {q.data.insights.records.best_win !== null ||
          q.data.insights.records.worst_defeat !== null ||
          q.data.insights.roster.length > 0 ? (
            <InsightsSections
              insights={q.data.insights}
              parts={["kpis", "roster", "streaks", "extremes", "hat_tricks", "referees", "venues"]}
            />
          ) : null}
        </>
      )}
    </div>
  );
}
