import { createFileRoute } from "@tanstack/react-router";
import {
  AlertTriangle,
  ChevronDown,
  ExternalLink,
  FileText,
  Info,
  Send,
  Sparkles,
} from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { api } from "@/api/client";
import { useAskDocuments, useDocuments } from "@/api/hooks";
import type { DocCategory, Modulistica, Sanction, XfiveDocument } from "@/api/types";
import { Btn, TextInput, toastError } from "@/components/admin/kit";
import { Checklist, categoryLabel, fmtSize, kindLabel, norm } from "@/components/admin/docs-ui";
import { Reveal } from "@/components/motion";
import { Panel } from "@/components/stats-ui";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { EmptyState, ErrorState, InfoBanner, PageTitle, Skeleton } from "@/components/ui-kit";
import { fmtDate } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/documenti")({
  head: () => ({
    meta: [
      { title: "Documenti XFive - AMIR COSTRUZIONI" },
      {
        name: "description",
        content:
          "La modulistica XFive letta e riassunta: sanzioni, regole di gioco, checklist, costi e convenzioni.",
      },
      { property: "og:title", content: "Documenti XFive - AMIR COSTRUZIONI" },
      { property: "og:description", content: "La modulistica XFive riassunta per lo staff." },
    ],
  }),
  component: DocumentsPage,
});

const EXAMPLES = [
  "Quanto costa un'espulsione diretta?",
  "Cosa rischiamo se non ci presentiamo a una partita?",
  "Cosa serve per tesserare un giocatore nuovo?",
  "Chi porta i palloni se giochiamo in casa?",
  "Quanto costa un contratto di 2 anni?",
];

