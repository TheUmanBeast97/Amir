import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, Search, X } from "lucide-react";
import { useMemo } from "react";
import { useZoneClubs } from "@/api/zone";
import { Reveal } from "@/components/motion";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { ClubCrest } from "@/components/zone/ClubCrest";
import { OWN_CLUB_ID } from "@/lib/area";
import { cn } from "@/lib/utils";
import { normalize } from "@/lib/zone";

/** `?q=` filtra l'elenco (senza accenti né maiuscole), tutto in pagina: i club sono poche centinaia. */
export const Route = createFileRoute("/mixed-zone/squadre/")({
  validateSearch: (s: Record<string, unknown>): { q?: string } =>
    typeof s["q"] === "string" && s["q"] ? { q: s["q"] } : {},
  head: () => ({
    meta: [
      { title: "Squadre - Mixed Zone" },
      {
        name: "description",
        content: "Tutte le squadre che hanno giocato nei tornei di calcio di XFive Alessandria.",
      },
      { property: "og:title", content: "Squadre - Mixed Zone" },
      { property: "og:description", content: "Tutte le squadre di XFive Alessandria." },
    ],
  }),
  component: ClubsPage,
});

function ClubsPage() {
  const { q = "" } = Route.useSearch();
  const navigate = Route.useNavigate();
  const clubs = useZoneClubs();
  const setQ = (v: string) => void navigate({ search: v ? { q: v } : {}, replace: true });

  const shown = useMemo(() => {
    const n = normalize(q);
    const all = (clubs.data ?? []).filter((c) => c.id !== null);
    const list = n ? all.filter((c) => normalize(c.name).includes(n)) : all;
    // la nostra squadra sempre in cima
    return [...list].sort((a, b) => {
      const oa = a.id === OWN_CLUB_ID ? 0 : 1;
      const ob = b.id === OWN_CLUB_ID ? 0 : 1;
      return oa - ob || a.name.localeCompare(b.name, "it");
    });
  }, [clubs.data, q]);

  return (
    <div className="space-y-5">
      <PageTitle kicker="Mixed Zone" title="Squadre">
        {clubs.data && (
          <span className="text-sm text-muted-foreground num">
            {shown.length} di {clubs.data.length}
          </span>
        )}
      </PageTitle>
      <Reveal now delay={0.15} y={8} className="relative max-w-xl">
        <Search className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-muted-foreground" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Cerca una squadra"
          aria-label="Cerca una squadra"
          className="min-h-12 w-full rounded-xl border border-input bg-card pl-11 pr-11 text-base text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-search-cancel-button]:hidden"
        />
        {q && (
          <button
            type="button"
            aria-label="Svuota la ricerca"
            onClick={() => setQ("")}
            className="press absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </Reveal>
      {clubs.isPending ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 12 }).map((_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      ) : clubs.isError ? (
        <ErrorState error={clubs.error} onRetry={() => clubs.refetch()} />
      ) : shown.length === 0 ? (
        <EmptyState>Nessuna squadra si chiama così.</EmptyState>
      ) : (
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {shown.map((c, i) => {
            const ours = c.id === OWN_CLUB_ID;
            return (
              <Reveal as="li" key={c.id} i={i % 4} y={16} scale={0.95}>
                <Link
                  to="/mixed-zone/squadre/$id"
                  params={{ id: String(c.id) }}
                  className={cn(
                    "lift group flex h-full flex-col items-center gap-2 rounded-xl border bg-card p-4 text-center",
                    ours ? "border-primary bg-highlight" : "hover:border-primary/40",
                  )}
                >
                  <ClubCrest club={c} size={64} />
                  <span className="line-clamp-2 w-full text-sm font-semibold leading-tight">
                    {c.name}
                  </span>
                  {ours ? (
                    <span className="rounded-full bg-primary px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary-foreground">
                      La nostra
                    </span>
                  ) : (
                    <ChevronRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                  )}
                </Link>
              </Reveal>
            );
          })}
        </ul>
      )}
    </div>
  );
}
