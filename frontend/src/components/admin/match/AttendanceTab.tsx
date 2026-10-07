import type { MatchDetail } from "@/api/types";
import { EmptyState } from "@/components/ui-kit";
import { RsvpEditor } from "../RsvpEditor";

export function AttendanceTab({ detail }: { detail: MatchDetail }) {
  if (!detail.event) return <EmptyState>Nessun evento collegato a questa partita.</EmptyState>;
  return <div className="pt-3"><RsvpEditor eventId={detail.event.id} responses={detail.responses} /></div>;
}