function DocumentsPage() {
  const q = useDocuments();
  return (
    <div className="space-y-4">
      <PageTitle kicker="Area staff" title="Documenti XFive" />
      {q.isPending ? (
        <div className="space-y-3">
          <Skeleton className="h-40" />
          <Skeleton className="h-64" />
        </div>
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (
        <Content m={q.data} />
      )}
    </div>
  );
}

function Content({ m }: { m: Modulistica }) {
  const [tab, setTab] = useState("documenti");
  const [openSlug, setOpenSlug] = useState<string | null>(null);
  const goTo = (slug: string) => {
    setTab("documenti");
    setOpenSlug(slug);
  };

  // dopo aver cambiato scheda si porta il documento scelto in vista
  useEffect(() => {
    if (!openSlug) return;
    const t = setTimeout(
      () =>
        document
          .getElementById(`doc-${openSlug}`)
          ?.scrollIntoView({ behavior: "smooth", block: "start" }),
      80,
    );
    return () => clearTimeout(t);
  }, [openSlug, tab]);

  return (
    <>
      <p className="-mt-2 max-w-3xl text-sm text-muted-foreground">
        I {m.documents.length} documenti del menu «Modulistica» di XFive, letti e riassunti il{" "}
        {fmtDate(m.updated_on)}. Per ogni dubbio vale sempre l'originale: si apre dalla scheda del
        documento.
      </p>
      <Ask goTo={goTo} />
      <Alerts m={m} goTo={goTo} />
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="flex h-auto w-full justify-start gap-1 overflow-x-auto p-1">
          <TabsTrigger value="documenti" className="min-h-10">
            Documenti ({m.documents.length})
          </TabsTrigger>
          <TabsTrigger value="sanzioni" className="min-h-10">
            Sanzioni
          </TabsTrigger>
          <TabsTrigger value="regole" className="min-h-10">
            Regole di gioco
          </TabsTrigger>
          <TabsTrigger value="checklist" className="min-h-10">
            Checklist
          </TabsTrigger>
          <TabsTrigger value="costi" className="min-h-10">
            Costi
          </TabsTrigger>
        </TabsList>
        <TabsContent value="documenti" className="mt-4">
          <DocsTab m={m} openSlug={openSlug} setOpenSlug={setOpenSlug} />
        </TabsContent>
        <TabsContent value="sanzioni" className="mt-4">
          <SanctionsTab m={m} />
        </TabsContent>
        <TabsContent value="regole" className="mt-4">
          <RulesTab m={m} />
        </TabsContent>
        <TabsContent value="checklist" className="mt-4">
          <ChecklistsTab m={m} />
        </TabsContent>
        <TabsContent value="costi" className="mt-4">
          <CostsTab m={m} goTo={goTo} />
        </TabsContent>
      </Tabs>
    </>
  );
}

// ---------------------------------------------------------------- domande

function Ask({ goTo }: { goTo: (slug: string) => void }) {
  const [text, setText] = useState("");
  const ask = useAskDocuments();
  const send = (question: string) => {
    const t = question.trim();
    if (t.length < 3 || ask.isPending) return;
    setText(t);
    ask.mutate(t, { onError: toastError });
  };
  const r = ask.data;
  return (
    <Panel title="Chiedi ai documenti" kicker="Risposte dai testi di XFive, con le fonti">
      <form
        onSubmit={(e: FormEvent) => {
          e.preventDefault();
          send(text);
        }}
        className="flex flex-col gap-2 sm:flex-row"
      >
        <TextInput
          value={text}
          onChange={(e) => setText(e.target.value)}
          maxLength={500}
          placeholder="Es. quanto costa un'espulsione diretta?"
          aria-label="La tua domanda sui documenti"
        />
        <Btn type="submit" disabled={ask.isPending || text.trim().length < 3} className="sm:w-40">
          <Send className="h-4 w-4" /> {ask.isPending ? "Cerco…" : "Chiedi"}
        </Btn>
      </form>
      <div className="mt-2 flex flex-wrap gap-1.5">
        {EXAMPLES.map((x) => (
          <button
            key={x}
            type="button"
            onClick={() => send(x)}
            className="press min-h-9 rounded-full border px-3 text-xs font-semibold text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            {x}
          </button>
        ))}
      </div>

      {r && (
        <div className="mt-4 space-y-3" aria-live="polite">
          {r.answer && (
            <Reveal now blur y={8} className="rounded-xl border border-primary/30 bg-primary/5 p-4">
              <div className="mb-2 inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-primary">
                <Sparkles className="h-3.5 w-3.5" /> Risposta dell'IA
                {r.model ? ` · ${r.model}` : ""}
              </div>
              <p className="whitespace-pre-wrap text-sm">{r.answer}</p>
              <p className="mt-2 text-[11px] text-muted-foreground">
                Controlla sempre la fonte qui sotto: l'IA può sbagliare.
              </p>
            </Reveal>
          )}
          {r.note && <InfoBanner>{r.note}</InfoBanner>}
          {r.passages.length === 0 ? (
            <EmptyState>
              Nei documenti non c'è nulla su questo. Prova con altre parole o chiedi a XFive
              (WhatsApp 338 643 0353).
            </EmptyState>
          ) : (
            <div>
              <div className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                Dove l'ho trovato
              </div>
              <ul className="space-y-1.5">
                {r.passages.map((p, i) => (
                  <Reveal
                    as="li"
                    now
                    key={`${p.doc}-${p.label}-${i}`}
                    i={i}
                    delay={0.15}
                    y={8}
                    className="rounded-lg border p-3 text-sm"
                  >
                    <div className="mb-1 flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        onClick={() => goTo(p.doc)}
                        className="press rounded-full bg-secondary px-2 py-0.5 text-[11px] font-bold hover:bg-accent"
                      >
                        {p.doc_title}
                      </button>
                      <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {p.label}
                      </span>
                    </div>
                    <p>{p.text}</p>
                  </Reveal>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Panel>
  );
}

// ---------------------------------------------------------------- da sapere

function Alerts({ m, goTo }: { m: Modulistica; goTo: (slug: string) => void }) {
  const doc = (slug: string) => m.documents.find((d) => d.slug === slug);
  const card = (a: Modulistica["alerts"][number], idx: number) => {
    const warn = a.level === "warning";
    const Icon = warn ? AlertTriangle : Info;
    return (
      <Reveal
        key={a.title}
        i={idx}
        className={cn(
          "rounded-xl border p-3 text-sm",
          warn ? "border-warning/40 bg-warning/10" : "bg-card",
        )}
      >
        <div className="flex items-start gap-2">
          <Icon
            className={cn(
              "mt-0.5 h-4 w-4 shrink-0",
              warn ? "text-warning" : "text-muted-foreground",
            )}
          />
          <div className="min-w-0">
            <div className="font-semibold">{a.title}</div>
            <p className="mt-0.5 text-muted-foreground">{a.text}</p>
            <div className="mt-2 flex flex-wrap gap-1.5">
              {a.docs.map((slug) => {
                const d = doc(slug);
                return d ? (
                  <button
                    key={slug}
                    type="button"
                    onClick={() => goTo(slug)}
                    className="min-h-8 rounded-full border px-2.5 text-[11px] font-bold hover:bg-accent"
                  >
                    Doc {d.number} · {d.title}
                  </button>
                ) : null;
              })}
            </div>
          </div>
        </div>
      </Reveal>
    );
  };
  const warnings = m.alerts.filter((a) => a.level === "warning");
  const others = m.alerts.filter((a) => a.level !== "warning");
  return (
    <section aria-label="Da sapere" className="space-y-2">
      {warnings.length > 0 && <div className="grid gap-2 md:grid-cols-2">{warnings.map(card)}</div>}
      {others.length > 0 && (
        <details className="group rounded-xl border bg-card p-3">
          <summary className="cursor-pointer text-sm font-semibold">
            Altri {others.length} punti da chiarire con XFive
          </summary>
          <div className="mt-3 grid gap-2 md:grid-cols-2">{others.map(card)}</div>
        </details>
      )}
    </section>
  );
}

// ---------------------------------------------------------------- documenti

async function openOriginal(slug: string) {
  const win = window.open("", "_blank"); // aperta subito, altrimenti il browser blocca il popup
  try {
    const url = URL.createObjectURL(await api.getDocumentFile(slug));
    if (win) win.location.href = url;
    else window.open(url, "_blank");
    setTimeout(() => URL.revokeObjectURL(url), 5 * 60_000);
  } catch (e) {
    win?.close();
    toastError(e);
  }
}

const chip = "rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider";

function DocsTab({
  m,
  openSlug,
  setOpenSlug,
}: {
  m: Modulistica;
  openSlug: string | null;
  setOpenSlug: (s: string | null) => void;
}) {
  const [cat, setCat] = useState<DocCategory | "all">("all");
  const [text, setText] = useState("");
  const cats = (Object.keys(categoryLabel) as DocCategory[]).filter((c) =>
    m.documents.some((d) => d.category === c),
  );
  const needle = norm(text.trim());
  const list = m.documents.filter(
    (d) =>
      d.slug === openSlug ||
      ((cat === "all" || d.category === cat) &&
        (!needle ||
          norm(
            [d.title, d.purpose, d.summary, d.when, ...d.key_points, ...d.actions].join(" "),
          ).includes(needle))),
  );
  const pill = (active: boolean) =>
    cn(
      "min-h-9 rounded-full border px-3 text-xs font-semibold",
      active
        ? "border-primary bg-primary text-primary-foreground"
        : "text-muted-foreground hover:bg-accent",
    );
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-1.5">
        <button type="button" onClick={() => setCat("all")} className={pill(cat === "all")}>
          Tutti ({m.documents.length})
        </button>
        {cats.map((c) => (
          <button key={c} type="button" onClick={() => setCat(c)} className={pill(cat === c)}>
            {categoryLabel[c]} ({m.documents.filter((d) => d.category === c).length})
          </button>
        ))}
      </div>
      <TextInput
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="Cerca nei documenti…"
        aria-label="Cerca nei documenti"
      />
      {list.length === 0 ? (
        <EmptyState>Nessun documento corrisponde alla ricerca.</EmptyState>
      ) : (
        <div className="grid gap-3 lg:grid-cols-2">
          {list.map((d) => (
            <DocCard
              key={d.slug}
              d={d}
              open={openSlug === d.slug}
              onToggle={() => setOpenSlug(openSlug === d.slug ? null : d.slug)}
            />
          ))}
        </div>
      )}
    </div>
  );
}

function DocCard({ d, open, onToggle }: { d: XfiveDocument; open: boolean; onToggle: () => void }) {
  return (
    <article
      id={`doc-${d.slug}`}
      className={cn(
        "scroll-mt-4 rounded-xl border bg-card p-4",
        open && "border-primary/50 lg:col-span-2",
      )}
    >
      <div className="flex items-start gap-3">
        <span
          className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/15 font-display text-lg text-primary num"
          aria-label={`Documento ${d.number}`}
        >
          {d.number}
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="text-lg leading-tight">{d.title}</h3>
          <div className="mt-1.5 flex flex-wrap gap-1.5">
            <span className={cn(chip, "bg-secondary text-muted-foreground")}>
              {categoryLabel[d.category]}
            </span>
            <span className={cn(chip, "bg-secondary text-muted-foreground")}>
              {kindLabel[d.kind]}
            </span>
            {d.fill_in && (
              <span className={cn(chip, "bg-warning/20 text-warning")}>Da compilare e firmare</span>
            )}
          </div>
        </div>
      </div>
      <p className="mt-3 text-sm">{d.purpose}</p>
      <p className="mt-2 text-xs text-muted-foreground">
        <strong className="text-foreground">Quando serve:</strong> {d.when}
      </p>
      <div className="mt-3 flex flex-wrap gap-2">
        <Btn onClick={() => openOriginal(d.slug)} className="min-h-10">
          <FileText className="h-4 w-4" /> Apri l'originale
          {d.size_bytes ? ` · ${fmtSize(d.size_bytes)}` : ""}
        </Btn>
        <a
          href={d.source_url}
          target="_blank"
          rel="noreferrer"
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-input bg-card px-4 text-sm font-semibold hover:bg-accent"
        >
          <ExternalLink className="h-4 w-4" /> Sul sito XFive
        </a>
        <Btn variant="ghost" onClick={onToggle} aria-expanded={open} className="min-h-10">
          <ChevronDown className={cn("h-4 w-4 transition-transform", open && "rotate-180")} />{" "}
          {open ? "Meno dettagli" : "Cosa dice"}
        </Btn>
      </div>
      {open && (
        <div className="mt-4 space-y-4 border-t pt-4 text-sm">
          <p>{d.summary}</p>
          <div>
            <h4 className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
              Punti chiave
            </h4>
            <ul className="list-disc space-y-1 pl-5">
              {d.key_points.map((p) => (
                <li key={p}>{p}</li>
              ))}
            </ul>
          </div>
          {d.amounts.length > 0 && (
            <div>
              <h4 className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                Importi
              </h4>
              <dl className="divide-y rounded-lg border">
                {d.amounts.map((a) => (
                  <div
                    key={a.label}
                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5 px-3 py-2"
                  >
                    <dt>{a.label}</dt>
                    <dd className="font-bold num">{a.amount}</dd>
                  </div>
                ))}
              </dl>
            </div>
          )}
          <div className="rounded-lg bg-success/10 p-3">
            <h4 className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-success">
              Cosa fare per noi
            </h4>
            <ul className="list-disc space-y-1 pl-5">
              {d.actions.map((p) => (
                <li key={p}>{p}</li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </article>
  );
}

// ---------------------------------------------------------------- sanzioni

const areaLabel: Record<Sanction["area"], string> = {
  disciplina: "Disciplina",
  rinuncia: "Rinunce e assenze",
  tesseramento: "Tesseramento",
  organizzazione: "Organizzazione",
  cauzione: "Cauzione",
  danni: "Danni",
};

function amountTone(s: Sanction) {
  if (s.cents_max === 0) return "bg-success/20 text-success";
  if (s.cents_min === null) return "bg-secondary text-muted-foreground";
  return s.cents_min >= 10000 ? "bg-primary/20 text-primary" : "bg-warning/20 text-warning";
}

function SanctionsTab({ m }: { m: Modulistica }) {
  const [text, setText] = useState("");
  const [area, setArea] = useState<Sanction["area"] | "all">("all");
  const areas = (Object.keys(areaLabel) as Sanction["area"][]).filter((a) =>
    m.sanctions.some((s) => s.area === a),
  );
  const needle = norm(text.trim());
  const list = m.sanctions.filter(
    (s) =>
      (area === "all" || s.area === area) &&
      (!needle || norm(`${s.code} ${s.title} ${s.amount} ${s.extra}`).includes(needle)),
  );
  const pill = (active: boolean) =>
    cn(
      "min-h-9 rounded-full border px-3 text-xs font-semibold",
      active
        ? "border-primary bg-primary text-primary-foreground"
        : "text-muted-foreground hover:bg-accent",
    );
  return (
    <div className="space-y-3">
      <InfoBanner>
        Ammonizioni, ammonizioni per proteste e squalifiche per somma di ammonizioni non costano
        nulla. Il peso vero sono le partite non giocate e i giocatori non tesserati: 3-0 a tavolino
        più multa.
      </InfoBanner>
      <div className="flex flex-wrap items-center gap-1.5">
        <button type="button" onClick={() => setArea("all")} className={pill(area === "all")}>
          Tutte
        </button>
        {areas.map((a) => (
          <button key={a} type="button" onClick={() => setArea(a)} className={pill(area === a)}>
            {areaLabel[a]}
          </button>
        ))}
      </div>
      <TextInput
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="Cerca: espulsione, ritardo, panchina…"
        aria-label="Cerca nelle sanzioni"
      />
      {list.length === 0 ? (
        <EmptyState>Nessuna sanzione corrisponde.</EmptyState>
      ) : (
        <ul className="space-y-2">
          {list.map((s, idx) => (
            <Reveal
              as="li"
              now
              key={s.code}
              i={idx}
              y={6}
              className="grid grid-cols-[2.75rem_1fr] gap-x-3 gap-y-2 rounded-xl border bg-card p-3 sm:grid-cols-[2.75rem_1fr_auto]"
            >
              <span className="grid h-9 place-items-center rounded-lg bg-secondary font-display text-lg num">
                {s.code}
              </span>
              <div className="min-w-0 text-sm">
                <div className="font-semibold">{s.title}</div>
                {s.extra && (
                  <div className="mt-0.5 text-xs font-semibold text-primary">{s.extra}</div>
                )}
                <div className="mt-1 text-[11px] uppercase tracking-wider text-muted-foreground">
                  {areaLabel[s.area]}
                </div>
              </div>
              <div
                className={cn(
                  "col-span-2 self-start rounded-lg px-3 py-1.5 text-sm font-bold sm:col-span-1 sm:max-w-56 sm:text-right",
                  amountTone(s),
                )}
              >
                {s.amount}
              </div>
            </Reveal>
          ))}
        </ul>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- regole

function RulesTab({ m }: { m: Modulistica }) {
  const [text, setText] = useState("");
  const needle = norm(text.trim());
  const list = m.technical_rules.filter(
    (r) => !needle || norm(`${r.topic} ${r.text}`).includes(needle),
  );
  return (
    <div className="space-y-3">
      <TextInput
        value={text}
        onChange={(e) => setText(e.target.value)}
        placeholder="Cerca una regola: rigore, portiere, sostituzioni…"
        aria-label="Cerca nelle regole di gioco"
      />
      {list.length === 0 ? (
        <EmptyState>Nessuna regola corrisponde.</EmptyState>
      ) : (
        <ul className="space-y-2">
          {list.map((r, idx) => {
            const isNew = r.n.startsWith("2026");
            return (
              <Reveal
                as="li"
                now
                key={r.n}
                i={idx}
                y={6}
                className={cn(
                  "rounded-xl border bg-card p-3",
                  isNew && "border-primary/40 bg-primary/5",
                )}
              >
                <div className="flex items-center gap-2">
                  <span className="rounded-md bg-secondary px-2 py-0.5 font-display text-base num">
                    {isNew ? "Novità 2026/27" : `Art. ${r.n}`}
                  </span>
                  <h3 className="text-base font-semibold leading-tight">{r.topic}</h3>
                </div>
                <p className="mt-1.5 text-sm text-muted-foreground">{r.text}</p>
              </Reveal>
            );
          })}
        </ul>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- checklist

function ChecklistsTab({ m }: { m: Modulistica }) {
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Panel title="Prima di ogni partita" kicker="Per non prendere multe">
        <Checklist
          storageKey="amir_checklist_match_generic"
          items={m.match_checklist}
          footer={m.match_checklist_footer}
        />
      </Panel>
      <Panel title="Iscrizione della squadra" kicker="Passaggi da fare una volta">
        <Checklist
          storageKey="amir_checklist_registration"
          items={m.registration_checklist.map((text) => ({ text, tip: "" }))}
        />
      </Panel>
    </div>
  );
}

// ---------------------------------------------------------------- costi

function CostsTab({ m, goTo }: { m: Modulistica; goTo: (slug: string) => void }) {
  const groups = [...new Set(m.price_list.map((p) => p.group))];
  const titleOf = (slug: string) => m.documents.find((d) => d.slug === slug);
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {groups.map((g) => (
        <Panel key={g} title={g}>
          <dl className="divide-y">
            {m.price_list
              .filter((p) => p.group === g)
              .map((p) => {
                const d = titleOf(p.doc);
                return (
                  <div
                    key={p.label}
                    className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2 text-sm"
                  >
                    <dt className="min-w-0 flex-1">
                      {p.label}
                      {d && (
                        <button
                          type="button"
                          onClick={() => goTo(p.doc)}
                          className="ml-2 rounded-full bg-secondary px-2 py-0.5 text-[10px] font-bold hover:bg-accent"
                        >
                          Doc {d.number}
                        </button>
                      )}
                    </dt>
                    <dd className="font-display text-xl num">{p.amount}</dd>
                  </div>
                );
              })}
          </dl>
        </Panel>
      ))}
    </div>
  );
}
