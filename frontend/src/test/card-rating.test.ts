import { describe, expect, it } from "vitest";
import type { CardScore, PlayerPage } from "@/api/types";
import { cardRating, explain, tierOf, tierRange, TIERS } from "@/lib/card-rating";

const card = (over: Partial<CardScore> = {}): CardScore => ({
  ovr: 88,
  tier: "oro",
  role: "portiere",
  matches: 40,
  confidence: 1,
  parts: [
    {
      key: "conceded",
      label: "Gol subiti a partita",
      value: 0.9,
      reference: 1.6,
      unit: "per_match",
      score: 0.83,
      weight: 0.4,
      points: 7.9,
    },
    {
      key: "clean_sheets",
      label: "Porta inviolata",
      value: 0.33,
      reference: 0.5,
      unit: "share",
      score: 0.66,
      weight: 0.2,
      points: 3.2,
    },
    {
      key: "presence",
      label: "Presenze",
      value: 40,
      reference: 100,
      unit: "count",
      score: 0.4,
      weight: 0.15,
      points: 1.4,
    },
  ],
  penalty: { yellow: 0, red: 0, max_weight: 0.05, points: 0 },
  ...over,
});

const page = (role: PlayerPage["player"]["role"], c: CardScore): PlayerPage =>
  ({
    player: { role, full_name: "Gigi Guanti" },
    totals: {
      matches: 40,
      goals: 2,
      goals_per_match: 0.05,
      conceded: 36,
      conceded_per_match: 0.9,
      clean_sheets: 13,
      mvp: 3,
      win_rate: 0.6,
      points_per_match: 1.95,
    },
    card: c,
  }) as unknown as PlayerPage;

describe("fasce", () => {
  it("cinque fasce di quattro punti da 80 a 99, con i confini giusti", () => {
    expect([80, 83, 84, 87, 88, 91, 92, 95, 96, 99].map(tierOf)).toEqual([
      "bronzo",
      "bronzo",
      "argento",
      "argento",
      "oro",
      "oro",
      "platino",
      "platino",
      "fuoco",
      "fuoco",
    ]);
    expect(TIERS).toHaveLength(5);
    expect(tierRange.bronzo).toBe("80-83");
    expect(tierRange.platino).toBe("92-95");
  });
});

describe("cardRating", () => {
  it("prende il voto dal server e mostra a un portiere porte inviolate e gol subiti", () => {
    const r = cardRating(page("portiere", card()));
    expect(r.ovr).toBe(88);
    expect(r.tier).toBe("oro");
    expect(r.pos).toBe("POR");
    expect(r.stats.map((s) => s.label)).toEqual([
      "Presenze",
      "Porte inviolate",
      "Subiti a partita",
      "Migliore",
      "Vittorie",
      "Punti a partita",
    ]);
    expect(r.stats[2]?.value).toBe("0,90");
  });

  it("a un attaccante mostra gol e gol a partita, e senza ruolo la sigla è GIO", () => {
    const r = cardRating(page("attaccante", card({ ovr: 96, role: "attaccante" })));
    expect(r.tier).toBe("fuoco");
    expect(r.stats[1]?.label).toBe("Gol");
    expect(r.stats[2]?.label).toBe("Gol a partita");
    expect(cardRating(page(null, card())).pos).toBe("GIO");
  });
});

describe("explain", () => {
  it("scrive ogni voce con numero di partenza, resa, peso e punti", () => {
    const e = explain(card());
    expect(e.roleLabel).toContain("Portiere");
    expect(e.parts[0]).toEqual({
      key: "conceded",
      label: "Gol subiti a partita",
      value: "0,90 a partita (squadra 1,60)",
      score: 83,
      weight: 40,
      points: 7.9,
    });
    expect(e.parts[1]?.value).toBe("33% (pieno al 50%)");
    expect(e.parts[2]?.value).toBe("40 (pieno a 100)");
    expect(e.penalty).toBeNull();
    expect(e.confidence).toBeNull();
  });

  it("dice dei cartellini e delle poche partite solo quando contano", () => {
    const e = explain(
      card({
        matches: 4,
        confidence: 0.4,
        penalty: { yellow: 3, red: 1, max_weight: 0.05, points: 0.5 },
      }),
    );
    expect(e.penalty).toEqual({
      label: "Cartellini",
      value: "3 gialli, 1 rossi (tolgono fino al 5%)",
      points: -0.5,
    });
    expect(e.confidence?.value).toBe("4 su 10: il voto vale il 40% di quello pieno");
  });
});
