import { afterEach, describe, expect, it, vi } from "vitest";
import { IMAGE_WIDTHS, sized } from "@/lib/img";

const badge = "https://api.example.com/api/v1/public/badges/6";

describe("sized", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("in locale (nessun ottimizzatore) lascia l'immagine com'è", () => {
    expect(sized(badge, 40)).toBe(badge);
  });

  it("senza indirizzo non restituisce nulla", () => {
    vi.stubGlobal("__IMAGE_HOSTS__", ["api.example.com"]);
    expect(sized(null, 40)).toBeUndefined();
    expect(sized("", 40)).toBeUndefined();
  });

  it("sul server passa dall'ottimizzatore, con la larghezza giusta per uno schermo a doppia densità", () => {
    vi.stubGlobal("__IMAGE_HOSTS__", ["api.example.com"]);

    expect(sized(badge, 40)).toBe(`/_vercel/image?url=${encodeURIComponent(badge)}&w=96&q=75`);
    expect(sized(badge, 20)).toContain("&w=64&");
    expect(sized(badge, 100)).toContain("&w=256&");
  });

  it("non supera mai la larghezza più grande consentita e usa solo larghezze configurate", () => {
    vi.stubGlobal("__IMAGE_HOSTS__", ["api.example.com"]);

    const url = sized(badge, 2000) ?? "";
    expect(url).toContain(`&w=${IMAGE_WIDTHS[IMAGE_WIDTHS.length - 1]}&`);
    for (const shown of [10, 33, 48, 64, 96, 150, 190]) {
      const width = Number(/[?&]w=(\d+)/.exec(sized(badge, shown) ?? "")?.[1]);
      expect(IMAGE_WIDTHS).toContain(width);
    }
  });

  it("gli indirizzi di altri siti e quelli relativi restano come sono", () => {
    vi.stubGlobal("__IMAGE_HOSTS__", ["api.example.com"]);

    expect(sized("https://altro.example.org/foto.png", 40)).toBe("https://altro.example.org/foto.png");
    expect(sized("/stemma-amir.png", 40)).toBe("/stemma-amir.png");
  });
});
