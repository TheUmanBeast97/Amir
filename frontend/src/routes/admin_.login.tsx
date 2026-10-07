import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useState, type FormEvent } from "react";
import { useLogin } from "@/api/hooks";
import { ApiError, TOKEN_KEY } from "@/api/client";
import { Card } from "@/components/ui-kit";

export const Route = createFileRoute("/admin_/login")({
  head: () => ({
    meta: [
      { title: "Accesso staff — AMIR COSTRUZIONI" },
      { name: "description", content: "Area riservata allo staff di AMIR COSTRUZIONI." },
      { property: "og:title", content: "Accesso staff — AMIR COSTRUZIONI" },
      { property: "og:description", content: "Area riservata allo staff." },
      { name: "robots", content: "noindex" },
    ],
  }),
  component: LoginPage,
});

function LoginPage() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const login = useLogin();
  const navigate = useNavigate();
  const errs = login.error instanceof ApiError ? login.error.errors : {};
  const submit = (e: FormEvent) => {
    e.preventDefault();
    login.mutate(
      { email, password },
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
    <div className="mx-auto min-h-screen max-w-sm px-4 pt-12">
      <h1 className="mb-4 text-4xl">Accesso staff</h1>
      <Card>
        <form onSubmit={submit} className="space-y-4" noValidate>
          <label className="block text-sm font-semibold">
            Email
            <input
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className={`${field} mt-1`}
            />
            {errs["email"] && (
              <span className="mt-1 block text-xs text-primary">{errs["email"][0]}</span>
            )}
          </label>
          <label className="block text-sm font-semibold">
            Password
            <input
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className={`${field} mt-1`}
            />
            {errs["password"] && (
              <span className="mt-1 block text-xs text-primary">{errs["password"][0]}</span>
            )}
          </label>
          {login.isError && !Object.keys(errs).length && (
            <p className="text-xs text-primary">{login.error.message}</p>
          )}
          <button
            disabled={login.isPending}
            className="min-h-12 w-full rounded-lg bg-primary font-bold text-primary-foreground disabled:opacity-60"
          >
            {login.isPending ? "Accesso in corso…" : "Accedi"}
          </button>
        </form>
      </Card>
    </div>
  );
}
