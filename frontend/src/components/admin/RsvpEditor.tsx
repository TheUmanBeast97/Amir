import { MessageCircle } from "lucide-react";
import { useState } from "react";
import { useRemind, useSetEventResponse } from "@/api/hooks";
import type { EventResponse, Id, Rsvp } from "@/api/types";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { cn } from "@/lib/utils";
import { Btn, Stat, copyText, toastError } from "./kit";

const opts: { v: Rsvp | null; label: string; on: string }[] = [
  { v: "yes", label: "Sì", on: "bg-success text-success-foreground" },
  { v: "maybe", label: "Forse", on: "bg-warning text-warning-foreground" },
  { v: "no", label: "No", on: "bg-primary text-primary-foreground" },
  { v: null, label: "-", on: "bg-muted text-foreground" },
];

/** Counters + editable RSVP list + reminder. Optional "attended" column for past events. */
export function RsvpEditor({ eventId, responses, showAttended = false }: { eventId: Id; responses: EventResponse[]; showAttended?: boolean }) {
  const set = useSetEventResponse(eventId);
  const remind = useRemind();
  const [msg, setMsg] = useState<{ text: string; wa_link: string } | null>(null);
  const count = (v: Rsvp | null) => responses.filter((r) => r.rsvp === v).length;
  const pending = count(null);
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-4 gap-2 text-center">
        <Stat label="Sì" value={count("yes")} tone="success" />
        <Stat label="Forse" value={count("maybe")} tone="warning" />
        <Stat label="No" value={count("no")} tone="primary" />
        <Stat label="Senza risposta" value={pending} />
      </div>
      {!showAttended && (
        <Btn variant="success" disabled={!pending || remind.isPending}
          onClick={() => remind.mutate(eventId, { onSuccess: (r) => { setMsg(r); window.open(r.wa_link, "_blank", "noopener"); }, onError: toastError })}>
          <MessageCircle className="h-4 w-4" /> Sollecita chi non ha risposto
        </Btn>
      )}
      <ul className="divide-y rounded-xl border">
        {responses.map((r) => (
          <li key={r.player_id} className="flex flex-wrap items-center gap-2 px-3 py-2">
            <span className="min-w-0 flex-1 truncate text-sm font-semibold">{r.player_name}{r.note && <span className="block text-xs font-normal italic text-muted-foreground">“{r.note}”</span>}</span>
            <div role="group" aria-label={`Risposta di ${r.player_name}`} className="flex gap-1">
              {opts.map((o) => (
                <button key={o.label} type="button" aria-pressed={r.rsvp === o.v} disabled={set.isPending}
                  onClick={() => set.mutate({ player_id: r.player_id, rsvp: o.v }, { onError: toastError })}
                  className={cn("min-h-10 min-w-11 rounded-lg border px-2 text-xs font-bold", r.rsvp === o.v ? o.on : "bg-secondary text-muted-foreground")}>{o.label}</button>
              ))}
            </div>
            {showAttended && (
              <label className="flex min-h-10 items-center gap-2 text-xs font-semibold">
                <input type="checkbox" className="h-5 w-5 accent-[var(--success)]" checked={!!r.attended}
                  onChange={(e) => set.mutate({ player_id: r.player_id, attended: e.target.checked }, { onError: toastError })} />
                Presente
              </label>
            )}
          </li>
        ))}
      </ul>
      <Dialog open={!!msg} onOpenChange={(o) => !o && setMsg(null)}>
        <DialogContent>
          <DialogHeader><DialogTitle>Messaggio di sollecito</DialogTitle><DialogDescription>WhatsApp si è aperto in una nuova scheda. Puoi anche copiare il testo.</DialogDescription></DialogHeader>
          <pre className="whitespace-pre-wrap rounded-lg bg-muted p-3 text-sm">{msg?.text}</pre>
          <div className="flex justify-end gap-2">
            <Btn variant="outline" onClick={() => msg && copyText(msg.text)}>Copia testo</Btn>
            <a href={msg?.wa_link} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center rounded-lg bg-success px-4 text-sm font-semibold text-success-foreground">Apri WhatsApp</a>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
