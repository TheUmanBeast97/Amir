import { sportLabel } from "@/lib/zone";
import { ChipBar } from "./SeasonChips";

/** Gli sport (formati di calcio) come chip; il valore vuoto vale «tutti». */
export function SportChips({
  sports,
  value,
  onChange,
  allLabel = "Tutti",
  className,
}: {
  sports: string[];
  value: string;
  onChange: (sport: string) => void;
  allLabel?: string | null;
  className?: string;
}) {
  const chips = [
    ...(allLabel ? [{ value: "", label: allLabel }] : []),
    ...sports.map((s) => ({ value: s, label: sportLabel(s) })),
  ];
  return (
    <ChipBar
      chips={chips}
      value={value}
      onChange={onChange}
      label="Sport"
      {...(className ? { className } : {})}
    />
  );
}
