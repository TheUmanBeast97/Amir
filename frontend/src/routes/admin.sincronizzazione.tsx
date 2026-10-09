import { useQueryClient } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import {
  Archive,
  BarChart3,
  CalendarDays,
  ChartColumn,
  ClipboardList,
  FileText,
  History,
  Images,
  RefreshCw,
  Shield,
  Trophy,
  Users,
  X,
  type LucideIcon,
} from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useRef, useState } from "react";
import { toast } from "sonner";
import { api } from "@/api/client";
import type { SyncProgress, SyncRun, SyncScope, ZoneChunkResult } from "@/api/types";
import type { ZoneStatus } from "@/api/zone-types";
import { ControlRoom } from "@/components/admin/ControlRoom";
import { Btn, Stat, toastError } from "@/components/admin/kit";
import {
  finishedText,
  isZoneScope,
  syncLabel,
  useSyncFlow,
  useZoneStatus,
  type SyncReport,
} from "@/components/admin/sync";
import { Card, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import { AREAS } from "@/lib/area";
import { fmtDateTime } from "@/lib/format";
import { dur, ease } from "@/lib/motion";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/sincronizzazione")({
  head: () => ({
    meta: [
      { title: "Sincronizzazione - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "Centro di sincronizzazione: aggiornamenti da XFive per AMIR e Mixed Zone, caricamento dell'archivio.",
      },
      { property: "og:title", content: "Sincronizzazione - AMIR COSTRUZIONI" },
      {
        property: "og:description",
        content: "Aggiornamenti da XFive e caricamento dell'archivio.",
      },
    ],
  }),
  component: SyncPage,
});

/** «Sincronizza tutto»: AMIR prima (senza storico, che è una tantum), poi tutte le sezioni della Mixed Zone. */
const EVERYTHING: SyncScope[] = [
  "current",
  "details",
  "media",
  "stats",
  "roster",
  "zone-tournaments",
  "zone-calendar",
  "zone-standings",
  "zone-stats",
  "zone-teams",
  "zone-reports",
  "zone-players",
];

interface SectionDef {
  scope: SyncScope;
  icon: LucideIcon;
  what: string;
}

const AMIR_SECTIONS: SectionDef[] = [
  {
    scope: "current",
    icon: CalendarDays,
    what: "Calendario e risultati della stagione in corso. Si aggiorna da solo ogni notte.",
  },
  {
    scope: "history",
    icon: History,
    what: "Le stagioni passate: serve solo con un database nuovo.",
  },
  {
    scope: "details",
    icon: ClipboardList,
    what: "Referti delle partite giocate: arbitro, distinta, marcatori, cartellini.",
  },
  { scope: "media", icon: Images, what: "Stemmi delle squadre e foto dei giocatori che mancano." },
  { scope: "stats", icon: ChartColumn, what: "Statistiche per torneo dei nostri giocatori." },
  {
    scope: "roster",
    icon: Users,
    what: "Profili e foto di tutta la rosa, riletti anche se c'erano già.",
  },
];

const ZONE_SECTIONS: SectionDef[] = [
  {
    scope: "zone-tournaments",
    icon: Trophy,
    what: "Elenchi per stagione e intestazioni dei tornei di calcio nuovi.",
  },
  {
    scope: "zone-calendar",
    icon: CalendarDays,
    what: "Calendario e risultati dei tornei in corso e in arrivo.",
  },
  { scope: "zone-standings", icon: BarChart3, what: "Classifiche (e gironi) dei tornei attivi." },
  {
    scope: "zone-stats",
    icon: ChartColumn,
    what: "Marcatori, miglior giocatore e disciplina dei tornei attivi.",
  },
  {
    scope: "zone-teams",
    icon: Shield,
    what: "Squadre iscritte e rose dei tornei attivi, con i profili collegati.",
  },
  {
    scope: "zone-reports",
    icon: FileText,
    what: "Referti delle partite giocate che non ne hanno ancora uno.",
  },
  {
    scope: "zone-players",
    icon: Users,
    what: "Profili dei giocatori mancanti o non riletti da 30 giorni.",
  },
];

