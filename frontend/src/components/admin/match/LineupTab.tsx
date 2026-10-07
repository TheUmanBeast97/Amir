import { Download, Save, Sparkles, Star, Trash2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { toPng } from "html-to-image";
import { usePlayers, useSaveLineup } from "@/api/hooks";
import type { Format, Id, LineupSlot, MatchDetail, Player } from "@/api/types";
import { DRAG_MIME, Pitch, PlayerPhoto, ShirtBadge } from "@/components/player-ui";
import { InfoBanner } from "@/components/ui-kit";
import { FORMATIONS, slotsFor } from "@/lib/formations";
import { opponent, roleLabel } from "@/lib/format";
import { shirtFor, shortName } from "@/lib/kit";
import { cn } from "@/lib/utils";
import { Btn, NativeSelect, fieldErrors } from "../kit";

const lineFor = (role: string | null) =>
  role === "difensore"
    ? "DIF"
    : role === "centrocampista"
      ? "CC"
      : role === "attaccante"
        ? "ATT"
        : null;

export function LineupTab({ detail }: { detail: MatchDetail }) {
  const m = detail.match;
  const format = m.competition.format as Format;
  const kit = detail.our_kit;
  const players = usePlayers();
  const save = useSaveLineup(m.id);
  const [formation, setFormation] = useState(
    detail.lineup?.formation ?? FORMATIONS[format][0]!.name,
  );
  const [slots, setSlots] = useState<LineupSlot[]>(
    () => detail.lineup?.slots ?? slotsFor(formation),
  );
  const [bench, setBench] = useState<Id[]>(detail.lineup?.bench ?? []);
  const [notes, setNotes] = useState(detail.lineup?.notes ?? "");
  const [picked, setPicked] = useState<Id | null>(null);
  const exportRef = useRef<HTMLDivElement>(null);

  const rsvp = useMemo(
    () => new Map(detail.responses.map((r) => [r.player_id, r.rsvp])),
    [detail.responses],
  );
  const called = useMemo(() => new Set(detail.callups.map((c) => c.player_id)), [detail.callups]);
  const pool = useMemo(
    () =>
      (players.data ?? [])
        .filter((p) => p.is_active && p.role !== "dirigente" && p.role !== "allenatore")
        .sort(
          (a, b) =>
            Number(called.has(b.id)) - Number(called.has(a.id)) ||
            Number(rsvp.get(b.id) === "yes") - Number(rsvp.get(a.id) === "yes") ||
            a.last_name.localeCompare(b.last_name),
        ),
    [players.data, rsvp, called],
  );
  const byId = useMemo(() => new Map(pool.map((p) => [p.id, p] as const)), [pool]);
  const get = (id: Id) => byId.get(id);
  const used = new Set([
    ...slots.map((s) => s.player_id).filter((x): x is Id => x !== null),
    ...bench,
  ]);

  const changeFormation = (f: string) => {
    const keep = slots.map((s) => s.player_id);
    setFormation(f);
    setSlots(slotsFor(f).map((s, i) => ({ ...s, player_id: keep[i] ?? null })));
  };
  const removeEverywhere = (id: Id) => {
    setSlots((ss) => ss.map((s) => (s.player_id === id ? { ...s, player_id: null } : s)));
    setBench((b) => b.filter((x) => x !== id));
  };
  const placeInSlot = (slot: number, id: Id) => {
    removeEverywhere(id);
    setSlots((ss) => ss.map((s) => (s.slot === slot ? { ...s, player_id: id } : s)));
    setPicked(null);
  };
  const placeOnBench = (id: Id) => {
    removeEverywhere(id);
    setBench((b) => [...b, id]);
    setPicked(null);
  };
  const onSlotClick = (s: LineupSlot) => {
    if (picked !== null) placeInSlot(s.slot, picked);
    else if (s.player_id !== null)
      setSlots((ss) => ss.map((x) => (x.slot === s.slot ? { ...x, player_id: null } : x)));
  };

  /** Compila in automatico: ogni giocatore nella sua zona (portiere, difesa, centrocampo, attacco), poi i convocati restanti in panchina. */
  const autoFill = () => {
    const source: Player[] = pool.filter((p) =>
      called.size ? called.has(p.id) : rsvp.get(p.id) !== "no",
    );
    const left = [...source];
    const take = (pred: (p: Player) => boolean) => {
      const i = left.findIndex(pred);
      return i >= 0 ? left.splice(i, 1)[0]! : null;
    };
    const next = slots.map((s) => ({ ...s, player_id: null as Id | null }));
    for (const s of next) {
      const p =
        s.label === "POR"
          ? take((x) => x.role === "portiere")
          : take((x) => lineFor(x.role) === s.label);
      if (p) s.player_id = p.id;
    }
    // le posizioni rimaste vuote si riempiono con chi avanza (i portieri solo se non c'è altro)
    for (const s of next) {
      if (s.player_id === null) {
        const p = take((x) => x.role !== "portiere") ?? take(() => true);
        if (p) s.player_id = p.id;
      }
    }
    setSlots(next);
    setBench(left.map((p) => p.id));
    setPicked(null);
    toast.success("Formazione proposta: sistemala come vuoi e salva.");
  };

  const gk = get(slots[0]?.player_id ?? -1);
  const empty = slots.filter((s) => s.player_id === null).length;
  const errs = Object.values(fieldErrors(save.error)).flat();
  useEffect(() => {
    if (save.isError && !errs.length) toast.error(save.error.message);
  }, [save.isError]); // eslint-disable-line react-hooks/exhaustive-deps

  const exportImg = async () => {
    if (!exportRef.current) return;
    try {
      const url = await toPng(exportRef.current, {
        pixelRatio: 2,
        backgroundColor: "#0B0B0D",
        cacheBust: true,
      });
      const a = document.createElement("a");
      a.href = url;
      a.download = `formazione-${m.id}.png`;
      a.click();
    } catch {
      toast.error("Esportazione non riuscita.");
    }
  };

  const row = (p: Player) => {
    const r = rsvp.get(p.id);
    return (
      <li key={p.id}>
        <button
          type="button"
          draggable
          onDragStart={(e) => e.dataTransfer.setData(DRAG_MIME, String(p.id))}
          onClick={() => setPicked(picked === p.id ? null : p.id)}
          aria-pressed={picked === p.id}
          className={cn(
            "flex min-h-12 w-full items-center gap-2 rounded-lg border bg-card px-2 text-left transition-colors hover:bg-accent",
            picked === p.id && "border-warning ring-2 ring-warning",
          )}
        >
          <span className="relative shrink-0">
            <PlayerPhoto player={p} size={36} />
            <ShirtBadge
              number={shirtFor(p, kit)}
              kit={kit ?? "red"}
              size={18}
              className="absolute -bottom-1 -right-1 !text-[9px]"
            />
          </span>
          <span className="min-w-0 flex-1">
            <span className="flex items-center gap-1 truncate text-xs font-semibold">
              {called.has(p.id) && (
                <Star
                  className="h-3 w-3 shrink-0 fill-warning text-warning"
                  aria-label="convocato"
                />
              )}
              <span className="truncate">{shortName(p)}</span>
            </span>
            <span className="block truncate text-[10px] text-muted-foreground">
              {p.role ? roleLabel[p.role] : "—"}
            </span>
          </span>
          <span
            className={cn(
              "h-2.5 w-2.5 shrink-0 rounded-full",
              r === "yes"
                ? "bg-success"
                : r === "maybe"
                  ? "bg-warning"
                  : r === "no"
                    ? "bg-primary"
                    : "bg-muted-foreground/40",
            )}
            aria-label={
              r === "yes"
                ? "ha detto sì"
                : r === "maybe"
                  ? "forse"
                  : r === "no"
                    ? "ha detto no"
                    : "nessuna risposta"
            }
          />
        </button>
      </li>
    );
  };

  const free = pool.filter((p) => !used.has(p.id));
  const freeCalled = free.filter((p) => called.has(p.id));
  const freeOthers = free.filter((p) => !called.has(p.id));

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap items-end gap-2">
        <label className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Modulo
          <NativeSelect
            value={formation}
            onChange={(e) => changeFormation(e.target.value)}
            className="mt-1 w-32"
          >
            {FORMATIONS[format].map((f) => (
              <option key={f.name}>{f.name}</option>
            ))}
          </NativeSelect>
        </label>
        <Btn
          onClick={() =>
            save.mutate(
              {
                formation,
                slots,
                bench,
                notes: notes.trim() || null,
                is_published: detail.lineup?.is_published ?? false,
              },
              { onSuccess: () => toast.success("Formazione salvata") },
            )
          }
          disabled={save.isPending}
        >
          <Save className="h-4 w-4" /> Salva
        </Btn>
        <Btn variant="outline" onClick={autoFill}>
          <Sparkles className="h-4 w-4" /> Proponi formazione
        </Btn>
        <Btn variant="outline" onClick={exportImg}>
          <Download className="h-4 w-4" /> Esporta come immagine
        </Btn>
        <Btn
          variant="ghost"
          onClick={() => {
            setSlots(slotsFor(formation));
            setBench([]);
            setPicked(null);
          }}
        >
          <Trash2 className="h-4 w-4" /> Svuota
        </Btn>
      </div>
      {kit === null && (
        <InfoBanner>
          Scegli la divisa nella barra qui sopra: sui giocatori compare il numero della maglia rossa
          o bianca.
        </InfoBanner>
      )}
      {(!gk || empty > 0) && (
        <InfoBanner>
          {!gk ? "Manca il portiere. " : ""}
          {empty > 0 ? `${empty} posizioni ancora vuote.` : ""}
        </InfoBanner>
      )}
      {errs.length > 0 && (
        <p role="alert" className="rounded-lg bg-primary/10 p-3 text-sm text-primary">
          {errs.join(" ")}
        </p>
      )}
      <p className="text-xs text-muted-foreground">
        Trascina un giocatore su una posizione, oppure toccalo e poi tocca la posizione. Tocca una
        posizione occupata per liberarla. La stella ★ indica i convocati.
      </p>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,460px)_1fr]">
        <div>
          <div ref={exportRef} className="rounded-2xl bg-[#0b0b0d] p-3">
            <div className="mb-2 flex items-center justify-between gap-2 px-1">
              <div className="flex items-center gap-2">
                <img src="/stemma-amir.png" alt="" className="h-8 w-8 object-contain" />
                <div className="leading-tight">
                  <div className="font-display text-lg text-white">AMIR COSTRUZIONI</div>
                  <div className="text-[11px] uppercase tracking-widest text-white/60">
                    vs {opponent(m).name}
                  </div>
                </div>
              </div>
              <span className="rounded-full bg-white/10 px-3 py-1 font-display text-xl text-white">
                {formation}
              </span>
            </div>
            <Pitch
              slots={slots}
              getPlayer={get}
              kit={kit}
              armed={picked !== null}
              onSlotClick={onSlotClick}
              onSlotDrop={placeInSlot}
            />
            <div
              onDragOver={(e) => e.preventDefault()}
              onDrop={(e) => {
                e.preventDefault();
                const id = Number(e.dataTransfer.getData(DRAG_MIME));
                if (id) placeOnBench(id);
              }}
              onClick={() => picked !== null && placeOnBench(picked)}
              className="mt-3 min-h-14 rounded-xl border border-dashed border-white/30 p-2"
            >
              <div className="mb-1 text-[10px] font-bold uppercase tracking-wider text-white/60">
                Panchina
              </div>
              <div className="flex flex-wrap gap-1.5">
                {bench.length ? (
                  bench.map((id) => {
                    const p = get(id);
                    return p ? (
                      <button
                        key={id}
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          removeEverywhere(id);
                        }}
                        aria-label={`Togli ${p.full_name} dalla panchina`}
                        className="flex items-center gap-1.5 rounded-full bg-white/10 py-0.5 pl-0.5 pr-2.5 text-xs font-semibold text-white hover:bg-white/20"
                      >
                        <PlayerPhoto player={p} size={24} /> {shortName(p)}{" "}
                        <span className="text-white/60 num">{shirtFor(p, kit)}</span>
                      </button>
                    ) : null;
                  })
                ) : (
                  <span className="text-xs text-white/50">
                    {picked !== null ? "Tocca qui per mettere in panchina" : "Vuota"}
                  </span>
                )}
              </div>
            </div>
          </div>
          <label className="mt-3 block text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Note per la squadra (visibili sul sito)
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              maxLength={1000}
              placeholder="Es. ritrovo alle 19:30 al campo, portate le pettorine"
              className="mt-1 w-full rounded-lg border border-input bg-background p-3 text-sm font-normal normal-case tracking-normal"
            />
          </label>
        </div>

        <div className="space-y-4">
          {freeCalled.length > 0 && (
            <div>
              <h3 className="mb-2 text-lg">Convocati ({freeCalled.length})</h3>
              <ul className="grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
                {freeCalled.map(row)}
              </ul>
            </div>
          )}
          <div>
            <h3 className="mb-2 text-lg">
              {freeCalled.length > 0 ? "Altri giocatori" : "Disponibili"} ({freeOthers.length})
            </h3>
            <ul className="grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
              {freeOthers.map(row)}
            </ul>
          </div>
        </div>
      </div>
    </div>
  );
}
