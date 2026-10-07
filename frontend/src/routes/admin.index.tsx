import { createFileRoute, Link } from "@tanstack/react-router";
import {
  AlertTriangle,
  CalendarClock,
  ClipboardList,
  Euro,
  FileText,
  Image,
  MapPin,
  RefreshCw,
  Shield,
  Swords,
  Target,
  Trophy,
  Users,
} from "lucide-react";
import { useDashboard, useDocuments } from "@/api/hooks";
import type { Dashboard, DashboardLeader } from "@/api/types";
import { Checklist } from "@/components/admin/docs-ui";
import { useSyncFlow } from "@/components/admin/sync";
import { Countdown, Crest, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { Btn } from "@/components/admin/kit";
import { CountUp, Draw, Reveal } from "@/components/motion";
import { PlayerPhoto } from "@/components/player-ui";
import { Kpi, Panel, ResultDot } from "@/components/stats-ui";
import { fmtDateLong, fmtDateTime, fmtDay, fmtTime, money, opponent, todayISO } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/")({
  head: () => ({
    meta: [
      { title: "Dashboard staff - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Panoramica per lo staff: prossima partita, stagione, Squad List, tesseramenti, certificati, quote e scadenze XFive.",
      },
      { property: "og:title", content: "Dashboard staff - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Panoramica dello staff AMIR COSTRUZIONI." },
    ],
  }),
  component: DashboardPage,
});

const daysLeft = (iso: string) => Math.ceil((Date.parse(`${iso}T23:59:59`) - Date.now()) / 864e5);

function Hero({ d }: { d: Dashboard }) {
  const m = d.next_match;
  const prep = d.overview.next_match_prep;
  if (!m) {
    return (
      <section className="card-cut rounded-xl bg-hero p-6">
        <div className="text-xs font-bold uppercase tracking-[0.2em] text-primary">
          Prossima partita
        </div>
        <p className="mt-2 font-display text-3xl">Nessuna partita in programma</p>
        <Link
          to="/admin/amichevoli"
          className="mt-3 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-primary"
        >
          <Swords className="h-4 w-4" /> Organizza un'amichevole
        </Link>
      </section>
    );
  }
  const opp = opponent(m);
  const rs = prep?.rsvp;
  const total = rs ? rs.yes + rs.maybe + rs.no + rs.pending : 0;
  const chip = (ok: boolean, label: string, detail?: string) => (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold",
        ok ? "bg-success/25 text-success" : "bg-white/10 text-white/80",
      )}
    >
      <span className={cn("h-2 w-2 rounded-full", ok ? "bg-success" : "bg-white/50")} /> {label}
      {detail && <span className="font-normal opacity-80">· {detail}</span>}
    </span>
  );
  return (
    <section className="card-cut relative isolate overflow-hidden rounded-xl bg-[#0b0b0d] p-5 text-white md:p-7">
      <div
        aria-hidden
        className="absolute inset-0 -z-20 bg-cover bg-center"
        style={{ backgroundImage: "url(/sfondo_match.jpg)" }}
      />
      <div
        aria-hidden
        className="absolute inset-0 -z-10 bg-gradient-to-r from-black/70 via-black/35 to-black/60"
      />
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs font-bold uppercase tracking-[0.2em]">
        <span className="rounded-full bg-primary px-3 py-1">
          {m.is_friendly ? "Amichevole" : "Prossima partita"}
        </span>
        <span className="text-white/75">
          {m.competition.name} · {m.round_label}
        </span>
      </div>
      <div className="mt-4 grid items-center gap-5 md:grid-cols-[1fr_auto]">
        <div className="flex items-center gap-4">
          <Crest team={m.home_team} size={72} />
          <span className="font-display text-3xl text-white/80">VS</span>
          <Crest team={m.away_team} size={72} />
          <div className="min-w-0">
            <div className="font-display text-3xl leading-tight md:text-4xl">{opp.name}</div>
            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/85">
              <span className="inline-flex items-center gap-1 capitalize">
                <CalendarClock className="h-4 w-4" />{" "}
                {m.kickoff_at
                  ? `${fmtDateLong(m.kickoff_at)} · ${fmtTime(m.kickoff_at)}`
                  : "Data da definire"}
              </span>
              {m.venue && (
                <span className="inline-flex items-center gap-1">
                  <MapPin className="h-4 w-4" /> {m.venue}
                </span>
              )}
            </div>
          </div>
        </div>
        {m.kickoff_at && <Countdown to={m.kickoff_at} />}
      </div>
      {prep && (
        <div className="mt-5 space-y-3 border-t border-white/15 pt-4">
          <div className="flex flex-wrap gap-2">
            {chip(
              prep.callups_count > 0,
              "Convocati",
              prep.callups_count > 0
                ? `${prep.callups_count}${prep.callups_published ? " · online" : " · non pubblicati"}`
                : "da fare",
            )}
            {chip(
              prep.lineup_saved,
              "Formazione",
              prep.lineup_saved ? (prep.lineup_published ? "online" : "non pubblicata") : "da fare",
            )}
            {m.our_kit
              ? chip(true, `Divisa ${m.our_kit === "red" ? "rossa" : "bianca"}`)
              : chip(false, "Divisa", "da scegliere")}
          </div>
          {rs && total > 0 && (
            <div>
              <div className="mb-1 flex justify-between text-[11px] font-semibold uppercase tracking-wider text-white/70">
                <span>Presenze</span>
                <span className="num">
                  {rs.yes} sì · {rs.maybe} forse · {rs.no} no · {rs.pending} senza risposta
                </span>
              </div>
              <div className="flex h-3 overflow-hidden rounded-full bg-white/15">
                <div className="bg-success" style={{ width: `${(rs.yes / total) * 100}%` }} />
                <div className="bg-warning" style={{ width: `${(rs.maybe / total) * 100}%` }} />
                <div className="bg-primary" style={{ width: `${(rs.no / total) * 100}%` }} />
              </div>
            </div>
          )}
        </div>
      )}
      <Link
        to="/admin/partite/$matchId"
        params={{ matchId: String(m.id) }}
        className="mt-4 inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm font-bold text-black hover:bg-white/90"
      >
        <ClipboardList className="h-4 w-4" /> Gestisci convocati e formazione
      </Link>
    </section>
  );
}

