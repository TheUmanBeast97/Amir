import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { useAdminMatches, useHistory, useHome } from "@/api/hooks";
import { EmptyState, ErrorState, PageTitle, Select, Skeleton } from "@/components/ui-kit";
import { MatchRow } from "@/components/match";
import { Toggle } from "@/components/admin/kit";

export const Route = createFileRoute("/admin/partite/")({
  head: () => ({
    meta: [
      { title: "Partite - Area staff AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Elenco delle partite con presenze, formazione e statistiche.",
      },
      { property: "og:title", content: "Partite - Area staff AMIR COSTRUZIONI" },
      { property: "og:description", content: "Gestione partite per lo staff." },
    ],
  }),
  component: AdminMatches,
});

function AdminMatches() {
  const home = useHome();
  const hist = useHistory();
  const [compId, setCompId] = useState("");
  const [all, setAll] = useState(false);
  const current = home.data?.competition;
  const competition_id = compId ? Number(compId) : current?.id;
  const q = useAdminMatches({ competition_id, scope: all ? "all" : "own" });
  return (
    <div>
      <PageTitle kicker="Area staff" title="Partite" />
      <div className="mb-5 flex flex-wrap items-end gap-3">
        <Select
          label="Competizione"
          value={compId || String(competition_id ?? "")}
          onChange={setCompId}
        >
          {current && (
            <option value={current.id}>
              {current.name} · {current.season}
            </option>
          )}
          {hist.data?.seasons
            .flatMap((s) => s.competitions)
            .map((c) => (
              <option key={c.id} value={c.id}>
                {c.name} · {c.season}
              </option>
            ))}
        </Select>
        <Toggle checked={all} onChange={setAll} label="Tutte le partite del girone" />
      </div>
      {q.isPending ? (
        <div className="space-y-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : q.data.length === 0 ? (
        <EmptyState>Nessuna partita.</EmptyState>
      ) : (
        <div className="grid gap-2 md:grid-cols-2">
          {q.data.map((m, idx) => (
            <MatchRow key={m.id} m={m} i={idx} admin />
          ))}
        </div>
      )}
    </div>
  );
}
