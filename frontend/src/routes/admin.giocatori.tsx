import { createFileRoute } from "@tanstack/react-router";
import { Copy, Pencil, Plus, Search, Share2, Trash2, Upload, Users } from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { useDeletePlayer, usePlayers } from "@/api/hooks";
import type { Player, PlayerRole, RegistrationStatus } from "@/api/types";
import { Card, EmptyState, ErrorState, InfoBanner, PageTitle, Skeleton } from "@/components/ui-kit";
import {
  Btn,
  Confirm,
  NativeSelect,
  TextInput,
  copyText,
  toastError,
  waLink,
} from "@/components/admin/kit";
import { PlayerDrawer } from "@/components/admin/PlayerDrawer";
import { ShirtBadge } from "@/components/player-ui";
import { ImportDialog } from "@/components/admin/ImportDialog";
import { useSyncFlow } from "@/components/admin/sync";
import { fmtDay, regLabel, roleLabel, todayISO } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/giocatori")({
  head: () => ({
    meta: [
      { title: "Giocatori - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Gestione rosa: anagrafiche, tesseramenti, Squad List, certificati e link personali.",
      },
      { property: "og:title", content: "Giocatori - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Gestione della rosa per lo staff." },
    ],
  }),
  component: PlayersPage,
});

const regCls: Record<RegistrationStatus, string> = {
  none: "bg-primary/15 text-primary",
  pending: "bg-warning/15 text-warning",
  approved: "bg-success/15 text-success",
};

/** I due numeri di maglia: rosso e bianco. Lo staff ha una sigla (A/D). */
function Numbers({ p }: { p: Player }) {
  const staff = p.role === "dirigente" || p.role === "allenatore";
  if (staff)
    return <span className="font-display text-lg text-warning">{p.shirt_number ?? "–"}</span>;
  const red = p.shirt_number_red ?? p.shirt_number;
  if (!red && !p.shirt_number_white) return <span className="text-muted-foreground">–</span>;
  return (
    <span className="flex items-center gap-1">
      {red && <ShirtBadge number={red} kit="red" size={26} />}
      {p.shirt_number_white && <ShirtBadge number={p.shirt_number_white} kit="white" size={26} />}
    </span>
  );
}

function shareLink(p: Player) {
  if (!p.magic_link) {
    toast.error("Link personale non disponibile.");
    return;
  }
  const text = `Ciao ${p.nickname ?? p.first_name}! Questo è il tuo link personale AMIR per confermare le presenze e vedere le quote: ${p.magic_link}`;
  window.open(waLink(text, p.phone), "_blank", "noopener");
}

