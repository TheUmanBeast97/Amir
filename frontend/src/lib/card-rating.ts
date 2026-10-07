import type { PlayerPage, PlayerRole } from "@/api/types";

/** I quattro tipi di figurina: dal voto dipende il metallo. «Fuoco» è la speciale AMIR, nera e rossa. */
export type Tier = "bronzo" | "argento" | "oro" | "fuoco";

export interface CardStat {
  label: string;
  value: string;
}

export interface CardRating {
  /** Il voto da 40 a 95. */
  ovr: number;
  tier: Tier;
  /** Sigla del ruolo: POR, DIF, CEN, ATT. */
  pos: string;
  /** I sei numeri in basso, tutti dalle statistiche vere. */
  stats: CardStat[];
}

const POS: Partial<Record<PlayerRole, string>> = {
  portiere: "POR",
  difensore: "DIF",
  centrocampista: "CEN",
  attaccante: "ATT",
};

const clamp = (n: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, n));

/**
 * Il voto della figurina: parte da 42 e sale con i gol a partita (fino a +26), le presenze (+14), le vittorie
 * della squadra con lui in campo (da -6 a +8), le volte miglior giocatore (+1,5 l'una, fino a 8) e scende con
 * i cartellini (fino a -6). I gol a partita si correggono sul numero di partite (la media della squadra pesa come
 * 12 partite), così 9 gol in 6 partite non valgono come 9 gol in 20. Usa solo le statistiche della scheda: chi ha
 * poche partite ha un voto basso per mancanza di dati, non di valore.
 */
export function ovrOf(t: PlayerPage["totals"]): number {
  const gpm = (t.goals + 0.4 * 12) / (t.matches + 12);
  const attack = clamp(gpm / 0.9, 0, 1) * 26;
  const presence = clamp(t.matches / 100, 0, 1) * 14;
  const winning = clamp((t.win_rate - 0.35) * 30, -6, 8);
  const stars = Math.min(t.mvp, 8) * 1.5;
  const discipline = Math.min(t.red * 3 + t.yellow * 0.3, 6);
  return Math.round(clamp(42 + attack + presence + winning + stars - discipline, 40, 95));
}

export const tierOf = (ovr: number): Tier =>
  ovr >= 74 ? "fuoco" : ovr >= 64 ? "oro" : ovr >= 52 ? "argento" : "bronzo";

export const tierLabel: Record<Tier, string> = {
  bronzo: "Bronzo",
  argento: "Argento",
  oro: "Oro",
  fuoco: "Fuoco",
};

/** Tutto quello che serve a disegnare una figurina, ricavato dalla scheda del giocatore. */
export function cardRating(page: PlayerPage): CardRating {
  const t = page.totals;
  const ovr = ovrOf(t);
  const pct = Math.round(t.win_rate * 100);
  return {
    ovr,
    tier: tierOf(ovr),
    pos: (page.player.role && POS[page.player.role]) || "GIO",
    stats: [
      { label: "Presenze", value: String(t.matches) },
      { label: "Gol", value: String(t.goals) },
      { label: "Gol a partita", value: t.goals_per_match.toFixed(2).replace(".", ",") },
      { label: "Migliore", value: String(t.mvp) },
      { label: "Vittorie", value: `${pct}%` },
      { label: "Punti a partita", value: t.points_per_match.toFixed(2).replace(".", ",") },
    ],
  };
}
