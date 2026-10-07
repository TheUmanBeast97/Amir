import { Check, MessageCircle, Save, Star, X } from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { usePlayers, useSaveCallups } from "@/api/hooks";
import type { Id, MatchDetail, Rsvp } from "@/api/types";
import { InfoBanner, Skeleton } from "@/components/ui-kit";
import { PlayerPhoto, ShirtBadge } from "@/components/player-ui";
import { roleLabel } from "@/lib/format";
import { shirtFor } from "@/lib/kit";
import { cn } from "@/lib/utils";
import { Btn, copyText, toastError, waLink } from "../kit";

const rsvpLabel: Record<Rsvp, { text: string; cls: string }> = {
  yes: { text: "Ha detto sì", cls: "bg-success/20 text-success" },
  maybe: { text: "Forse", cls: "bg-warning/20 text-warning" },
  no: { text: "Ha detto no", cls: "bg-primary/20 text-primary" },
};
const RSVP_ORDER: Record<string, number> = { yes: 0, maybe: 1, none: 2, no: 3 };

/** Convocati: chi viene, con una nota facoltativa. Si possono pubblicare sul sito. */
export function CallupsTab({ detail }: { detail: MatchDetail }) {
  const m = detail.match;
  const players = usePlayers();
  const save = useSaveCallups(m.id);
  const [chosen, setChosen] = useState<Map<Id, string>>(
    () => new Map(detail.callups.map((c) => [c.player_id, c.note ?? ""])),
  );
  const rsvp = useMemo(
    () => new Map(detail.responses.map((r) => [r.player_id, r.rsvp])),
    [detail.responses],
  );

  const pool = useMemo(
    () =>
      (players.data ?? [])
        .filter((p) => p.is_active && p.role !== "dirigente" && p.role !== "allenatore")
        .sort(
          (a, b) =>
            (RSVP_ORDER[rsvp.get(a.id) ?? "none"] ?? 2) -
              (RSVP_ORDER[rsvp.get(b.id) ?? "none"] ?? 2) || a.last_name.localeCompare(b.last_name),
        ),
    [players.data, rsvp],
  );

  if (players.isPending) return <Skeleton className="mt-3 h-64" />;

  const toggle = (id: Id) =>
    setChosen((c) => {
      const n = new Map(c);
      if (n.has(id)) n.delete(id);
      else n.set(id, "");
      return n;
    });
  const setNote = (id: Id, note: string) => setChosen((c) => new Map(c).set(id, note));
  const allYes = () =>
    setChosen((c) => {
      const n = new Map(c);
      pool
        .filter((p) => rsvp.get(p.id) === "yes")
        .forEach((p) => {
          if (!n.has(p.id)) n.set(p.id, "");
        });
      return n;
    });

  const submit = (published?: boolean) =>
    save.mutate(
      {
        players: [...chosen].map(([player_id, note]) => ({ player_id, note: note.trim() || null })),
        ...(published !== undefined ? { published } : {}),
      },
      {
        onSuccess: () =>
          toast.success(
            published ? "Convocati salvati e pubblicati sul sito" : "Convocati salvati",
          ),
        onError: toastError,
      },
    );

  const names = pool.filter((p) => chosen.has(p.id));
  const whatsapp = () => {
    const when = m.kickoff_at
      ? new Date(m.kickoff_at).toLocaleString("it-IT", {
          timeZone: "Europe/Rome",
          weekday: "long",
          day: "numeric",
          month: "long",
          hour: "2-digit",
          minute: "2-digit",
        })
      : "data da definire";
    const opp = m.home_team.is_own ? m.away_team.name : m.home_team.name;
    return [
      `📋 *Convocati AMIR vs ${opp}*`,
      when + (m.venue ? ` · ${m.venue}` : ""),
      "",
      ...names.map((p, i) => {
        const n = shirtFor(p, detail.our_kit);
        return `${i + 1}. ${p.nickname ?? p.full_name}${n ? ` (${n})` : ""}`;
      }),
      "",
      "Ci vediamo in campo! 🔴⚫",
    ].join("\n");
  };

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-display text-3xl num">{chosen.size}</span>
        <span className="text-sm text-muted-foreground">convocati</span>
        <div className="ml-auto flex flex-wrap gap-2">
          <Btn variant="outline" onClick={allYes}>
            <Check className="h-4 w-4" /> Aggiungi chi ha detto sì
          </Btn>
          <Btn variant="ghost" onClick={() => setChosen(new Map())} disabled={!chosen.size}>
            <X className="h-4 w-4" /> Azzera
          </Btn>
        </div>
      </div>
      {detail.our_kit === null && (
        <InfoBanner>
          Scegli la divisa qui sopra: i numeri di maglia mostrati (rosso o bianco) dipendono da
          quella.
        </InfoBanner>
      )}

      <ul className="grid gap-2 md:grid-cols-2">
        {pool.map((p) => {
          const on = chosen.has(p.id);
          const r = rsvp.get(p.id);
          return (
            <li
              key={p.id}
              className={cn(
                "rounded-xl border p-2 transition-colors",
                on ? "border-success/60 bg-success/10" : "bg-card",
              )}
            >
              <div className="flex items-center gap-3">
                <button
                  type="button"
                  onClick={() => toggle(p.id)}
                  aria-pressed={on}
                  aria-label={`${on ? "Togli" : "Convoca"} ${p.full_name}`}
                  className="flex min-h-12 min-w-0 flex-1 items-center gap-3 text-left"
                >
                  <span className="relative">
                    <PlayerPhoto player={p} size={44} className={cn(on && "ring-2 ring-success")} />
                    <ShirtBadge
                      number={shirtFor(p, detail.our_kit)}
                      kit={detail.our_kit ?? "red"}
                      size={20}
                      className="absolute -bottom-1 -right-1"
                    />
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold">{p.full_name}</span>
                    <span className="flex flex-wrap items-center gap-1 text-[11px]">
                      <span className="text-muted-foreground">
                        {p.role ? roleLabel[p.role] : "—"}
                      </span>
                      {r ? (
                        <span
                          className={cn(
                            "rounded-full px-1.5 py-0.5 font-semibold",
                            rsvpLabel[r].cls,
                          )}
                        >
                          {rsvpLabel[r].text}
                        </span>
                      ) : (
                        <span className="text-muted-foreground">· nessuna risposta</span>
                      )}
                    </span>
                  </span>
                </button>
                <span
                  className={cn(
                    "grid h-8 w-8 shrink-0 place-items-center rounded-full border-2",
                    on
                      ? "border-success bg-success text-success-foreground"
                      : "border-muted-foreground/40",
                  )}
                >
                  {on && <Check className="h-4 w-4" />}
                </span>
              </div>
              {on && (
                <input
                  aria-label={`Nota per ${p.full_name}`}
                  value={chosen.get(p.id) ?? ""}
                  onChange={(e) => setNote(p.id, e.target.value)}
                  maxLength={160}
                  placeholder="Nota facoltativa (es. capitano, porta le pettorine)"
                  className="mt-2 min-h-10 w-full rounded-lg border border-input bg-background px-3 text-sm"
                />
              )}
            </li>
          );
        })}
      </ul>

      <div className="flex flex-wrap gap-2 border-t pt-3">
        <Btn onClick={() => submit()} disabled={save.isPending}>
          <Save className="h-4 w-4" /> {save.isPending ? "Salvo…" : "Salva convocati"}
        </Btn>
        <Btn
          variant="success"
          onClick={() => submit(true)}
          disabled={save.isPending || chosen.size === 0}
        >
          <Star className="h-4 w-4" /> Salva e pubblica sul sito
        </Btn>
        <Btn
          variant="outline"
          disabled={chosen.size === 0}
          onClick={() => copyText(whatsapp(), "Messaggio copiato")}
        >
          Copia testo
        </Btn>
        <Btn
          variant="outline"
          disabled={chosen.size === 0}
          onClick={() => window.open(waLink(whatsapp()), "_blank", "noopener")}
        >
          <MessageCircle className="h-4 w-4" /> WhatsApp
        </Btn>
      </div>
    </div>
  );
}
