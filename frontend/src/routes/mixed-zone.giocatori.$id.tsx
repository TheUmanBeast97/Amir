import { createFileRoute, Link } from "@tanstack/react-router";
import { motion, useReducedMotion } from "motion/react";
import { ArrowLeft, Flame, Home, Shield, Star, Target, Trophy, Users } from "lucide-react";
import { useState } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { useZonePlayer } from "@/api/zone";
import type { ZonePlayerPage } from "@/api/zone-types";
import { CountUp, OnView, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Kpi, Panel } from "@/components/stats-ui";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui-kit";
import { ClubCrest } from "@/components/zone/ClubCrest";
import { MatchRow } from "@/components/zone/MatchRow";
import { OWN_CLUB_ID } from "@/lib/area";
import { dur, ease, spring } from "@/lib/motion";
import { cn } from "@/lib/utils";
import { FORM_COLOR, outcomeFor, seasonShort, sportLabel } from "@/lib/zone";

export const Route = createFileRoute("/mixed-zone/giocatori/$id")({
  head: () => ({
    meta: [
      { title: "Giocatore - Mixed Zone" },
      {
        name: "description",
        content:
          "Carriera XFive di un giocatore: squadre, tornei, presenze, gol, cartellini e rendimento stagione per stagione.",
      },
      { property: "og:title", content: "Giocatore - Mixed Zone" },
      { property: "og:description", content: "Carriera, numeri e rendimento su XFive." },
    ],
  }),
  component: PlayerRoute,
});

const tip = {
  background: "var(--card)",
  border: "1px solid var(--border)",
  borderRadius: 8,
  color: "var(--foreground)",
  fontSize: 12,
} as const;

function PlayerRoute() {
  const { id } = Route.useParams();
  const q = useZonePlayer(Number(id));
  return (
    <div className="space-y-5">
      <Link
        to="/mixed-zone/giocatori"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        Giocatori
      </Link>
      {q.isPending ? (
        <div className="space-y-4">
          <Skeleton className="h-56" />
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => (
              <Skeleton key={i} className="h-24" />
            ))}
          </div>
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <PlayerView page={q.data} />
      )}
    </div>
  );
}

