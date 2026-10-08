/**
 * Stemmi e foto sono salvati grandi (fino a 500 px, anche 400 KB) perché servono pure alle grafiche da esportare, ma nelle pagine
 * si vedono a 40-200 px. Sul server di Vercel passano dall'ottimizzatore di immagini: stessa figura, a misura, in WebP, dalla rete
 * più vicina a chi guarda (da centinaia di KB a pochi). In locale l'ottimizzatore non c'è e si usa l'originale.
 *
 * Gli indirizzi che l'ottimizzatore accetta sono decisi alla costruzione del sito (vite.config.ts, `__IMAGE_HOSTS__`).
 */
declare const __IMAGE_HOSTS__: string[] | undefined;

/** Le larghezze configurate in vite.config.ts per l'ottimizzatore: si sceglie la più piccola che regge uno schermo a doppia densità. */
export const IMAGE_WIDTHS = [64, 96, 128, 192, 256, 384];

/** L'indirizzo da usare per mostrare l'immagine a `shownPx` pixel di larghezza. */
export function sized(url: string | null | undefined, shownPx: number): string | undefined {
  if (!url) return undefined;

  const hosts = typeof __IMAGE_HOSTS__ === "undefined" ? [] : __IMAGE_HOSTS__;
  if (hosts.length === 0) return url;

  try {
    if (!hosts.includes(new URL(url).hostname)) return url;
  } catch {
    return url; // indirizzo relativo o non valido: così com'è
  }

  const width = IMAGE_WIDTHS.find((w) => w >= shownPx * 2) ?? IMAGE_WIDTHS[IMAGE_WIDTHS.length - 1];

  return `/_vercel/image?url=${encodeURIComponent(url)}&w=${width}&q=75`;
}
