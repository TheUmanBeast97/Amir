import { useQueryClient } from "@tanstack/react-query";
import { Check, FileDown, RefreshCw, Send, X } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { ApiError } from "@/api/client";
import { useSaveSheet, useSheet } from "@/api/hooks";
import type { MatchDetail, SheetPlayer, SheetState } from "@/api/types";
import { ErrorState, InfoBanner, Skeleton } from "@/components/ui-kit";
import { fmtDay } from "@/lib/format";
import { cn } from "@/lib/utils";
import { Btn, Confirm, toastError } from "../kit";
import { XfiveConnectCard } from "../XfiveConnect";

interface Row {
  on: boolean;
  number: string;
}

const keyOf = (p: SheetPlayer) => (p.xfive_id !== null ? `x${p.xfive_id}` : `p${p.player_id}`);

/** Da dove parte il numero: se su XFive c'è già una distinta quello, altrimenti l'ultimo che abbiamo usato noi. */
const startNumber = (p: SheetPlayer) =>
  p.xfive_selected && p.number ? p.number : (p.suggested_number ?? p.number ?? "");

const initRows = (s: SheetState) =>
  new Map<string, Row>(
    s.players.map((p) => [keyOf(p), { on: p.selected, number: startNumber(p) }]),
  );

/**
 * La distinta ufficiale: si sceglie chi gioca e con quale numero, e si invia a XFive con l'account collegato.
 * I numeri si ricordano dalle distinte passate (e si vedono accanto a ogni giocatore): se non ricordi la maglia di qualcuno,
 * è qui. XFive la rende apribile solo in una finestra prima della gara; fuori da quella finestra si prepara e basta.
 */
