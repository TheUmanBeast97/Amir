import { createFileRoute } from "@tanstack/react-router";
import { motion } from "motion/react";
import { useState } from "react";
import { useAttendance, useHistory } from "@/api/hooks";
import type { AttendanceRow } from "@/api/types";
import { ActivePill, CountUp, Reveal } from "@/components/motion";
import { Card, EmptyState, ErrorState, PageTitle, Select, Skeleton } from "@/components/ui-kit";
import { sized } from "@/lib/img";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/classifiche")({
  head: () => ({
    meta: [
      { title: "Classifiche squadra - AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Presenze, allenamenti, marcatori, assist, voti medi e cartellini dei giocatori.",
      },
      { property: "og:title", content: "Classifiche squadra - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Le classifiche interne della squadra." },
    ],
  }),
  component: Leaderboards,
});

type Tab = {
  id: string;
  label: string;
  value: (r: AttendanceRow) => number | null;
  fmt?: (n: number) => string;
  unit: string;
};
const tabs: Tab[] = [
  { id: "presenze", label: "Più presenti", value: (r) => r.matches_played, unit: "partite" },
  {
    id: "allenamenti",
    label: "Allenamenti",
    value: (r) => r.trainings_attended,
    unit: "allenamenti",
  },
  { id: "gol", label: "Marcatori", value: (r) => r.goals, unit: "gol" },
  { id: "assist", label: "Assist", value: (r) => r.assists, unit: "assist" },
  {
    id: "voto",
    label: "Voto medio",
    value: (r) => r.avg_rating,
    fmt: (n) => n.toFixed(1).replace(".", ","),
    unit: "media",
  },
  {
    id: "cartellini",
    label: "Cartellini",
    value: (r) => r.yellow + r.red * 2,
    unit: "punti (rosso = 2)",
  },
];

const initials = (n: string) =>
  n
    .split(" ")
    .map((w) => w[0])
    .slice(0, 2)
    .join("");
function Avatar({ r, size }: { r: AttendanceRow; size: number }) {
  return r.photo_url ? (
    <img
      src={sized(r.photo_url, size)}
      alt=""
      className="rounded-full object-cover"
      style={{ width: size, height: size }}
    />
  ) : (
    <div
      className="grid place-items-center rounded-full bg-secondary font-display"
      style={{ width: size, height: size, fontSize: size * 0.36 }}
    >
      {initials(r.full_name)}
    </div>
  );
}

function Leaderboards() {
  const hist = useHistory();
  const [season, setSeason] = useState("2026/2027");
  const [tab, setTab] = useState(tabs[0]!);
  const q = useAttendance(season);
  const seasons = ["2026/2027", ...(hist.data?.seasons.map((s) => s.season) ?? [])];
  const ranked = (q.data ?? [])
    .map((r) => ({ r, v: tab.value(r) }))
    .filter((x): x is { r: AttendanceRow; v: number } => x.v !== null && x.v > 0)
    .sort((a, b) => b.v - a.v);
  const show = (v: number) => (tab.fmt ? tab.fmt(v) : String(v));
  const podium = ranked.slice(0, 3);
  const order = [1, 0, 2];

  return (
    <div>
      <PageTitle kicker="Area staff" title="Classifiche squadra">
        <Select label="Stagione" value={season} onChange={setSeason}>
          {seasons.map((s) => (
            <option key={s} value={s}>
              {s}
            </option>
          ))}
          <option value="all">Di sempre</option>
        </Select>
      </PageTitle>
      <div role="tablist" className="mb-4 flex gap-1 overflow-x-auto pb-1">
        {tabs.map((t) => (
          <button
            key={t.id}
            role="tab"
            aria-selected={tab.id === t.id}
            onClick={() => setTab(t)}
            className={cn(
              "press relative min-h-11 shrink-0 rounded-lg px-3 text-sm font-semibold transition-colors",
              tab.id === t.id ? "text-primary-foreground" : "bg-secondary text-muted-foreground",
            )}
          >
            {tab.id === t.id && (
              <ActivePill id="leaderboard-tab" className="rounded-lg bg-primary" />
            )}
            <span className="relative">{t.label}</span>
          </button>
        ))}
      </div>
      {q.isPending ? (
        <Skeleton className="h-80" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : ranked.length === 0 ? (
        <EmptyState>Ancora nessun dato per questa classifica.</EmptyState>
      ) : (
        <>
          {/* il podio si riscrive a ogni cambio di classifica: prima le colonne basse, il vincitore per ultimo */}
          <div key={`${tab.id}-${season}`} className="mb-4 grid grid-cols-3 items-end gap-2">
            {order.map((i) => {
              const x = podium[i];
              if (!x) return <div key={i} />;
              const wait = i === 0 ? 0.4 : i === 1 ? 0.22 : 0.08;
              return (
                <div key={x.r.player_id} className="flex flex-col items-center text-center">
                  <motion.div
                    initial={{ opacity: 0, scale: 0.5, y: 16 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    transition={{ ...spring.pop, delay: wait + 0.25 }}
                  >
                    <Avatar r={x.r} size={i === 0 ? 72 : 56} />
                  </motion.div>
                  <motion.div
                    className="mt-1 w-full truncate text-xs font-semibold"
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: dur.base, delay: wait + 0.3 }}
                  >
                    {x.r.full_name}
                  </motion.div>
                  <motion.div
                    className={cn(
                      "mt-1 grid w-full place-items-center rounded-t-lg font-display text-2xl num",
                      i === 0
                        ? "h-24 bg-warning text-warning-foreground"
                        : i === 1
                          ? "h-16 bg-secondary"
                          : "h-12 bg-secondary",
                    )}
                    initial={{ clipPath: "inset(100% 0 0 0)" }}
                    animate={{ clipPath: "inset(0% 0 0 0)" }}
                    transition={{ duration: dur.slow, ease: ease.out, delay: wait }}
                  >
                    <CountUp value={x.v} duration={0.9} {...(tab.fmt ? { format: tab.fmt } : {})} />
                  </motion.div>
                </div>
              );
            })}
          </div>
          <Card className="p-0 md:p-0">
            <ol key={`${tab.id}-${season}`} className="divide-y">
              {ranked.slice(3).map((x, i) => (
                <Reveal
                  as="li"
                  key={x.r.player_id}
                  i={i}
                  x={-12}
                  y={0}
                  className="flex items-center gap-3 px-4 py-2"
                >
                  <span className="w-6 text-sm text-muted-foreground num">{i + 4}</span>
                  <Avatar r={x.r} size={32} />
                  <span className="min-w-0 flex-1 truncate text-sm font-semibold">
                    {x.r.full_name}
                  </span>
                  <span className="font-display text-xl num">{show(x.v)}</span>
                </Reveal>
              ))}
            </ol>
          </Card>
          <p className="mt-2 text-xs text-muted-foreground">Valori in {tab.unit}.</p>
        </>
      )}
    </div>
  );
}
