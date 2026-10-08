import { api, TOKEN_KEY } from "@/api/client";

/**
 * La sagoma del giocatore senza sfondo, per la figurina. Si ritaglia nel browser dello staff la prima volta che apre la
 * figurina (la libreria scarica una volta un modello di qualche decina di MB, poi resta in cache) e si salva sul server:
 * da lì in poi la vedono tutti già pronta. Chi non è dello staff non ritaglia mai: usa la sagoma se c'è, altrimenti la foto.
 */
export const canCutout = () => typeof window !== "undefined" && !!localStorage.getItem(TOKEN_KEY);

/** Sotto questa larghezza il ritaglio viene troppo grezzo: meglio tenere la foto intera. */
const MIN_SIDE = 120;

export type CutoutProgress = "scarico il modello" | "ritaglio" | "salvo";

/**
 * Ritaglia la foto e la salva per il giocatore. Restituisce l'indirizzo locale della sagoma (da mostrare subito) oppure
 * null se la foto non si può leggere (altro sito senza permesso), è troppo piccola o il ritaglio non riesce.
 */
export async function makeCutout(
  playerId: number,
  photoUrl: string,
  onProgress: (step: CutoutProgress) => void,
): Promise<string | null> {
  let source: Blob;
  try {
    const res = await fetch(photoUrl, { mode: "cors" });
    if (!res.ok) return null;
    source = await res.blob();
  } catch {
    return null;
  }

  const size = await imageSize(source);
  if (!size || Math.min(size.width, size.height) < MIN_SIDE) return null;

  onProgress("scarico il modello");
  const { removeBackground } = await import("@imgly/background-removal");
  let cut: Blob;
  try {
    cut = await removeBackground(source, {
      model: "isnet_fp16",
      proxyToWorker: true, // il calcolo gira in un thread a parte: la pagina resta reattiva
      output: { format: "image/png", quality: 1 },
      progress: (key) => {
        if (key.startsWith("compute")) onProgress("ritaglio");
      },
    });
  } catch {
    return null;
  }

  onProgress("salvo");
  try {
    await api.uploadCutout(playerId, new File([cut], `${playerId}.png`, { type: "image/png" }));
  } catch {
    return null;
  }

  return URL.createObjectURL(cut);
}

function imageSize(blob: Blob): Promise<{ width: number; height: number } | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(blob);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      resolve({ width: img.naturalWidth, height: img.naturalHeight });
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };
    img.src = url;
  });
}