/** I conteggi del backend, in italiano; quelli tecnici (richieste, cursore) non si mostrano. */
const COUNT_LABELS: Record<string, string> = {
  tournaments: "tornei",
  matches: "partite",
  clubs: "club",
  standings: "classifiche",
  groups: "gironi",
  stats: "tabelle",
  teams: "squadre",
  roster: "in rosa",
  linked: "collegati",
  reports: "referti",
  lineup: "righe di distinta",
  players: "profili",
  lists: "elenchi",
  updated: "aggiornati",
  skipped: "scartati",
  missing: "non trovati",
  errors: "errori",
  items: "voci",
  games: "referti",
  rows: "righe",
  badges: "stemmi",
  photos: "foto",
  created: "creati",
};

/** Le sezioni dell'archivio (zone-NNN-sezione.json.gz), in italiano. */
const CHUNK_LABELS: Record<ZoneChunkResult["section"], string> = {
  tournaments: "tornei",
  teams: "squadre",
  clubs: "club",
  players: "giocatori",
  calendar: "calendario",
  tables: "classifiche e statistiche",
  reports: "referti",
};

const chunkSection = (name: string) =>
  (/zone-\d+-([a-z]+)\.json\.gz$/i.exec(name)?.[1] ?? "") as ZoneChunkResult["section"] | "";

const statusCls = {
  running: "bg-warning/15 text-warning",
  ok: "bg-success/15 text-success",
  error: "bg-primary/15 text-primary",
};
const statusLbl = { running: "In corso", ok: "Riuscita", error: "Errore" };

const it = new Intl.NumberFormat("it-IT");

/** I numeri che contano, senza gli zeri e senza le chiavi tecniche. */
function counts(c: Record<string, number> | undefined, max = 4) {
  if (!c) return [];
  return Object.entries(c)
    .filter(([k, v]) => v > 0 && k in COUNT_LABELS && k !== "errors")
    .slice(0, max)
    .map(([k, v]) => `${it.format(v)} ${COUNT_LABELS[k]}`);
}

const fmtSeconds = (s: number) =>
  s < 60 ? `${Math.max(1, Math.round(s))} s` : `${Math.floor(s / 60)} min ${Math.round(s % 60)} s`;

/** Una carta per sezione: cosa fa, com'è andata l'ultima volta, quanto c'è e il pulsante. */
function SectionCard({
  def,
  last,
  zone,
  busy,
  working,
  onRun,
}: {
  def: SectionDef;
  last: SyncRun | undefined;
  zone: ZoneStatus["sections"][number] | undefined;
  busy: boolean;
  working: SyncScope | null;
  onRun: () => void;
}) {
  const Icon = def.icon;
  const zoneScope = isZoneScope(def.scope);
  const when = last?.started_at ?? zone?.synced_at ?? null;
  const remaining = last?.status === "ok" ? (last.stats["remaining"] ?? 0) : 0;
  const nums = counts(zone?.counts ?? last?.stats);
  const active = working === def.scope;

  return (
    <Card className={cn("flex flex-col gap-3", active && "ring-2 ring-primary/60")}>
      <div className="flex items-start gap-3">
        <span
          className="grid h-10 w-10 shrink-0 place-items-center rounded-xl"
          style={{
            background: `color-mix(in oklab, ${zoneScope ? AREAS.mixed.accent : AREAS.hub.accent} 18%, transparent)`,
            color: zoneScope ? AREAS.mixed.accent : AREAS.hub.accent,
          }}
        >
          <Icon className={cn("h-5 w-5", active && "animate-pulse")} />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-lg leading-tight">{syncLabel[def.scope]}</h3>
            {last && (
              <span
                className={cn(
                  "rounded-full px-2 py-0.5 text-[10px] font-bold uppercase",
                  statusCls[last.status],
                )}
              >
                {statusLbl[last.status]}
              </span>
            )}
          </div>
          <p className="mt-1 text-sm text-muted-foreground">{def.what}</p>
        </div>
      </div>

      <dl className="grid gap-1 text-xs text-muted-foreground">
        <div className="flex gap-2">
          <dt className="shrink-0 font-semibold">Ultimo aggiornamento</dt>
          <dd className="capitalize">{when ? fmtDateTime(when) : "mai"}</dd>
        </div>
        {nums.length > 0 && (
          <div className="flex gap-2">
            <dt className="shrink-0 font-semibold">Contiene</dt>
            <dd>{nums.join(" · ")}</dd>
          </div>
        )}
        <div className="flex gap-2">
          <dt className="shrink-0 font-semibold">Da fare</dt>
          <dd>
            {last?.status === "error"
              ? (last.error ?? "l'ultimo aggiornamento non è riuscito")
              : remaining > 0
                ? `ne mancano ancora ${it.format(remaining)}`
                : when
                  ? "niente, tutto fatto"
                  : "tutto, non è mai partito"}
          </dd>
        </div>
      </dl>

      <Btn variant="outline" onClick={onRun} disabled={busy} className="mt-auto self-start">
        <RefreshCw className={cn("h-4 w-4", active && "animate-spin")} />{" "}
        {active ? "In corso…" : "Aggiorna"}
      </Btn>
    </Card>
  );
}

