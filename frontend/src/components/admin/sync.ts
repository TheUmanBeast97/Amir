import { useEffect, useState } from "react";
import { toast } from "sonner";
import { useSync, useSyncRuns } from "@/api/hooks";
import { toastError } from "./kit";

export function useSyncFlow() {
  const sync = useSync();
  const [polling, setPolling] = useState(false);
  const runs = useSyncRuns(polling);
  const running = runs.data?.some((r) => r.status === "running") ?? false;
  useEffect(() => {
    if (polling && runs.data && !running) {
      setPolling(false);
      const last = runs.data[0];
      if (last?.status === "ok") toast.success("Dati XFive aggiornati");
      else if (last?.status === "error") toast.error(last.error ?? "Aggiornamento non riuscito");
    }
  }, [polling, running, runs.data]);
  const start = (scope: "current" | "history") =>
    sync.mutate(scope, { onSuccess: () => { setPolling(true); toast("Aggiornamento avviato…"); }, onError: toastError });
  return { start, busy: sync.isPending || polling || running, runs };
}

