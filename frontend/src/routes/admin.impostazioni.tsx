import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { ChartColumn, ClipboardList, Download, History, Images, KeyRound, LogOut, RefreshCw, Upload, Users } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { api, TOKEN_KEY } from "@/api/client";
import { useCheckXfiveAdmin, useXfiveAdminStatus } from "@/api/hooks";
import type { LocalImportResult, SyncScope } from "@/api/types";
import { Card, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { Btn, TextInput, toastError } from "@/components/admin/kit";
import { useLogout } from "@/components/admin/AdminShell";
import { syncLabel, useSyncFlow } from "@/components/admin/sync";
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

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** Le righe che raccontano cosa è successo portando online info e pagamenti del computer. */
function importSummary(r: LocalImportResult): string[] {
  const lines = [
    `${plural(r.players_matched, "giocatore abbinato", "giocatori abbinati")}: ${r.players_filled} completati con ${plural(r.fields_filled, "dato", "dati")} (maglie, telefoni, note...).`,
    `Pagamenti: ${plural(r.charges_created, "addebito", "addebiti")}, ${plural(r.player_charges_created, "quota", "quote")} e ${plural(r.payments_created, "versamento", "versamenti")} aggiunti${r.payments_already_there > 0 ? `, ${r.payments_already_there} c'erano già` : ""}.`,
  ];
  if (r.players_not_found > 0)
    lines.push(
      `${plural(r.players_not_found, "giocatore del computer non c'è", "giocatori del computer non ci sono")} ancora online: premi «Importa da XFive» in Giocatori e poi ripeti qui.`,
    );
  if (r.players_ambiguous > 0)
    lines.push(`${plural(r.players_ambiguous, "giocatore non si capisce", "giocatori non si capiscono")} (omonimi): vanno completati a mano.`);
  if (r.finance_skipped > 0)
    lines.push(`${plural(r.finance_skipped, "voce di pagamento lasciata", "voci di pagamento lasciate")} fuori perché il giocatore non c'è online.`);
  return lines;
}

/** Copia di sicurezza di tutti i dati: serve anche a portarli dal computer a un server. */
function BackupCard() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<"download" | "restore" | "import" | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [word, setWord] = useState("");
  const [localFile, setLocalFile] = useState<File | null>(null);
  const [imported, setImported] = useState<string[] | null>(null);
  const { start: startSync, busy: syncing, left } = useSyncFlow();

  const importLocal = async () => {
    if (!localFile) return;
    setBusy("import");
    setImported(null);
    try {
      const r = await api.importLocalData(localFile);
      setImported(importSummary(r));
      toast.success("Fatto: info e pagamenti del computer sono online.");
      await queryClient.invalidateQueries(); // giocatori, pagamenti e saldi si rileggono
      setBusy(null);
      // il resto si prende da XFive: partite giocate, foto salvate e statistiche (ora che i giocatori hanno il loro profilo)
      await startSync(["details", "media", "stats"]);
      await queryClient.invalidateQueries();
    } catch (e) {
      toastError(e);
    } finally {
      setBusy(null);
    }
  };

  const download = async () => {
    setBusy("download");
    try {
      const url = URL.createObjectURL(await api.downloadBackup());
      const a = document.createElement("a");
      a.href = url;
      a.download = `amir-backup-${new Date().toISOString().slice(0, 10)}.json.gz`;
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
        modo per portare i dati da un computer a un server. Stemmi e foto non ci sono: si riscaricano da XFive con «Stemmi e foto».
      </p>
      <Btn onClick={download} disabled={busy !== null}>
        <Download className="h-4 w-4" /> {busy === "download" ? "Preparo la copia…" : "Scarica backup"}
      </Btn>

      <h3 className="mb-2 mt-6 text-lg">Porta qui info e pagamenti del computer</h3>
      <p className="mb-3 text-sm text-muted-foreground">
        Carica il file <code>database.sqlite</code> del gestionale sul computer (o un backup). I giocatori si riconoscono dal nome e si
        completa solo quello che online è vuoto: maglie, telefono, email, soprannome, note, scheda scout. Squad List, tesseramenti e
        certificati di XFive non si toccano. Addebiti, quote e versamenti si aggiungono se mancano. Non sostituisce niente e si può
        ripetere senza fare doppioni. Prima servono i giocatori online: «Importa da XFive» in Giocatori. Finito, scarica da solo da XFive
        partite giocate, foto e statistiche.
      </p>
      <div className="space-y-3">
        <input
          type="file"
          accept=".gz,.json,.sqlite,.db,application/gzip,application/json,application/vnd.sqlite3,application/x-sqlite3"
          aria-label="File del gestionale sul computer"
          onChange={(e) => {
            setLocalFile(e.target.files?.[0] ?? null);
            setImported(null);
          }}
          className="block w-full text-sm file:mr-3 file:min-h-11 file:cursor-pointer file:rounded-lg file:border-0 file:bg-secondary file:px-4 file:font-semibold file:text-foreground"
        />
        <Btn onClick={importLocal} disabled={!localFile || busy !== null || syncing}>
          <Upload className="h-4 w-4" /> {busy === "import" ? "Importo…" : "Importa info e pagamenti"}
        </Btn>
        {syncing && (
          <p className="text-sm text-muted-foreground" role="status">
            Scarico da XFive partite giocate, foto e statistiche{left ? ` (ne mancano ancora ${left})` : ""}. Ci vuole un paio di minuti:
            lascia aperta la pagina.
          </p>
        )}
        {imported && (
          <ul className="space-y-1 rounded-lg border border-success/40 bg-success/5 p-3 text-sm" role="status">
            {imported.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        )}
      </div>

      <h3 className="mb-2 mt-6 text-lg">Ripristina da un backup</h3>
      <p className="mb-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm">
        Sostituisce <strong>tutti</strong> i dati di adesso con quelli del file: utenti compresi, quindi dopo dovrai accedere di nuovo con
        un utente che era nel backup. Prima scarica un backup dei dati di adesso: non resta nessuna copia. Si può caricare anche il
        vecchio file <code>database.sqlite</code> del gestionale sul computer.
      </p>
      <div className="space-y-3">
        <input
          type="file"
          accept=".gz,.json,.sqlite,.db,application/gzip,application/json,application/vnd.sqlite3,application/x-sqlite3"
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

/** L'accesso all'area amministrazione di XFive con l'account dello staff: rosa, Squad List, certificati e tesseramenti. */
function XfiveAdminCard({ start, busy, working }: { start: (scope: SyncScope | SyncScope[]) => void; busy: boolean; working: SyncScope | null }) {
  const status = useXfiveAdminStatus();
  const check = useCheckXfiveAdmin();
  const s = status.data;
  const last = s?.last_run;

  const tryLogin = () =>
    check.mutate(undefined, {
      onSuccess: (r) => (r.ok ? toast.success(r.message) : toast.error(r.message)),
      onError: toastError,
    });

  const badge = !s
    ? null
    : !s.configured
      ? { text: "Non configurato", cls: "bg-muted text-muted-foreground" }
      : !s.enabled
        ? { text: "Spento", cls: "bg-warning/15 text-warning" }
        : { text: "Acceso", cls: "bg-success/15 text-success" };

  return (
    <Card>
      <div className="mb-1 flex flex-wrap items-center gap-3">
        <h2 className="text-2xl">Area amministrazione XFive</h2>
        {badge && <span className={cn("rounded-full px-2 py-0.5 text-[11px] font-bold uppercase", badge.cls)}>{badge.text}</span>}
      </div>
      <p className="mb-3 text-sm text-muted-foreground">
        Legge dal tuo account amministratore su XFive la rosa: crea i giocatori che mancano e tiene allineati Squad List, scadenze dei
        certificati e stato dei tesseramenti. Solo lettura su XFive. Maglie, telefoni, note e pagamenti restano tuoi: non vengono da XFive.
        Email e password stanno solo nelle variabili protette del server: qui non si digitano e non si salvano.
      </p>

      {status.isPending ? (
        <Skeleton className="h-16" />
      ) : status.isError ? (
        <ErrorState error={status.error} onRetry={() => status.refetch()} />
      ) : (
        <>
          {!s?.configured && (
            <p className="mb-3 rounded-lg border bg-secondary/40 p-3 text-sm">
              Nel progetto del backend su Vercel imposta <code>XFIVE_ADMIN_EMAIL</code> e <code>XFIVE_ADMIN_PASSWORD</code> (come variabile
              sensibile), poi <code>XFIVE_ADMIN_ENABLED=1</code> per accenderlo, e premi Redeploy.
            </p>
          )}
          {s?.configured && !s.enabled && (
            <p className="mb-3 rounded-lg border bg-secondary/40 p-3 text-sm">
              Le credenziali ci sono ma è spento: puoi provare l'accesso, ma la lettura della rosa resta ferma. Per accenderlo imposta{" "}
              <code>XFIVE_ADMIN_ENABLED=1</code> nel backend e premi Redeploy.
            </p>
          )}
          {s?.blocked_until && (
            <p className="mb-3 rounded-lg border border-primary/40 bg-primary/5 p-3 text-sm">
              XFive ha rifiutato l'accesso: nuovi tentativi sospesi fino alle {fmtDateTime(s.blocked_until)}, per non rischiare il blocco dell'account.
              Controlla email e password nel backend.
            </p>
          )}

          <div className="flex flex-wrap gap-2">
            <Btn variant="outline" onClick={tryLogin} disabled={!s?.configured || check.isPending || busy}>
              <KeyRound className="h-4 w-4" /> {check.isPending ? "Provo l'accesso…" : "Prova accesso"}
            </Btn>
            <Btn onClick={() => start(["players", "media"])} disabled={!s?.enabled || busy || check.isPending}>
              <Users className={cn("h-4 w-4", working === "players" && "animate-pulse")} /> Importa giocatori da XFive
            </Btn>
            <Btn variant="outline" onClick={() => start("admin")} disabled={!s?.enabled || busy || check.isPending}>
              <RefreshCw className={cn("h-4 w-4", working === "admin" && "animate-spin")} /> Aggiorna rosa da XFive
            </Btn>
          </div>

          {check.data && (
            <p className={cn("mt-3 text-sm", check.data.ok ? "text-success" : "text-primary")} role="status">
              {check.data.message}
            </p>
          )}

          {last && (
            <p className="mt-3 text-xs text-muted-foreground">
              Ultima lettura: <span className="capitalize">{fmtDateTime(last.started_at)}</span> ·{" "}
              {last.status === "error" ? (last.error ?? "errore") : last.stats["disabled"] ? "spento" : summary(last.stats)}
              {s && s.players_synced > 0 ? ` · ${s.players_synced} giocatori collegati a XFive` : ""}
            </p>
          )}
        </>
      )}
    </Card>
  );
}

/** I numeri che contano di una lettura, in italiano e senza gli zeri. */
function summary(stats: Record<string, number>) {
  const parts: [string, string][] = [
    ["created", "giocatori creati"],
    ["matched", "abbinati"],
    ["squad_list_changes", "cambi di Squad List"],
    ["certificate_changes", "certificati aggiornati"],
    ["unmatched", "non abbinati su XFive"],
    ["ambiguous", "dubbi sul nome"],
    ["birth_mismatch", "date di nascita diverse"],
    ["missing_on_xfive", "nostri giocatori assenti su XFive"],
  ];
  const text = parts.filter(([k]) => (stats[k] ?? 0) > 0).map(([k, label]) => `${stats[k]} ${label}`);
  return text.length ? text.join(", ") : "nessuna differenza";
}

function SettingsPage() {
  const { start, busy, working, left, runs } = useSyncFlow();
  const logout = useLogout();
  return (
    <div className="space-y-4">
      <PageTitle kicker="Area staff" title="Impostazioni" />
      <Card>
        <h2 className="mb-3 text-2xl">XFive</h2>
        <div className="flex flex-wrap gap-2">
          <Btn onClick={() => start(["current", "history", "details", "media", "stats"])} disabled={busy}><Download className="h-4 w-4" /> Scarica tutto da XFive</Btn>
          <Btn variant="outline" onClick={() => start("current")} disabled={busy}><RefreshCw className={cn("h-4 w-4", busy && "animate-spin")} /> Aggiorna calendario</Btn>
          <Btn variant="outline" onClick={() => start("details")} disabled={busy}><ClipboardList className="h-4 w-4" /> Partite giocate</Btn>
          <Btn variant="outline" onClick={() => start("media")} disabled={busy}><Images className="h-4 w-4" /> Stemmi e foto</Btn>
          <Btn variant="outline" onClick={() => start("stats")} disabled={busy}><ChartColumn className="h-4 w-4" /> Statistiche</Btn>
          <Btn variant="outline" onClick={() => start("history")} disabled={busy}><History className="h-4 w-4" /> Importa storico</Btn>
        </div>
        {working && (
          <p className="mt-3 text-sm text-muted-foreground" role="status">
            {syncLabel[working]}: in corso{left ? `, ne mancano ancora ${left}` : ""}…
          </p>
        )}
        <p className="mt-3 text-xs text-muted-foreground">
          «Scarica tutto da XFive» fa in fila calendario, storico, partite giocate, stemmi e foto e statistiche (qualche minuto, lascia aperta
          la pagina): serve quando il database è nuovo o vuoto. Il calendario si aggiorna da solo ogni notte. «Stemmi e foto» serve dopo un ripristino dei dati: le immagini non stanno nei backup e
          si riscaricano da XFive a più riprese (un minuto circa ogni volta, il sito richiama da solo finché finisce).
        </p>
        <h3 className="mb-2 mt-5 text-lg">Ultime sincronizzazioni</h3>
        {runs.isPending ? <Skeleton className="h-32" /> : runs.isError ? <ErrorState error={runs.error} onRetry={() => runs.refetch()} /> :
          runs.data.length === 0 ? <EmptyState>Ancora nessuna sincronizzazione.</EmptyState> : (
          <ul className="divide-y text-sm">
            {runs.data.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-2 py-2.5">
                <span className={cn("rounded-full px-2 py-0.5 text-[11px] font-bold uppercase", statusCls[r.status])}>{statusLbl[r.status]}</span>
                <span className="font-semibold">{syncLabel[r.scope]}</span>
                <span className="capitalize text-muted-foreground">{fmtDateTime(r.started_at)}</span>
                <span className="w-full text-xs text-muted-foreground md:ml-auto md:w-auto">
                  {r.error ?? Object.entries(r.stats).map(([k, v]) => `${k}: ${v}`).join(" · ")}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <XfiveAdminCard start={start} busy={busy} working={working} />
      <BackupCard />
      <Card>
        <h2 className="mb-3 text-2xl">Account</h2>
        <Btn variant="outline" onClick={logout}><LogOut className="h-4 w-4" /> Esci</Btn>
      </Card>
    </div>
  );
}
