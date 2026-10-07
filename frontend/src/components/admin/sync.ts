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
};

/** Quante volte al massimo si richiama un aggiornamento che dichiara di avere ancora da fare. */
const MAX_ROUNDS = 20;

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
      if (last?.status === "ok") {
        const remaining = last.stats["remaining"] ?? 0;
        toast.success(remaining > 0 ? `Fatto un pezzo: ne mancano ancora ${remaining}. Premi di nuovo.` : "Dati XFive aggiornati");
      } else if (last?.status === "error") toast.error(last.error ?? "Aggiornamento non riuscito");
    }
  }, [polling, running, runs.data]);

  const start = async (scope: SyncScope) => {
    setWorking(scope);
    try {
      let run: SyncRun = await sync.mutateAsync(scope);

      if (run.status === "running") {
        setPolling(true);
        toast("Aggiornamento avviato…");
        return;
      }

      for (let round = 1; run.status === "ok" && (run.stats["remaining"] ?? 0) > 0 && round < MAX_ROUNDS; round++) {
        setLeft(run.stats["remaining"] ?? 0);
        run = await sync.mutateAsync(scope);
      }

      if (run.status === "error") toast.error(run.error ?? "Aggiornamento non riuscito");
      else if ((run.stats["remaining"] ?? 0) > 0) toast.warning(`Fatto un pezzo: ne mancano ancora ${run.stats["remaining"]}. Premi di nuovo.`);
      else toast.success(`${syncLabel[scope]}: aggiornato`);
    } catch (e) {
      toastError(e);
    } finally {
      setWorking(null);
      setLeft(null);
    }
  };

  return { start, busy: working !== null || polling || running, working, left, runs };
}
