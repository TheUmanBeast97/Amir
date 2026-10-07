import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, ArrowRight, Star } from "lucide-react";
import { useHistory } from "@/api/hooks";
import { CompetitionCard, InsightsSections, winPct } from "@/components/history-ui";
import { Reveal } from "@/components/motion";
import { Kpi } from "@/components/stats-ui";
import { Card, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";

export const Route = createFileRoute("/storico/stagione/$season")({
  head: () => ({
    meta: [
      { title: "Scheda stagione — AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Una stagione di AMIR COSTRUZIONI: rosa, record, serie, arbitri e campi.",
      },
      { property: "og:title", content: "Scheda stagione — AMIR COSTRUZIONI" },
      { property: "og:description", content: "Rosa, record e curiosità di una stagione." },
    ],
  }),
  component: SeasonPage,
});

function SeasonPage() {
  const { season: param } = Route.useParams();
  const label = param.replace("-", "/"); // nell'indirizzo la barra diventa un trattino: 2025-2026
  const q = useHistory();

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
        <div className="space-y-4">
          <Skeleton className="h-40" />
          <Skeleton className="h-72" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        (() => {
          const seasons = q.data.seasons; // dalla più recente
          const i = seasons.findIndex((s) => s.season === label);
          const season = seasons[i];
          if (!season) return <EmptyState>Stagione non trovata nello storico.</EmptyState>;
          const newer = seasons[i - 1];
          const older = seasons[i + 1];
          const r = season.record;
          return (
            <>
              <PageTitle kicker="Scheda stagione" title={season.season} />
              <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Kpi
                  i={0}
                  label="Giocate"
                  value={r.played}
                  sub={`${season.competitions.length} competizioni`}
                />
                <Kpi
                  i={1}
                  label="V-N-P"
                  value={`${r.won}-${r.drawn}-${r.lost}`}
                  sub={`${winPct(r)}% di vittorie`}
                  tone="success"
                />
                <Kpi
                  i={2}
                  label="Gol fatti-subiti"
                  value={`${r.goals_for}-${r.goals_against}`}
                  sub={`differenza ${r.goals_for - r.goals_against > 0 ? "+" : ""}${r.goals_for - r.goals_against}`}
                />
                <Kpi
                  i={3}
                  label="Punti"
                  value={r.points}
                  sub="3 la vittoria, 1 il pareggio"
                  tone="warning"
                />
              </div>
              {season.top_scorers.length > 0 && (
                <Card>
                  <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="inline-flex items-center gap-1 text-xs font-bold uppercase tracking-wider text-warning">
                      <Star className="h-4 w-4 fill-warning" /> Marcatori della stagione
                    </span>
                    {season.top_scorers.map((s, idx) => (
                      <Reveal as="span" key={s.player.id} i={idx} scale={0.85} y={0}>
                        <Link
                          to="/rosa/$playerId"
                          params={{ playerId: String(s.player.id) }}
                          className="press inline-block rounded-full bg-secondary px-3 py-1 font-semibold hover:bg-accent"
                        >
                          {s.player.full_name} <span className="text-primary num">{s.goals}</span>
                        </Link>
                      </Reveal>
                    ))}
                  </div>
                </Card>
              )}
              <section>
                <Reveal x={-14} y={0}>
                  <h2 className="mb-2 text-2xl">Le competizioni</h2>
                </Reveal>
                <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                  {season.competitions.map((c, idx) => (
                    <Reveal key={c.id} i={idx} y={12}>
                      <CompetitionCard c={c} i={idx} />
                    </Reveal>
                  ))}
                </div>
              </section>
              <InsightsSections
                insights={season.insights}
                parts={[
                  "kpis",
                  "roster",
                  "streaks",
                  "extremes",
                  "splits",
                  "hat_tricks",
                  "referees",
                  "venues",
                ]}
              />
              <nav aria-label="Altre stagioni" className="flex flex-wrap justify-between gap-2">
                {older ? (
                  <Link
                    to="/storico/stagione/$season"
                    params={{ season: older.season.replace("/", "-") }}
                    className="press group inline-flex min-h-11 items-center gap-2 rounded-lg border bg-card px-4 text-sm font-semibold hover:border-primary/40"
                  >
                    <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
                    {older.season}
                  </Link>
                ) : (
                  <span />
                )}
                {newer && (
                  <Link
                    to="/storico/stagione/$season"
                    params={{ season: newer.season.replace("/", "-") }}
                    className="press group inline-flex min-h-11 items-center gap-2 rounded-lg border bg-card px-4 text-sm font-semibold hover:border-primary/40"
                  >
                    {newer.season}{" "}
                    <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" />
                  </Link>
                )}
              </nav>
            </>
          );
        })()
      )}
    </div>
  );
}