function PlayersPage() {
  const q = usePlayers();
  const del = useDeletePlayer();
  const [search, setSearch] = useState("");
  const [active, setActive] = useState("active");
  const [squad, setSquad] = useState("");
  const [reg, setReg] = useState("");
  const [role, setRole] = useState("");
  const [editing, setEditing] = useState<Player | null | undefined>(undefined);
  const [importOpen, setImportOpen] = useState(false);
  const { start: startSync, busy: syncing } = useSyncFlow();

  const rows = useMemo(
    () =>
      (q.data ?? [])
        .filter((p) => {
          const s = search.trim().toLowerCase();
          if (
            s &&
            !`${p.full_name} ${p.nickname ?? ""} ${p.shirt_number ?? ""} ${p.shirt_number_red ?? ""} ${p.shirt_number_white ?? ""}`
              .toLowerCase()
              .includes(s)
          )
            return false;
          if (active === "active" && !p.is_active) return false;
          if (active === "inactive" && p.is_active) return false;
          if (squad === "yes" && !p.in_squad_list) return false;
          if (squad === "no" && p.in_squad_list) return false;
          if (reg && p.registration_status !== reg) return false;
          if (role && p.role !== role) return false;
          return true;
        })
        .sort((a, b) => a.last_name.localeCompare(b.last_name)),
    [q.data, search, active, squad, reg, role],
  );

  const today = todayISO();
  const certCell = (p: Player) =>
    !p.medical_cert_expires_on ? (
      <span className="text-primary">Mancante</span>
    ) : (
      <span className={cn(p.medical_cert_expires_on < today && "font-semibold text-primary")}>
        {fmtDay(p.medical_cert_expires_on)}
      </span>
    );
  const actions = (p: Player) => (
    <div className="flex flex-wrap gap-1">
      <Btn
        variant="ghost"
        className="px-2"
        aria-label={`Modifica ${p.full_name}`}
        onClick={() => setEditing(p)}
      >
        <Pencil className="h-4 w-4" />
      </Btn>
      <Btn
        variant="ghost"
        className="px-2"
        aria-label={`Copia link di ${p.full_name}`}
        onClick={() => p.magic_link && copyText(p.magic_link, "Link copiato")}
      >
        <Copy className="h-4 w-4" />
      </Btn>
      <Btn
        variant="ghost"
        className="px-2"
        aria-label={`Invia link a ${p.full_name} su WhatsApp`}
        onClick={() => shareLink(p)}
      >
        <Share2 className="h-4 w-4" />
      </Btn>
      {p.is_active && (
        <Confirm
          title={`Disattivare ${p.full_name}?`}
          confirmLabel="Disattiva"
          description="Il giocatore non comparirà più in rosa e nelle convocazioni, ma presenze, statistiche e pagamenti restano nello storico. Puoi riattivarlo quando vuoi."
          onConfirm={() =>
            del.mutate(p.id, {
              onSuccess: () => toast.success("Giocatore disattivato"),
              onError: toastError,
            })
          }
        >
          {(open) => (
            <Btn
              variant="ghost"
              className="px-2 text-primary"
              aria-label={`Disattiva ${p.full_name}`}
              onClick={open}
            >
              <Trash2 className="h-4 w-4" />
            </Btn>
          )}
        </Confirm>
      )}
    </div>
  );

  return (
    <div>
      <PageTitle kicker="Area staff" title="Giocatori">
        <div className="flex gap-2">
          <Btn variant="outline" onClick={() => startSync(["players", "media"])} disabled={syncing}>
            <Users className="h-4 w-4" /> {syncing ? "Importo…" : "Importa da XFive"}
          </Btn>
          <Btn variant="outline" onClick={() => setImportOpen(true)}>
            <Upload className="h-4 w-4" /> Importa righe
          </Btn>
          <Btn onClick={() => setEditing(null)}>
            <Plus className="h-4 w-4" /> Nuovo
          </Btn>
        </div>
      </PageTitle>
      <div className="mb-4">
        <InfoBanner>
          Il tesseramento va richiesto almeno 72 ore prima della partita (mai sotto le 24 ore). Ogni
          giocatore può avere un numero diverso con la divisa rossa e con quella bianca. Dirigenti e
          allenatori: usa A o D.
        </InfoBanner>
      </div>
      <div className="mb-4 grid grid-cols-2 gap-2 md:grid-cols-5">
        <label className="relative col-span-2 md:col-span-1">
          <span className="sr-only">Cerca</span>
          <Search className="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-muted-foreground" />
          <TextInput
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Cerca nome o numero"
            className="pl-9"
          />
        </label>
        <NativeSelect aria-label="Stato" value={active} onChange={(e) => setActive(e.target.value)}>
          <option value="active">Attivi</option>
          <option value="inactive">Disattivati</option>
          <option value="">Tutti</option>
        </NativeSelect>
        <NativeSelect
          aria-label="Squad List"
          value={squad}
          onChange={(e) => setSquad(e.target.value)}
        >
          <option value="">Squad List: tutti</option>
          <option value="yes">In Squad List</option>
          <option value="no">Fuori Squad List</option>
        </NativeSelect>
        <NativeSelect
          aria-label="Tesseramento"
          value={reg}
          onChange={(e) => setReg(e.target.value)}
        >
          <option value="">Tesseramento: tutti</option>
          {(Object.keys(regLabel) as RegistrationStatus[]).map((k) => (
            <option key={k} value={k}>
              {regLabel[k]}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Ruolo" value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="">Ruolo: tutti</option>
          {(Object.keys(roleLabel) as PlayerRole[]).map((k) => (
            <option key={k} value={k}>
              {roleLabel[k]}
            </option>
          ))}
        </NativeSelect>
      </div>

      {q.isPending ? (
        <Skeleton className="h-96" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState>
          {q.data.length === 0 ? (
            <>
              Nessun giocatore. Premi «Importa da XFive» per prendere la rosa dall'area amministrazione (serve l'accesso acceso in
              Impostazioni). Poi, da Impostazioni, «Dati e backup», porta qui maglie, telefoni e pagamenti del gestionale sul computer.
            </>
          ) : (
            "Nessun giocatore con questi filtri."
          )}
        </EmptyState>
      ) : (
        <>
          <div className="hidden overflow-hidden rounded-xl border md:block">
            <table className="w-full text-sm">
              <thead className="bg-muted/60 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-3 py-2">#</th>
                  <th className="px-3 py-2">Giocatore</th>
                  <th className="px-3 py-2">Ruolo</th>
                  <th className="px-3 py-2">Tesseramento</th>
                  <th className="px-3 py-2">Squad List</th>
                  <th className="px-3 py-2">Certificato</th>
                  <th className="px-3 py-2">Azioni</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((p) => (
                  <tr key={p.id} className={cn("border-t", !p.is_active && "opacity-60")}>
                    <td className="px-3 py-2">
                      <Numbers p={p} />
                    </td>
                    <td className="px-3 py-2">
                      <div className="font-semibold">{p.full_name}</div>
                      {p.nickname && (
                        <div className="text-xs italic text-muted-foreground">“{p.nickname}”</div>
                      )}
                    </td>
                    <td className="px-3 py-2">{p.role ? roleLabel[p.role] : "-"}</td>
                    <td className="px-3 py-2">
                      <span
                        className={cn(
                          "rounded-full px-2 py-0.5 text-xs font-semibold",
                          regCls[p.registration_status],
                        )}
                      >
                        {regLabel[p.registration_status]}
                      </span>
                    </td>
                    <td className="px-3 py-2">{p.in_squad_list ? "Sì" : "-"}</td>
                    <td className="px-3 py-2 num">{certCell(p)}</td>
                    <td className="px-3 py-1">{actions(p)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="grid gap-2 md:hidden">
            {rows.map((p) => (
              <Card key={p.id} className="p-3">
                {/* l'attenuazione sta dentro la scheda: l'opacità della scheda stessa la gestisce l'animazione d'ingresso */}
                <div className={cn("flex items-start gap-3", !p.is_active && "opacity-60")}>
                  <span className="w-14 shrink-0">
                    <Numbers p={p} />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-semibold">{p.full_name}</div>
                    <div className="text-xs text-muted-foreground">
                      {p.role ? roleLabel[p.role] : "Ruolo n.d."}
                      {p.in_squad_list ? " · Squad List" : ""}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                      <span
                        className={cn(
                          "rounded-full px-2 py-0.5 font-semibold",
                          regCls[p.registration_status],
                        )}
                      >
                        {regLabel[p.registration_status]}
                      </span>
                      <span>Cert.: {certCell(p)}</span>
                    </div>
                  </div>
                </div>
                <div className="mt-2 border-t pt-1">{actions(p)}</div>
              </Card>
            ))}
          </div>
        </>
      )}
      <PlayerDrawer player={editing} onClose={() => setEditing(undefined)} />
      <ImportDialog open={importOpen} onOpenChange={setImportOpen} />
    </div>
  );
}