/** Il riepilogo di un giro: cosa è stato fatto e in quanto tempo. */
function Report({ report, onClose }: { report: SyncReport; onClose: () => void }) {
  const total = (report.finishedAt - report.startedAt) / 1000;
  const ok = report.outcomes.filter((o) => o.status === "ok").length;
  const errors = report.outcomes.filter((o) => o.status === "error").length;
  return (
    <Card className="border border-success/30">
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <h2 className="text-2xl">Riepilogo</h2>
        <span className="text-sm text-muted-foreground">
          {ok} {ok === 1 ? "sezione riuscita" : "sezioni riuscite"}
          {errors ? `, ${errors} con errori` : ""} in {fmtSeconds(total)}
        </span>
        <button
          type="button"
          onClick={onClose}
          className="press ml-auto grid h-9 w-9 place-items-center rounded-lg hover:bg-accent"
          aria-label="Chiudi il riepilogo"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
      <ul className="divide-y text-sm">
        {report.outcomes.map((o, i) => (
          <motion.li
            key={o.scope}
            className="flex flex-wrap items-center gap-2 py-2"
            initial={{ opacity: 0, x: -8 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ duration: dur.base, ease: ease.out, delay: Math.min(i, 8) * 0.04 }}
          >
            <span
              className={cn(
                "rounded-full px-2 py-0.5 text-[10px] font-bold uppercase",
                o.status === "ok"
                  ? statusCls.ok
                  : o.status === "error"
                    ? statusCls.error
                    : "bg-muted text-muted-foreground",
              )}
            >
              {o.status === "ok" ? "Fatto" : o.status === "error" ? "Errore" : "Saltato"}
            </span>
            <span className="font-semibold">{syncLabel[o.scope]}</span>
            <span className="w-full text-xs text-muted-foreground md:ml-auto md:w-auto">
              {o.status === "ok"
                ? `${finishedText(o.scope, o.stats)} · ${fmtSeconds(o.seconds)}`
                : o.status === "error"
                  ? (o.error ?? "non riuscito")
                  : "non partito perché il giro si è fermato prima"}
            </span>
          </motion.li>
        ))}
      </ul>
    </Card>
  );
}

interface Upload {
  index: number; // pezzi già mandati
  total: number;
  current: string; // etichetta del pezzo in corso
  progress: SyncProgress;
  log: string[];
  results: ZoneChunkResult[];
}

