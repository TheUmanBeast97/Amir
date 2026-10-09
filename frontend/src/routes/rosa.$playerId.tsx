import { createFileRoute, Link } from "@tanstack/react-router";
import { motion, useReducedMotion } from "motion/react";
import {
  ArrowLeft,
  Award,
  Flame,
  Globe,
  Medal,
  Shield,
  Star,
  Target,
  Trophy,
  Users,
} from "lucide-react";
import { useMemo, useState } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  PolarAngleAxis,
  PolarGrid,
  Radar,
  RadarChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { useCareer, usePlayerPage } from "@/api/hooks";
import type { PlayerMatchRow, PlayerPage, TeamRecordLine } from "@/api/types";
import { Crest, EmptyState, ErrorState, Skeleton } from "@/components/ui-kit";
import {
  MilestonesPanel,
  PartnersPanel,
  ScoutPanel,
  VictimsPanel,
} from "@/components/player-extras";
import { CountUp, OnView, Reveal } from "@/components/motion";
import { PlayerCardButton } from "@/components/player-card";
import { PlayerPhoto, ShirtBadge } from "@/components/player-ui";
import { CompareBars, Kpi, Panel, RESULT_COLOR, ResultDot } from "@/components/stats-ui";
import { fmtShortDate, kindLabel, roleLabel } from "@/lib/format";
import { dur, ease, spring } from "@/lib/motion";

export const Route = createFileRoute("/rosa/$playerId")({
  head: () => ({
    meta: [
      { title: "Scheda giocatore - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Statistiche, grafici di rendimento, record e carriera di un giocatore di AMIR COSTRUZIONI.",
      },
      { property: "og:title", content: "Scheda giocatore - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Statistiche e rendimento." },
    ],
  }),
  component: PlayerPageRoute,
});

const tip = {
  background: "var(--card)",
  border: "1px solid var(--border)",
  borderRadius: 8,
  color: "var(--foreground)",
  fontSize: 12,
} as const;
const PIE_COLORS = [
  "var(--primary)",
  "var(--success)",
  "var(--warning)",
  "var(--foreground)",
  "var(--muted-foreground)",
];

