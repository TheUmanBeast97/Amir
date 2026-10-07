import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft } from "lucide-react";
import { useMatchDetail } from "@/api/hooks";
import { ErrorState, InfoBanner, Skeleton } from "@/components/ui-kit";
import { MatchRow } from "@/components/match";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AttendanceTab } from "@/components/admin/match/AttendanceTab";
import { CallupsTab } from "@/components/admin/match/CallupsTab";
import { LineupTab } from "@/components/admin/match/LineupTab";
import { PostMatchTab } from "@/components/admin/match/PostMatchTab";
import { PrecedentiTab } from "@/components/admin/match/PrecedentiTab";
import { PublishBar } from "@/components/admin/match/PublishBar";

export const Route = createFileRoute("/admin/partite/$matchId")({
  head: () => ({
    meta: [
      { title: "Gestione partita — AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Presenze, convocati, formazione, statistiche e precedenti della partita.",
      },
      { property: "og:title", content: "Gestione partita — AMIR COSTRUZIONI" },
      { property: "og:description", content: "Presenze, convocati, formazione e statistiche." },
    ],
  }),
  component: AdminMatchDetail,
});

function AdminMatchDetail() {
  const { matchId } = Route.useParams();
  const q = useMatchDetail(Number(matchId));
  return (
    <div className="space-y-4">
      <Link
        to="/admin/partite"
        className="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground"
      >
        <ArrowLeft className="h-4 w-4" /> Partite
      </Link>
      {q.isPending ? (
        <Skeleton className="h-64" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <>
          <h1 className="text-4xl">{q.data.match.round_label}</h1>
          <MatchRow m={q.data.match} />
          {q.data.match.is_provisional ? (
            <InfoBanner>In attesa di calendario ufficiale.</InfoBanner>
          ) : (
            <>
              <PublishBar detail={q.data} />
              <Tabs defaultValue="presenze">
                <TabsList className="flex h-auto w-full flex-wrap justify-start">
                  <TabsTrigger value="presenze" className="min-h-10">
                    Presenze
                  </TabsTrigger>
                  <TabsTrigger value="convocati" className="min-h-10">
                    Convocati{q.data.callups.length > 0 && ` (${q.data.callups.length})`}
                  </TabsTrigger>
                  <TabsTrigger value="formazione" className="min-h-10">
                    Formazione
                  </TabsTrigger>
                  <TabsTrigger value="dopo" className="min-h-10">
                    Dopo la partita
                  </TabsTrigger>
                  <TabsTrigger value="precedenti" className="min-h-10">
                    Precedenti
                  </TabsTrigger>
                </TabsList>
                <TabsContent value="presenze">
                  <AttendanceTab detail={q.data} />
                </TabsContent>
                <TabsContent value="convocati">
                  <CallupsTab detail={q.data} />
                </TabsContent>
                <TabsContent value="formazione">
                  <LineupTab detail={q.data} />
                </TabsContent>
                <TabsContent value="dopo">
                  <PostMatchTab detail={q.data} />
                </TabsContent>
                <TabsContent value="precedenti">
                  <PrecedentiTab detail={q.data} />
                </TabsContent>
              </Tabs>
            </>
          )}
        </>
      )}
    </div>
  );
}
