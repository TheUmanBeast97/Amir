import { describe, expect, it } from "vitest";
import { AREAS, areaOf, ZONE_NAV } from "@/lib/area";

describe("areaOf", () => {
  it("il sito di oggi (home, rosa, link giocatore) è Amir Hub", () => {
    expect(areaOf("/")).toBe("hub");
    expect(areaOf("/rosa/3")).toBe("hub");
    expect(areaOf("/p/abc")).toBe("hub");
  });

  it("tutto ciò che sta sotto /mixed-zone è la Mixed Zone", () => {
    expect(areaOf("/mixed-zone")).toBe("mixed");
    expect(areaOf("/mixed-zone/tornei/187")).toBe("mixed");
  });

  it("l'area amministrativa, login compreso, è la Staff Area", () => {
    expect(areaOf("/admin/login")).toBe("staff");
    expect(areaOf("/admin")).toBe("staff");
  });

  it("un indirizzo che somiglia soltanto non cambia area", () => {
    expect(areaOf("/mixed-zonex")).toBe("hub");
    expect(areaOf("/administrator")).toBe("hub");
  });
});

describe("AREAS e ZONE_NAV", () => {
  it("ogni area ha il suo ingresso e il suo colore", () => {
    expect(AREAS.mixed.entry).toBe("/mixed-zone");
    expect(AREAS.hub.entry).toBe("/");
    expect(AREAS.staff.entry).toBe("/admin");
    expect(AREAS.mixed.accent).toBe("#22d3ee");
    expect(AREAS.hub.preview).toHaveLength(3);
  });

  it("il menu della Mixed Zone ha sei voci, la prima è la home", () => {
    expect(ZONE_NAV).toHaveLength(6);
    expect(ZONE_NAV[0].to).toBe("/mixed-zone");
    expect(ZONE_NAV.map((v) => v.label)).toEqual([
      "Home",
      "Tornei",
      "Squadre",
      "Giocatori",
      "Partite",
      "Statistiche",
    ]);
  });
});
