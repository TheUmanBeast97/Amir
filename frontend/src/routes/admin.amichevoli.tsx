import { createFileRoute, Link } from "@tanstack/react-router";
import { Pencil, Plus, Trash2, Users } from "lucide-react";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import {
  useDeleteFriendly,
  useEventResponses,
  useFriendlies,
  useHistory,
  useSaveFriendly,
} from "@/api/hooks";
import type { Friendly, KitColor } from "@/api/types";
import {
  Card,
  Crest,
  EmptyState,
  ErrorState,
  OutcomeBadge,
  PageTitle,
  Skeleton,
} from "@/components/ui-kit";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Btn, Confirm, Field, TextInput, firstErr, toastError } from "@/components/admin/kit";
import { RsvpEditor } from "@/components/admin/RsvpEditor";
import { KitPicker } from "@/components/player-ui";
import { fmtDateTime, opponent, outcome } from "@/lib/format";
import { KIT_LABEL } from "@/lib/kit";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/amichevoli")({
  head: () => ({
    meta: [
      { title: "Amichevoli — Area staff AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Organizza le amichevoli: avversario, data, divisa, presenze e risultato.",
      },
      { property: "og:title", content: "Amichevoli — Area staff AMIR COSTRUZIONI" },
      { property: "og:description", content: "Gestione delle amichevoli." },
    ],
  }),
  component: FriendliesPage,
});

const toLocal = (iso: string | null) =>
  iso
    ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 6e4)
        .toISOString()
        .slice(0, 16)
    : "";

function FriendlyForm({ item, onDone }: { item: Friendly | null; onDone: () => void }) {
  const save = useSaveFriendly();
  const hist = useHistory();
  const known = [...new Set((hist.data?.opponents ?? []).map((o) => o.team.name))];
  const m = item?.match;
  const isHomeNow = m ? m.home_team.is_own : true;

  const [name, setName] = useState(m ? opponent(m).name : "");
  const [when, setWhen] = useState(toLocal(m?.kickoff_at ?? null));
  const [venue, setVenue] = useState(m?.venue ?? "");
  const [home, setHome] = useState(isHomeNow);
  const [kit, setKit] = useState<KitColor | null>(m?.our_kit ?? null);
  const [us, setUs] = useState(
    m && m.home_score !== null ? String(isHomeNow ? m.home_score : m.away_score) : "",
  );
  const [them, setThem] = useState(
    m && m.home_score !== null ? String(isHomeNow ? m.away_score : m.home_score) : "",
  );
  const [errs, setErrs] = useState<Record<string, string>>({});

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const v: Record<string, string> = {};
    if (name.trim().length < 2) v["opponent_name"] = "Inserisci la squadra avversaria.";
    if (!when) v["kickoff_at"] = "Scegli data e ora.";
    if ((us === "") !== (them === ""))
      v["home_score"] = "Inserisci entrambi i punteggi, oppure nessuno.";
    if ((us !== "" && !/^\d{1,2}$/.test(us)) || (them !== "" && !/^\d{1,2}$/.test(them)))
      v["home_score"] = "Punteggi da 0 a 99.";
    setErrs(v);
    if (Object.keys(v).length) return;
    const scored = us !== "" && them !== "";
    save.mutate(
      {
        ...(item ? { id: item.match.id } : {}),
        data: {
          opponent_name: name.trim(),
          kickoff_at: when.replace("T", " "),
          venue: venue.trim() || null,
          is_home: home,
          our_kit: kit,
          home_score: scored ? Number(home ? us : them) : null,
          away_score: scored ? Number(home ? them : us) : null,
        },
      },
      {
        onSuccess: () => {
          toast.success(item ? "Amichevole aggiornata" : "Amichevole creata");
          onDone();
        },
        onError: toastError,
      },
    );
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3">
      <Field
        label="Squadra avversaria *"
        error={errs["opponent_name"] ?? firstErr(save.error, "opponent_name")}
      >
        <TextInput
          list="known-teams"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Es. Real Madrink"
          autoComplete="off"
        />
        <datalist id="known-teams">
          {known.map((n) => (
            <option key={n} value={n} />
          ))}
        </datalist>
      </Field>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field
          label="Data e ora *"
          error={errs["kickoff_at"] ?? firstErr(save.error, "kickoff_at")}
        >
          <TextInput type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} />
        </Field>
        <Field label="Campo">
          <TextInput
            value={venue}
            onChange={(e) => setVenue(e.target.value)}
            placeholder="Es. 100GRIGIO - CAMPO 4"
          />
        </Field>
      </div>
      <div className="flex flex-wrap items-end gap-4">
        <div>
          <div className="mb-1 text-sm font-semibold">Giochiamo</div>
          <div
            role="group"
            aria-label="In casa o in trasferta"
            className="inline-flex overflow-hidden rounded-lg border"
          >
            {(
              [
                [true, "In casa"],
                [false, "In trasferta"],
              ] as const
            ).map(([v, l]) => (
              <button
                key={l}
                type="button"
                aria-pressed={home === v}
                onClick={() => setHome(v)}
                className={cn(
                  "min-h-11 px-3 text-sm font-semibold",
                  home === v ? "bg-accent" : "text-muted-foreground hover:bg-accent/50",
                )}
              >
                {l}
              </button>
            ))}
          </div>
        </div>
        <div>
          <div className="mb-1 text-sm font-semibold">Divisa</div>
          <div className="flex items-center gap-2">
            <KitPicker value={kit} onChange={setKit} />
            {kit && (
              <button
                type="button"
                onClick={() => setKit(null)}
                className="text-xs font-semibold text-muted-foreground underline"
              >
                Togli
              </button>
            )}
          </div>
        </div>
      </div>
      <fieldset className="rounded-lg border p-3">
        <legend className="px-1 text-sm font-semibold">Risultato (a partita finita)</legend>
        <div className="flex items-end gap-3">
          <Field label="AMIR">
            <TextInput
              inputMode="numeric"
              value={us}
              onChange={(e) => setUs(e.target.value)}
              className="w-20 text-center"
            />
          </Field>
          <span className="pb-3 font-display text-xl">–</span>
          <Field label="Avversario">
            <TextInput
              inputMode="numeric"
              value={them}
              onChange={(e) => setThem(e.target.value)}
              className="w-20 text-center"
            />
          </Field>
        </div>
        {(errs["home_score"] ?? firstErr(save.error, "home_score")) && (
          <p role="alert" className="mt-1 text-xs text-primary">
            {errs["home_score"] ?? firstErr(save.error, "home_score")}
          </p>
        )}
        <p className="mt-1 text-xs text-muted-foreground">
          Le amichevoli non contano per storico e statistiche, ma il risultato resta nel calendario.
        </p>
      </fieldset>
      <div className="flex justify-end gap-2">
        <Btn variant="outline" onClick={onDone}>
          Annulla
        </Btn>
        <Btn type="submit" disabled={save.isPending}>
          {save.isPending ? "Salvo…" : "Salva"}
        </Btn>
      </div>
    </form>
  );
}

