import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowRight, BarChart3, FileText, Shield, Swords, Trophy, Users } from "lucide-react";
import { useZoneHome } from "@/api/zone";
import type { ZoneHome } from "@/api/zone-types";
import { CountUp, Reveal } from "@/components/motion";
import { EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { MatchRow } from "@/components/zone/MatchRow";
import { SeasonChips } from "@/components/zone/SeasonChips";
import { TournamentCard } from "@/components/zone/TournamentCard";
import { ZoneSearch } from "@/components/zone/ZoneSearch";
import { sportLabel } from "@/lib/zone";

/** `?season=2025/2026` sceglie la stagione; senza, il backend usa quella in corso. */
export const Route = createFileRoute("/mixed-zone/")({
  validateSearch: (s: Record<string, unknown>): { season?: string } =>
    typeof s["season"] === "string" && s["season"] ? { season: s["season"] } : {},
  head: () => ({
    meta: [
      { title: "Home - Mixed Zone" },
      {
        name: "description",
        content:
          "Tutto XFive Alessandria: tornei, squadre, giocatori, partite e statistiche di tutti i campionati di calcio.",
      },
      { property: "og:title", content: "Mixed Zone - Tutto XFive Alessandria" },
      { property: "og:description", content: "Tornei, squadre, giocatori, partite e statistiche." },
    ],
  }),
  component: ZoneHomePage,
});

const TOTALS: { key: keyof ZoneHome["totals"]; label: string; icon: typeof Trophy }[] = [
  { key: "tournaments", label: "Tornei", icon: Trophy },
  { key: "clubs", label: "Squadre", icon: Shield },
  { key: "players", label: "Giocatori", icon: Users },
  { key: "matches", label: "Partite", icon: Swords },
  { key: "reports", label: "Referti", icon: FileText },
];

function ZoneHomePage() {
  const { season } = Route.useSearch();
  const navigate = Route.useNavigate();
  const q = useZoneHome(season);
  const setSeason = (s: string) => void navigate({ search: s ? { season: s } : {}, replace: true });

  return (
    <div className="space-y-6">
      <PageTitle kicker="Mixed Zone" title="Tutto XFive Alessandria" />
      <Reveal now delay={0.15} y={8}>
        <ZoneSearch />
      </Reveal>

      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-10" />
          <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
            {TOTALS.map((t) => (
              <Skeleton key={t.key} className="h-24" />
            ))}
          </div>
          <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
            <Skeleton className="h-28" />
            <Skeleton className="h-28" />
          </div>
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <HomeBody h={q.data} season={season ?? q.data.season} setSeason={setSeason} />
      )}
    </div>
  );
}

function HomeBody({
  h,
  season,
  setSeason,
}: {
  h: ZoneHome;
  season: string;
  setSeason: (s: string) => void;
}) {
  return (
    <>
      <SeasonChips seasons={h.seasons} value={season} onChange={setSeason} />

      {/* i numeri di tutto l'archivio, grandi come su un tabellone */}
      <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
        {TOTALS.map(({ key, label, icon: Icon }, i) => (
          <Reveal
            key={key}
            i={i}
            scale={0.94}
            className="card-cut relative overflow-hidden rounded-xl bg-hero p-3 md:p-4"
          >
            <Icon className="absolute -right-2 -top-2 h-14 w-14 text-primary/15" aria-hidden />
            <div className="relative font-display text-4xl leading-none text-primary num md:text-5xl">
              <CountUp value={h.totals[key]} />
            </div>
            <div className="relative mt-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {label}
            </div>
          </Reveal>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section>
          <div className="mb-3 flex items-end justify-between">
            <h2 className="text-2xl">Ultimi risultati</h2>
            <Link
              to="/mixed-zone/partite"
              className="text-sm font-semibold text-primary hover:underline"
            >
              Tutte le partite →
            </Link>
          </div>
          {h.latest.length === 0 ? (
            <EmptyState>Nessun risultato in questa stagione.</EmptyState>
          ) : (
            <div className="grid grid-cols-1 gap-2">
              {h.latest.slice(0, 8).map((m, i) => (
                <MatchRow key={m.id} m={m} i={i} />
              ))}
            </div>
          )}
        </section>
        <section>
          <div className="mb-3 flex items-end justify-between">
            <h2 className="text-2xl">Prossime partite</h2>
            <Link
              to="/mixed-zone/partite"
              className="text-sm font-semibold text-primary hover:underline"
            >
              Per giorno →
            </Link>
          </div>
          {h.upcoming.length === 0 ? (
            <EmptyState>Nessuna partita in programma.</EmptyState>
          ) : (
            <div className="grid grid-cols-1 gap-2">
              {h.upcoming.slice(0, 8).map((m, i) => (
                <MatchRow key={m.id} m={m} i={i} />
              ))}
            </div>
          )}
        </section>
      </div>

      <section className="space-y-6">
        <div className="flex items-end justify-between">
          <h2 className="text-2xl">
            I tornei <span className="text-muted-foreground num">{season}</span>
          </h2>
          <Link
            to="/mixed-zone/tornei"
            search={{ season }}
            className="text-sm font-semibold text-primary hover:underline"
          >
            Tutti i tornei →
          </Link>
        </div>
        {h.tournaments.length === 0 ? (
          <EmptyState>Nessun torneo di calcio in questa stagione.</EmptyState>
        ) : (
          h.tournaments.map(({ sport, items }) => (
            <div key={sport}>
              <Reveal x={-12} y={0}>
                <h3 className="mb-2 flex items-center gap-2 text-xl">
                  <span className="h-5 w-1.5 rounded bg-primary" />
                  {sportLabel(sport)}
                  <span className="text-sm font-normal normal-case tracking-normal text-muted-foreground num">
                    {items.length} {items.length === 1 ? "torneo" : "tornei"}
                  </span>
                </h3>
              </Reveal>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {items.map((t, i) => (
                  <TournamentCard key={t.id} t={t} i={i % 3} />
                ))}
              </div>
            </div>
          ))
        )}
      </section>

      <Reveal>
        <Link
          to="/mixed-zone/statistiche"
          className="press group flex min-h-12 items-center justify-center gap-2 rounded-xl border border-primary/40 font-semibold text-primary hover:bg-primary/10"
        >
          <BarChart3 className="h-5 w-5" /> Le classifiche di sempre: bomber, presenze, squadre più
          vincenti
          <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" />
        </Link>
      </Reveal>
    </>
  );
}
