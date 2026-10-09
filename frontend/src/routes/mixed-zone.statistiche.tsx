import { createFileRoute } from "@tanstack/react-router";
import { useZoneStats } from "@/api/zone";
import { Reveal } from "@/components/motion";
import { ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { SeasonChips } from "@/components/zone/SeasonChips";
import { SportChips } from "@/components/zone/SportChips";
import { StatsBoard } from "@/components/zone/StatsBoard";

interface Search {
  season?: string;
  sport?: string;
}

/** `?season=` e `?sport=` restringono le classifiche di sempre; senza, contano tutte le stagioni e tutti i formati. */
export const Route = createFileRoute("/mixed-zone/statistiche")({
  validateSearch: (s: Record<string, unknown>): Search => ({
    ...(typeof s["season"] === "string" && s["season"] ? { season: s["season"] } : {}),
    ...(typeof s["sport"] === "string" && s["sport"] ? { sport: s["sport"] } : {}),
  }),
  head: () => ({
    meta: [
      { title: "Statistiche - Mixed Zone" },
      {
        name: "description",
        content:
          "Le classifiche di sempre di XFive Alessandria: bomber, presenze, cartellini, miglior giocatore, squadre più vincenti e difese meno battute.",
      },
      { property: "og:title", content: "Statistiche - Mixed Zone" },
      { property: "og:description", content: "Le classifiche di sempre di XFive Alessandria." },
    ],
  }),
  component: StatsPage,
});

function StatsPage() {
  const { season, sport } = Route.useSearch();
  const navigate = Route.useNavigate();
  const q = useZoneStats({ season, sport });
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
      <PageTitle kicker="Mixed Zone" title="Statistiche" />
      <Reveal as="p" now delay={0.15} className="-mt-2 text-sm text-muted-foreground">
        Le classifiche di sempre, ricalcolate dai referti pubblicati da XFive: solo i giocatori con
        un profilo abbinato. Filtra per stagione e formato.
      </Reveal>
      {q.data && (
        <>
          <SeasonChips
            seasons={q.data.seasons}
            value={season ?? ""}
            onChange={(s) => update({ season: s })}
            allLabel="Tutte le stagioni"
          />
          <SportChips
            sports={q.data.sports}
            value={sport ?? ""}
            onChange={(s) => update({ sport: s })}
            allLabel="Tutti i formati"
          />
        </>
      )}
      {q.isPending ? (
        <div className="space-y-3">
          <Skeleton className="h-10" />
          <Skeleton className="h-10" />
          <Skeleton className="h-[32rem]" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <StatsBoard
          key={`${season ?? ""}-${sport ?? ""}`}
          board={q.data}
          header={
            <span className="text-xs text-muted-foreground">
              {season ?? "Tutte le stagioni"}
              {sport ? ` · ${sport}` : ""}
            </span>
          }
        />
      )}
    </div>
  );
}
