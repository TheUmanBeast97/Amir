import { useQuery } from "@tanstack/react-query";
import { useCallback, useEffect, useRef, useState } from "react";
import { toast } from "sonner";
import { api } from "@/api/client";
import { useSync, useSyncRuns } from "@/api/hooks";
import type { SyncProgress, SyncRun, SyncScope } from "@/api/types";
import { toastError } from "./kit";

export const syncLabel: Record<SyncScope, string> = {
  current: "Calendario",
  history: "Storico",
  details: "Partite giocate",
  media: "Stemmi e foto",
  stats: "Statistiche",
  roster: "Profili e foto della rosa",
  admin: "Rosa da XFive",
  players: "Giocatori da XFive",
  "zone-tournaments": "Tornei XFive",
  "zone-calendar": "Calendari e risultati",
  "zone-standings": "Classifiche",
  "zone-stats": "Statistiche tornei",
  "zone-teams": "Squadre e rose",
  "zone-reports": "Referti",
  "zone-players": "Profili giocatori",
  zone: "Mixed Zone (tutto)",
};

/** Gli scope della Mixed Zone (tabelle xf_*): lavorano in ciano, gli altri in rosso AMIR. */
export const isZoneScope = (scope: SyncScope | string | null | undefined) =>
  typeof scope === "string" && scope.startsWith("zone");

/** Quante volte al massimo si richiama un aggiornamento che dichiara di avere ancora da fare. */
const MAX_ROUNDS = 20;

/** Quante righe tiene il registro della sala di controllo. */
const LOG_LINES = 20;

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** I numeri che contano di un pezzo di Mixed Zone, in italiano e senza gli zeri. */
function zoneSummary(scope: SyncScope, st: Record<string, number>): string {
  const n = (k: string) => st[k] ?? 0;
  const parts: string[] = [];
  const push = (k: string, one: string, many: string) =>
    n(k) > 0 && parts.push(plural(n(k), one, many));

  switch (scope) {
    case "zone-tournaments":
      push("lists", "elenco letto", "elenchi letti");
      push("tournaments", "torneo nuovo", "tornei nuovi");
      push("updated", "aggiornato", "aggiornati");
      push("skipped", "pagina scartata", "pagine scartate");
      break;
    case "zone-calendar":
      push("tournaments", "torneo", "tornei");
      push("matches", "partita", "partite");
      push("clubs", "club nuovo", "club nuovi");
      break;
    case "zone-standings":
      push("tournaments", "torneo", "tornei");
      push("standings", "classifica", "classifiche");
      push("groups", "girone", "gironi");
      break;
    case "zone-stats":
      push("tournaments", "torneo", "tornei");
      push("stats", "tabella", "tabelle");
      break;
    case "zone-teams":
      push("tournaments", "torneo", "tornei");
      push("teams", "squadra", "squadre");
      push("roster", "giocatore in rosa", "giocatori in rosa");
      push("clubs", "club nuovo", "club nuovi");
      push("linked", "profilo collegato", "profili collegati");
      break;
    case "zone-reports":
      push("reports", "referto", "referti");
      push("lineup", "riga di distinta", "righe di distinta");
      push("skipped", "partita senza referto", "partite senza referto");
      break;
    case "zone-players":
      push("players", "profilo riletto", "profili riletti");
      break;
    default:
      push("sections", "sezione", "sezioni");
      push("tournaments", "torneo", "tornei");
      push("matches", "partita", "partite");
      push("standings", "classifica", "classifiche");
      push("reports", "referto", "referti");
      push("players", "profilo", "profili");
  }
  if (n("missing") > 0) parts.push(`${n("missing")} non trovati su XFive`);
  if (n("errors") > 0) parts.push(plural(n("errors"), "errore", "errori"));
  return parts.length ? parts.join(", ") : "era già tutto aggiornato";
}

