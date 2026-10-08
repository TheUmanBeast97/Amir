import { removeBackground } from "@imgly/background-removal";

/**
 * Il ritaglio della foto, in un Web Worker: riceve il Blob della foto e rimanda i passi della libreria
 * (chiavi `fetch:…` mentre scarica il modello, `compute:…` mentre ritaglia) e poi il PNG senza sfondo, o l'errore.
 * Qui dentro la libreria può bloccare quanto vuole: il thread principale, e con lui l'animazione, restano liberi.
 */
export type CutoutReply =
  | { type: "progress"; key: string }
  | { type: "done"; blob: Blob }
  | { type: "error"; message: string };

const reply = (message: CutoutReply) => self.postMessage(message);

self.addEventListener("message", async (e: MessageEvent<Blob>) => {
  try {
    const blob = await removeBackground(e.data, {
      model: "isnet_fp16",
      output: { format: "image/png", quality: 1 },
      progress: (key) => reply({ type: "progress", key }),
    });
    reply({ type: "done", blob });
  } catch (err) {
    reply({ type: "error", message: err instanceof Error ? err.message : String(err) });
  }
});