function Presences({ eventId, played }: { eventId: number; played: boolean }) {
  const r = useEventResponses(eventId);
  if (r.isPending) return <Skeleton className="h-40" />;
  if (r.isError) return <ErrorState error={r.error} onRetry={() => r.refetch()} />;
  return (
    <div>
      {played && (
        <p className="mb-2 text-sm text-muted-foreground">
          Partita giocata: spunta chi c'era davvero.
        </p>
      )}
      <RsvpEditor eventId={eventId} responses={r.data} showAttended={played} />
    </div>
  );
}

function FriendlyCard({ item, onEdit }: { item: Friendly; onEdit: () => void }) {
  const m = item.match;
  const del = useDeleteFriendly();
  const [open, setOpen] = useState(false);
  const opp = opponent(m);
  const played = m.status === "played";
  const o = outcome(m);
  return (
    <Card className="p-3">
      <div className="flex flex-wrap items-center gap-3">
        <Crest team={opp} size={44} />
        <div className="min-w-0 flex-1">
          <div className="truncate font-display text-xl leading-tight">AMIR vs {opp.name}</div>
          <div className="text-xs capitalize text-muted-foreground">
            {fmtDateTime(m.kickoff_at)}
            {m.venue ? ` · ${m.venue}` : ""}
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold">
            <span className="rounded-full bg-secondary px-2 py-0.5">
              {m.home_team.is_own ? "In casa" : "In trasferta"}
            </span>
            {m.our_kit && (
              <span className="rounded-full bg-secondary px-2 py-0.5">
                Divisa {KIT_LABEL[m.our_kit].toLowerCase()}
              </span>
            )}
            {item.event && !played && (
              <span className="num">
                <span className="text-success">{item.event.summary.yes} sì</span> ·{" "}
                <span className="text-warning">{item.event.summary.maybe} forse</span> ·{" "}
                <span className="text-primary">{item.event.summary.no} no</span> ·{" "}
                {item.event.summary.pending} ?
              </span>
            )}
          </div>
        </div>
        {played && o && (
          <div className="flex items-center gap-2">
            <OutcomeBadge o={o} />
            <span className="font-display text-3xl num">
              {m.home_score}–{m.away_score}
            </span>
          </div>
        )}
      </div>
      <div className="mt-2 flex flex-wrap items-center gap-1 border-t pt-2">
        {item.event && (
          <Btn variant="ghost" onClick={() => setOpen(!open)} aria-expanded={open}>
            <Users className="h-4 w-4" /> Presenze
          </Btn>
        )}
        <Link
          to="/admin/partite/$matchId"
          params={{ matchId: String(m.id) }}
          className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-semibold text-primary hover:bg-accent"
        >
          Convocati e formazione
        </Link>
        <div className="ml-auto flex">
          <Btn variant="ghost" className="px-2" aria-label="Modifica amichevole" onClick={onEdit}>
            <Pencil className="h-4 w-4" />
          </Btn>
          <Confirm
            title="Eliminare l'amichevole?"
            description="Verranno eliminate anche le risposte dei giocatori, i convocati e la formazione."
            confirmLabel="Elimina"
            onConfirm={() =>
              del.mutate(m.id, {
                onSuccess: () => toast.success("Amichevole eliminata"),
                onError: toastError,
              })
            }
          >
            {(openDialog) => (
              <Btn
                variant="ghost"
                className="px-2 text-primary"
                aria-label="Elimina amichevole"
                onClick={openDialog}
              >
                <Trash2 className="h-4 w-4" />
              </Btn>
            )}
          </Confirm>
        </div>
      </div>
      {open && item.event && (
        <div className="mt-3 border-t pt-3">
          <Presences eventId={item.event.id} played={played} />
        </div>
      )}
    </Card>
  );
}

