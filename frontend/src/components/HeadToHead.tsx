import { useHeadToHead } from "@/api/hooks";
import { EmptyState, ErrorState, Skeleton } from "./ui-kit";
import { HistoryMatchItem } from "./match";

export function HeadToHead({ teamId }: { teamId: number }) {
  const q = useHeadToHead(teamId);
  if (q.isPending) return <Skeleton className="h-40" />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const { matches, opponent } = q.data;
  const record = q.data;
  return (
    <div>
      <h2 className="mb-3 text-2xl">Precedenti vs {opponent.name}</h2>
      {matches.length === 0 ? (
        <EmptyState>Nessun precedente contro questa squadra.</EmptyState>
      ) : (
        <>
          <div className="mb-3 grid grid-cols-3 gap-2 text-center">
            {[["Vittorie", record.won, "text-success"], ["Pareggi", record.drawn, "text-warning"], ["Sconfitte", record.lost, "text-primary"]].map(([l, v, c]) => (
              <div key={l} className="rounded-xl bg-card p-3">
                <div className={`font-display text-3xl num ${c}`}>{v}</div>
                <div className="text-[11px] uppercase tracking-wider text-muted-foreground">{l}</div>
              </div>
            ))}
          </div>
          <div className="space-y-2">{matches.map((m) => <HistoryMatchItem key={m.match_id} m={m} />)}</div>
        </>
      )}
    </div>
  );
}
