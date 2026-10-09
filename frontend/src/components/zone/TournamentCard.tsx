import { Link } from "@tanstack/react-router";
import { ChevronRight, Trophy, Users } from "lucide-react";
import { useState } from "react";
import type { ZoneTournament } from "@/api/zone-types";
import { Grow, Reveal } from "@/components/motion";
import { sized } from "@/lib/img";
import { cn } from "@/lib/utils";
import { TOURNAMENT_STATUS, sportLabel } from "@/lib/zone";

/** La locandina del torneo; se manca o non carica, una coppa sul fondo dell'area. */
function Flyer({ t }: { t: ZoneTournament }) {
  const [broken, setBroken] = useState(false);
  if (t.flyer_url && !broken)
    return (
      <img
        src={sized(t.flyer_url, 384)}
        alt=""
        loading="lazy"
        onError={() => setBroken(true)}
        className="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-105"
      />
    );
  return <Trophy className="h-12 w-12 text-muted-foreground/50" />;
}

/** Scheda di un torneo nell'elenco: locandina, stato, nome, stagione e sport, avanzamento delle partite. */
export function TournamentCard({ t, i = 0 }: { t: ZoneTournament; i?: number }) {
  const s = TOURNAMENT_STATUS[t.status];
  const pct = t.total > 0 ? Math.round((t.played / t.total) * 100) : 0;
  return (
    <Reveal i={i} y={18} scale={0.96}>
      <Link
        to="/mixed-zone/tornei/$id"
        params={{ id: String(t.id) }}
        className="card-cut group relative block overflow-hidden rounded-xl bg-card transition-transform duration-200 hover:-translate-y-1 active:translate-y-0 active:scale-[0.985] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <div className="relative grid aspect-[16/9] place-items-center overflow-hidden bg-hero">
          <Flyer t={t} />
          <div
            aria-hidden
            className="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/60 to-transparent"
          />
          <span
            className={cn(
              "absolute left-2 top-2 rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide backdrop-blur",
              s.cls,
            )}
          >
            {s.label}
          </span>
          {t.teams_count !== null && (
            <span className="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-[11px] font-semibold text-white backdrop-blur-sm num">
              <Users className="h-3 w-3" /> {t.teams_count}
            </span>
          )}
        </div>
        <div className="p-3">
          <div className="flex items-center justify-between gap-1">
            <div className="truncate font-display text-xl leading-tight">{t.name}</div>
            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
          </div>
          <div className="mt-0.5 truncate text-xs text-muted-foreground">
            <span className="num">{t.season}</span> · {sportLabel(t.sport)}
            {t.dates ? ` · ${t.dates}` : ""}
          </div>
          <div className="mt-2 flex items-center gap-2 text-[11px] text-muted-foreground num">
            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
              <Grow
                axis="x"
                i={i}
                className="h-full rounded-full bg-primary"
                style={{ width: `${Math.max(pct > 0 ? 3 : 0, pct)}%` }}
              />
            </div>
            <span className="shrink-0">
              {t.played} di {t.total} partite
            </span>
          </div>
        </div>
      </Link>
    </Reveal>
  );
}
