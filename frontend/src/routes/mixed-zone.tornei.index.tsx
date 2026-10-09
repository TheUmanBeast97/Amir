import { createFileRoute } from "@tanstack/react-router";
import { useMemo } from "react";
import { useZoneHome, useZoneTournaments } from "@/api/zone";
import type { ZoneTournament } from "@/api/zone-types";
import { Reveal } from "@/components/motion";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { SeasonChips } from "@/components/zone/SeasonChips";
import { SportChips } from "@/components/zone/SportChips";
import { TournamentCard } from "@/components/zone/TournamentCard";
import { TOURNAMENT_STATUS, statusOrder } from "@/lib/zone";

interface Search {
  season?: string;
  sport?: string;
}

/** `?season=` e `?sport=` filtrano l'elenco; lo sport si filtra qui, senza richiamare il server. */
export const Route = createFileRoute("/mixed-zone/tornei/")({
  validateSearch: (s: Record<string, unknown>): Search => ({
    ...(typeof s["season"] === "string" && s["season"] ? { season: s["season"] } : {}),
    ...(typeof s["sport"] === "string" && s["sport"] ? { sport: s["sport"] } : {}),
  }),
  head: () => ({
    meta: [
      { title: "Tornei - Mixed Zone" },
      {
        name: "description",
        content:
          "Tutti i campionati, le coppe e i tornei di calcio di XFive Alessandria, stagione per stagione.",
      },
      { property: "og:title", content: "Tornei - Mixed Zone" },
      { property: "og:description", content: "Campionati, coppe e tornei di XFive Alessandria." },
    ],
  }),
  component: TournamentsPage,
});

const STATUSES = ["ongoing", "incoming", "previous"] as const;

function TournamentsPage() {
  const { season, sport } = Route.useSearch();
  const navigate = Route.useNavigate();
  const home = useZoneHome();
  // la stagione di serie è quella che il backend considera in corso
  const current = season ?? home.data?.season;
  const q = useZoneTournaments(current ? { season: current } : {});

  const sports = useMemo(() => [...new Set((q.data ?? []).map((t) => t.sport))].sort(), [q.data]);
  const shown = useMemo(
    () =>
      (q.data ?? [])
        .filter((t) => !sport || t.sport === sport)
        .sort(
          (a, b) =>
            statusOrder[a.status] - statusOrder[b.status] || a.name.localeCompare(b.name, "it"),
        ),
    [q.data, sport],
  );
  const groups = STATUSES.map((st) => ({ st, items: shown.filter((t) => t.status === st) })).filter(
    (g) => g.items.length > 0,
  );

  const update = (patch: Search) =>
    void navigate({
      search: (prev) => {
        const next = { ...prev, ...patch };
        return {
          ...(next.season ? { season: next.season } : {}),
          ...(next.sport ? { sport: next.sport } : {}),
        };
      },
      replace: true,
    });

  return (
    <div className="space-y-5">
      <PageTitle kicker="Mixed Zone" title="Tornei">
        {q.data && (
          <span className="text-sm text-muted-foreground num">
            {shown.length} {shown.length === 1 ? "torneo" : "tornei"}
          </span>
        )}
      </PageTitle>
      {home.data && (
        <SeasonChips
          seasons={home.data.seasons}
          value={current ?? ""}
          onChange={(s) => update({ season: s, sport: "" })}
        />
      )}
      {sports.length > 1 && (
        <SportChips sports={sports} value={sport ?? ""} onChange={(s) => update({ sport: s })} />
      )}
      {q.isPending ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : shown.length === 0 ? (
        <EmptyState>Nessun torneo di calcio con questi filtri.</EmptyState>
      ) : (
        groups.map(({ st, items }) => <StatusGroup key={st} st={st} items={items} />)
      )}
    </div>
  );
}

function StatusGroup({ st, items }: { st: ZoneTournament["status"]; items: ZoneTournament[] }) {
  return (
    <section>
      <Reveal x={-12} y={0}>
        <h2 className="mb-2 flex items-center gap-2 text-xl">
          <span className="h-5 w-1.5 rounded bg-primary" />
          {TOURNAMENT_STATUS[st].label}
          <span className="text-sm font-normal normal-case tracking-normal text-muted-foreground num">
            {items.length}
          </span>
        </h2>
      </Reveal>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {items.map((t, i) => (
          <TournamentCard key={t.id} t={t} i={i % 3} />
        ))}
      </div>
    </section>
  );
}
