import { useEffect, useState } from "react";
import { toast } from "sonner";
import { useSync, useSyncRuns } from "@/api/hooks";
import type { SyncRun, SyncScope } from "@/api/types";
import { toastError } from "./kit";

export const syncLabel: Record<SyncScope, string> = {
  current: "Calendario",
  history: "Storico",
  details: "Partite giocate",
  media: "Stemmi e foto",
  stats: "Statistiche",
  admin: "Rosa da XFive",
  players: "Giocatori da XFive",
};

/** Quante volte al massimo si richiama un aggiornamento che dichiara di avere ancora da fare. */
const MAX_ROUNDS = 20;

/** Il messaggio finale di un aggiornamento riuscito, con i numeri che contano. */
function finished(scope: SyncScope, run: SyncRun) {
  const st = run.stats;

  if (st["disabled"]) {
    toast.warning(
      "L'accesso a XFive è spento: si accende in Impostazioni, scheda «Area amministrazione XFive».",
    );
    return;
  }

  const remaining = st["remaining"] ?? 0;
  if (remaining > 0) {
    toast.warning(`Fatto un pezzo: ne mancano ancora ${remaining}. Premi di nuovo.`);
    return;
  }

  if (scope === "players" || scope === "admin") {
    const parts: string[] = [];
    if (st["created"]) parts.push(`${st["created"]} giocatori creati`);
    if (st["new_links"]) parts.push(`${st["new_links"]} già presenti e collegati`);
    if (st["updated"]) parts.push(`${st["updated"]} aggiornati`);
    if (st["unmatched"]) parts.push(`${st["unmatched"]} su XFive senza corrispondenza`);
    toast.success(
      parts.length
        ? `Rosa da XFive: ${parts.join(", ")}`
        : "Rosa da XFive: era già tutto allineato",
    );
    return;
  }

  toast.success(
    scope === "current" || scope === "history"
      ? "Dati XFive aggiornati"
      : `${syncLabel[scope]}: aggiornato`,
  );
}

/**
 * Avvia un aggiornamento da XFive. In locale parte dopo la risposta e se ne segue lo stato a intervalli; sul server la risposta
 * contiene già l'esito, e se c'è ancora da fare ("remaining") si richiama da soli finché finisce, perché una richiesta lì dura poco.
 */
export function useSyncFlow() {
  const sync = useSync();
  const [polling, setPolling] = useState(false);
  const [working, setWorking] = useState<SyncScope | null>(null);
  const [left, setLeft] = useState<number | null>(null);
  const runs = useSyncRuns(polling);
  const running = runs.data?.some((r) => r.status === "running") ?? false;

  useEffect(() => {
    if (polling && runs.data && !running) {
      setPolling(false);
      const last = runs.data[0];
      if (last?.status === "ok") finished(last.scope, last);
      else if (last?.status === "error") toast.error(last.error ?? "Aggiornamento non riuscito");
    }
  }, [polling, running, runs.data]);

  /** Un aggiornamento, oppure una lista da fare in fila (si ferma al primo che non riesce o che è spento). */
  const start = async (scopes: SyncScope | SyncScope[]) => {
    try {
      for (const scope of Array.isArray(scopes) ? scopes : [scopes]) {
        setWorking(scope);
        let run: SyncRun = await sync.mutateAsync(scope);

        if (run.status === "running") {
          setPolling(true);
          toast("Aggiornamento avviato…");
          return;
        }

        for (
          let round = 1;
          run.status === "ok" && (run.stats["remaining"] ?? 0) > 0 && round < MAX_ROUNDS;
          round++
        ) {
          setLeft(run.stats["remaining"] ?? 0);
          run = await sync.mutateAsync(scope);
        }

        if (run.status === "error") {
          toast.error(run.error ?? "Aggiornamento non riuscito");
          return;
        }
        finished(scope, run);
        if (run.stats["disabled"]) return;
      }
    } catch (e) {
      toastError(e);
    } finally {
      setWorking(null);
      setLeft(null);
    }
  };

  return { start, busy: working !== null || polling || running, working, left, runs };
}
