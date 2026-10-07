import { createFileRoute } from "@tanstack/react-router";
import { Check, HelpCircle, MapPin, X } from "lucide-react";
import { useState } from "react";
import { useMe, useSetMyRsvp } from "@/api/hooks";
import type { Rsvp, TeamEvent } from "@/api/types";
import { ApiError } from "@/api/client";
import { Card, ErrorState, Skeleton } from "@/components/ui-kit";
import { fmtDate, fmtDateTime, methodLabel, money } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/p/$token")({
  head: () => ({
    meta: [
      { title: "La mia pagina — AMIR COSTRUZIONI" },
      { name: "description", content: "Conferma la tua presenza e controlla le quote da versare." },
      { property: "og:title", content: "La mia pagina — AMIR COSTRUZIONI" },
      { property: "og:description", content: "Conferma la presenza e controlla le quote." },
      { name: "robots", content: "noindex" },
    ],
  }),
  component: PlayerPageView,
});

const choices: { v: Rsvp; label: string; icon: typeof Check; on: string }[] = [
  { v: "yes", label: "Ci sono", icon: Check, on: "bg-success text-success-foreground border-success" },
  { v: "maybe", label: "Forse", icon: HelpCircle, on: "bg-warning text-warning-foreground border-warning" },
  { v: "no", label: "Non ci sono", icon: X, on: "bg-primary text-primary-foreground border-primary" },
];

function EventCard({ ev, token }: { ev: TeamEvent; token: string }) {
  const m = useSetMyRsvp(token);
  const [note, setNote] = useState("");
  const noteErr = m.error instanceof ApiError ? m.error.errors["note"]?.[0] : undefined;
  return (
    <Card>
      <div className="text-xs font-bold uppercase tracking-[0.2em] text-primary">{ev.type === "match" ? "Partita" : ev.type === "training" ? "Allenamento" : "Evento sociale"}</div>
      <h3 className="mt-1 text-2xl">{ev.title}</h3>
      <p className="mt-1 text-sm capitalize text-muted-foreground">{fmtDateTime(ev.starts_at)}</p>
      {ev.venue && <p className="flex items-center gap-1 text-sm text-muted-foreground"><MapPin className="h-3.5 w-3.5" />{ev.venue}</p>}
      <div className="mt-4 grid grid-cols-3 gap-2">
        {choices.map(({ v, label, icon: Icon, on }) => (
          <button key={v} disabled={m.isPending} onClick={() => m.mutate({ event_id: ev.id, rsvp: v, note: note || null })}
            aria-pressed={ev.my_rsvp === v}
            className={cn("flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border-2 text-sm font-bold transition-all active:scale-95", ev.my_rsvp === v ? on : "border-border bg-secondary")}>
            <Icon className="h-5 w-5" />{label}
          </button>
        ))}
      </div>
      <input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Nota (facoltativa)"
        onBlur={() => ev.my_rsvp && note && m.mutate({ event_id: ev.id, rsvp: ev.my_rsvp, note })}
        className="mt-3 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm" />
      {noteErr && <p className="mt-1 text-xs text-primary">{noteErr}</p>}
      {m.isError && !noteErr && <p className="mt-1 text-xs text-primary">Salvataggio non riuscito. Riprova.</p>}
    </Card>
  );
}

function PlayerPageView() {
  const { token } = Route.useParams();
  const q = useMe(token);
  if (q.isPending) return <div className="space-y-4"><Skeleton className="h-20" /><Skeleton className="h-64" /></div>;
  if (q.isError) return <ErrorState error={q.error} />;
  const { player, upcoming_events: events, balance } = q.data;
  const due = balance.items.filter((c) => c.balance_cents > 0);
  const payments = balance.items.flatMap((c) => c.payments.map((p) => ({ ...p, title: c.charge.title })));
  return (
    <div className="space-y-5">
      <header>
        <div className="text-xs font-bold uppercase tracking-[0.2em] text-primary">Ciao</div>
        <h1 className="text-5xl">{player.nickname ?? player.full_name.split(" ")[0]}</h1>
      </header>
      <section className="space-y-3">
        <h2 className="text-2xl">Prossimi impegni</h2>
        {events.length ? events.map((ev) => <EventCard key={ev.id} ev={ev} token={token} />) : <p className="text-sm text-muted-foreground">Nessun impegno in programma.</p>}
      </section>
      <Card>
        <h2 className="text-2xl">Il tuo saldo</h2>
        <div className={cn("mt-2 font-display text-5xl num", balance.balance_cents > 0 ? "text-primary" : "text-success")}>{balance.balance_cents > 0 ? `-${money(balance.balance_cents)}` : money(0)}</div>
        <h3 className="mt-5 text-lg">Da pagare</h3>
        {due.length ? (
          <ul className="mt-2 divide-y">{due.map((c) => (
            <li key={c.id} className="flex justify-between py-2 text-sm"><span>{c.charge.title}{c.charge.due_on && <span className={cn("block text-xs", c.is_overdue ? "text-primary" : "text-muted-foreground")}>{c.is_overdue ? "scaduta il" : "entro"} {fmtDate(c.charge.due_on)}</span>}</span><span className="font-bold num">{money(c.balance_cents)}</span></li>
          ))}</ul>
        ) : <p className="mt-1 text-sm text-success">Sei in regola, nessuna quota da pagare.</p>}
        <h3 className="mt-5 text-lg">Pagamenti registrati</h3>
        {payments.length ? (
          <ul className="mt-2 divide-y">{payments.map((p) => (
            <li key={p.id} className="flex justify-between py-2 text-sm"><span>{fmtDate(p.paid_at)} · {p.title} · {methodLabel[p.method]}</span><span className="font-bold text-success num">{money(p.amount_cents)}</span></li>
          ))}</ul>
        ) : <p className="mt-1 text-sm text-muted-foreground">Nessun pagamento registrato.</p>}
      </Card>
    </div>
  );
}
