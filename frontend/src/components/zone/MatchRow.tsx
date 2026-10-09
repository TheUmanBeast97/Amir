import { Link } from "@tanstack/react-router";
import { FileText, MapPin } from "lucide-react";
import { motion } from "motion/react";
import type { ZoneId, ZoneMatch } from "@/api/zone-types";
import { Reveal } from "@/components/motion";
import { RESULT_LETTER } from "@/components/stats-ui";
import { OWN_CLUB_ID } from "@/lib/area";
import { spring } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { FORM_COLOR, kickoffLabel, outcomeFor } from "@/lib/zone";
import { ClubCrest } from "./ClubCrest";

/**
 * Una partita XFive in una riga: quando si gioca, il torneo, le due squadre con stemma e punteggio, il campo.
 * Tutta la riga apre il referto (`/mixed-zone/partite/{id}`); l'icona del documento dice che il referto c'è.
 * Con `highlightClub` la squadra è in grassetto e a destra compare l'esito dal suo punto di vista.
 */
export function MatchRow({
  m,
  i = 0,
  showTournament = true,
  highlightClub = OWN_CLUB_ID,
}: {
  m: ZoneMatch;
  i?: number;
  showTournament?: boolean;
  highlightClub?: ZoneId | null;
}) {
  const o = outcomeFor(m, highlightClub);
  const winner =
    m.played && m.home_score !== null && m.away_score !== null && m.home_score !== m.away_score
      ? m.home_score > m.away_score
        ? "home"
        : "away"
      : null;
  return (
    <Reveal i={i} y={10} className="min-w-0">
      <Link
        to="/mixed-zone/partite/$id"
        params={{ id: String(m.id) }}
        className="lift flex items-center gap-3 rounded-xl border bg-card p-3 hover:border-primary/40"
      >
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <span className="truncate">{kickoffLabel(m)}</span>
            {showTournament && (
              <span className="hidden truncate rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary sm:inline">
                {m.tournament.name}
                {m.round_label ? ` · ${m.round_label}` : ""}
              </span>
            )}
            {m.has_report && (
              <FileText
                className="ml-auto h-3.5 w-3.5 shrink-0 text-primary"
                aria-label="Referto disponibile"
              />
            )}
          </div>
          {(["home", "away"] as const).map((side) => {
            const club = m[side];
            const score = side === "home" ? m.home_score : m.away_score;
            const ours = club.id !== null && club.id === highlightClub;
            return (
              <div key={side} className="flex items-center gap-2">
                <ClubCrest club={club} size={24} />
                <span
                  className={cn(
                    "flex-1 truncate text-sm",
                    ours && "font-bold",
                    winner === side ? "text-foreground" : m.played && "text-muted-foreground",
                  )}
                >
                  {club.name}
                </span>
                <span className="font-display text-lg num">{m.played ? (score ?? "") : ""}</span>
              </div>
            );
          })}
          {(m.venue || (showTournament && m.round_label)) && (
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              {m.venue ? (
                <>
                  <MapPin className="h-3 w-3" />
                  <span className="truncate">{m.venue}</span>
                </>
              ) : null}
              {showTournament && (
                <span className="truncate sm:hidden">
                  {m.venue ? " · " : ""}
                  {m.tournament.name}
                </span>
              )}
            </div>
          )}
        </div>
        {o && (
          <motion.span
            initial={{ scale: 0.4, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            transition={spring.pop}
            className="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold text-black num"
            style={{ background: FORM_COLOR[o] }}
            aria-label={`Esito: ${RESULT_LETTER[o]}`}
          >
            {RESULT_LETTER[o]}
          </motion.span>
        )}
      </Link>
    </Reveal>
  );
}
