import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowRight, Globe } from "lucide-react";
import { useHome, useStandings } from "@/api/hooks";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { StandingsTable } from "@/components/match";
import { Reveal } from "@/components/motion";

export const Route = createFileRoute("/classifica")({
  head: () => ({
    meta: [
      { title: "Classifica - AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Classifica completa del girone CITTADELLA XFive Alessandria.",
      },
      { property: "og:title", content: "Classifica - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Classifica completa del girone." },
    ],
  }),
  component: StandingsPage,
});

function StandingsPage() {
  const home = useHome();
  const q = useStandings(home.data?.competition?.id);
  return (
    <div>
      <PageTitle
        kicker={
          home.data?.competition
            ? `${home.data.competition.name} · ${home.data.competition.season}`
            : undefined
        }
        title="Classifica"
      />
      {q.isPending ? (
        <Skeleton className="h-96" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.data.length === 0 ? (
        <EmptyState>Classifica non disponibile.</EmptyState>
      ) : (
        <StandingsTable rows={q.data} />
      )}
      <Reveal as="p" delay={0.4} y={6} className="mt-3 text-xs text-muted-foreground">
        P giocate · V vinte · N pareggiate · Pe perse · GF gol fatti · GS gol subiti · DR differenza
        reti · Pt punti
      </Reveal>
      {home.data?.competition?.xfive_tournament_id && (
        <Reveal className="mt-6">
          <Link
            to="/mixed-zone/tornei/$id"
            params={{ id: String(home.data.competition.xfive_tournament_id) }}
            className="press group flex min-h-12 items-center justify-center gap-2 rounded-xl border border-primary/40 font-semibold text-primary hover:bg-primary/10"
          >
            <Globe className="h-5 w-5" /> Il torneo nella Mixed Zone: calendario completo, squadre e
            statistiche
            <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" />
          </Link>
        </Reveal>
      )}
    </div>
  );
}