function PlayerPageRoute() {
  const { playerId } = Route.useParams();
  const q = usePlayerPage(Number(playerId));
  return (
    <div className="space-y-5">
      <Link
        to="/rosa"
        className="press group inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" />{" "}
        La rosa
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

function PlayerView({ page }: { page: PlayerPage }) {
  const { player: p, totals: t } = page;
  const staff = p.role === "dirigente" || p.role === "allenatore";
  const hasStats = t.matches > 0 || t.goals > 0;

  return (
    <>
      <section className="card-cut relative overflow-hidden rounded-xl bg-hero p-5 md:p-8">
        <img
          src="/stemma-amir.png"
          alt=""
          aria-hidden
          className="pointer-events-none absolute -right-6 -top-6 w-48 opacity-[.07]"
        />
        <div className="relative flex flex-col items-center gap-5 md:flex-row md:items-end">
          <motion.div
            initial={{ opacity: 0, scale: 0.7, rotate: -4 }}
            animate={{ opacity: 1, scale: 1, rotate: 0 }}
            transition={{ ...spring.soft, delay: 0.05 }}
          >
            <PlayerPhoto player={p} size={136} className="border-4 border-white/80 shadow-2xl" />
          </motion.div>
          <div className="text-center md:text-left">
            <Reveal now delay={0.2} y={8}>
              <div className="text-xs font-bold uppercase tracking-[0.2em] text-primary">
                {p.role ? roleLabel[p.role] : "Giocatore"}
                {!p.is_active && " · ex giocatore"}
              </div>
            </Reveal>
            <h1 className="text-5xl leading-none md:text-6xl">
              <span className="block overflow-hidden pb-1">
                <motion.span
                  className="block"
                  initial={{ y: "105%" }}
                  animate={{ y: "0%" }}
                  transition={{ duration: dur.hero, ease: ease.out, delay: 0.25 }}
                >
                  {p.full_name}
                </motion.span>
              </span>
            </h1>
            {p.nickname && (
              <Reveal now delay={0.55} y={6} as="p" className="mt-1 italic text-muted-foreground">
                “{p.nickname}”
              </Reveal>
            )}
            <div className="mt-3 flex flex-wrap justify-center gap-2 text-xs font-semibold md:justify-start">
              {[
                p.nationality,
                p.age ? `${p.age} anni` : null,
                p.first_season
                  ? `Con noi ${
                      p.first_season === p.last_season
                        ? `nel ${p.first_season}`
                        : `dal ${p.first_season} al ${p.last_season}`
                    }`
                  : null,
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
                    className="rounded-full bg-background/60 px-3 py-1"
                  >
                    {c}
                  </Reveal>
                ))}
            </div>
          </div>
          {!staff && (p.shirt_number_red || p.shirt_number_white || p.shirt_number) && (
            <div className="flex items-center gap-3 md:ml-auto">
              {(p.shirt_number_red || p.shirt_number) && (
                <motion.div
                  className="text-center"
                  initial={{ opacity: 0, scale: 0.3, rotate: -20 }}
                  animate={{ opacity: 1, scale: 1, rotate: 0 }}
                  transition={{ ...spring.pop, delay: 0.5 }}
                >
                  <ShirtBadge number={p.shirt_number_red ?? p.shirt_number} kit="red" size={60} />
                  <div className="mt-1 text-[10px] uppercase tracking-wider text-muted-foreground">
                    Rossa
                  </div>
                </motion.div>
              )}
              {p.shirt_number_white && (
                <motion.div
                  className="text-center"
                  initial={{ opacity: 0, scale: 0.3, rotate: 20 }}
                  animate={{ opacity: 1, scale: 1, rotate: 0 }}
                  transition={{ ...spring.pop, delay: 0.62 }}
                >
                  <ShirtBadge number={p.shirt_number_white} kit="white" size={60} />
                  <div className="mt-1 text-[10px] uppercase tracking-wider text-muted-foreground">
                    Bianca
                  </div>
                </motion.div>
              )}
            </div>
          )}
        </div>
        {hasStats && (page.rank.appearances || page.rank.goals) && (
          <div className="relative mt-5 flex flex-wrap justify-center gap-2 text-xs font-semibold md:justify-start">
            {page.rank.appearances && (
              <Reveal
                as="span"
                now
                delay={0.8}
                y={0}
                scale={0.88}
                className="inline-flex items-center gap-1 rounded-full bg-primary/20 px-3 py-1 text-primary"
              >
                <Medal className="h-3.5 w-3.5" /> N° {page.rank.appearances} per presenze nella
                storia (su {page.rank.of})
              </Reveal>
            )}
            {page.rank.goals && (
              <Reveal
                as="span"
                now
                delay={0.9}
                y={0}
                scale={0.88}
                className="inline-flex items-center gap-1 rounded-full bg-primary/20 px-3 py-1 text-primary"
              >
                <Target className="h-3.5 w-3.5" /> N° {page.rank.goals} tra i marcatori di sempre
              </Reveal>
            )}
          </div>
        )}
        {(hasStats && !staff) || p.xfive_person_id ? (
          <Reveal
            now
            delay={1}
            className="relative mt-4 flex flex-wrap items-center justify-center gap-2 md:justify-start"
          >
            {hasStats && !staff && <PlayerCardButton page={page} />}
            {p.xfive_person_id && (
              // la sua carriera con tutte le squadre, nella Mixed Zone
              <Link
                to="/mixed-zone/giocatori/$id"
                params={{ id: String(p.xfive_person_id) }}
                className="press inline-flex min-h-11 items-center gap-2 rounded-lg border border-primary/40 px-4 text-sm font-semibold text-primary hover:bg-primary/10"
              >
                <Globe className="h-4 w-4" /> Carriera XFive
              </Link>
            )}
          </Reveal>
        ) : null}
      </section>

      {!hasStats ? (
        <EmptyState>
          {staff
            ? "Dirigenti e allenatori non hanno statistiche di gioco."
            : "Nessuna presenza registrata per ora: le statistiche compariranno dopo le prime partite."}
        </EmptyState>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            <Kpi
              i={0}
              label="Presenze"
              value={t.matches}
              sub={`${page.by_season.length} ${page.by_season.length === 1 ? "stagione" : "stagioni"}`}
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
              sub={
                t.mvp_points > 0
                  ? `${t.mvp_points} punti nelle classifiche XFive`
                  : "stelle in partita"
              }
              tone="warning"
              icon={<Star className="h-5 w-5" />}
            />
            <Kpi
              i={3}
              label="Vittorie"
              value={
                <>
                  <CountUp value={Math.round(t.win_rate * 100)} />%
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
              sub={
                t.matches
                  ? `${((t.yellow / t.matches) * 10).toFixed(1)} ogni 10 partite`
                  : undefined
              }
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
              value={t.points_per_match}
              decimals={2}
              sub="quando è in campo"
              tone="success"
            />
            <Kpi
              i={7}
              label="Tornei giocati"
              value={t.competitions}
              sub="campionati, coppe, tornei"
              tone="neutral"
              icon={<Award className="h-5 w-5" />}
            />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            {page.scout && <ScoutPanel scout={page.scout} />}
            <SeasonChart page={page} />
            <FormPanel rows={page.form} />
            <MilestonesPanel page={page} />
            <PartnersPanel partners={page.partners} />
            <ImpactPanel page={page} />
            <KindDonut page={page} />
            <RadarPanel page={page} />
            <RecordsPanel page={page} />
            <VictimsPanel victims={page.victims} />
          </div>

          <CareerTable page={page} />
          <MatchLog rows={page.match_log} />
        </>
      )}
    </>
  );
}

function SeasonChart({ page }: { page: PlayerPage }) {
  const still = !!useReducedMotion();
  const data = [...page.by_season].reverse().map((s) => ({
    stagione: s.season.replace(/^20(\d\d)\/20(\d\d)$/, "$1/$2"),
    Presenze: s.matches,
    Gol: s.goals,
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
    </Panel>
  );
}

function FormPanel({ rows }: { rows: PlayerMatchRow[] }) {
  const still = !!useReducedMotion();
  const data = rows.map((r, i) => ({
    n: i + 1,
    gol: r.goals,
    r,
    label: r.opponent.short_name.slice(0, 5),
  }));
  return (
    <Panel title="Forma recente" kicker="Ultime partite">
      <div className="mb-3 flex flex-wrap items-center gap-1.5">
        {rows.map((r, i) => (
          <ResultDot key={r.match_id} r={r.result} i={i} />
        ))}
        <span className="ml-1 text-xs text-muted-foreground">
          dalla più vecchia alla più recente
        </span>
      </div>
      <OnView className="h-44">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 6, right: 6, left: -26, bottom: 0 }}>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            <XAxis
              dataKey="n"
              interval={0}
              tickFormatter={(n: number) => data[n - 1]?.label ?? ""}
              tick={{ fill: "var(--muted-foreground)", fontSize: 9 }}
              axisLine={false}
              tickLine={false}
            />
            <YAxis
              allowDecimals={false}
              tick={{ fill: "var(--muted-foreground)", fontSize: 12 }}
              axisLine={false}
              tickLine={false}
            />
            <Tooltip
              contentStyle={tip}
              cursor={{ fill: "var(--accent)" }}
              formatter={(v: number) => [`${v} gol`, ""]}
              labelFormatter={(_l, payload) => {
                const r = (payload?.[0]?.payload as { r?: PlayerMatchRow } | undefined)?.r;
                return r ? `${r.opponent.name} ${r.score_for}–${r.score_against}` : "";
              }}
            />
            <Bar
              dataKey="gol"
              radius={[4, 4, 0, 0]}
              minPointSize={3}
              isAnimationActive={!still}
              animationDuration={800}
            >
              {data.map((d) => (
                <Cell
                  key={d.n}
                  fill={d.r.result ? RESULT_COLOR[d.r.result] : "var(--muted-foreground)"}
                />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </OnView>
      <p className="mt-1 text-[11px] text-muted-foreground">
        Altezza = gol segnati; colore = esito della squadra (verde vittoria, giallo pareggio, rosso
        sconfitta).
      </p>
    </Panel>
  );
}

function ImpactPanel({ page }: { page: PlayerPage }) {
  const im = page.impact;
  if (!im || im.without.played === 0) {
    return (
      <Panel title="Con e senza di lui" kicker="Effetto sulla squadra">
        <p className="text-sm text-muted-foreground">
          Servono più partite con la distinta letta per confrontare i risultati della squadra con e
          senza questo giocatore.
        </p>
      </Panel>
    );
  }
  const per = (r: TeamRecordLine, k: "goals_for" | "goals_against") =>
    r.played ? r[k] / r.played : 0;
  const wr = (r: TeamRecordLine) => (r.played ? (r.won / r.played) * 100 : 0);
  const w = im.with;
  const wo = im.without;
  return (
    <Panel title="Con e senza di lui" kicker="Effetto sulla squadra">
      <div className="space-y-4">
        <CompareBars
          label="Punti a partita"
          a={w.points_per_match}
          b={wo.points_per_match}
          max={3}
          aLabel={`Con (${w.played})`}
          bLabel={`Senza (${wo.played})`}
          format={(n) => n.toFixed(2)}
        />
        <CompareBars
          label="% vittorie"
          a={wr(w)}
          b={wr(wo)}
          max={100}
          aLabel="Con lui"
          bLabel="Senza di lui"
          format={(n) => `${Math.round(n)}%`}
        />
        <CompareBars
          label="Gol fatti a partita"
          a={per(w, "goals_for")}
          b={per(wo, "goals_for")}
          max={Math.max(per(w, "goals_for"), per(wo, "goals_for"), 1)}
          aLabel="Con lui"
          bLabel="Senza di lui"
          format={(n) => n.toFixed(1)}
        />
        <CompareBars
          label="Gol subiti a partita"
          a={per(w, "goals_against")}
          b={per(wo, "goals_against")}
          max={Math.max(per(w, "goals_against"), per(wo, "goals_against"), 1)}
          aLabel="Con lui"
          bLabel="Senza di lui"
          format={(n) => n.toFixed(1)}
        />
      </div>
      <p className="mt-3 text-[11px] text-muted-foreground">
        Calcolato su {im.sample} partite tra il suo esordio e l'ultima presenza: dati utili ma non
        una sentenza, il calcio ha mille variabili.
      </p>
    </Panel>
  );
}

function KindDonut({ page }: { page: PlayerPage }) {
  const still = !!useReducedMotion();
  const data = page.by_kind.map((k) => ({
    name: kindLabel[k.kind],
    value: k.matches,
    gol: k.goals,
  }));
  return (
    <Panel title="Dove ha giocato" kicker="Per tipo di competizione">
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
                {data.map((d, i) => (
                  <Cell key={d.name} fill={PIE_COLORS[i % PIE_COLORS.length] ?? "var(--primary)"} />
                ))}
              </Pie>
              <Tooltip
                contentStyle={tip}
                formatter={(v: number, _n, item) => [
                  `${v} presenze · ${(item.payload as { gol: number }).gol} gol`,
                  "",
                ]}
              />
            </PieChart>
          </ResponsiveContainer>
        </OnView>
        <ul className="w-full space-y-1.5 text-sm">
          {data.map((d, i) => (
            <Reveal as="li" key={d.name} i={i} x={12} y={0} className="flex items-center gap-2">
              <span
                className="h-3 w-3 rounded-full"
                style={{ background: PIE_COLORS[i % PIE_COLORS.length] }}
              />
              <span className="flex-1">{d.name}</span>
              <span className="font-bold num">{d.value}</span>
              <span className="w-14 text-right text-xs text-muted-foreground num">{d.gol} gol</span>
            </Reveal>
          ))}
        </ul>
      </div>
    </Panel>
  );
}

function RadarPanel({ page }: { page: PlayerPage }) {
  const still = !!useReducedMotion();
  const career = useCareer();
  const t = page.totals;
  const data = useMemo(() => {
    const pool = (career.data ?? []).filter((r) => r.matches >= 3);
    if (!pool.length || t.matches === 0) return null;
    const max = (f: (r: (typeof pool)[number]) => number) => Math.max(1, ...pool.map(f));
    const norm = (v: number, m: number) => Math.round(Math.min(1, v / m) * 100);
    const cards = (t.yellow + 3 * t.red) / t.matches;
    return [
      {
        asse: "Presenze",
        valore: norm(
          t.matches,
          max((r) => r.matches),
        ),
      },
      {
        asse: "Gol",
        valore: norm(
          t.goals,
          max((r) => r.goals),
        ),
      },
      {
        asse: "Gol a partita",
        valore: norm(
          t.goals_per_match,
          max((r) => r.goals_per_match),
        ),
      },
      {
        asse: "Miglior giocatore",
        valore: norm(
          t.mvp,
          max((r) => r.mvp),
        ),
      },
      { asse: "Punti a partita", valore: norm(t.points_per_match, 3) },
      { asse: "Fair play", valore: Math.round((1 - Math.min(1, cards / 0.6)) * 100) },
    ];
  }, [career.data, t]);
  return (
    <Panel title="Il suo profilo" kicker="Confronto con la storia della squadra">
      {!data ? (
        <p className="text-sm text-muted-foreground">
          Il profilo comparirà con qualche presenza in più.
        </p>
      ) : (
        <>
          <OnView className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <RadarChart data={data} outerRadius="72%">
                <PolarGrid stroke="var(--border)" />
                <PolarAngleAxis
                  dataKey="asse"
                  tick={{ fill: "var(--muted-foreground)", fontSize: 11 }}
                />
                <Radar
                  dataKey="valore"
                  stroke="var(--primary)"
                  fill="var(--primary)"
                  fillOpacity={0.35}
                  isAnimationActive={!still}
                  animationDuration={1000}
                />
                <Tooltip contentStyle={tip} formatter={(v: number) => [`${v}/100`, ""]} />
              </RadarChart>
            </ResponsiveContainer>
          </OnView>
          <p className="text-[11px] text-muted-foreground">
            100 = il migliore di sempre tra chi ha almeno 3 presenze. Fair play: meno cartellini a
            partita, punteggio più alto.
          </p>
        </>
      )}
    </Panel>
  );
}

function RecordsPanel({ page }: { page: PlayerPage }) {
  const r = page.records;
  const line = (m: PlayerMatchRow | null) =>
    m
      ? `${m.date ? fmtShortDate(m.date) : m.season} · ${m.home_away === "H" ? "" : "@ "}${m.opponent.short_name} ${m.score_for}–${m.score_against}`
      : "-";
  const items: [string, string][] = [
    ["Esordio", line(r.debut)],
    ["Primo gol", line(r.first_goal)],
    [
      "Partita da record",
      r.most_goals_in_match
        ? `${r.most_goals_in_match.goals} gol · ${line(r.most_goals_in_match)}`
        : "-",
    ],
    ["Triplette (o più)", String(r.hat_tricks)],
    ["Partite di fila a segno", String(r.scoring_streak)],
    ["Presenze senza subire gol", String(r.clean_appearances)],
  ];
  return (
    <Panel title="Record personali" kicker="Da ricordare">
      <dl className="divide-y">
        {items.map(([k, v], idx) => (
          <Reveal
            key={k}
            i={idx}
            y={6}
            className="flex items-center justify-between gap-3 py-2 text-sm"
          >
            <dt className="text-muted-foreground">{k}</dt>
            <dd className="text-right font-semibold">{v}</dd>
          </Reveal>
        ))}
      </dl>
    </Panel>
  );
}

function CareerTable({ page }: { page: PlayerPage }) {
  return (
    <Panel title="Carriera, torneo per torneo" kicker="Con la maglia AMIR">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[520px] text-sm num">
          <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th className="py-2 text-left">Torneo</th>
              <th>Pres.</th>
              <th>Gol</th>
              <th>Gialli</th>
              <th>Rossi</th>
              <th>★</th>
            </tr>
          </thead>
          <tbody>
            {page.career.map((c, idx) => (
              <motion.tr
                key={c.competition.id}
                initial={{ opacity: 0 }}
                whileInView={{ opacity: 1 }}
                viewport={{ once: true, amount: 0.2 }}
                transition={{ duration: dur.base, ease: ease.out, delay: Math.min(idx, 8) * 0.035 }}
                className="border-t"
              >
                <td className="py-2 pr-2">
                  <div className="font-semibold">{c.competition.name}</div>
                  <div className="text-xs text-muted-foreground">
                    {c.competition.season} · {kindLabel[c.competition.kind]} · a{" "}
                    {c.competition.format}
                  </div>
                </td>
                <td className="text-center">{c.matches || "–"}</td>
                <td className="text-center font-bold">{c.goals}</td>
                <td className="text-center">{c.yellow || "–"}</td>
                <td className="text-center">{c.red || "–"}</td>
                <td className="text-center">{c.mvp || "–"}</td>
              </motion.tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-2 text-[11px] text-muted-foreground">
        Gol e cartellini sono quelli ufficiali delle classifiche XFive quando la distinta della
        partita non era stata pubblicata. “–” = nessuna presenza registrata.
      </p>
    </Panel>
  );
}

function MatchLog({ rows }: { rows: PlayerMatchRow[] }) {
  const [all, setAll] = useState(false);
  const shown = all ? rows : rows.slice(0, 12);
  return (
    <Panel title="Tutte le sue partite" kicker={`${rows.length} presenze`}>
      <ul className="divide-y">
        {shown.map((r, idx) => (
          <Reveal
            as="li"
            key={r.match_id}
            i={idx}
            x={-10}
            y={0}
            className="flex items-center gap-3 py-2"
          >
            <ResultDot r={r.result} i={idx} />
            <Crest team={r.opponent} size={26} />
            <div className="min-w-0 flex-1">
              <div className="truncate text-sm font-semibold">
                {r.home_away === "A" ? "@ " : ""}
                {r.opponent.name}
              </div>
              <div className="truncate text-xs text-muted-foreground">
                {r.date ? fmtShortDate(r.date) : r.season} · {r.competition_name}
              </div>
            </div>
            <div className="flex items-center gap-2 text-sm">
              {r.goals > 0 && <span className="font-bold text-primary">⚽ {r.goals}</span>}
              {r.yellow > 0 && <span title="Ammonizione">🟨</span>}
              {r.red > 0 && <span title="Espulsione">🟥</span>}
              {r.mvp && (
                <Star
                  className="h-4 w-4 fill-warning text-warning"
                  aria-label="Miglior giocatore"
                />
              )}
              <span className="w-12 text-right font-display text-lg num">
                {r.score_for}–{r.score_against}
              </span>
            </div>
          </Reveal>
        ))}
      </ul>
      {rows.length > 12 && (
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
