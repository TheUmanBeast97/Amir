import { Link } from "@tanstack/react-router";
import { motion } from "motion/react";
import type { ZoneId, ZoneStandings } from "@/api/zone-types";
import { RESULT_LETTER } from "@/components/stats-ui";
import { OWN_CLUB_ID } from "@/lib/area";
import { dur, ease, stagger } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { FORM_COLOR } from "@/lib/zone";
import { ClubCrest } from "./ClubCrest";

/** Le colonne XFive nell'ordine in cui le leggiamo: i punti per ultimi, come nella classifica di Amir Hub. */
const FULL = ["G", "V", "N", "P", "F", "S", "+/-", "FP", "Pt"];
const COMPACT = ["G", "+/-", "Pt"];

/**
 * La classifica di un torneo (un girone per tabella): posizione, stemma, nome con il link alla squadra,
 * le colonne di XFive e la forma delle ultime partite a pallini. Sul telefono scorre in orizzontale.
 */
export function StandingsTable({
  table,
  highlightClub = OWN_CLUB_ID,
  compact = false,
  showGroup = true,
}: {
  table: ZoneStandings;
  highlightClub?: ZoneId | null;
  compact?: boolean;
  /** Il titolo del girone sopra la tabella (si toglie quando è l'unico). */
  showGroup?: boolean;
}) {
  const known = new Set(table.columns.map((c) => c.key));
  const keys = (compact ? COMPACT : FULL).filter((k) => known.has(k));
  const label = (k: string) => table.columns.find((c) => c.key === k)?.label ?? k;
  const notStarted = table.rows.length > 0 && table.rows.every((r) => (r.values["G"] ?? 0) === 0);
  const hasForm = !compact && table.rows.some((r) => r.form.length > 0);

  return (
    <div>
      {showGroup && table.group && (
        <h3 className="mb-2 flex items-center gap-2 text-xl">
          <span className="h-5 w-1.5 rounded bg-primary" />
          {table.group}
        </h3>
      )}
      {notStarted && (
        <p className="mb-2 text-xs text-muted-foreground">Il torneo non è ancora iniziato</p>
      )}
      <div className="overflow-x-auto rounded-xl border">
        <table className="w-full text-sm num">
          <thead className="bg-muted/60 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="w-10 px-2 py-2 text-center">#</th>
              <th className="px-2 py-2 text-left">Squadra</th>
              {keys.map((k) => (
                <th key={k} title={label(k)} className="px-2 py-2 text-center">
                  {k === "+/-" ? "DR" : k}
                </th>
              ))}
              {hasForm && <th className="px-2 py-2 text-center">Forma</th>}
            </tr>
          </thead>
          <tbody>
            {table.rows.map((r, rowIndex) => {
              const ours = r.club.id !== null && r.club.id === highlightClub;
              return (
                <motion.tr
                  key={`${r.position}-${r.club.id ?? r.club.name}`}
                  initial={{ opacity: 0, x: -14 }}
                  whileInView={{ opacity: 1, x: 0 }}
                  viewport={{ once: true, amount: 0.3 }}
                  transition={{
                    duration: dur.slow,
                    ease: ease.out,
                    delay: stagger(rowIndex, 0.035, 12),
                  }}
                  className={cn(
                    "border-t transition-colors hover:bg-accent/40",
                    ours && "bg-highlight font-bold hover:bg-highlight",
                  )}
                >
                  <td
                    className={cn("px-2 py-2.5 text-center", ours && "border-l-4 border-primary")}
                  >
                    {notStarted ? "-" : r.position}
                  </td>
                  <td className="px-2 py-2.5">
                    <div className="flex items-center gap-2">
                      <ClubCrest club={r.club} size={22} />
                      {r.club.id !== null ? (
                        <Link
                          to="/mixed-zone/squadre/$id"
                          params={{ id: String(r.club.id) }}
                          className="max-w-[9rem] truncate hover:underline md:max-w-none"
                        >
                          {r.club.name}
                        </Link>
                      ) : (
                        <span className="max-w-[9rem] truncate md:max-w-none">{r.club.name}</span>
                      )}
                      {ours && (
                        <span className="rounded-full bg-primary px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-primary-foreground">
                          Noi
                        </span>
                      )}
                    </div>
                  </td>
                  {keys.map((k) => {
                    const v = r.values[k] ?? 0;
                    return (
                      <td
                        key={k}
                        className={cn("px-2 py-2.5 text-center", k === "Pt" && "font-bold")}
                      >
                        {k === "+/-" && v > 0 ? `+${v}` : v}
                      </td>
                    );
                  })}
                  {hasForm && (
                    <td className="px-2 py-2.5">
                      <div className="flex justify-center gap-0.5">
                        {r.form.map((f, idx) => (
                          <span
                            key={idx}
                            title={RESULT_LETTER[f]}
                            className="grid h-4 w-4 place-items-center rounded-full text-[9px] font-bold text-black"
                            style={{ background: FORM_COLOR[f] }}
                          >
                            {RESULT_LETTER[f]}
                          </span>
                        ))}
                      </div>
                    </td>
                  )}
                </motion.tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
