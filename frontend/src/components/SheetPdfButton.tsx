import { FileText } from "lucide-react";
import { useEffect, useState } from "react";
import { TOKEN_KEY } from "@/api/client";
import { useSheetPdf } from "@/api/hooks";
import type { Id } from "@/api/types";
import { cn } from "@/lib/utils";

/** Chi è dello staff ha il token nel browser: solo a lui compare il tasto del PDF (letto dopo il primo disegno, per non sfasare il rendering lato server). */
function useIsStaff() {
  const [staff, setStaff] = useState(false);
  useEffect(() => {
    try {
      setStaff(!!localStorage.getItem(TOKEN_KEY));
    } catch {
      setStaff(false);
    }
  }, []);
  return staff;
}

/**
 * «PDF distinta»: il link a XFive non sta nei dati pubblici, lo chiede al server solo chi è dello staff. Compare quando per la
 * partita risulta una distinta (inviata da qui, o compilata su XFive e vista aprendo la scheda Distinta nella Staff Area).
 */
export function SheetPdfButton({ matchId, className }: { matchId: Id; className?: string }) {
  const staff = useIsStaff();
  const pdf = useSheetPdf(matchId, staff);
  if (!staff || !pdf.data) return null;
  return (
    <a
      href={pdf.data.url}
      target="_blank"
      rel="noreferrer noopener"
      className={cn(
        "press inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm font-bold uppercase tracking-wider text-[#0b0b0d] shadow-lg transition-colors hover:bg-white/85",
        className,
      )}
    >
      <FileText className="h-4 w-4" /> PDF distinta
    </a>
  );
}