function PlayerView({ page }: { page: ZonePlayerPage }) {
  const { player: p, totals: t } = page;
  const hasStats = t.matches > 0;
  const ours = p.clubs.some((c) => c.club.id === OWN_CLUB_ID);
  const winPct = t.matches ? Math.round((t.wins / t.matches) * 100) : 0;
  const tournaments = p.clubs.reduce((n, c) => n + c.tournaments.length, 0);
  const last = p.clubs[0];

  return (
    <>
      <section className="card-cut relative overflow-hidden rounded-xl bg-hero p-5 md:p-8">
        <div className="relative flex flex-col items-center gap-5 md:flex-row md:items-end">
          <motion.div
            initial={{ opacity: 0, scale: 0.7, rotate: -4 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            transition={{ ...spring.soft, delay: 0.05 }}
          >
            <PlayerPhoto
              player={{ full_name: p.name, photo_url: p.photo_url }}
              size={136}
              className="border-4 border-white/80 shadow-2xl"
            />
          </motion.div>
          <div className="min-w-0 text-center md:text-left">
            <Reveal now delay={0.2} y={8}>
              <div className="text-xs font-bold uppercase tracking-[0.2em] text-primary">
                Giocatore XFive{last ? ` · ${last.club.name}` : ""}
              </div>
            </Reveal>
            <h1 className="text-4xl leading-none md:text-6xl">
              <span className="block overflow-hidden pb-1">
                <motion.span
                  className="block"
                  initial={{ y: "105%" }}
                  animate={{ y: "0%" }}
                  transition={{ duration: dur.hero, ease: ease.out, delay: 0.25 }}
                >
                  {p.name}
                </motion.span>
              </span>
            </h1>
            <div className="mt-3 flex flex-wrap justify-center gap-2 text-xs font-semibold md:justify-start">
              {[
                p.nationality,
                p.age ? `${p.age} anni` : null,
                `${p.clubs.length} ${p.clubs.length === 1 ? "squadra" : "squadre"}`,
                `${tournaments} ${tournaments === 1 ? "torneo" : "tornei"}`,
              ]
                .filter((c): c is string => !!c)
                .map((c, idx) => (
                  <Reveal
                    key={c}
                    as="span"
                    now
                    delay={0.6 + idx * 0.08}
                    y={0}
                    scale={0.85}
                    className="rounded-full bg-background/60 px-3 py-1 num"
                  >
                    {c}
                  </Reveal>
                ))}
            </div>
          </div>
        </div>
        {p.clubs.length > 0 && (
          <div className="relative mt-5 flex flex-wrap justify-center gap-2 md:justify-start">
            {p.clubs.map((c, idx) =>
              c.club.id !== null ? (
                <Reveal key={c.club.id} as="span" now delay={0.8 + idx * 0.06} y={0} scale={0.9}>
                  <Link
                    to="/mixed-zone/squadre/$id"
                    params={{ id: String(c.club.id) }}
                    className="press flex items-center gap-2 rounded-full bg-background/60 py-1 pl-1 pr-3 text-xs font-semibold hover:bg-accent"
                  >
                    <ClubCrest club={c.club} size={24} />
                    {c.club.name}
                    <span className="text-muted-foreground num">{c.tournaments.length}</span>
                  </Link>
                </Reveal>
              ) : null,
            )}
          </div>
        )}
        {ours && (
          <Reveal now delay={1} className="relative mt-4 flex justify-center md:justify-start">
            <Link
              to="/rosa"
              className="press inline-flex min-h-10 items-center gap-2 rounded-lg border border-primary/40 px-3 text-sm font-semibold text-primary hover:bg-primary/10"
            >
              <Home className="h-4 w-4" /> Gioca con AMIR COSTRUZIONI: la scheda nell'Amir Hub
            </Link>
          </Reveal>
        )}
      </section>

      {!hasStats ? (
        <EmptyState>
          Nessun referto con questo giocatore in distinta: i numeri compariranno quando XFive
          pubblicherà le prime partite.
        </EmptyState>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            <Kpi
              i={0}
              label="Presenze"
              value={t.matches}
              sub={`${page.by_season.length} ${page.by_season.length === 1 ? "stagione" : "stagioni"} nei referti`}
              tone="neutral"
              icon={<Users className="h-5 w-5" />}
            />
            <Kpi
              i={1}
              label="Gol"
              value={t.goals}
              sub={`${t.goals_per_match.toFixed(2)} a partita`}
              tone="primary"
              icon={<Target className="h-5 w-5" />}
            />
            <Kpi
              i={2}
              label="Miglior giocatore"
              value={t.mvp}
              sub="stelle in partita"
              tone="warning"
              icon={<Star className="h-5 w-5" />}
            />
            <Kpi
              i={3}
              label="Vittorie"
              value={
                <>
                  <CountUp value={winPct} />%
                </>
              }
              sub={`${t.wins}V · ${t.draws}N · ${t.losses}P`}
              tone="success"
              icon={<Trophy className="h-5 w-5" />}
            />
            <Kpi
              i={4}
              label="Ammonizioni"
              value={t.yellow}
              sub={`${((t.yellow / t.matches) * 10).toFixed(1)} ogni 10 partite`}
              tone="warning"
              icon={<Shield className="h-5 w-5" />}
            />
            <Kpi
              i={5}
              label="Espulsioni"
              value={t.red}
              tone={t.red > 0 ? "primary" : "neutral"}
              icon={<Flame className="h-5 w-5" />}
            />
            <Kpi
              i={6}
              label="Punti a partita"
              value={(t.wins * 3 + t.draws) / t.matches}
              decimals={2}
              sub="quando è in campo"
              tone="success"
            />
            <Kpi
              i={7}
              label="Tornei"
              value={tournaments}
              sub="su XFive Alessandria"
              tone="neutral"
            />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <SeasonChart page={page} />
            <ResultsDonut page={page} />
            <PartnersPanel page={page} />
            <VictimsPanel page={page} />
          </div>

          <CareerTable page={page} />
          <RecentMatches page={page} />
        </>
      )}
    </>
  );
}

function SeasonChart({ page }: { page: ZonePlayerPage }) {
  const still = !!useReducedMotion();
  const data = [...page.by_season].reverse().map((s) => ({
    stagione: seasonShort(s.season),
    Presenze: s.matches,
    Gol: s.goals,
    Stelle: s.mvp,
  }));
  return (
    <Panel title="Stagione per stagione" kicker="Presenze e gol">
      <OnView className="h-60">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 6, right: 6, left: -18, bottom: 0 }}>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            <XAxis
              dataKey="stagione"
              tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              allowDecimals={false}
              tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
              axisLine={false}
              tickLine={false}
            />
            <Tooltip contentStyle={tip} cursor={{ fill: "var(--accent)" }} />
            <Legend wrapperStyle={{ fontSize: 12 }} />
            <Bar
              dataKey="Presenze"
              fill="var(--muted-foreground)"
              radius={[4, 4, 0, 0]}
              isAnimationActive={!still}
              animationDuration={900}
            />
            <Bar
              dataKey="Gol"
              fill="var(--primary)"
              radius={[4, 4, 0, 0]}
              isAnimationActive={!still}
              animationDuration={900}
              animationBegin={150}
            />
          </BarChart>
        </ResponsiveContainer>
      </OnView>
      <div className="mt-2 overflow-x-auto">
        <table className="w-full text-xs num">
          <thead className="text-[10px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="py-1 text-left">Stagione</th>
              <th>Pres.</th>
              <th>Gol</th>
              <th>Gialli</th>
              <th>Rossi</th>
              <th>★</th>
            </tr>
          </thead>
          <tbody>
            {page.by_season.map((s) => (
              <tr key={s.season} className="border-t">
                <td className="py-1 text-left font-semibold">{s.season}</td>
                <td className="text-center">{s.matches}</td>
                <td className="text-center font-bold text-primary">{s.goals}</td>
                <td className="text-center">{s.yellow || "–"}</td>
                <td className="text-center">{s.red || "–"}</td>
                <td className="text-center">{s.mvp || "–"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

function ResultsDonut({ page }: { page: ZonePlayerPage }) {
  const still = !!useReducedMotion();
  const t = page.totals;
  const data = [
    { name: "Vittorie", value: t.wins, color: FORM_COLOR.W },
    { name: "Pareggi", value: t.draws, color: FORM_COLOR.D },
    { name: "Sconfitte", value: t.losses, color: FORM_COLOR.L },
  ].filter((d) => d.value > 0);
  return (
    <Panel title="Come finisce quando gioca" kicker="Esiti delle sue partite">
      <div className="flex flex-col items-center gap-3 sm:flex-row">
        <OnView className="h-48 w-48 shrink-0">
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={data}
                dataKey="value"
                nameKey="name"
                innerRadius={48}
                outerRadius={78}
                paddingAngle={2}
                stroke="var(--card)"
                isAnimationActive={!still}
                animationDuration={900}
              >
                {data.map((d) => (
                  <Cell key={d.name} fill={d.color} />
                ))}
              </Pie>
              <Tooltip contentStyle={tip} formatter={(v: number) => [`${v} partite`, ""]} />
            </PieChart>
          </ResponsiveContainer>
        </OnView>
        <ul className="w-full space-y-1.5 text-sm">
          {data.map((d, i) => (
            <Reveal as="li" key={d.name} i={i} x={12} y={0} className="flex items-center gap-2">
              <span className="h-3 w-3 rounded-full" style={{ background: d.color }} />
              <span className="flex-1">{d.name}</span>
              <span className="font-bold num">{d.value}</span>
              <span className="w-12 text-right text-xs text-muted-foreground num">
                {Math.round((d.value / t.matches) * 100)}%
              </span>
            </Reveal>
          ))}
        </ul>
      </div>
    </Panel>
  );
}

function PartnersPanel({ page }: { page: ZonePlayerPage }) {
  if (page.partners.length === 0) return null;
  return (
    <Panel title="Con chi si vince" kicker="I compagni d'oro">
      <ul className="space-y-1.5">
        {page.partners.map((m, i) => (
          <Reveal as="li" key={m.player.id} i={i} x={-14} y={0}>
            <Link
              to="/mixed-zone/giocatori/$id"
              params={{ id: String(m.player.id) }}
              className="press flex items-center gap-3 rounded-lg bg-background/40 p-2 hover:bg-accent"
            >
              <span
                className={cn(
                  "w-5 text-center font-display text-xl num",
                  i === 0 ? "text-warning" : "text-muted-foreground",
                )}
              >
                {i + 1}
              </span>
              <PlayerPhoto
                player={{ full_name: m.player.name, photo_url: m.player.photo_url }}
                size={36}
              />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">{m.player.name}</div>
                <div className="text-[11px] text-muted-foreground num">
                  {m.played} partite insieme · {m.won} vinte
                </div>
              </div>
              <div className="text-right">
                <div className="font-display text-2xl leading-none text-success num">
                  <CountUp value={m.points_per_match} decimals={2} />
                </div>
                <div className="text-[10px] text-muted-foreground">punti a partita</div>
              </div>
            </Link>
          </Reveal>
        ))}
      </ul>
      <p className="mt-2 text-[11px] text-muted-foreground">
        Punti della squadra nelle partite in cui giocano entrambi (almeno 6).
      </p>
    </Panel>
  );
}

function VictimsPanel({ page }: { page: ZonePlayerPage }) {
  if (page.victims.length === 0) return null;
  return (
    <Panel title="Le sue vittime" kicker="A chi ha segnato di più">
      <ul className="space-y-1.5">
        {page.victims.map((v, i) => {
          const body = (
            <>
              <ClubCrest club={v.club} size={36} />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">{v.club.name}</div>
                <div className="text-[11px] text-muted-foreground num">
                  in {v.matches} {v.matches === 1 ? "partita" : "partite"}
                </div>
              </div>
              <div className="text-right">
                <span className="font-display text-3xl leading-none text-primary num">
                  <CountUp value={v.goals} duration={0.8} />
                </span>
                <span className="ml-1 text-[10px] uppercase text-muted-foreground">gol</span>
              </div>
            </>
          );
          const cls = "flex items-center gap-3 rounded-lg bg-background/40 p-2";
          return (
            <Reveal as="li" key={v.club.id ?? v.club.name} i={i} x={-14} y={0}>
              {v.club.id !== null ? (
                <Link
                  to="/mixed-zone/squadre/$id"
                  params={{ id: String(v.club.id) }}
                  className={cn(cls, "press hover:bg-accent")}
                >
                  {body}
                </Link>
              ) : (
                <div className={cls}>{body}</div>
              )}
            </Reveal>
          );
        })}
      </ul>
    </Panel>
  );
}

function CareerTable({ page }: { page: ZonePlayerPage }) {
  const rows = page.player.clubs.flatMap((c) => c.tournaments.map((t) => ({ club: c.club, t })));
  if (rows.length === 0) return null;
  return (
    <Panel title="Carriera, torneo per torneo" kicker="Dal profilo XFive">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[480px] text-sm">
          <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="py-2 text-left">Torneo</th>
              <th className="text-left">Squadra</th>
              <th className="text-right">Stagione</th>
            </tr>
          </thead>
          <tbody>
            {rows.map(({ club, t }, idx) => (
              <motion.tr
                key={`${club.id}-${t.id}`}
                initial={{ opacity: 0 }}
                whileInView={{ opacity: 1 }}
                viewport={{ once: true, amount: 0.2 }}
                transition={{ duration: dur.base, ease: ease.out, delay: Math.min(idx, 8) * 0.035 }}
                className="border-t"
              >
                <td className="py-2 pr-2">
                  <Link
                    to="/mixed-zone/tornei/$id"
                    params={{ id: String(t.id) }}
                    className="font-semibold hover:text-primary"
                  >
                    {t.name}
                  </Link>
                  <div className="text-xs text-muted-foreground">{sportLabel(t.sport)}</div>
                </td>
                <td className="pr-2">
                  <div className="flex items-center gap-2">
                    <ClubCrest club={club} size={22} />
                    <span className="max-w-[10rem] truncate md:max-w-none">{club.name}</span>
                  </div>
                </td>
                <td className="text-right num">{t.season}</td>
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

function RecentMatches({ page }: { page: ZonePlayerPage }) {
  const [all, setAll] = useState(false);
  const rows = page.recent;
  const shown = all ? rows : rows.slice(0, 8);
  if (rows.length === 0) return null;
  // la squadra per cui giocava: quella fra le sue che appare nella partita
  const mine = new Set(page.player.clubs.map((c) => c.club.id));
  return (
    <Panel title="Le sue ultime partite" kicker={`${rows.length} nei referti`}>
      <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
        {shown.map((m, i) => {
          const club = mine.has(m.home.id) ? m.home.id : mine.has(m.away.id) ? m.away.id : null;
          const o = outcomeFor(m, club);
          return (
            <div key={m.id} className="relative">
              <MatchRow m={m} i={i} highlightClub={club} />
              {(m.goals > 0 || m.mvp) && (
                <div className="pointer-events-none absolute right-12 top-3 flex items-center gap-1 text-xs font-bold">
                  {m.goals > 0 && (
                    <span className="rounded-full bg-primary/15 px-2 py-0.5 text-primary num">
                      ⚽ {m.goals}
                    </span>
                  )}
                  {m.mvp && (
                    <Star
                      className="h-4 w-4 fill-warning text-warning"
                      aria-label="Miglior giocatore"
                    />
                  )}
                </div>
              )}
              {o === null && <span className="sr-only">Esito non disponibile</span>}
            </div>
          );
        })}
      </div>
      {rows.length > 8 && (
        <button
          type="button"
          onClick={() => setAll(!all)}
          className="press mt-2 min-h-11 w-full rounded-lg border text-sm font-semibold hover:bg-accent"
        >
          {all ? "Mostra meno" : `Mostra tutte le ${rows.length} partite`}
        </button>
      )}
    </Panel>
  );
}
