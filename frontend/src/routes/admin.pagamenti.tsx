import { createFileRoute } from "@tanstack/react-router";
import { MessageCircle, Plus, Trash2, Wallet } from "lucide-react";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import {
  useAddPayment,
  useCharges,
  useCreateCharge,
  useDeleteCharge,
  useDeletePayment,
  useFinanceSummary,
  usePlayerBalance,
  usePlayers,
} from "@/api/hooks";
import type { ChargeKind, Id, Payment, PlayerBalance, PlayerCharge } from "@/api/types";
import { Card, EmptyState, ErrorState, PageTitle, Skeleton } from "@/components/ui-kit";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Btn,
  Confirm,
  Field,
  NativeSelect,
  Stat,
  TextInput,
  firstErr,
  toastError,
  waLink,
} from "@/components/admin/kit";
import { chargeKindLabel, fmtDay, methodLabel, money, todayISO } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/admin/pagamenti")({
  head: () => ({
    meta: [
      { title: "Pagamenti — Area staff AMIR COSTRUZIONI" },
      {
        name: "description",
        content: "Quote, multe e pagamenti dei giocatori: quanto dovuto e quanto versato.",
      },
      { property: "og:title", content: "Pagamenti — Area staff AMIR COSTRUZIONI" },
      { property: "og:description", content: "Registro quote e pagamenti." },
    ],
  }),
  component: PaymentsPage,
});

const parseEuro = (s: string) => Math.round(Number(s.replace(",", ".")) * 100);
type Row = Omit<PlayerBalance, "items">;

function NewChargeDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
}) {
  const players = usePlayers();
  const create = useCreateCharge();
  const [title, setTitle] = useState("");
  const [kind, setKind] = useState<ChargeKind>("quota_stagione");
  const [amount, setAmount] = useState("");
  const [due, setDue] = useState("");
  const [allP, setAllP] = useState(true);
  const [sel, setSel] = useState<Id[]>([]);
  const [errs, setErrs] = useState<Record<string, string>>({});
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const v: Record<string, string> = {};
    if (!title.trim()) v["title"] = "Inserisci una descrizione.";
    const cents = parseEuro(amount);
    if (!(cents > 0)) v["amount"] = "Inserisci un importo valido.";
    if (!allP && !sel.length) v["players"] = "Seleziona almeno un giocatore.";
    setErrs(v);
    if (Object.keys(v).length) return;
    create.mutate(
      {
        title: title.trim(),
        kind,
        amount_cents: cents,
        due_on: due || null,
        ...(allP ? {} : { player_ids: sel }),
      },
      {
        onSuccess: () => {
          toast.success("Voce creata");
          onOpenChange(false);
          setTitle("");
          setAmount("");
          setDue("");
          setSel([]);
        },
        onError: toastError,
      },
    );
  };
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Nuova voce</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="space-y-3">
          <Field label="Tipo">
            <NativeSelect value={kind} onChange={(e) => setKind(e.target.value as ChargeKind)}>
              {(Object.keys(chargeKindLabel) as ChargeKind[]).map((k) => (
                <option key={k} value={k}>
                  {chargeKindLabel[k]}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field label="Descrizione *" error={errs["title"] ?? firstErr(create.error, "title")}>
            <TextInput
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="Es. Quota stagione 2026/2027"
            />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Importo a testa (€) *" error={errs["amount"]}>
              <TextInput
                inputMode="decimal"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                placeholder="0,00"
              />
            </Field>
            <Field label="Scadenza">
              <TextInput type="date" value={due} onChange={(e) => setDue(e.target.value)} />
            </Field>
          </div>
          <fieldset>
            <legend className="mb-1 text-sm font-semibold">A chi</legend>
            <label className="flex min-h-10 items-center gap-2 text-sm">
              <input type="radio" checked={allP} onChange={() => setAllP(true)} /> Tutti i giocatori
              attivi
            </label>
            <label className="flex min-h-10 items-center gap-2 text-sm">
              <input type="radio" checked={!allP} onChange={() => setAllP(false)} /> Solo alcuni
            </label>
            {!allP && (
              <div className="mt-1 grid max-h-48 grid-cols-2 gap-1 overflow-y-auto rounded-lg border p-2">
                {players.data
                  ?.filter((p) => p.is_active)
                  .map((p) => (
                    <label key={p.id} className="flex min-h-9 items-center gap-2 text-xs">
                      <input
                        type="checkbox"
                        checked={sel.includes(p.id)}
                        onChange={(e) =>
                          setSel((s) =>
                            e.target.checked ? [...s, p.id] : s.filter((x) => x !== p.id),
                          )
                        }
                      />{" "}
                      {p.full_name}
                    </label>
                  ))}
              </div>
            )}
            {errs["players"] && <p className="mt-1 text-xs text-primary">{errs["players"]}</p>}
          </fieldset>
          <div className="flex justify-end gap-2">
            <Btn variant="outline" onClick={() => onOpenChange(false)}>
              Annulla
            </Btn>
            <Btn type="submit" disabled={create.isPending}>
              Crea voce
            </Btn>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PayDialog({ item, onClose }: { item: PlayerCharge | null; onClose: () => void }) {
  const add = useAddPayment();
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState<Payment["method"]>("contanti");
  const [date, setDate] = useState(todayISO());
  const [err, setErr] = useState("");
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!item) return;
    const cents = amount ? parseEuro(amount) : item.balance_cents;
    if (!(cents > 0)) return setErr("Inserisci un importo valido.");
    if (cents > item.balance_cents) return setErr(`Massimo ${money(item.balance_cents)}.`);
    if (!date) return setErr("Scegli la data.");
    setErr("");
    add.mutate(
      { id: item.id, data: { amount_cents: cents, method, paid_at: date } },
      {
        onSuccess: () => {
          toast.success("Pagamento registrato");
          setAmount("");
          onClose();
        },
        onError: toastError,
      },
    );
  };
  return (
    <Dialog open={!!item} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Registra pagamento</DialogTitle>
          <DialogDescription>
            {item?.charge.title} · residuo {item && money(item.balance_cents)}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} noValidate className="space-y-3">
          <Field label="Importo (€)" hint="Vuoto = salda tutto il residuo">
            <TextInput
              inputMode="decimal"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder={item ? (item.balance_cents / 100).toFixed(2).replace(".", ",") : ""}
            />
          </Field>
          <Field label="Metodo">
            <NativeSelect
              value={method}
              onChange={(e) => setMethod(e.target.value as Payment["method"])}
            >
              {(["contanti", "satispay", "paypal", "revolut", "bonifico"] as const).map((m) => (
                <option key={m} value={m}>
                  {methodLabel[m]}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field label="Data">
            <TextInput type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
          {err && (
            <p role="alert" className="text-sm text-primary">
              {err}
            </p>
          )}
          <div className="flex justify-end gap-2">
            <Btn variant="outline" onClick={onClose}>
              Annulla
            </Btn>
            <Btn type="submit" disabled={add.isPending}>
              Registra
            </Btn>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function PlayerDetail({ id, onClose }: { id: Id | null; onClose: () => void }) {
  const q = usePlayerBalance(id);
  const delPay = useDeletePayment();
  const [paying, setPaying] = useState<PlayerCharge | null>(null);
  return (
    <Dialog open={id !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{q.data?.player_name ?? "Dettaglio"}</DialogTitle>
          <DialogDescription>Voci assegnate e pagamenti registrati.</DialogDescription>
        </DialogHeader>
        {q.isPending ? (
          <Skeleton className="h-40" />
        ) : q.isError ? (
          <ErrorState error={q.error} />
        ) : q.data.items.length === 0 ? (
          <EmptyState>Nessuna voce assegnata.</EmptyState>
        ) : (
          <ul className="space-y-2">
            {q.data.items.map((it) => (
              <li key={it.id} className="rounded-xl border p-3">
                <div className="flex flex-wrap items-center gap-2">
                  <div className="min-w-0 flex-1">
                    <div className="font-semibold">{it.charge.title}</div>
                    <div
                      className={cn(
                        "text-xs",
                        it.is_overdue ? "text-primary" : "text-muted-foreground",
                      )}
                    >
                      {money(it.paid_cents)} di {money(it.amount_cents)}
                      {it.charge.due_on ? ` · scadenza ${fmtDay(it.charge.due_on)}` : ""}
                      {it.is_overdue ? " · scaduta" : ""}
                    </div>
                  </div>
                  {it.balance_cents > 0 ? (
                    <Btn variant="outline" onClick={() => setPaying(it)}>
                      <Wallet className="h-4 w-4" /> Registra pagamento
                    </Btn>
                  ) : (
                    <span className="text-sm font-semibold text-success">Saldato</span>
                  )}
                </div>
                {it.payments.length > 0 && (
                  <ul className="mt-2 divide-y border-t text-xs">
                    {it.payments.map((p) => (
                      <li key={p.id} className="flex items-center gap-2 py-1.5">
                        <span className="flex-1">
                          {fmtDay(p.paid_at)} · {methodLabel[p.method]}
                          {p.note ? ` · ${p.note}` : ""}
                        </span>
                        <span className="font-bold text-success num">{money(p.amount_cents)}</span>
                        <Confirm
                          title="Eliminare il pagamento?"
                          description={`Il pagamento di ${money(p.amount_cents)} verrà tolto dal registro.`}
                          confirmLabel="Elimina"
                          onConfirm={() =>
                            delPay.mutate(p.id, {
                              onSuccess: () => toast.success("Pagamento eliminato"),
                              onError: toastError,
                            })
                          }
                        >
                          {(open) => (
                            <button
                              type="button"
                              onClick={open}
                              aria-label="Elimina pagamento"
                              className="grid h-9 w-9 place-items-center rounded-md text-primary hover:bg-accent"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          )}
                        </Confirm>
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        )}
        <PayDialog item={paying} onClose={() => setPaying(null)} />
      </DialogContent>
    </Dialog>
  );
}

function PaymentsPage() {
  const sum = useFinanceSummary();
  const charges = useCharges();
  const players = usePlayers();
  const delCharge = useDeleteCharge();
  const [newOpen, setNewOpen] = useState(false);
  const [detail, setDetail] = useState<Id | null>(null);

  const status = (r: Row): "ok" | "partial" | "overdue" => {
    if (r.balance_cents <= 0) return "ok";
    return r.paid_cents > 0 ? "partial" : "overdue";
  };
  const cls = { ok: "text-success", partial: "text-warning", overdue: "text-primary" };
  const lbl = { ok: "In regola", partial: "Parziale", overdue: "Da pagare" };

  const remind = (r: Row) => {
    const p = players.data?.find((x) => x.id === r.player_id);
    const text = `Ciao ${p?.nickname ?? p?.first_name ?? r.player_name}! Promemoria AMIR: risultano ancora da versare ${money(r.balance_cents)}. Puoi vedere il dettaglio dal tuo link personale${p?.magic_link ? `: ${p.magic_link}` : ""}. Grazie! ⚽`;
    window.open(waLink(text, p?.phone), "_blank", "noopener");
  };

  return (
    <div className="space-y-4">
      <PageTitle kicker="Area staff" title="Pagamenti">
        <Btn onClick={() => setNewOpen(true)}>
          <Plus className="h-4 w-4" /> Nuova voce
        </Btn>
      </PageTitle>
      {sum.isPending ? (
        <Skeleton className="h-28" />
      ) : sum.isError ? (
        <ErrorState error={sum.error} onRetry={() => sum.refetch()} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-2 text-center">
            <Stat label="Totale dovuto" value={money(sum.data.total_due_cents)} />
            <Stat label="Versato" value={money(sum.data.total_paid_cents)} tone="success" />
            <Stat
              label="Da incassare"
              value={money(sum.data.total_outstanding_cents)}
              tone="warning"
            />
          </div>
          <Card>
            <h2 className="mb-3 text-2xl">Per giocatore</h2>
            {sum.data.players.length === 0 ? (
              <EmptyState>Nessun giocatore.</EmptyState>
            ) : (
              <ul className="divide-y">
                {[...sum.data.players]
                  .sort((a, b) => b.balance_cents - a.balance_cents)
                  .map((r) => {
                    const s = status(r);
                    return (
                      <li key={r.player_id} className="flex flex-wrap items-center gap-2 py-2">
                        <button
                          type="button"
                          onClick={() => setDetail(r.player_id)}
                          className="min-w-0 flex-1 text-left"
                        >
                          <div className="truncate font-semibold">{r.player_name}</div>
                          <div className="text-xs text-muted-foreground num">
                            Versato {money(r.paid_cents)} di {money(r.due_cents)}
                          </div>
                        </button>
                        <span className={cn("text-right font-display text-xl num", cls[s])}>
                          {r.balance_cents > 0 ? money(r.balance_cents) : "✓"}
                          <span className="block text-[10px] font-sans font-semibold uppercase">
                            {lbl[s]}
                          </span>
                        </span>
                        {r.balance_cents > 0 && (
                          <Btn
                            variant="ghost"
                            className="px-2 text-success"
                            aria-label={`Sollecito WhatsApp a ${r.player_name}`}
                            onClick={() => remind(r)}
                          >
                            <MessageCircle className="h-5 w-5" />
                          </Btn>
                        )}
                      </li>
                    );
                  })}
              </ul>
            )}
          </Card>
        </>
      )}
      <Card>
        <h2 className="mb-3 text-2xl">Voci</h2>
        {charges.isPending ? (
          <Skeleton className="h-24" />
        ) : charges.isError ? (
          <ErrorState error={charges.error} />
        ) : charges.data.length === 0 ? (
          <EmptyState>Nessuna voce. Crea la prima quota.</EmptyState>
        ) : (
          <ul className="divide-y">
            {charges.data.map((c) => (
              <li key={c.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                <div className="min-w-0 flex-1">
                  <div className="font-semibold">{c.title}</div>
                  <div className="text-xs text-muted-foreground">
                    {chargeKindLabel[c.kind]} · {money(c.amount_cents)} a testa · {c.assigned_count}{" "}
                    giocatori{c.due_on ? ` · entro ${fmtDay(c.due_on)}` : ""}
                  </div>
                </div>
                <span className="num">
                  {money(c.total_paid_cents)} / {money(c.total_due_cents)}
                </span>
                <Confirm
                  title="Eliminare la voce?"
                  description="Verranno eliminati anche gli addebiti e i pagamenti collegati."
                  confirmLabel="Elimina"
                  onConfirm={() =>
                    delCharge.mutate(c.id, {
                      onSuccess: () => toast.success("Voce eliminata"),
                      onError: toastError,
                    })
                  }
                >
                  {(open) => (
                    <Btn
                      variant="ghost"
                      className="px-2 text-primary"
                      aria-label={`Elimina ${c.title}`}
                      onClick={open}
                    >
                      <Trash2 className="h-4 w-4" />
                    </Btn>
                  )}
                </Confirm>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <NewChargeDialog open={newOpen} onOpenChange={setNewOpen} />
      <PlayerDetail id={detail} onClose={() => setDetail(null)} />
    </div>
  );
}
