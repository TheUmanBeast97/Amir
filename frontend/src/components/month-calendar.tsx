import { useMemo, useState } from "react";
import { AnimatePresence, motion } from "motion/react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import type { Match } from "@/api/types";
import { cn } from "@/lib/utils";
import { fmtTime, opponent } from "@/lib/format";
import { dur, ease, spring } from "@/lib/motion";
import { Crest } from "./ui-kit";
import { MatchRow } from "./match";

/** Il mese scorre nella direzione in cui si naviga: avanti entra da destra, indietro da sinistra. */
const slide = {
  enter: (d: number) => ({ opacity: 0, x: d * 36 }),
  center: { opacity: 1, x: 0 },
  exit: (d: number) => ({ opacity: 0, x: d * -36 }),
};

const TZ = "Europe/Rome";
const WEEKDAYS = ["Lun", "Mar", "Mer", "Gio", "Ven", "Sab", "Dom"];
const pad = (n: number) => String(n).padStart(2, "0");
const dayKey = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`;
const romeDay = (iso: string) =>
  new Intl.DateTimeFormat("en-CA", { timeZone: TZ }).format(new Date(iso));
const monthLabel = (y: number, m: number) =>
  new Intl.DateTimeFormat("it-IT", { timeZone: "UTC", month: "long", year: "numeric" }).format(
    new Date(Date.UTC(y, m, 1)),
  );

/** Vista a calendario mensile: lo stemma dell'avversario compare nei giorni in cui giochiamo, un puntino per le altre partite del girone. */
export function MonthCalendar({ matches }: { matches: Match[] }) {
  const scheduled = useMemo(() => matches.filter((m) => m.kickoff_at), [matches]);
  const undated = matches.length - scheduled.length;

  const byDay = useMemo(() => {
    const map = new Map<string, Match[]>();
    for (const m of scheduled) {
      const k = romeDay(m.kickoff_at!);
      map.set(
        k,
        [...(map.get(k) ?? []), m].sort((a, b) => a.kickoff_at!.localeCompare(b.kickoff_at!)),
      );
    }
    return map;
  }, [scheduled]);

  // parte dal mese della prossima nostra partita (o dalla prima partita con data)
  const start = useMemo(() => {
    const ours = scheduled
      .filter((m) => m.is_own_match)
      .sort((a, b) => a.kickoff_at!.localeCompare(b.kickoff_at!));
    const upcoming =
      ours.find((m) => new Date(m.kickoff_at!).getTime() >= Date.now()) ?? ours[0] ?? scheduled[0];
    return upcoming ? romeDay(upcoming.kickoff_at!) : romeDay(new Date().toISOString());
  }, [scheduled]);

  const [cursor, setCursor] = useState(() => ({
    y: Number(start.slice(0, 4)),
    m: Number(start.slice(5, 7)) - 1,
  }));
  const [selected, setSelected] = useState<string>(start);
  const [dir, setDir] = useState(1);

  const first = new Date(Date.UTC(cursor.y, cursor.m, 1)).getUTCDay();
  const offset = (first + 6) % 7; // settimana che parte da lunedì
  const days = new Date(Date.UTC(cursor.y, cursor.m + 1, 0)).getUTCDate();
  const cells = Array.from({ length: Math.ceil((offset + days) / 7) * 7 }, (_, i) => {
    const d = i - offset + 1;
    return d >= 1 && d <= days ? d : null;
  });
  const today = romeDay(new Date().toISOString());

  const move = (delta: number) => {
    const d = new Date(Date.UTC(cursor.y, cursor.m + delta, 1));
    setDir(delta >= 0 ? 1 : -1);
    setCursor({ y: d.getUTCFullYear(), m: d.getUTCMonth() });
  };
  const monthKey = `${cursor.y}-${cursor.m}`;
  const selectedMatches = byDay.get(selected) ?? [];

  return (
    <div>
      <div className="mb-3 flex items-center justify-between gap-2">
        <button
          onClick={() => move(-1)}
          aria-label="Mese precedente"
          className="press grid h-11 w-11 place-items-center rounded-lg border hover:bg-accent"
        >
          <ChevronLeft className="h-5 w-5" />
        </button>
        <div className="text-center">
          <div className="relative h-8 overflow-hidden">
            <AnimatePresence mode="popLayout" initial={false} custom={dir}>
              <motion.div
                key={monthKey}
                custom={dir}
                variants={{
                  enter: (d: number) => ({ opacity: 0, y: d * 18, filter: "blur(3px)" }),
                  center: { opacity: 1, y: 0, filter: "blur(0px)" },
                  exit: (d: number) => ({ opacity: 0, y: d * -18, filter: "blur(3px)" }),
                }}
                initial="enter"
                animate="center"
                exit="exit"
                transition={{ duration: dur.base, ease: ease.out }}
                className="font-display text-2xl capitalize"
              >
                {monthLabel(cursor.y, cursor.m)}
              </motion.div>
            </AnimatePresence>
          </div>
          <button
            onClick={() => {
              const [y, m] = today.split("-").map(Number) as [number, number];
              setDir(y * 12 + (m - 1) >= cursor.y * 12 + cursor.m ? 1 : -1);
              setCursor({ y, m: m - 1 });
              setSelected(today);
            }}
            className="press text-xs font-semibold text-primary hover:underline"
          >
            Vai a oggi
          </button>
        </div>
        <button
          onClick={() => move(1)}
          aria-label="Mese successivo"
          className="press grid h-11 w-11 place-items-center rounded-lg border hover:bg-accent"
        >
          <ChevronRight className="h-5 w-5" />
        </button>
      </div>

      <div className="overflow-hidden rounded-xl border bg-card">
        <div className="grid grid-cols-7 border-b bg-muted/60 text-center text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
          {WEEKDAYS.map((w) => (
            <div key={w} className="py-2">
              {w}
            </div>
          ))}
        </div>
        <div className="relative">
          <AnimatePresence mode="popLayout" initial={false} custom={dir}>
            <motion.div
              key={monthKey}
              custom={dir}
              variants={slide}
              initial="enter"
              animate="center"
              exit="exit"
              transition={{ duration: dur.base, ease: ease.out }}
              className="grid grid-cols-7"
            >
              {cells.map((d, i) => {
                if (d === null)
                  return <div key={i} className="min-h-16 border-b border-r bg-muted/20 md:min-h-24" />;
                const key = dayKey(cursor.y, cursor.m, d);
                const ms = byDay.get(key) ?? [];
                const ours = ms.filter((m) => m.is_own_match);
                const others = ms.length - ours.length;
                return (
                  <motion.button
                    key={i}
                    onClick={() => setSelected(key)}
                    aria-label={`${d} ${monthLabel(cursor.y, cursor.m)}${ours.length ? ", partita AMIR" : ""}`}
                    whileTap={{ scale: 0.95 }}
                    className={cn(
                      "relative flex min-h-16 flex-col items-center gap-1 border-b border-r p-1 text-left transition-colors hover:bg-accent md:min-h-24 md:items-stretch md:p-1.5",
                      ours.length > 0 && "bg-highlight",
                    )}
                  >
                    {selected === key && (
                      <motion.span
                        layoutId="calendar-selected"
                        aria-hidden
                        transition={spring.snappy}
                        className="pointer-events-none absolute inset-0 border-2 border-primary"
                      />
                    )}
                    <span
                      className={cn(
                        "grid h-6 w-6 place-items-center rounded-full text-xs font-semibold num",
                        key === today && "bg-primary text-primary-foreground",
                        ours.length > 0 && key !== today && "text-primary",
                      )}
                    >
                      {d}
                    </span>
                    {ours.map((m) => (
                      <span key={m.id} className="flex items-center gap-1">
                        <motion.span
                          className="inline-flex"
                          initial={{ scale: 0, rotate: -12 }}
                          animate={{ scale: 1, rotate: 0 }}
                          transition={{ ...spring.pop, delay: 0.12 + Math.min(i, 30) * 0.012 }}
                        >
                          <Crest team={opponent(m)} size={22} />
                        </motion.span>
                        <span className="hidden truncate text-[11px] font-semibold md:inline">
                          {fmtTime(m.kickoff_at!)} {opponent(m).name}
                        </span>
                      </span>
                    ))}
                    {others > 0 && (
                      <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
                        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60" />
                        <span className="hidden md:inline">
                          {others} {others === 1 ? "altra partita" : "altre partite"}
                        </span>
                      </span>
                    )}
                  </motion.button>
                );
              })}
            </motion.div>
          </AnimatePresence>
        </div>
      </div>

      <div className="mt-5 space-y-2">
        <AnimatePresence mode="wait" initial={false}>
          <motion.div
            key={selected}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: dur.fast, ease: ease.out }}
            className="space-y-2"
          >
            <h2 className="text-lg capitalize">
              {new Intl.DateTimeFormat("it-IT", {
                timeZone: "UTC",
                weekday: "long",
                day: "numeric",
                month: "long",
              }).format(new Date(`${selected}T12:00:00Z`))}
            </h2>
            {selectedMatches.length ? (
              <div className="grid gap-2 md:grid-cols-2">
                {selectedMatches.map((m, idx) => (
                  <MatchRow key={m.id} m={m} i={idx} />
                ))}
              </div>
            ) : (
              <p className="text-sm text-muted-foreground">Nessuna partita in questo giorno.</p>
            )}
          </motion.div>
        </AnimatePresence>
        {undated > 0 && (
          <p className="pt-2 text-xs text-muted-foreground">
            {undated} {undated === 1 ? "partita" : "partite"} con data ancora da definire:{" "}
            {undated === 1 ? "comparirà" : "compariranno"} qui appena XFive{" "}
            {undated === 1 ? "la pubblica" : "le pubblica"}.
          </p>
        )}
      </div>
    </div>
  );
}
