import { KeyRound, LogOut, ShieldCheck } from "lucide-react";
import { useState, type FormEvent } from "react";
import { toast } from "sonner";
import { useConnectXfive, useDisconnectXfive, useXfiveSession } from "@/api/hooks";
import { Skeleton } from "@/components/ui-kit";
import { cn } from "@/lib/utils";
import { Btn, Field, TextInput, toastError } from "./kit";

const EMAIL_KEY = "amir_xfive_email";

const readEmail = () => {
  try {
    return localStorage.getItem(EMAIL_KEY) ?? "";
  } catch {
    return "";
  }
};

const hhmm = (iso: string) =>
  new Date(iso).toLocaleTimeString("it-IT", {
    timeZone: "Europe/Rome",
    hour: "2-digit",
    minute: "2-digit",
  });

/**
 * «Collega XFive» / «Esci da XFive»: chi dello staff entra nel proprio account XFive dalla Staff Area.
 * La password va solo a XFive, per fare il login: non viene salvata (resta solo la sessione, cifrata sul server, che scade).
 */
export function XfiveConnectCard({ className }: { className?: string }) {
  const q = useXfiveSession();
  const connect = useConnectXfive();
  const disconnect = useDisconnectXfive();
  const [email, setEmail] = useState(readEmail);
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);

  if (q.isPending) return <Skeleton className="h-24" />;

  const status = q.data;
  const blockedUntil = status?.blocked_until ? new Date(status.blocked_until) : null;
  const blocked = blockedUntil !== null && blockedUntil.getTime() > Date.now();

  const submit = (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    connect.mutate(
      { email: email.trim(), password },
      {
        onSuccess: () => {
          try {
            localStorage.setItem(EMAIL_KEY, email.trim());
          } catch {
            /* la memoria del browser può essere bloccata: non serve */
          }
          setPassword("");
          toast.success("Collegato a XFive");
        },
        onError: (err) => {
          setPassword("");
          setError(err instanceof Error ? err.message : "Collegamento non riuscito.");
        },
      },
    );
  };

  if (status?.connected) {
    return (
      <div
        className={cn(
          "flex flex-wrap items-center gap-3 rounded-xl border border-success/40 bg-success/10 p-3",
          className,
        )}
      >
        <ShieldCheck className="h-5 w-5 shrink-0 text-success" />
        <div className="min-w-0 flex-1 text-sm">
          <div className="font-semibold">Collegato a XFive come {status.email_hint}</div>
          {status.expires_at && (
            <div className="text-xs text-muted-foreground">
              La sessione scade alle {hhmm(status.expires_at)} e si rinnova a ogni uso. La password
              non è salvata.
            </div>
          )}
        </div>
        <Btn
          variant="outline"
          disabled={disconnect.isPending}
          onClick={() =>
            disconnect.mutate(undefined, {
              onSuccess: () => toast.success("Uscito da XFive"),
              onError: toastError,
            })
          }
        >
          <LogOut className="h-4 w-4" /> Esci da XFive
        </Btn>
      </div>
    );
  }

  return (
    <form onSubmit={submit} className={cn("space-y-3 rounded-xl border bg-card p-3", className)}>
      <div className="flex items-start gap-3">
        <KeyRound className="mt-0.5 h-5 w-5 shrink-0 text-primary" />
        <div className="text-sm">
          <div className="font-semibold">Collega XFive</div>
          <p className="text-xs text-muted-foreground">
            Serve per inviare la distinta con il tuo account. La password va solo a XFive per fare
            il login e non viene salvata: resta soltanto la sessione, cifrata, che scade dopo un
            po'.
          </p>
        </div>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        <Field label="Email di XFive">
          <TextInput
            type="email"
            name="xfive-email"
            autoComplete="username"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
        </Field>
        <Field label="Password di XFive">
          <TextInput
            type="password"
            name="xfive-password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>
      </div>
      {blocked && blockedUntil && (
        <p role="alert" className="text-sm text-primary">
          Troppi accessi rifiutati da XFive: per sicurezza riprova dopo le{" "}
          {hhmm(blockedUntil.toISOString())}.
        </p>
      )}
      {error && (
        <p role="alert" className="text-sm text-primary">
          {error}
        </p>
      )}
      <Btn type="submit" disabled={connect.isPending || blocked || !email || !password}>
        <KeyRound className="h-4 w-4" /> {connect.isPending ? "Accesso in corso…" : "Collega XFive"}
      </Btn>
    </form>
  );
}
