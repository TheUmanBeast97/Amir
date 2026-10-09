import { useNavigate } from "@tanstack/react-router";
import { Loader2, Search, Shield, Trophy, UserRound, X } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from "react";
import { useZoneSearch } from "@/api/zone";
import type { ZoneSearchResult } from "@/api/zone-types";
import { PlayerPhoto } from "@/components/player-ui";
import { dur, ease } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { sportLabel } from "@/lib/zone";
import { ClubCrest } from "./ClubCrest";

type Kind = "club" | "player" | "tournament";

interface Hit {
  kind: Kind;
  id: number;
  label: string;
  sub: string;
  node: ZoneSearchResult["clubs"][number] | ZoneSearchResult["players"][number] | null;
}

const GROUPS: { kind: Kind; title: string; icon: typeof Shield }[] = [
  { kind: "club", title: "Squadre", icon: Shield },
  { kind: "player", title: "Giocatori", icon: UserRound },
  { kind: "tournament", title: "Tornei", icon: Trophy },
];

/** Aspetta che si smetta di scrivere prima di interrogare il server. */
function useDebounced(value: string, ms: number) {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

/**
 * La ricerca globale della Mixed Zone: un campo che, dopo 250 ms, interroga `/zone/search` e mostra i
 * risultati raggruppati (squadre, giocatori, tornei). Frecce per scorrere, Invio per aprire, Esc per chiudere.
 */
export function ZoneSearch({
  className,
  autoFocus = false,
}: {
  className?: string;
  autoFocus?: boolean;
}) {
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const dq = useDebounced(q.trim(), 250);
  const query = useZoneSearch(dq);
  const navigate = useNavigate();
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);

  const hits = useMemo<Hit[]>(() => {
    const d = query.data;
    if (!d) return [];
    return [
      ...d.clubs
        .filter((c): c is typeof c & { id: number } => c.id !== null)
        .map((c) => ({ kind: "club" as const, id: c.id, label: c.name, sub: "Squadra", node: c })),
      ...d.players.map((p) => ({
        kind: "player" as const,
        id: p.id,
        label: p.name,
        sub: "Giocatore",
        node: p,
      })),
      ...d.tournaments.map((t) => ({
        kind: "tournament" as const,
        id: t.id,
        label: t.name,
        sub: `${t.season} · ${sportLabel(t.sport)}`,
        node: null,
      })),
    ];
  }, [query.data]);

  // la lista si riallinea quando cambiano i risultati
  useEffect(() => setActive(0), [hits]);

  const go = (h: Hit) => {
    setOpen(false);
    setQ("");
    const id = String(h.id);
    if (h.kind === "club") void navigate({ to: "/mixed-zone/squadre/$id", params: { id } });
    else if (h.kind === "player")
      void navigate({ to: "/mixed-zone/giocatori/$id", params: { id } });
    else void navigate({ to: "/mixed-zone/tornei/$id", params: { id } });
  };

  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape") {
      setOpen(false);
      return;
    }
    if (!hits.length) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setOpen(true);
      setActive((a) => (a + 1) % hits.length);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive((a) => (a - 1 + hits.length) % hits.length);
    } else if (e.key === "Enter") {
      const h = hits[active];
      if (h) {
        e.preventDefault();
        go(h);
      }
    }
  };

  const showing = open && dq.length >= 2;
  const empty = showing && query.isSuccess && hits.length === 0;

  return (
    <div className={cn("relative", className)}>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3.5 top-1/2 h-5 w-5 -translate-y-1/2 text-muted-foreground" />
        <input
          ref={inputRef}
          type="search"
          role="combobox"
          aria-expanded={showing}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={showing && hits[active] ? `${listId}-${active}` : undefined}
          autoFocus={autoFocus}
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onKeyDown={onKey}
          placeholder="Cerca squadre, giocatori, tornei"
          aria-label="Cerca nella Mixed Zone"
          className="min-h-12 w-full rounded-xl border border-input bg-card pl-11 pr-11 text-base text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-search-cancel-button]:hidden"
        />
        {q && (
          <button
            type="button"
            aria-label="Svuota la ricerca"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => {
              setQ("");
              inputRef.current?.focus();
            }}
            className="press absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            {query.isFetching ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <X className="h-4 w-4" />
            )}
          </button>
        )}
      </div>
      <AnimatePresence>
        {showing && (hits.length > 0 || empty) && (
          <motion.div
            key="results"
            role="listbox"
            id={listId}
            initial={{ opacity: 0, y: -6, scale: 0.99 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -6 }}
            transition={{ duration: dur.fast, ease: ease.out }}
            className="absolute inset-x-0 top-full z-40 mt-2 max-h-[60vh] overflow-y-auto rounded-xl border bg-popover p-1.5 text-popover-foreground shadow-2xl"
          >
            {empty ? (
              <p className="px-3 py-4 text-center text-sm text-muted-foreground">
                Nessun risultato per «{dq}»
              </p>
            ) : (
              GROUPS.map(({ kind, title, icon: Icon }) => {
                const group = hits.filter((h) => h.kind === kind);
                if (!group.length) return null;
                return (
                  <div key={kind} className="py-1">
                    <div className="flex items-center gap-1.5 px-3 py-1 text-[10px] font-bold uppercase tracking-[0.18em] text-primary">
                      <Icon className="h-3 w-3" /> {title}
                    </div>
                    {group.map((h) => {
                      const idx = hits.indexOf(h);
                      const isActive = idx === active;
                      return (
                        <button
                          key={`${h.kind}-${h.id}`}
                          type="button"
                          role="option"
                          id={`${listId}-${idx}`}
                          aria-selected={isActive}
                          // il mousedown non deve far perdere il fuoco al campo prima del click
                          onMouseDown={(e) => e.preventDefault()}
                          onMouseEnter={() => setActive(idx)}
                          onClick={() => go(h)}
                          className={cn(
                            "flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition-colors",
                            isActive ? "bg-accent" : "hover:bg-accent/60",
                          )}
                        >
                          {h.kind === "club" && h.node && "badge_url" in h.node && (
                            <ClubCrest club={{ ...h.node, id: h.id }} size={32} />
                          )}
                          {h.kind === "player" && h.node && "photo_url" in h.node && (
                            <PlayerPhoto
                              player={{ full_name: h.label, photo_url: h.node.photo_url }}
                              size={32}
                            />
                          )}
                          {h.kind === "tournament" && (
                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/15 text-primary">
                              <Trophy className="h-4 w-4" />
                            </span>
                          )}
                          <span className="min-w-0 flex-1">
                            <span className="block truncate font-semibold">{h.label}</span>
                            <span className="block truncate text-xs text-muted-foreground">
                              {h.sub}
                            </span>
                          </span>
                        </button>
                      );
                    })}
                  </div>
                );
              })
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