function Leaders({ title, unit, list }: { title: string; unit: string; list: DashboardLeader[] }) {
  return (
    <div>
      <div className="mb-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
        {title}
      </div>
      {list.length === 0 ? (
        <p className="text-xs text-muted-foreground">Ancora nessun dato.</p>
      ) : (
        <ol className="space-y-1.5">
          {list.map((l, i) => (
            <Reveal as="li" key={l.player.id} i={i} x={-10} y={0}>
              <Link
                to="/rosa/$playerId"
                params={{ playerId: String(l.player.id) }}
                className="press flex items-center gap-2 rounded-lg p-1 hover:bg-accent"
              >
                <span
                  className={cn(
                    "w-4 text-center font-display text-lg num",
                    i === 0 ? "text-warning" : "text-muted-foreground",
                  )}
                >
                  {i + 1}
                </span>
                <PlayerPhoto player={l.player} size={30} />
                <span className="min-w-0 flex-1 truncate text-sm font-semibold">
                  {l.player.nickname ?? l.player.full_name}
                </span>
                <span className="font-display text-xl num">
                  <CountUp value={l.value} duration={0.8} />
                  <span className="ml-0.5 text-[10px] text-muted-foreground">{unit}</span>
                </span>
              </Link>
            </Reveal>
          ))}
        </ol>
      )}
    </div>
  );
}

/** Checklist XFive per la prossima partita (dai documenti), con il promemoria in più se giochiamo in casa. */
function PreMatch({ d }: { d: Dashboard }) {
  const m = d.next_match;
  const docs = useDocuments();
  if (!m) return null;
  const extra = m.home_team.is_own
    ? [
        {
          text: "In casa: ritira la sacca dei palloni in segreteria e riconsegnala a fine partita",
          tip: "Novità 2026/27. Servono anche pettorine di un colore diverso dalle nostre maglie.",
        },
      ]
    : [];
  return (
    <Panel
      title="Prima della partita"
      kicker={`Checklist XFive · contro ${opponent(m).name}`}
      className="lg:col-span-2"
      action={
        <Link
          to="/admin/documenti"
          className="inline-flex min-h-9 items-center gap-1 text-xs font-semibold text-primary"
        >
          <FileText className="h-3.5 w-3.5" /> Documenti
        </Link>
      }
    >
      {docs.isPending ? (
        <Skeleton className="h-40" />
      ) : docs.isError ? (
        <p className="text-sm text-muted-foreground">
          Non riesco a caricare la checklist: apri la pagina Documenti XFive.
        </p>
      ) : (
        <Checklist
          storageKey={`amir_checklist_match_${m.id}`}
          items={docs.data.match_checklist}
          footer={docs.data.match_checklist_footer}
          extra={extra}
        />
      )}
    </Panel>
  );
}

