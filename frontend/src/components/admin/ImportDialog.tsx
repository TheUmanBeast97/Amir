import { useState } from "react";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { useImportPlayers } from "@/api/hooks";
import type { ImportResult } from "@/api/types";
import { Btn, Stat, inputCls, toastError } from "./kit";

const COLS = ["cognome", "nome", "data_nascita", "ruolo", "numero", "telefono", "email", "tesseramento", "squad_list", "scadenza_certificato"] as const;
const HEAD = ["Cognome", "Nome", "Nascita", "Ruolo", "N°", "Telefono", "Email", "Tesser.", "Squad", "Certificato"];
const regMap: Record<string, string> = { approvato: "approved", approved: "approved", "in attesa": "pending", pending: "pending", attesa: "pending", none: "none", "da richiedere": "none", no: "none", "": "none" };

const toIso = (s: string) => {
  const m = s.trim().match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/);
  return m ? `${m[3]}-${m[2]!.padStart(2, "0")}-${m[1]!.padStart(2, "0")}` : s.trim();
};

export function parseRows(text: string): Record<string, string>[] {
  const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  if (!lines.length) return [];
  const sep = lines[0]!.includes("\t") ? "\t" : lines[0]!.includes(";") ? ";" : ",";
  const cells = lines.map((l) => l.split(sep).map((c) => c.trim().replace(/^"|"$/g, "")));
  if (/cognome|surname/i.test(cells[0]![0] ?? "")) cells.shift();
  return cells.map((c) => {
    const r: Record<string, string> = {};
    COLS.forEach((k, i) => (r[k] = c[i] ?? ""));
    r["data_nascita"] = toIso(r["data_nascita"]!);
    r["scadenza_certificato"] = toIso(r["scadenza_certificato"]!);
    r["ruolo"] = r["ruolo"]!.toLowerCase();
    r["tesseramento"] = regMap[r["tesseramento"]!.toLowerCase()] ?? r["tesseramento"]!;
    return r;
  });
}

export function ImportDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const [text, setText] = useState("");
  const [result, setResult] = useState<ImportResult | null>(null);
  const imp = useImportPlayers();
  const rows = parseRows(text);
  const close = (o: boolean) => { onOpenChange(o); if (!o) { setText(""); setResult(null); } };
  const onFile = async (f: File | undefined) => { if (f) setText(await f.text()); };

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Importa giocatori</DialogTitle>
          <DialogDescription>
            Incolla il testo o carica un CSV. Colonne nell'ordine: cognome, nome, data di nascita, ruolo, numero di maglia, telefono, email, tesseramento, squad list (sì/no), scadenza certificato.
          </DialogDescription>
        </DialogHeader>
        {result ? (
          <div className="space-y-3">
            <div className="grid grid-cols-3 gap-2 text-center">
              <Stat label="Creati" value={result.created} tone="success" />
              <Stat label="Aggiornati" value={result.updated} tone="warning" />
              <Stat label="Saltati" value={result.skipped} tone="primary" />
            </div>
            {result.errors.length > 0 && <ul className="list-disc space-y-1 pl-5 text-sm text-primary">{result.errors.map((e) => <li key={e}>{e}</li>)}</ul>}
            <div className="flex justify-end"><Btn onClick={() => close(false)}>Chiudi</Btn></div>
          </div>
        ) : (
          <div className="space-y-3">
            <label className="block text-sm font-semibold">Testo da incollare
              <textarea value={text} onChange={(e) => setText(e.target.value)} rows={5} placeholder="Rossi;Mario;12/03/1994;difensore;5;3330000000;mario@esempio.it;approvato;sì;30/06/2027"
                className={`${inputCls} mt-1 py-2 font-mono text-xs`} />
            </label>
            <label className="block text-sm font-semibold">Oppure carica un file CSV
              <input type="file" accept=".csv,text/csv,text/plain" onChange={(e) => onFile(e.target.files?.[0])} className="mt-1 block w-full text-sm file:mr-3 file:min-h-11 file:rounded-lg file:border-0 file:bg-secondary file:px-4 file:font-semibold file:text-secondary-foreground" />
            </label>
            {rows.length > 0 && (
              <div>
                <h3 className="mb-2 text-lg">Anteprima ({rows.length} righe)</h3>
                <div className="max-h-64 overflow-auto rounded-xl border">
                  <table className="w-full text-xs">
                    <thead className="sticky top-0 bg-muted text-left"><tr>{HEAD.map((h) => <th key={h} className="px-2 py-1.5">{h}</th>)}</tr></thead>
                    <tbody>{rows.map((r, i) => (
                      <tr key={i} className="border-t">{COLS.map((k) => <td key={k} className={`whitespace-nowrap px-2 py-1 ${(k === "cognome" || k === "nome") && !r[k] ? "bg-primary/20" : ""}`}>{r[k]}</td>)}</tr>
                    ))}</tbody>
                  </table>
                </div>
              </div>
            )}
            <div className="flex justify-end gap-2">
              <Btn variant="outline" onClick={() => close(false)}>Annulla</Btn>
              <Btn disabled={!rows.length || imp.isPending} onClick={() => imp.mutate(rows, { onSuccess: setResult, onError: toastError })}>
                {imp.isPending ? "Importazione…" : `Importa ${rows.length || ""} giocatori`}
              </Btn>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
