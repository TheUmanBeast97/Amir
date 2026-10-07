import { createFileRoute, Link } from "@tanstack/react-router";
import { ChevronRight, History } from "lucide-react";
import { useRoster } from "@/api/hooks";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { Reveal } from "@/components/motion";
import { ShirtBadge } from "@/components/player-ui";
import { roleLabel } from "@/lib/format";
import { initials } from "@/lib/kit";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/rosa/")({
  head: () => ({
    meta: [
      { title: "Rosa — AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Giocatori e staff di AMIR COSTRUZIONI per la stagione 2026/2027: scheda personale, statistiche e rendimento.",
      },
      { property: "og:title", content: "Rosa — AMIR COSTRUZIONI" },
      { property: "og:description", content: "Giocatori e staff della squadra." },
    ],
  }),
  component: RosterPage,
});

function RosterPage() {
  // la rosa pubblica usa l'endpoint pubblico: /players è dell'area admin e richiede il login
  const q = useRoster();
  return (
    <div>
      <PageTitle kicker="Stagione 2026/2027" title="La rosa" />
      <Reveal as="p" now delay={0.15} className="-mt-2 mb-4 text-sm text-muted-foreground">
        Tocca un giocatore per aprire la sua scheda: statistiche, grafici di rendimento e carriera.
      </Reveal>
      {q.isPending ? (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-60" />
          ))}
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.data.length === 0 ? (
        <EmptyState>Nessun giocatore in rosa.</EmptyState>
      ) : (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          {q.data.map((p, i) => {
            const staff = p.role === "dirigente" || p.role === "allenatore";
            return (
              <Reveal key={p.id} i={i % 4} y={22} scale={0.94}>
              <Link
                to="/rosa/$playerId"
                params={{ playerId: String(p.id) }}
                className="card-cut group relative block overflow-hidden rounded-xl bg-card transition-transform duration-200 hover:-translate-y-1 active:translate-y-0 active:scale-[0.985] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <div className="relative grid aspect-[4/3] place-items-center overflow-hidden bg-hero">
                  {p.photo_url ? (
                    <img
                      src={p.photo_url}
                      alt=""
                      className="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-105"
                    />
                  ) : (
                    <span className="font-display text-5xl text-muted-foreground/60">
                      {initials(p.full_name)}
                    </span>
                  )}
                  <div
                    aria-hidden
                    className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/60 to-transparent"
                  />
                  {staff ? (
                    <span className="absolute left-2 top-2 rounded-full bg-warning px-2 py-0.5 font-display text-lg text-warning-foreground">
                      {p.shirt_number ?? "–"}
                    </span>
                  ) : (
                    <div className="absolute bottom-2 left-2 flex items-center gap-1.5">
                      {p.shirt_number_red && (
                        <ShirtBadge number={p.shirt_number_red} kit="red" size={30} />
                      )}
                      {p.shirt_number_white && (
                        <ShirtBadge number={p.shirt_number_white} kit="white" size={30} />
                      )}
                      {!p.shirt_number_red && !p.shirt_number_white && (
                        <ShirtBadge number={p.shirt_number} kit="red" size={30} />
                      )}
                    </div>
                  )}
                </div>
                <div className="p-3">
                  <div className="flex items-center justify-between gap-1">
                    <div className="truncate font-display text-xl leading-tight">{p.full_name}</div>
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                  </div>
                  {p.nickname && (
                    <div className="truncate text-xs italic text-muted-foreground">
                      “{p.nickname}”
                    </div>
                  )}
                  <div
                    className={cn(
                      "mt-2 inline-block rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide",
                      staff ? "bg-warning/20 text-warning" : "bg-secondary",
                    )}
                  >
                    {p.role ? roleLabel[p.role] : "—"}
                  </div>
                </div>
              </Link>
              </Reveal>
            );
          })}
        </div>
      )}
      <Reveal className="mt-6">
        <Link
          to="/storico"
          className="press flex min-h-12 items-center justify-center gap-2 rounded-xl border border-primary/40 font-semibold text-primary hover:bg-primary/10"
        >
          <History className="h-5 w-5" /> Ex giocatori e classifica di sempre nello Storico
        </Link>
      </Reveal>
    </div>
  );
}