/** Le cose da sapere ricavate dalla modulistica (incongruenze, scadenze passate, punti da chiarire). */
function DocsAlerts() {
  const docs = useDocuments();
  const warnings = docs.data?.alerts.filter((a) => a.level === "warning") ?? [];
  return (
    <Panel title="Dai documenti XFive" kicker="Da sapere">
      {docs.isPending ? (
        <Skeleton className="h-32" />
      ) : docs.isError ? (
        <p className="text-sm text-muted-foreground">Documenti non disponibili.</p>
      ) : (
        <>
          <ul className="space-y-2">
            {warnings.map((a, i) => (
              <Reveal
                as="li"
                key={a.title}
                i={i}
                y={8}
                className="flex items-start gap-2 rounded-lg bg-warning/10 p-2.5 text-sm"
              >
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-warning" />
                <div>
                  <div className="font-semibold">{a.title}</div>
                  <p className="text-xs text-muted-foreground">{a.text}</p>
                </div>
              </Reveal>
            ))}
          </ul>
          <Link
            to="/admin/documenti"
            className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg border border-input bg-card px-4 text-sm font-semibold hover:bg-accent"
          >
            <FileText className="h-4 w-4" /> Apri i {docs.data.documents.length} documenti
          </Link>
        </>
      )}
    </Panel>
  );
}