function FriendliesPage() {
  const q = useFriendlies();
  const [form, setForm] = useState<Friendly | null | undefined>(undefined);
  const upcoming = (q.data ?? [])
    .filter((f) => f.match.status !== "played")
    .sort((a, b) => (a.match.kickoff_at ?? "").localeCompare(b.match.kickoff_at ?? ""));
  const past = (q.data ?? []).filter((f) => f.match.status === "played");
  return (
    <div>
      <PageTitle kicker="Area staff" title="Amichevoli">
        <Btn onClick={() => setForm(null)}>
          <Plus className="h-4 w-4" /> Nuova amichevole
        </Btn>
      </PageTitle>
      <p className="-mt-2 mb-4 text-sm text-muted-foreground">
        Le partite non ufficiali organizzate da noi. Compaiono nel calendario del sito, hanno
        presenze, convocati e formazione come le altre, ma non contano per storico e statistiche.
      </p>
      {q.isPending ? (
        <Skeleton className="h-64" />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => q.refetch()} />
      ) : (q.data ?? []).length === 0 ? (
        <EmptyState>Nessuna amichevole. Organizzane una per tenere il gruppo in forma!</EmptyState>
      ) : (
        <div className="space-y-6">
          {upcoming.length > 0 && (
            <section>
              <h2 className="mb-2 text-2xl">Da giocare</h2>
              <div className="space-y-2">
                {upcoming.map((f) => (
                  <FriendlyCard key={f.match.id} item={f} onEdit={() => setForm(f)} />
                ))}
              </div>
            </section>
          )}
          {past.length > 0 && (
            <section>
              <h2 className="mb-2 text-2xl">Giocate</h2>
              <div className="space-y-2">
                {past.map((f) => (
                  <FriendlyCard key={f.match.id} item={f} onEdit={() => setForm(f)} />
                ))}
              </div>
            </section>
          )}
        </div>
      )}
      <Dialog open={form !== undefined} onOpenChange={(o) => !o && setForm(undefined)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{form ? "Modifica amichevole" : "Nuova amichevole"}</DialogTitle>
            <DialogDescription>
              {form
                ? "Aggiorna data, campo, divisa o inserisci il risultato."
                : "Scegli l'avversaria e quando si gioca: ai giocatori basta il link personale per rispondere."}
            </DialogDescription>
          </DialogHeader>
          {form !== undefined && <FriendlyForm item={form} onDone={() => setForm(undefined)} />}
        </DialogContent>
      </Dialog>
    </div>
  );
}
