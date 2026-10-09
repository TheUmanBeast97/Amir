import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, Search, UserRound, X } from "lucide-react";
import { useEffect, useState } from "react";
import { useZonePlayers } from "@/api/zone";
import { Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";

/** `?q=` è il nome cercato; la ricerca parte dalla seconda lettera, dopo una breve pausa. */
export const Route = createFileRoute("/mixed-zone/giocatori/")({
  validateSearch: (s: Record<string, unknown>): { q?: string } =>
    typeof s["q"] === "string" && s["q"] ? { q: s["q"] } : {},
  head: () => ({
    meta: [
      { title: "Giocatori - Mixed Zone" },
      {
        name: "description",
        content:
          "Cerca un giocatore fra tutti quelli che hanno giocato nei tornei di XFive Alessandria.",
      },
      { property: "og:title", content: "Giocatori - Mixed Zone" },
      { property: "og:description", content: "Tutti i giocatori di XFive Alessandria." },
    ],
  }),
  component: PlayersPage,
});

function PlayersPage() {
  const { q = "" } = Route.useSearch();
  const navigate = Route.useNavigate();
  // il campo risponde subito; l'indirizzo (e la richiesta) si aggiornano dopo una pausa
  const [text, setText] = useState(q);
  useEffect(() => setText(q), [q]);
  useEffect(() => {
    const t = setTimeout(() => {
      if (text.trim() !== q)
        void navigate({ search: text.trim() ? { q: text.trim() } : {}, replace: true });
    }, 300);
    return () => clearTimeout(t);
  }, [text, q, navigate]);

  const ready = q.trim().length >= 2;
  const players = useZonePlayers(ready ? { q } : {});

  return (
    <div className="space-y-5">
      <PageTitle kicker="Mixed Zone" title="Giocatori">
        {ready && players.data && (
          <span className="text-sm text-muted-foreground num">
            {players.data.length} {players.data.length === 1 ? "trovato" : "trovati"}
          </span>
        )}
      </PageTitle>
      <Reveal now delay={0.15} y={8} className="relative max-w-xl">
        <Search className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-muted-foreground" />
        <input
          type="search"
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="Cerca per cognome o nome"
          aria-label="Cerca un giocatore"
          autoFocus
          className="min-h-12 w-full rounded-xl border border-input bg-card pl-11 pr-11 text-base text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-search-cancel-button]:hidden"
        />
        {text && (
          <button
            type="button"
            aria-label="Svuota la ricerca"
            onClick={() => setText("")}
            className="press absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <X className="h-4 w-4" />
          </button>
        )}
      </Reveal>

      {!ready ? (
        <EmptyState>
          <UserRound className="mx-auto mb-2 h-8 w-8 text-primary/60" />
          Scrivi almeno due lettere: cerchiamo fra tutti i giocatori che hanno un profilo su XFive
          Alessandria. Accenti e maiuscole non contano.
        </EmptyState>
      ) : players.isPending ? (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : players.isError ? (
        <ErrorState error={players.error} onRetry={() => players.refetch()} />
      ) : players.data.length === 0 ? (
        <EmptyState>Nessun giocatore si chiama «{q}».</EmptyState>
      ) : (
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {players.data.map((p, i) => (
            <Reveal as="li" key={p.id} i={i % 6} y={12} scale={0.96}>
              <Link
                to="/mixed-zone/giocatori/$id"
                params={{ id: String(p.id) }}
                className="lift group flex items-center gap-3 rounded-xl border bg-card p-2.5 hover:border-primary/40"
              >
                <PlayerPhoto player={{ full_name: p.name, photo_url: p.photo_url }} size={48} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-semibold">{p.name}</span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {p.nationality ?? "Giocatore"}
                  </span>
                </span>
                <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
              </Link>
            </Reveal>
          ))}
        </ul>
      )}
    </div>
  );
}