/** Chi compie gli anni nelle prossime settimane (dato personale: solo area staff). */
function Birthdays({ list }: { list: Dashboard["birthdays"] }) {
  return (
    <Panel title="Compleanni" kicker="Prossimi 45 giorni">
      {list.length === 0 ? (
        <p className="text-sm text-muted-foreground">Nessun compleanno in vista.</p>
      ) : (
        <ul className="space-y-1.5">
          {list.map((b, i) => (
            <Reveal as="li" key={b.player.id} i={i} x={-10} y={0}>
              <Link
                to="/rosa/$playerId"
                params={{ playerId: String(b.player.id) }}
                className="press flex items-center gap-2 rounded-lg p-1 hover:bg-accent"
              >
                <PlayerPhoto player={b.player} size={30} />
                <div className="min-w-0 flex-1">
                  <div className="truncate text-sm font-semibold">
                    {b.player.nickname ?? b.player.full_name}
                  </div>
                  <div className="text-[11px] text-muted-foreground num">
                    {fmtDay(b.date).slice(0, 5)} · compie {b.turns} anni
                  </div>
                </div>
                <span
                  className={cn(
                    "shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold num",
                    b.days_left === 0
                      ? "bg-primary text-primary-foreground"
                      : b.days_left <= 7
                        ? "bg-warning/20 text-warning"
                        : "bg-secondary text-muted-foreground",
                  )}
                >
                  {b.days_left === 0 ? "oggi 🎂" : `${b.days_left} gg`}
                </span>
              </Link>
            </Reveal>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function DashboardPage() {
  const q = useDashboard();
  const { start, busy } = useSyncFlow();
  if (q.isPending)
    return (
      <div className="space-y-4">
        <Skeleton className="h-64" />
        <div className="grid gap-3 md:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-28" />
          ))}
        </div>
      </div>
    );
  if (q.isError) return <ErrorState error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data;
  const o = d.overview;
  const { count, limit } = d.squad_list;
  const today = todayISO();
  const r = o.record;
  const next = d.deadlines.find((x) => x.date >= today);

  return (
    <div className="space-y-4">
      <PageTitle kicker="Area staff" title="Dashboard" />
      <Hero d={d} />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Kpi
          i={0}
          label={`Stagione ${o.season}`}
          value={r.played ? `${r.won}-${r.drawn}-${r.lost}` : "-"}
          sub={
            r.played
              ? `${r.points} punti · ${r.goals_for}-${r.goals_against} reti`
              : "Il campionato deve ancora iniziare"
          }
          tone="success"
          icon={<Trophy className="h-6 w-6" />}
        />
        <Reveal i={1} scale={0.97} className="relative overflow-hidden rounded-xl border bg-card p-3 md:p-4">
          <div
            aria-hidden
            className="absolute inset-x-0 top-0 h-16 bg-gradient-to-b from-warning/25 to-warning/0"
          />
          <div className="relative">
            <div className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              Forma recente
            </div>
            <div className="mt-2 flex gap-1.5">
              {o.form.length ? (
                o.form.map((f, i) => <ResultDot key={i} r={f} i={i} className="h-8 w-8 text-sm" />)
              ) : (
                <span className="font-display text-4xl">-</span>
              )}
            </div>
            <div className="mt-2 text-xs text-muted-foreground">
              ultime {o.form.length || 0} partite ufficiali
            </div>
          </div>
        </Reveal>
        <Kpi
          i={2}
          label="Squad List"
          value={
            <>
              <CountUp value={count} />/{limit}
            </>
          }
          tone={count > limit ? "primary" : count === limit ? "success" : "warning"}
          icon={<Shield className="h-6 w-6" />}
          sub={
            count > limit
              ? `Togline ${count - limit}`
              : count < limit
                ? `Ne puoi aggiungere ${limit - count}`
                : "Completa"
          }
        />
        <Kpi
          i={3}
          label="Da incassare"
          value={money(d.finance.outstanding_cents)}
          tone={d.finance.overdue_count ? "primary" : "neutral"}
          icon={<Euro className="h-6 w-6" />}
          sub={
            d.finance.overdue_count
              ? `${d.finance.overdue_count} voci scadute`
              : "Nessuna voce scaduta"
          }
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Panel
          title="I numeri della squadra"
          kicker="Di sempre, rosa attuale"
          className="lg:col-span-2"
        >
          <div className="grid gap-4 sm:grid-cols-3">
            <Leaders title="Marcatori" unit="gol" list={o.leaders.goals} />
            <Leaders title="Presenze" unit="pres." list={o.leaders.matches} />
            <Leaders title="Miglior giocatore" unit="★" list={o.leaders.mvp} />
          </div>
          <p className="mt-3 text-[11px] text-muted-foreground">
            {o.players_count} giocatori in rosa e {o.former_players_count} ex nello storico. Tocca
            un nome per aprire la scheda.
          </p>
        </Panel>

        <Panel title="Tesseramenti" kicker="Rosa attuale">
          {(() => {
            const t =
              d.registrations.none + d.registrations.pending + d.registrations.approved || 1;
            return (
              <>
                <div className="h-4 overflow-hidden rounded-full bg-muted">
                  <Draw className="flex h-full w-full">
                    <div
                      className="bg-success"
                      style={{ width: `${(d.registrations.approved / t) * 100}%` }}
                    />
                    <div
                      className="bg-warning"
                      style={{ width: `${(d.registrations.pending / t) * 100}%` }}
                    />
                    <div
                      className="bg-primary"
                      style={{ width: `${(d.registrations.none / t) * 100}%` }}
                    />
                  </Draw>
                </div>
                <dl className="mt-3 grid grid-cols-3 gap-2 text-center">
                  <div>
                    <dd className="font-display text-3xl text-success num">
                      <CountUp value={d.registrations.approved} duration={0.8} />
                    </dd>
                    <dt className="text-[10px] uppercase tracking-wider text-muted-foreground">
                      Approvati
                    </dt>
                  </div>
                  <div>
                    <dd className="font-display text-3xl text-warning num">
                      <CountUp value={d.registrations.pending} duration={0.8} />
                    </dd>
                    <dt className="text-[10px] uppercase tracking-wider text-muted-foreground">
                      In attesa
                    </dt>
                  </div>
                  <div>
                    <dd className="font-display text-3xl text-primary num">
                      <CountUp value={d.registrations.none} duration={0.8} />
                    </dd>
                    <dt className="text-[10px] uppercase tracking-wider text-muted-foreground">
                      Da richiedere
                    </dt>
                  </div>
                </dl>
              </>
            );
          })()}
        </Panel>

        <Panel
          title="Certificati medici"
          kicker="Prossimi 30 giorni"
          className={d.certificates_expiring.length ? "border-warning/50" : ""}
        >
          {d.certificates_expiring.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              Tutto in regola: nessun certificato in scadenza.
            </p>
          ) : (
            <ul className="space-y-1.5">
              {d.certificates_expiring.map((c, i) => {
                const expired = c.expires_on < today;
                return (
                  <Reveal as="li" key={c.player.id} i={i} x={-10} y={0} className="flex items-center gap-2">
                    <PlayerPhoto player={c.player} size={28} />
                    <span className="min-w-0 flex-1 truncate text-sm font-semibold">
                      {c.player.full_name}
                    </span>
                    <span
                      className={cn(
                        "inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold num",
                        expired ? "bg-primary/20 text-primary" : "bg-warning/20 text-warning",
                      )}
                    >
                      {expired && <AlertTriangle className="h-3 w-3" />}
                      {expired ? "Scaduto " : "Scade "}
                      {fmtDay(c.expires_on)}
                    </span>
                  </Reveal>
                );
              })}
            </ul>
          )}
        </Panel>

        <Panel
          title="Scadenze XFive"
          kicker={next ? `Prossima tra ${Math.max(0, daysLeft(next.date))} giorni` : "Calendario"}
          className="lg:col-span-2"
        >
          <ul className="space-y-1.5">
            {d.deadlines.map((x, i) => {
              const left = daysLeft(x.date);
              const past = x.date < today;
              const isNext = next?.date === x.date && next.title === x.title;
              return (
                <Reveal
                  as="li"
                  key={x.date + x.title}
                  i={i}
                  y={6}
                  className={cn(
                    "flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm",
                    isNext && "bg-primary/10 ring-1 ring-primary/40",
                    past && "text-muted-foreground line-through",
                  )}
                >
                  <span
                    className={cn(
                      "w-24 shrink-0 text-xs font-bold num",
                      isNext ? "text-primary" : "text-muted-foreground",
                    )}
                  >
                    {fmtDay(x.date)}
                  </span>
                  <span className="flex-1">{x.title}</span>
                  {!past && (
                    <span
                      className={cn(
                        "shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold num",
                        left <= 7
                          ? "bg-primary/20 text-primary"
                          : "bg-secondary text-muted-foreground",
                      )}
                    >
                      {left <= 0 ? "oggi" : `${left} gg`}
                    </span>
                  )}
                </Reveal>
              );
            })}
          </ul>
        </Panel>

        <PreMatch d={d} />
        <DocsAlerts />
        <Birthdays list={d.birthdays} />

        <Panel title="Scorciatoie" kicker="Per fare in fretta">
          <div className="grid grid-cols-2 gap-2">
            {(
              [
                ["/admin/amichevoli", "Amichevole", Swords, "from-warning/30"],
                ["/admin/giocatori", "Giocatori", Users, "from-success/30"],
                ["/admin/grafiche", "Grafiche", Image, "from-primary/30"],
                ["/admin/classifiche", "Classifiche", Target, "from-foreground/15"],
                ["/admin/documenti", "Documenti", FileText, "from-warning/30"],
                ["/admin/pagamenti", "Pagamenti", Euro, "from-success/30"],
              ] as const
            ).map(([to, label, Icon, glow], i) => (
              <Reveal key={to} i={i} scale={0.92} y={8}>
                <Link
                  to={to}
                  className={cn(
                    "lift flex min-h-20 flex-col items-center justify-center gap-1 rounded-xl border bg-gradient-to-b to-transparent text-sm font-semibold hover:border-primary/50",
                    glow,
                  )}
                >
                  <Icon className="h-6 w-6" /> {label}
                </Link>
              </Reveal>
            ))}
          </div>
        </Panel>

        <Panel title="Sincronizzazione XFive" kicker="Calendario, risultati, formazioni">
          <p className="text-sm text-muted-foreground">
            {d.sync.status === "never" ? (
              "Mai eseguita."
            ) : (
              <>
                Ultimo aggiornamento:{" "}
                <span className="capitalize">{fmtDateTime(d.sync.last_run_at)}</span> ·{" "}
                {d.sync.status === "ok" ? (
                  <span className="text-success">riuscito</span>
                ) : (
                  <span className="text-primary">errore</span>
                )}
              </>
            )}
          </p>
          <Btn className="mt-3" onClick={() => start("current")} disabled={busy}>
            <RefreshCw className={cn("h-4 w-4", busy && "animate-spin")} />{" "}
            {busy ? "Aggiornamento in corso…" : "Aggiorna da XFive"}
          </Btn>
        </Panel>

        <details className="rounded-xl border bg-card p-4 lg:col-span-2">
          <summary className="cursor-pointer text-xl font-semibold">
            Regole da ricordare ({d.rules.length})
          </summary>
          <ul className="mt-3 list-disc space-y-1 pl-5 text-sm">
            {d.rules.map((x) => (
              <li key={x}>{x}</li>
            ))}
          </ul>
        </details>
      </div>
    </div>
  );
}
