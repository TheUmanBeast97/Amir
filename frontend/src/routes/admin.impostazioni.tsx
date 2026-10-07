import { createFileRoute } from "@tanstack/react-router";
import { History, LogOut, RefreshCw } from "lucide-react";
import { Card, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { Btn } from "@/components/admin/kit";
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
      <Card>
        <h2 className="mb-3 text-2xl">Account</h2>
        <Btn variant="outline" onClick={logout}><LogOut className="h-4 w-4" /> Esci</Btn>
      </Card>
    </div>
  );
}
