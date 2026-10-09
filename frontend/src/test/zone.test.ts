import { describe, expect, it } from "vitest";
import type { ZoneMatch } from "@/api/zone-types";
import {
  addDays,
  clubInitials,
  kickoffLabel,
  normalize,
  outcomeFor,
  seasonShort,
  sportLabel,
} from "@/lib/zone";

const match = (over: Partial<ZoneMatch> = {}): ZoneMatch => ({
  id: 1,
  tournament: { id: 187, name: "CITTADELLA", season: "2026/2027", sport: "Calcio a 8", format: 8 },
  round: 1,
  round_label: "1ª giornata",
  home: { id: 159, name: "AMIR COSTRUZIONI", badge_url: null },
  away: { id: 351, name: "CAFFÈ KM0", badge_url: null },
  kickoff_at: "2026-10-12T19:00:00Z",
  kickoff_raw: "lun 12/10 21:00",
  venue: null,
  home_score: 3,
  away_score: 1,
  played: true,
  has_report: true,
  ...over,
});

describe("etichette", () => {
  it("lo sport perde il genere maschile ma tiene gli altri", () => {
    expect(sportLabel("Calcio a 8 - Maschile")).toBe("Calcio a 8");
    expect(sportLabel("Calcio a 7 Over - Maschile")).toBe("Calcio a 7 Over");
    expect(sportLabel("Calcio a 5 - Femminile")).toBe("Calcio a 5 · Femminile");
    expect(sportLabel("Calcio a 11")).toBe("Calcio a 11");
  });

  it("la stagione si accorcia solo se ha la forma 20AA/20BB", () => {
    expect(seasonShort("2026/2027")).toBe("26/27");
    expect(seasonShort("Estate 2026")).toBe("Estate 2026");
  });

  it("le iniziali del club ignorano simboli e numeri attaccati", () => {
    expect(clubInitials("CAFFÈ KM0-PALESTRA MEETING")).toBe("CK");
    expect(clubInitials("#NONMOLLARE")).toBe("N");
    expect(clubInitials("F.C. ROMANIA")).toBe("FC");
  });

  it("la ricerca non guarda accenti né maiuscole", () => {
    expect(normalize("CAFFÈ  KM0")).toBe("caffe km0");
    expect(normalize("  Pôzzolo ")).toBe("pozzolo");
  });
});

describe("partite", () => {
  it("l'orario usa la data vera, poi il testo di XFive, poi «da definire»", () => {
    expect(kickoffLabel(match())).toContain("21:00");
    expect(kickoffLabel(match({ kickoff_at: null }))).toBe("lun 12/10 21:00");
    expect(kickoffLabel(match({ kickoff_at: null, kickoff_raw: null }))).toBe("Data da definire");
  });

  it("l'esito dipende da chi guarda", () => {
    expect(outcomeFor(match(), 159)).toBe("W");
    expect(outcomeFor(match(), 351)).toBe("L");
    expect(outcomeFor(match({ home_score: 2, away_score: 2 }), 159)).toBe("D");
    expect(
      outcomeFor(match({ played: false, home_score: null, away_score: null }), 159),
    ).toBeNull();
    expect(outcomeFor(match(), 999)).toBeNull();
    expect(outcomeFor(match(), null)).toBeNull();
  });

  it("i giorni si spostano anche a cavallo del mese e dell'anno", () => {
    expect(addDays("2026-10-30", 7)).toBe("2026-11-06");
    expect(addDays("2027-01-03", -7)).toBe("2026-12-27");
  });
});
