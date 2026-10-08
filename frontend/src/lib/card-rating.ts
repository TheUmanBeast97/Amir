import type { CardPart, CardScore, PlayerPage, PlayerRole } from "@/api/types";

/** Le cinque fasce della figurina, dal voto: 80-83, 84-87, 88-91, 92-95, 96-99. «Fuoco» è la speciale AMIR, nera e rossa. */
export type Tier = "bronzo" | "argento" | "oro" | "platino" | "fuoco";

export const TIERS: Tier[] = ["bronzo", "argento", "oro", "platino", "fuoco"];

export interface CardStat {
  label: string;
  value: string;
}

export interface CardRating {
  /** Il voto da 80 a 99, calcolato dal server con pesi diversi per ruolo (vedi `page.card`). */
  ovr: number;
  tier: Tier;
  /** Sigla del ruolo: POR, DIF, CEN, ATT. */
  pos: string;
  /** I sei numeri in basso, tutti dalle statistiche vere; cambiano con il ruolo. */
  stats: CardStat[];
}

const POS: Partial<Record<PlayerRole, string>> = {
  portiere: "POR",
  difensore: "DIF",
  centrocampista: "CEN",
  attaccante: "ATT",
};

export const tierOf = (ovr: number): Tier =>
  ovr >= 96
    ? "fuoco"
    : ovr >= 92
      ? "platino"
      : ovr >= 88
        ? "oro"
        : ovr >= 84
          ? "argento"
          : "bronzo";

export const tierLabel: Record<Tier, string> = {
  bronzo: "Bronzo",
  argento: "Argento",
  oro: "Oro",
  platino: "Platino",
  fuoco: "Fuoco",
};

/** Intervallo di voto di una fascia, per la legenda. */
export const tierRange: Record<Tier, string> = {
  bronzo: "80-83",
  argento: "84-87",
  oro: "88-91",
  platino: "92-95",
  fuoco: "96-99",
};

const it = (n: number, digits = 2) => n.toFixed(digits).replace(".", ",");

/** Tutto quello che serve a disegnare una figurina, ricavato dalla scheda del giocatore. */
export function cardRating(page: PlayerPage): CardRating {
  const t = page.totals;
  const role = page.player.role;
  const defensive = role === "portiere" || role === "difensore";
  const pct = Math.round(t.win_rate * 100);
  return {
    ovr: page.card.ovr,
    tier: tierOf(page.card.ovr),
    pos: (role && POS[role]) || "GIO",
    stats: [
      { label: "Presenze", value: String(t.matches) },
      role === "portiere"
        ? { label: "Porte inviolate", value: String(t.clean_sheets) }
        : { label: "Gol", value: String(t.goals) },
      defensive
        ? { label: "Subiti a partita", value: it(t.conceded_per_match) }
        : { label: "Gol a partita", value: it(t.goals_per_match) },
      { label: "Migliore", value: String(t.mvp) },
      { label: "Vittorie", value: `${pct}%` },
      { label: "Punti a partita", value: it(t.points_per_match) },
    ],
  };
}

/** Una voce della scomposizione, scritta per il pannello «i». */
export interface ExplainedPart {
  key: string;
  label: string;
  /** Il numero di partenza, in parole: «0,9 a partita (squadra 1,6)». */
  value: string;
  /** Quanto rende da 0 a 100 su quella voce. */
  score: number;
  /** Il peso del ruolo, in percento. */
  weight: number;
  /** I punti portati al voto. */
  points: number;
}

const describe = (p: CardPart): string => {
  switch (p.unit) {
    case "per_match":
      return p.key === "conceded"
        ? `${it(p.value)} a partita (squadra ${it(p.reference ?? 0)})`
        : `${it(p.value)} a partita (pieno a ${it(p.reference ?? 0, 1)})`;
    case "share":
      return `${Math.round(p.value * 100)}% (pieno al ${Math.round((p.reference ?? 1) * 100)}%)`;
    default:
      return `${p.value} (pieno a ${p.reference ?? ""})`;
  }
};

/** La scomposizione del voto pronta da mostrare, più le righe speciali (cartellini e poche partite). */
export function explain(card: CardScore): {
  parts: ExplainedPart[];
  penalty: { label: string; value: string; points: number } | null;
  confidence: { label: string; value: string } | null;
  roleLabel: string;
} {
  const parts = card.parts.map((p) => ({
    key: p.key,
    label: p.label,
    value: describe(p),
    score: Math.round(p.score * 100),
    weight: Math.round(p.weight * 100),
    points: p.points,
  }));
  const pen = card.penalty;
  const penalty =
    pen.yellow + pen.red > 0
      ? {
          label: "Cartellini",
          value: `${pen.yellow} gialli, ${pen.red} rossi (tolgono fino al ${Math.round(pen.max_weight * 100)}%)`,
          points: -pen.points,
        }
      : null;
  const confidence =
    card.confidence < 1
      ? {
          label: "Poche partite",
          value: `${card.matches} su 10: il voto vale il ${Math.round(card.confidence * 100)}% di quello pieno`,
        }
      : null;
  const roleLabel =
    card.role === "portiere"
      ? "Portiere: conta soprattutto quanto si subisce"
      : card.role === "difensore"
        ? "Difensore: pesano i gol subiti e le vittorie"
        : card.role === "attaccante"
          ? "Attaccante: pesano i gol fatti"
          : "Centrocampista: gol, vittorie e premi";
  return { parts, penalty, confidence, roleLabel };
}
