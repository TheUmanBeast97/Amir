import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { ArrowLeft, Eye, EyeOff, LogIn } from "lucide-react";
import { useEffect, useState, type FormEvent } from "react";
import { useLogin } from "@/api/hooks";
import { ApiError, TOKEN_KEY } from "@/api/client";
import { Reveal } from "@/components/motion";
import { Card } from "@/components/ui-kit";

export const Route = createFileRoute("/admin_/login")({
  head: () => ({
    meta: [
      { title: "Accesso staff - AMIR COSTRUZIONI" },
      { name: "description", content: "Area riservata allo staff di AMIR COSTRUZIONI." },
      { property: "og:title", content: "Accesso staff - AMIR COSTRUZIONI" },
      { property: "og:description", content: "Area riservata allo staff." },
      { name: "robots", content: "noindex" },
    ],
  }),
  component: LoginPage,
});

function LoginPage() {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const login = useLogin();
  const navigate = useNavigate();

  // chi è già dentro non deve rivedere la pagina di accesso
  useEffect(() => {
    if (localStorage.getItem(TOKEN_KEY)) navigate({ to: "/admin", replace: true });
  }, [navigate]);

  const err = login.error instanceof ApiError ? login.error : null;
  const fieldErrors = err?.errors ?? {};
  const tooMany = err?.status === 429;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    login.mutate(
      // il nome utente è l'email: lo spazio in più copiato per sbaglio non deve far fallire l'accesso
      { email: username.trim(), password },
      {
        onSuccess: (r) => {
          localStorage.setItem(TOKEN_KEY, r.token);
          navigate({ to: "/admin" });
        },
      },
    );
  };
  const field = "min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm";
  return (
    <div className="mx-auto min-h-screen max-w-sm px-4 pt-10">
      <Link to="/" className="press group mb-6 inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-muted-foreground hover:text-foreground">
        <ArrowLeft className="h-4 w-4 transition-transform duration-200 group-hover:-translate-x-1" /> Torna al sito
      </Link>
      <Reveal now>
        <h1 className="text-4xl">Accesso staff</h1>
        <p className="mb-4 mt-2 text-sm text-muted-foreground">Entra con il tuo nome utente (la tua email) e la password.</p>
      </Reveal>
      <Reveal now delay={0.1}>
        <Card>
          <form onSubmit={submit} className="space-y-4" noValidate>
            <label className="block text-sm font-semibold">
              Nome utente
              <input
                type="text"
                inputMode="email"
                autoComplete="username"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                autoFocus
                required
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                aria-invalid={!!fieldErrors["email"]}
                className={`${field} mt-1`}
              />
              {fieldErrors["email"] && (
                <span role="alert" className="mt-1 block text-xs text-primary">
                  {fieldErrors["email"][0]}
                </span>
              )}
            </label>
            <label className="block text-sm font-semibold">
              Password
              <span className="relative mt-1 block">
                <input
                  type={show ? "text" : "password"}
                  autoComplete="current-password"
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  aria-invalid={!!fieldErrors["password"]}
                  className={`${field} pr-12`}
                />
                <button
                  type="button"
                  onClick={() => setShow((v) => !v)}
                  aria-label={show ? "Nascondi la password" : "Mostra la password"}
                  aria-pressed={show}
                  className="press absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-lg text-muted-foreground hover:text-foreground"
                >
                  {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </span>
              {fieldErrors["password"] && (
                <span role="alert" className="mt-1 block text-xs text-primary">
                  {fieldErrors["password"][0]}
                </span>
              )}
            </label>
            {tooMany && (
              <p role="alert" className="text-xs text-primary">
                Troppi tentativi ravvicinati: aspetta un minuto e riprova.
              </p>
            )}
            {login.isError && !tooMany && !Object.keys(fieldErrors).length && (
              <p role="alert" className="text-xs text-primary">
                {login.error.message}
              </p>
            )}
            <button
              disabled={login.isPending || !username.trim() || !password}
              className="press inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-primary font-bold text-primary-foreground disabled:opacity-60"
            >
              <LogIn className="h-4 w-4" /> {login.isPending ? "Accesso in corso…" : "Accedi"}
            </button>
          </form>
        </Card>
      </Reveal>
    </div>
  );
}