export function SheetTab({ detail }: { detail: MatchDetail }) {
  const matchId = detail.match.id;
  const qc = useQueryClient();
  const q = useSheet(matchId);
  const save = useSaveSheet(matchId);
  const [rows, setRows] = useState<Map<string, Row>>(new Map());
  const [note, setNote] = useState("");

  // quando arriva (o si rilegge) lo stato, la scelta riparte da quello
  useEffect(() => {
    if (q.data) {
      setRows(initRows(q.data));
      setNote(q.data.note ?? "");
    }
  }, [q.dataUpdatedAt]); // eslint-disable-line react-hooks/exhaustive-deps

  if (q.isPending) return <Skeleton className="mt-3 h-64" />;
  if (q.isError)
    return (
      <div className="pt-3">
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      </div>
    );

  const s = q.data;
  const row = (p: SheetPlayer): Row => rows.get(keyOf(p)) ?? { on: false, number: "" };
  const numberOf = (p: SheetPlayer) => row(p).number.trim().toUpperCase();
  const chosen = s.players.filter((p) => row(p).on);

  const counts = new Map<string, number>();
  chosen.forEach((p) => {
    const n = numberOf(p);
    if (n) counts.set(n, (counts.get(n) ?? 0) + 1);
  });
  const repeated = (p: SheetPlayer) => {
    const n = numberOf(p);
    return row(p).on && n !== "" && (counts.get(n) ?? 0) > 1;
  };

  const problems: string[] = [];
  if (!chosen.length) problems.push("Scegli almeno un giocatore.");
  if (chosen.some((p) => p.xfive_id === null))
    problems.push("Qualcuno non è abbinato a XFive: aggiorna la rosa dalla sincronizzazione.");
  if (chosen.some((p) => !numberOf(p))) problems.push("Manca il numero di maglia a qualcuno.");
  if (chosen.some(repeated)) problems.push("Ci sono numeri di maglia ripetuti.");
  const canSend = s.connected && s.open && problems.length === 0 && !save.isPending;

  const patch = (p: SheetPlayer, v: Partial<Row>) =>
    setRows((r) => new Map(r).set(keyOf(p), { ...row(p), ...v }));

  const send = () =>
    save.mutate(
      {
        players: chosen.map((p) => ({ xfive_id: p.xfive_id as number, number: numberOf(p) })),
        note: note.trim() || null,
      },
      {
        onSuccess: (r) => toast.success(r.message ?? "Distinta inviata a XFive"),
        onError: (e) => {
          toastError(e);
          if (
            e instanceof ApiError &&
            (e.state === "not_connected" || e.state === "session_expired")
          ) {
            void qc.invalidateQueries({ queryKey: ["xfive-session"] });
            void q.refetch();
          }
        },
      },
    );

  const summary = chosen
    .map((p) => `${numberOf(p)} ${p.name}`)
    .sort((a, b) => parseInt(a, 10) - parseInt(b, 10) || a.localeCompare(b))
    .join(", ");

  return (
    <div className="space-y-3 pt-3">
      <XfiveConnectCard />

      {!s.open && s.reason && <InfoBanner>{s.reason}</InfoBanner>}
      {s.last_sent && (
        <p className="text-xs text-muted-foreground">
          Ultima distinta inviata a XFive il {fmtDay(s.last_sent.sent_at)}: {s.last_sent.players}{" "}
          giocatori.
        </p>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <span className="font-display text-3xl num">{chosen.length}</span>
        <span className="text-sm text-muted-foreground">in distinta</span>
        <div className="ml-auto flex flex-wrap gap-2">
          <Btn variant="outline" disabled={q.isFetching} onClick={() => void q.refetch()}>
            <RefreshCw className={cn("h-4 w-4", q.isFetching && "animate-spin")} /> Ricarica da
            XFive
          </Btn>
          <Btn
            variant="ghost"
            disabled={!chosen.length}
            onClick={() =>
              setRows((r) => new Map([...r].map(([k, v]) => [k, { ...v, on: false }])))
            }
          >
            <X className="h-4 w-4" /> Azzera
          </Btn>
        </div>
      </div>

      <ul className="grid gap-2 md:grid-cols-2">
        {s.players.map((p) => {
          const r = row(p);
          const dup = repeated(p);
          return (
            <li
              key={keyOf(p)}
              className={cn(
                "rounded-xl border p-2 transition-colors",
                r.on ? "border-success/60 bg-success/10" : "bg-card",
              )}
            >
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  aria-pressed={r.on}
                  aria-label={`${r.on ? "Togli" : "Metti in distinta"} ${p.name}`}
                  onClick={() => patch(p, { on: !r.on })}
                  className="flex min-h-12 min-w-0 flex-1 items-center gap-3 text-left"
                >
                  <span
                    className={cn(
                      "grid h-7 w-7 shrink-0 place-items-center rounded-full border-2",
                      r.on
                        ? "border-success bg-success text-success-foreground"
                        : "border-muted-foreground/40",
                    )}
                  >
                    {r.on && <Check className="h-4 w-4" />}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold">{p.name}</span>
                    <span className="flex flex-wrap items-center gap-1 text-[11px]">
                      {p.called_up && (
                        <span className="rounded-full bg-primary/15 px-1.5 py-0.5 font-semibold text-primary">
                          convocato
                        </span>
                      )}
                      {p.xfive_id === null && (
                        <span className="rounded-full bg-warning/20 px-1.5 py-0.5 font-semibold text-warning">
                          non abbinato a XFive
                        </span>
                      )}
                    </span>
                  </span>
                </button>
                <input
                  value={r.number}
                  maxLength={3}
                  inputMode="text"
                  placeholder="N°"
                  aria-label={`Numero di maglia di ${p.name}`}
                  aria-invalid={dup}
                  onChange={(e) => patch(p, { number: e.target.value.toUpperCase() })}
                  className={cn(
                    "min-h-11 w-16 shrink-0 rounded-lg border bg-background px-2 text-center font-display text-xl num uppercase focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                    dup ? "border-primary text-primary" : "border-input",
                  )}
                />
              </div>
              {p.history.length > 0 && (
                <div className="mt-1 flex flex-wrap items-center gap-1 pl-10 text-[11px] text-muted-foreground">
                  <span>Già usati:</span>
                  {p.history.map((h) => (
                    <button
                      key={h.number}
                      type="button"
                      title={`Usato ${h.count} ${h.count === 1 ? "volta" : "volte"}, l'ultima il ${fmtDay(h.last_used_at)}`}
                      onClick={() => patch(p, { number: h.number, on: true })}
                      className="press rounded-full border px-2 py-0.5 font-semibold text-foreground hover:bg-accent"
                    >
                      {h.number}
                      <span className="font-normal text-muted-foreground"> ×{h.count}</span>
                    </button>
                  ))}
                </div>
              )}
            </li>
          );
        })}
      </ul>

      {s.open && (
        <label className="block text-sm font-semibold">
          <span className="mb-1 block">Nota per XFive (facoltativa)</span>
          <textarea
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={500}
            rows={2}
            className="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm font-normal focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
        </label>
      )}

      {problems.length > 0 && chosen.length > 0 && (
        <ul className="space-y-0.5 text-sm text-primary" role="alert">
          {problems.map((t) => (
            <li key={t}>{t}</li>
          ))}
        </ul>
      )}

      <div className="flex flex-wrap items-center gap-2">
        <Confirm
          title="Inviare la distinta a XFive?"
          description={`${chosen.length} giocatori: ${summary}. È la distinta ufficiale della gara su XFive.`}
          confirmLabel="Invia a XFive"
          onConfirm={send}
        >
          {(open) => (
            <Btn disabled={!canSend} onClick={open}>
              <Send className="h-4 w-4" />{" "}
              {save.isPending ? "Invio in corso…" : "Rivedi e invia a XFive"}
            </Btn>
          )}
        </Confirm>
        {s.pdf_url && (
          <a
            href={s.pdf_url}
            target="_blank"
            rel="noreferrer"
            className="press inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-input bg-card px-4 text-sm font-semibold hover:bg-accent"
          >
            <FileDown className="h-4 w-4" /> PDF della distinta
          </a>
        )}
        {!s.open && (
          <span className="text-xs text-muted-foreground">
            Si invia solo quando XFive apre la distinta.
          </span>
        )}
      </div>
    </div>
  );
}
