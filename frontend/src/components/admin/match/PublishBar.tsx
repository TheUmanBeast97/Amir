import { Link } from "@tanstack/react-router";
import { Eye, Lightbulb } from "lucide-react";
import { toast } from "sonner";
import { useUpdateMatch } from "@/api/hooks";
import type { MatchSettings } from "@/api/client";
import type { MatchDetail } from "@/api/types";
import { KitPicker } from "@/components/player-ui";
import { opponent } from "@/lib/format";
import { KIT_LABEL } from "@/lib/kit";
import { Toggle, toastError } from "../kit";

/** Suggerisce la divisa che "stacca" di più dai colori dell'avversaria. */
function kitHint(hex: string | null): { kit: "red" | "white"; why: string } | null {
  const m = hex?.match(/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
  if (!m) return null;
  const [r, g, b] = [m[1], m[2], m[3]].map((x) => parseInt(x ?? "0", 16)) as [
    number,
    number,
    number,
  ];
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  if (r > 150 && g < 90 && b < 90) return { kit: "white", why: "gioca in rosso" };
  if (lum > 0.8) return { kit: "red", why: "gioca in bianco/chiaro" };
  return null;
}

/** Divisa e pubblicazione: ciò che qui si accende compare subito nella pagina pubblica della partita. */
export function PublishBar({ detail }: { detail: MatchDetail }) {
  const m = detail.match;
  const upd = useUpdateMatch(m.id);
  const opp = opponent(m);
  const hint = kitHint(opp.kit1_color);
  const apply = (patch: MatchSettings, ok: string) =>
    upd.mutate(patch, { onSuccess: () => toast.success(ok), onError: toastError });

  return (
    <div className="grid gap-3 rounded-xl border bg-card p-3 md:grid-cols-[auto_1fr_auto] md:items-center">
      <div>
        <div className="mb-1 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
          Divisa della partita
        </div>
        <KitPicker
          value={detail.our_kit}
          disabled={upd.isPending}
          onChange={(k) => apply({ our_kit: k }, `Divisa ${KIT_LABEL[k].toLowerCase()}`)}
        />
        {hint && detail.our_kit !== hint.kit && (
          <button
            type="button"
            onClick={() =>
              apply({ our_kit: hint.kit }, `Divisa ${KIT_LABEL[hint.kit].toLowerCase()}`)
            }
            className="mt-1 flex items-center gap-1 text-left text-xs text-warning hover:underline"
          >
            <Lightbulb className="h-3.5 w-3.5 shrink-0" /> {opp.name} {hint.why}: meglio la{" "}
            {KIT_LABEL[hint.kit].toLowerCase()}?
          </button>
        )}
      </div>
      <div className="grid gap-x-6 sm:grid-cols-2">
        <Toggle
          label="Convocati visibili sul sito"
          checked={detail.callups_published}
          onChange={(v) =>
            detail.callups.length === 0 && v
              ? toast.error("Prima salva i convocati.")
              : apply({ callups_published: v }, v ? "Convocati pubblicati" : "Convocati nascosti")
          }
        />
        <Toggle
          label="Formazione visibile sul sito"
          checked={detail.lineup?.is_published ?? false}
          onChange={(v) =>
            !detail.lineup && v
              ? toast.error("Prima salva la formazione.")
              : apply({ lineup_published: v }, v ? "Formazione pubblicata" : "Formazione nascosta")
          }
        />
      </div>
      <Link
        to="/partite/$matchId"
        params={{ matchId: String(m.id) }}
        target="_blank"
        className="inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-accent"
      >
        <Eye className="h-4 w-4" /> Vedi sul sito
      </Link>
    </div>
  );
}
