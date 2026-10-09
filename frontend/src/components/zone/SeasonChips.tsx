import { useId } from "react";
import type { ZoneSeason } from "@/api/zone-types";
import { ActivePill } from "@/components/motion";
import { cn } from "@/lib/utils";

export interface Chip {
  value: string;
  label: string;
  /** Un numero piccolo accanto all'etichetta (es. quanti tornei ha la stagione). */
  count?: number | undefined;
}

/**
 * Una fila di chip, una sola attiva, con il segno del colore dell'area che scivola da una all'altra.
 * Scorre in orizzontale sul telefono invece di andare a capo.
 */
export function ChipBar({
  chips,
  value,
  onChange,
  label,
  className,
}: {
  chips: Chip[];
  value: string;
  onChange: (v: string) => void;
  label: string;
  className?: string;
}) {
  const pillId = useId();
  return (
    <div
      role="tablist"
      aria-label={label}
      className={cn(
        "-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] md:mx-0 md:flex-wrap md:px-0",
        className,
      )}
    >
      {chips.map((c) => {
        const active = c.value === value;
        return (
          <button
            key={c.value}
            type="button"
            role="tab"
            aria-selected={active}
            onClick={() => onChange(c.value)}
            className={cn(
              "press relative flex min-h-10 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-sm font-semibold transition-colors",
              active
                ? "text-primary-foreground"
                : "bg-secondary text-muted-foreground hover:text-foreground",
            )}
          >
            {active && <ActivePill id={pillId} className="rounded-full bg-primary" />}
            <span className="relative num">{c.label}</span>
            {c.count !== undefined && (
              <span
                className={cn(
                  "relative rounded-full px-1.5 text-[10px] num",
                  active ? "bg-primary-foreground/20" : "bg-background/60",
                )}
              >
                {c.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

/** Le stagioni XFive come chip; `allLabel` aggiunge in testa la scelta «tutte» (valore vuoto). */
export function SeasonChips({
  seasons,
  value,
  onChange,
  allLabel,
  className,
}: {
  seasons: ZoneSeason[];
  value: string;
  onChange: (season: string) => void;
  allLabel?: string;
  className?: string;
}) {
  const chips: Chip[] = [
    ...(allLabel ? [{ value: "", label: allLabel }] : []),
    ...seasons.map((s) => ({ value: s.label, label: s.label, count: s.tournaments })),
  ];
  return (
    <ChipBar
      chips={chips}
      value={value}
      onChange={onChange}
      label="Stagione"
      {...(className ? { className } : {})}
    />
  );
}
