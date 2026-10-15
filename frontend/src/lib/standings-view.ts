import { useCallback, useEffect, useState } from "react";

/** Come si vede la classifica: compatta (Pt G V N P) o estesa (con anche gol fatti, subiti e differenza reti). */
export type StandingsView = "compact" | "extended";

const KEY = "amir_standings_view";

const read = (): StandingsView => {
  try {
    return localStorage.getItem(KEY) === "extended" ? "extended" : "compact";
  } catch {
    return "compact";
  }
};

/**
 * La vista scelta dal visitatore, ricordata nel browser. Parte sempre da «compatta» al primo disegno (uguale lato server)
 * e poi legge la scelta salvata, così non si sfasa il rendering.
 */
export function useStandingsView(): [StandingsView, (v: StandingsView) => void] {
  const [view, setView] = useState<StandingsView>("compact");
  useEffect(() => setView(read()), []);
  const choose = useCallback((v: StandingsView) => {
    setView(v);
    try {
      localStorage.setItem(KEY, v);
    } catch {
      /* la memoria del browser può essere bloccata: la scelta vale solo per questa visita */
    }
  }, []);
  return [view, choose];
}
