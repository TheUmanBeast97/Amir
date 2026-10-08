import { api, TOKEN_KEY } from "@/api/client";
import type { CutoutReply } from "./cutout.worker";

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
  let cut: Blob;
  try {
    cut = await cutInWorker(source, (key) => {
      if (key.startsWith("compute")) onProgress("ritaglio");
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

/**
 * Il ritaglio gira in un Web Worker nostro (`cutout.worker.ts`): sul thread principale la libreria bloccherebbe la pagina
 * per tutta la durata del calcolo, animazione compresa. Il suo `proxyToWorker` non basta: nella 1.7.0 vale solo quando
 * il modello gira su WebGPU (`device: "gpu"` e browser che lo supporta), altrimenti è ignorato e tutto resta sul thread principale.
 * Risolve con il PNG senza sfondo; rifiuta se la libreria fallisce o il worker non parte.
 */
function cutInWorker(source: Blob, onKey: (key: string) => void): Promise<Blob> {
  return new Promise((resolve, reject) => {
    const worker = new Worker(new URL("./cutout.worker.ts", import.meta.url), { type: "module" });
    worker.onmessage = (e: MessageEvent<CutoutReply>) => {
      const m = e.data;
      if (m.type === "progress") {
        onKey(m.key);
        return;
      }
      worker.terminate();
      if (m.type === "done") resolve(m.blob);
      else reject(new Error(m.message));
    };
    worker.onerror = (e) => {
      worker.terminate();
      reject(new Error(e.message));
    };
    worker.postMessage(source);
  });
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
