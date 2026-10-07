import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { Download, History, LogOut, RefreshCw, Upload } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { api, TOKEN_KEY } from "@/api/client";
import { Card, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { Btn, TextInput, toastError } from "@/components/admin/kit";
import { useLogout } from "@/components/admin/AdminShell";
import { useSyncFlow } from "@/components/admin/sync";
import { fmtDateTime } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/impostazioni")({
  head: () => ({
    meta: [
      { title: "Impostazioni - AMIR COSTRUZIONI" },
      { name: "description", content: "Sincronizzazioni XFive, importazione storico e uscita dall'area staff." },
      { property: "og:title", content: "Impostazioni - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Sincronizzazioni XFive e impostazioni dello staff." },
    ],
  }),
  component: SettingsPage,
});

const statusCls = { running: "bg-warning/15 text-warning", ok: "bg-success/15 text-success", error: "bg-primary/15 text-primary" };
const statusLbl = { running: "In corso", ok: "Riuscita", error: "Errore" };

/** Copia di sicurezza di tutti i dati: serve anche a portarli dal computer a un server. */
function BackupCard() {
  const navigate = useNavigate();
  const [busy, setBusy] = useState<"download" | "restore" | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [word, setWord] = useState("");

  const download = async () => {
    setBusy("download");
    try {
      const url = URL.createObjectURL(await api.downloadBackup());
      const a = document.createElement("a");
      a.href = url;
      a.download = `amir-backup-${new Date().toISOString().slice(0, 10)}.sqlite`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 10_000);
      toast.success("Backup scaricato. Conservalo in un posto sicuro: contiene anche dati personali.");
    } catch (e) {
      toastError(e);
    } finally {
      setBusy(null);
    }
  };

  const restore = async () => {
    if (!file) return;
    setBusy("restore");
    try {
      await api.restoreBackup(file, word);
      toast.success("Dati ripristinati. Accedi di nuovo.");
      localStorage.removeItem(TOKEN_KEY);
      navigate({ to: "/admin/login" });
    } catch (e) {
      toastError(e);
      setBusy(null);
    }
  };

  return (
    <Card>
      <h2 className="mb-1 text-2xl">Dati e backup</h2>
      <p className="mb-3 text-sm text-muted-foreground">
        Tutto quello che l'app sa (giocatori, partite, pagamenti, utenti) sta in un solo file. Scarica una copia ogni tanto: è anche il
        modo per portare i dati da un computer a un server.
      </p>
      <Btn onClick={download} disabled={busy !== null}>
        <Download className="h-4 w-4" /> {busy === "download" ? "Preparo la copia…" : "Scarica backup"}
      </Btn>

      <h3 className="mb-2 mt-6 text-lg">Ripristina da un backup</h3>
      <p className="mb-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm">
        Sostituisce <strong>tutti</strong> i dati di adesso con quelli del file: utenti compresi, quindi dopo dovrai accedere di nuovo con
        un utente che era nel backup. La versione di prima resta sul server, accanto al database.
      </p>
      <div className="space-y-3">
        <input
          type="file"
          accept=".sqlite,.db,application/vnd.sqlite3,application/x-sqlite3"
          aria-label="File di backup da ripristinare"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          className="block w-full text-sm file:mr-3 file:min-h-11 file:cursor-pointer file:rounded-lg file:border-0 file:bg-secondary file:px-4 file:font-semibold file:text-foreground"
        />
        <TextInput
          value={word}
          onChange={(e) => setWord(e.target.value)}
          placeholder="Scrivi RIPRISTINA per confermare"
          aria-label="Parola di conferma"
          autoComplete="off"
        />
        <Btn variant="outline" onClick={restore} disabled={!file || word !== "RIPRISTINA" || busy !== null}>
          <Upload className="h-4 w-4" /> {busy === "restore" ? "Ripristino in corso…" : "Ripristina"}
        </Btn>
      </div>
    </Card>
  );
}

function SettingsPage() {
  const { start, busy, runs } = useSyncFlow();
  const logout = useLogout();
  return (
    <div className="space-y-4">
      <PageTitle kicker="Area staff" title="Impostazioni" />
      <Card>
        <h2 className="mb-3 text-2xl">XFive</h2>
        <div className="flex flex-wrap gap-2">
          <Btn onClick={() => start("current")} disabled={busy}><RefreshCw className={cn("h-4 w-4", busy && "animate-spin")} /> Aggiorna calendario</Btn>
          <Btn variant="outline" onClick={() => start("history")} disabled={busy}><History className="h-4 w-4" /> Importa storico</Btn>
        </div>
        <h3 className="mb-2 mt-5 text-lg">Ultime sincronizzazioni</h3>
        {runs.isPending ? <Skeleton className="h-32" /> : runs.isError ? <ErrorState error={runs.error} onRetry={() => runs.refetch()} /> :
          runs.data.length === 0 ? <EmptyState>Ancora nessuna sincronizzazione.</EmptyState> : (
          <ul className="divide-y text-sm">
            {runs.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-2 py-2.5">
                <span className={cn("rounded-full px-2 py-0.5 text-[11px] font-bold uppercase", statusCls[r.status])}>{statusLbl[r.status]}</span>
                <span className="font-semibold">{r.scope === "current" ? "Calendario" : "Storico"}</span>
                <span className="capitalize text-muted-foreground">{fmtDateTime(r.started_at)}</span>
                <span className="w-full text-xs text-muted-foreground md:ml-auto md:w-auto">
                  {r.error ?? Object.entries(r.stats).map(([k, v]) => `${k}: ${v}`).join(" · ")}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <BackupCard />
      <Card>
        <h2 className="mb-3 text-2xl">Account</h2>
        <Btn variant="outline" onClick={logout}><LogOut className="h-4 w-4" /> Esci</Btn>
      </Card>
    </div>
  );
}
