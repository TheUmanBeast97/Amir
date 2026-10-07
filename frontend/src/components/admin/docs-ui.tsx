import { motion } from "motion/react";
import { Check, RotateCcw } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Btn } from "@/components/admin/kit";
import { Reveal, RollDigits } from "@/components/motion";
import type { DocCategory, DocKind, MatchCheckItem } from "@/api/types";
import { spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

export const categoryLabel: Record<DocCategory, string> = {
  guida: "Guide e checklist",
  regolamento: "Regolamento",
  tesseramento: "Tesseramento",
  privacy: "Privacy",
  contratti: "Contratti e prestiti",
  convenzioni: "Convenzioni e promo",
};
export const kindLabel: Record<DocKind, string> = {
  guida: "Guida",
  regolamento: "Regolamento",
  modulo: "Modulo",
  informativa: "Informativa",
  promo: "Promozione",
  listino: "Listino",
};

/** Per cercare senza badare a maiuscole e accenti. */
export const norm = (s: string) => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

export const fmtSize = (bytes: number | null) => (bytes === null ? "" : bytes > 1_000_000 ? `${(bytes / 1_000_000).toFixed(1)} MB` : `${Math.round(bytes / 1000)} KB`);

/**
 * Elenco di spunte salvato solo in questo browser (è una comodità personale: se il browser lo dimentica, si riparte da zero).
 * Le spunte sono indicate dal testo della voce, così sopravvivono se l'elenco viene riordinato.
 */
function useTicks(storageKey: string) {
  const [ticked, setTicked] = useState<string[]>([]);
  useEffect(() => {
    try {
      const raw = localStorage.getItem(storageKey);
      const parsed: unknown = raw ? JSON.parse(raw) : [];
      setTicked(Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === "string") : []);
    } catch {
      setTicked([]);
    }
  }, [storageKey]);
  // si parte sempre dallo stato più recente, così due tocchi ravvicinati non si sovrascrivono
  const update = useCallback((change: (prev: string[]) => string[]) => {
    setTicked((prev) => {
      const next = change(prev);
      try {
        localStorage.setItem(storageKey, JSON.stringify(next));
      } catch {
        /* private window or blocked storage: the ticks just stay on screen */
      }
      return next;
    });
  }, [storageKey]);
  const toggle = (text: string) => update((prev) => (prev.includes(text) ? prev.filter((t) => t !== text) : [...prev, text]));
  return { ticked, toggle, reset: () => update(() => []) };
}

/** Lista di controllo con caselle da spuntare: prima della partita, o per l'iscrizione. */
export function Checklist({ storageKey, items, footer, extra }: {
  storageKey: string;
  items: MatchCheckItem[];
  footer?: string;
  extra?: { text: string; tip?: string }[];
}) {
  const { ticked, toggle, reset } = useTicks(storageKey);
  const all = [...(extra ?? []).map((e) => ({ text: e.text, tip: e.tip ?? "" })), ...items];
  const done = all.filter((i) => ticked.includes(i.text)).length;
  return (
    <div>
      <div className="mb-3 flex items-center gap-3">
        <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuemin={0} aria-valuemax={all.length} aria-valuenow={done} aria-label="Voci completate">
          <motion.div className="h-full w-full origin-left bg-success" initial={false} animate={{ scaleX: all.length ? done / all.length : 0 }} transition={spring.snappy} />
        </div>
        <span className="inline-flex items-center text-xs font-bold num text-muted-foreground"><RollDigits value={done} pad={1} />/{all.length}</span>
        {done > 0 && <Btn variant="ghost" onClick={reset} className="min-h-9 px-2 text-xs" aria-label="Azzera le spunte"><RotateCcw className="h-3.5 w-3.5" /> Azzera</Btn>}
      </div>
      <ul className="space-y-1.5">
        {all.map((i, idx) => {
          const on = ticked.includes(i.text);
          return (
            <Reveal as="li" key={i.text} i={idx} y={8}>
              <button type="button" onClick={() => toggle(i.text)} aria-pressed={on} className={cn("press flex min-h-11 w-full items-start gap-3 rounded-lg border px-3 py-2 text-left text-sm hover:bg-accent", on && "border-success/40 bg-success/10")}>
                <span className={cn("mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded border transition-colors duration-150", on ? "border-success bg-success text-success-foreground" : "border-input")}>
                  {on && (
                    <motion.span className="grid place-items-center" initial={{ scale: 0, rotate: -40 }} animate={{ scale: 1, rotate: 0 }} transition={spring.pop}>
                      <Check className="h-3.5 w-3.5" />
                    </motion.span>
                  )}
                </span>
                <span className="min-w-0">
                  <span className={cn("block font-semibold transition-colors duration-200", on && "text-muted-foreground line-through")}>{i.text}</span>
                  {i.tip && <span className="mt-0.5 block text-xs text-muted-foreground">{i.tip}</span>}
                </span>
              </button>
            </Reveal>
          );
        })}
      </ul>
      {footer && <p className="mt-3 rounded-lg bg-warning/10 p-3 text-xs font-semibold">{footer}</p>}
      <p className="mt-2 text-[11px] text-muted-foreground">Le spunte restano solo su questo browser.</p>
    </div>
  );
}