function SyncPage() {
  const flow = useSyncFlow();
  const status = useZoneStatus();
  const queryClient = useQueryClient();
  const fileInput = useRef<HTMLInputElement>(null);
  const [upload, setUpload] = useState<Upload | null>(null);
  const [uploaded, setUploaded] = useState<ZoneChunkResult[] | null>(null);

  const runs = flow.runs.data ?? [];
  const lastOf = (scope: SyncScope) => runs.find((r) => r.scope === scope);
  const zoneOf = (scope: SyncScope) => status.data?.sections.find((s) => s.section === scope);
  // una corsa partita altrove (la notturna, la console): la sala la racconta lo stesso
  const external = runs.find((r) => r.status === "running");
  const busy = flow.busy || upload !== null;
  const scopeNow = flow.working ?? external?.scope ?? null;
  const accent = upload || isZoneScope(scopeNow) ? AREAS.mixed.accent : AREAS.hub.accent;

  /** Manda i pezzi uno alla volta, in ordine di nome; si ferma al primo che non va. */
  const uploadChunks = async (files: File[]) => {
    const list = [...files].sort((a, b) => a.name.localeCompare(b.name, "it", { numeric: true }));
    if (list.length === 0) return;
    const bad = list.find((f) => !/\.gz$/i.test(f.name));
    if (bad) {
      toast.error(`«${bad.name}» non è un pezzo dell'archivio (.json.gz).`);
      return;
    }
    setUploaded(null);
    const results: ZoneChunkResult[] = [];
    let log: string[] = [
      `Carico ${list.length} ${list.length === 1 ? "pezzo" : "pezzi"} dell'archivio`,
    ];
    const show = (index: number, current: string) =>
      setUpload({
        index,
        total: list.length,
        current,
        progress: {
          section: "zone-import",
          message:
            index < list.length
              ? `Pezzo ${index + 1} di ${list.length}: ${current}`
              : "Archivio caricato",
          done: index,
          total: list.length,
        },
        log,
        results: [...results],
      });
    try {
      for (const [i, file] of list.entries()) {
        const section = chunkSection(file.name);
        const label = section ? CHUNK_LABELS[section] : file.name;
        show(i, label);
        const r = await api.uploadZoneChunk(file);
        results.push(r);
        const nums = counts(r.counts, 3);
        log = [
          ...log,
          `Pezzo ${i + 1} di ${list.length} (${CHUNK_LABELS[r.section]}): ${it.format(r.items)} voci${nums.length ? `, ${nums.join(", ")}` : ""}`,
        ].slice(-20);
      }
      show(list.length, "fatto");
      toast.success(`Archivio caricato: ${list.length} ${list.length === 1 ? "pezzo" : "pezzi"}.`);
    } catch (e) {
      toastError(e);
    } finally {
      setUploaded(results);
      setUpload(null);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["zone"] }),
        queryClient.invalidateQueries({ queryKey: ["zone-status"] }),
      ]);
    }
  };

  return (
    <div className="space-y-4">
      <PageTitle kicker="Area staff" title="Sincronizzazione">
        <div className="flex flex-wrap gap-2">
          <Btn onClick={() => flow.start(EVERYTHING)} disabled={busy}>
            <RefreshCw className={cn("h-4 w-4", flow.busy && "animate-spin")} /> Sincronizza tutto
          </Btn>
          <Btn variant="outline" onClick={() => fileInput.current?.click()} disabled={busy}>
            <Archive className="h-4 w-4" /> Carica archivio
          </Btn>
          <input
            ref={fileInput}
            type="file"
            multiple
            accept=".gz,application/gzip,application/x-gzip"
            className="sr-only"
            aria-label="Pezzi dell'archivio XFive (.json.gz)"
            onChange={(e) => {
              const files = Array.from(e.target.files ?? []);
              e.target.value = "";
              void uploadChunks(files);
            }}
          />
        </div>
      </PageTitle>

      <p className="text-sm text-muted-foreground">
        «Sincronizza tutto» fa in fila le sezioni di AMIR e poi tutta la Mixed Zone (qualche minuto:
        lascia aperta la pagina). «Carica archivio» prende i pezzi{" "}
        <code>zone-001-tournaments.json.gz … zone-012-reports.json.gz</code> dell'export e li manda
        uno alla volta, in ordine: si può ripetere senza fare doppioni.
      </p>

      <AnimatePresence mode="wait">
        {busy && (
          <ControlRoom
            key="room"
            active
            label={
              upload
                ? `Archivio: ${upload.current}`
                : scopeNow
                  ? syncLabel[scopeNow]
                  : "Aggiornamento in corso"
            }
            progress={upload ? upload.progress : (flow.progress ?? external?.progress ?? null)}
            log={upload ? upload.log : flow.log}
            accent={accent}
            queue={flow.queue.map((s) => syncLabel[s])}
            title={upload ? "Caricamento dell'archivio" : "Sala di controllo"}
          />
        )}
      </AnimatePresence>

      {flow.report && !busy && <Report report={flow.report} onClose={flow.dismissReport} />}

      {uploaded && uploaded.length > 0 && !busy && (
        <Card className="border border-success/30">
          <div className="mb-2 flex items-center gap-3">
            <h2 className="text-2xl">Archivio caricato</h2>
            <button
              type="button"
              onClick={() => setUploaded(null)}
              className="press ml-auto grid h-9 w-9 place-items-center rounded-lg hover:bg-accent"
              aria-label="Chiudi"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
          <ul className="divide-y text-sm">
            {uploaded.map((r, i) => (
              <li key={i} className="flex flex-wrap items-center gap-2 py-2">
                <span className="font-semibold capitalize">{CHUNK_LABELS[r.section]}</span>
                <span className="text-xs text-muted-foreground md:ml-auto">
                  {it.format(r.items)} voci
                  {counts(r.counts, 3).length ? ` · ${counts(r.counts, 3).join(" · ")}` : ""}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <section aria-labelledby="amir-h">
        <div className="mb-3 flex items-center gap-3">
          <span
            className="h-6 w-1.5 rounded-full"
            style={{ background: AREAS.hub.accent }}
            aria-hidden
          />
          <h2 id="amir-h" className="text-2xl">
            AMIR
          </h2>
          <span className="text-sm text-muted-foreground">la nostra squadra su XFive</span>
        </div>
        {flow.runs.isPending ? (
          <Skeleton className="h-48" />
        ) : flow.runs.isError ? (
          <ErrorState error={flow.runs.error} onRetry={() => flow.runs.refetch()} />
        ) : (
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            {AMIR_SECTIONS.map((def) => (
              <SectionCard
                key={def.scope}
                def={def}
                last={lastOf(def.scope)}
                zone={undefined}
                busy={busy}
                working={flow.working}
                onRun={() => flow.start(def.scope)}
              />
            ))}
          </div>
        )}
      </section>

      <section aria-labelledby="zone-h" className="space-y-3">
        <div className="flex flex-wrap items-center gap-3">
          <span
            className="h-6 w-1.5 rounded-full"
            style={{ background: AREAS.mixed.accent }}
            aria-hidden
          />
          <h2 id="zone-h" className="whitespace-nowrap text-2xl">
            Mixed Zone
          </h2>
          <span className="text-sm text-muted-foreground">tutto XFive Alessandria</span>
          <Btn
            variant="outline"
            className="ml-auto"
            onClick={() => flow.start(ZONE_SECTIONS.map((d) => d.scope))}
            disabled={busy}
          >
            <RefreshCw className={cn("h-4 w-4", isZoneScope(flow.working) && "animate-spin")} />{" "}
            Tutta la Mixed Zone
          </Btn>
        </div>

        {status.isPending ? (
          <Skeleton className="h-20" />
        ) : status.isError ? (
          <ErrorState error={status.error} onRetry={() => status.refetch()} />
        ) : status.data.tournaments === 0 ? (
          <EmptyState>
            La Mixed Zone è vuota: carica l'archivio oppure lancia «Tornei XFive» e poi le altre
            sezioni.
          </EmptyState>
        ) : (
          <div className="grid grid-cols-3 gap-2 md:grid-cols-6">
            <Stat label="Tornei" value={status.data.tournaments} />
            <Stat label="Club" value={status.data.clubs} />
            <Stat label="Squadre" value={status.data.teams} />
            <Stat label="Giocatori" value={status.data.players} />
            <Stat label="Partite" value={status.data.matches} />
            <Stat label="Referti" value={status.data.reports} />
          </div>
        )}

        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
          {ZONE_SECTIONS.map((def) => (
            <SectionCard
              key={def.scope}
              def={def}
              last={lastOf(def.scope)}
              zone={zoneOf(def.scope)}
              busy={busy}
              working={flow.working}
              onRun={() => flow.start(def.scope)}
            />
          ))}
        </div>
      </section>
    </div>
  );
}