/** La frase finale di un aggiornamento riuscito, con i numeri che contano (anche per il registro e il riepilogo). */
export function finishedText(scope: SyncScope, st: Record<string, number>): string {
  if (isZoneScope(scope)) return `${syncLabel[scope]}: ${zoneSummary(scope, st)}`;

  if (scope === "players" || scope === "admin") {
    const parts: string[] = [];
    if (st["created"]) parts.push(`${st["created"]} giocatori creati`);
    if (st["new_links"]) parts.push(`${st["new_links"]} già presenti e collegati`);
    if (st["updated"]) parts.push(`${st["updated"]} aggiornati`);
    if (st["unmatched"]) parts.push(`${st["unmatched"]} su XFive senza corrispondenza`);
    return parts.length
      ? `Rosa da XFive: ${parts.join(", ")}`
      : "Rosa da XFive: era già tutto allineato";
  }

  if (scope === "roster") {
    const parts: string[] = [];
    if (st["players"]) parts.push(`${st["players"]} giocatori riletti`);
    if (st["photos"]) parts.push(`${st["photos"]} foto nuove`);
    if (st["not_found"]) parts.push(`${st["not_found"]} non trovati su XFive`);
    if (st["ambiguous"]) parts.push(`${st["ambiguous"]} con più profili possibili`);
    return parts.length
      ? `Rosa riletta: ${parts.join(", ")}`
      : "Rosa riletta: nessun giocatore attivo";
  }

  if (scope === "details") {
    const parts: string[] = [];
    if (st["games"]) parts.push(plural(st["games"], "referto letto", "referti letti"));
    if (st["rows"]) parts.push(plural(st["rows"], "riga di distinta", "righe di distinta"));
    return `Partite giocate: ${parts.length ? parts.join(", ") : "niente di nuovo"}`;
  }

  if (scope === "media") {
    const parts: string[] = [];
    if (st["badges"]) parts.push(plural(st["badges"], "stemma", "stemmi"));
    if (st["photos"]) parts.push(plural(st["photos"], "foto", "foto"));
    return `Stemmi e foto: ${parts.length ? `${parts.join(" e ")} scaricati` : "niente di nuovo"}`;
  }

  return scope === "current" || scope === "history"
    ? "Dati XFive aggiornati"
    : `${syncLabel[scope]}: aggiornato`;
}

/** Il messaggio finale di un aggiornamento riuscito, a schermo. */
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

  toast.success(finishedText(scope, st));
}

/** L'esito di uno scope dentro un giro di aggiornamenti, per il riepilogo finale. */
export interface SyncOutcome {
  scope: SyncScope;
  status: "ok" | "error" | "skipped";
  stats: Record<string, number>;
  error: string | null;
  /** Secondi impiegati da questo scope (tutti i pezzi). */
  seconds: number;
}

/** Il riepilogo di un giro: cosa è stato fatto, in quanto tempo. */
export interface SyncReport {
  outcomes: SyncOutcome[];
  startedAt: number;
  finishedAt: number;
}

const now = () => (typeof performance === "undefined" ? Date.now() : performance.now());

/** Quanto c'è nella Mixed Zone (conteggi delle tabelle e sezioni), per le carte del centro di sincronizzazione. */
export const useZoneStatus = () =>
  useQuery({ queryKey: ["zone-status"], queryFn: () => api.getZoneStatus() });

/**
 * Avvia un aggiornamento da XFive. In locale parte dopo la risposta e se ne segue lo stato a intervalli; sul server la risposta
 * contiene già l'esito, e se c'è ancora da fare ("remaining") si richiama da soli finché finisce, perché una richiesta lì dura poco.
 * In entrambi i casi ogni risposta porta `progress` (l'ultimo passo, con il messaggio vero del backend): finisce in `progress`
 * e nel `log` (le ultime righe), che la sala di controllo mostra dal vivo.
 */
export function useSyncFlow() {
  const sync = useSync();
  const [polling, setPolling] = useState(false);
  const [working, setWorking] = useState<SyncScope | null>(null);
  const [queue, setQueue] = useState<SyncScope[]>([]);
  const [left, setLeft] = useState<number | null>(null);
  const [progress, setProgress] = useState<SyncProgress | null>(null);
  const [log, setLog] = useState<string[]>([]);
  const [report, setReport] = useState<SyncReport | null>(null);
  const runs = useSyncRuns(polling);
  const running = runs.data?.some((r) => r.status === "running") ?? false;
  /** Chi, dentro `start`, sta aspettando che il polling veda la corsa finita. */
  const waiting = useRef<{ id: number; resolve: (run: SyncRun | undefined) => void } | null>(null);
  /** Da quando si segue la corsa: le liste lette prima non contano (potrebbero non averla ancora). */
  const pollSince = useRef(0);
  const lastLine = useRef<string | null>(null);

  const note = useCallback((line: string) => {
    if (!line || line === lastLine.current) return;
    lastLine.current = line;
    setLog((l) => [...l, line].slice(-LOG_LINES));
  }, []);

  const follow = useCallback(
    (p: SyncProgress | null | undefined) => {
      if (!p) return;
      setProgress(p);
      note(p.message);
    },
    [note],
  );

  // in locale il lavoro va avanti dopo la risposta: l'avanzamento si legge dalla corsa "in corso" a ogni giro di polling
  useEffect(() => {
    if (!polling || !runs.data || runs.dataUpdatedAt < pollSince.current) return;
    const current = runs.data.find((r) => r.status === "running");
    if (current) {
      follow(current.progress);
      return;
    }
    setPolling(false);
    // la lista arriva dalla più recente: la nostra corsa è quella con lo stesso id (o la prima)
    const mine = waiting.current ? runs.data.find((r) => r.id === waiting.current?.id) : undefined;
    const last = mine ?? runs.data[0];
    follow(last?.progress);
    if (waiting.current) {
      waiting.current.resolve(last);
      waiting.current = null;
    } else if (last?.status === "ok") finished(last.scope, last);
    else if (last?.status === "error") toast.error(last.error ?? "Aggiornamento non riuscito");
  }, [polling, runs.data, runs.dataUpdatedAt, follow]);

  /** Una chiamata al backend; se parte dopo la risposta, aspetta che il polling la veda finita. */
  const call = useCallback(
    async (scope: SyncScope): Promise<SyncRun> => {
      const run = await sync.mutateAsync(scope);
      follow(run.progress);
      if (run.status !== "running") return run;
      pollSince.current = Date.now();
      setPolling(true);
      const done = await new Promise<SyncRun | undefined>((resolve) => {
        waiting.current = { id: run.id, resolve };
      });
      return done ?? run;
    },
    [sync, follow],
  );

  /** Un aggiornamento, oppure una lista da fare in fila (si ferma al primo che non riesce o che è spento). */
  const start = async (scopes: SyncScope | SyncScope[]) => {
    const list = Array.isArray(scopes) ? scopes : [scopes];
    const outcomes: SyncOutcome[] = [];
    const startedAt = now();
    setReport(null);
    setLog([]);
    lastLine.current = null;
    setProgress(null);
    setQueue(list);
    let current: SyncScope | undefined;
    try {
      for (const [i, scope] of list.entries()) {
        current = scope;
        setWorking(scope);
        setQueue(list.slice(i + 1));
        setProgress({ section: scope, message: `Avvio: ${syncLabel[scope]}`, done: 0, total: 0 });
        note(`Avvio: ${syncLabel[scope]}`);
        const t0 = now();
        let run = await call(scope);

        for (
          let round = 1;
          run.status === "ok" && (run.stats["remaining"] ?? 0) > 0 && round < MAX_ROUNDS;
          round++
        ) {
          setLeft(run.stats["remaining"] ?? 0);
          note(`${syncLabel[scope]}: fatto un pezzo, ne mancano ancora ${run.stats["remaining"]}`);
          run = await call(scope);
        }
        setLeft(null);
        const seconds = (now() - t0) / 1000;

        if (run.status === "error") {
          toast.error(run.error ?? "Aggiornamento non riuscito");
          note(`${syncLabel[scope]}: errore, ${run.error ?? "aggiornamento non riuscito"}`);
          outcomes.push({ scope, status: "error", stats: run.stats, error: run.error, seconds });
          break;
        }
        finished(scope, run);
        note(finishedText(scope, run.stats));
        outcomes.push({ scope, status: "ok", stats: run.stats, error: null, seconds });
        if (run.stats["disabled"]) break;
      }
    } catch (e) {
      toastError(e);
      const scope = current ?? list[0];
      if (scope)
        outcomes.push({
          scope,
          status: "error",
          stats: {},
          error: e instanceof Error ? e.message : "Aggiornamento non riuscito",
          seconds: 0,
        });
    } finally {
      for (const scope of list.slice(outcomes.length))
        outcomes.push({ scope, status: "skipped", stats: {}, error: null, seconds: 0 });
      setReport({ outcomes, startedAt, finishedAt: now() });
      setWorking(null);
      setQueue([]);
      setLeft(null);
      setProgress(null);
    }
  };

  return {
    start,
    busy: working !== null || polling || running,
    working,
    /** Gli scope ancora da fare dopo quello in corso. */
    queue,
    left,
    progress,
    log,
    report,
    dismissReport: () => setReport(null),
    runs,
  };
}
