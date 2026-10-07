import { Link, useRouterState } from "@tanstack/react-router";
import { CalendarDays, History, Home, LogIn, Moon, ShieldCheck, Sun, Trophy, Users } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useEffect, useState, type ReactNode } from "react";
import { TOKEN_KEY } from "@/api/client";
import { ActivePill, PageTransition } from "@/components/motion";
import { dur, ease, spring } from "@/lib/motion";

/** Una voce è attiva se l'indirizzo è il suo (la home solo se è esattamente `/`). */
const isActive = (pathname: string, to: string) => (to === "/" ? pathname === "/" : pathname === to || pathname.startsWith(`${to}/`));

const nav = [
  { to: "/", label: "Home", icon: Home },
  { to: "/calendario", label: "Calendario", icon: CalendarDays },
  { to: "/classifica", label: "Classifica", icon: Trophy },
  { to: "/rosa", label: "Rosa", icon: Users },
  { to: "/storico", label: "Storico", icon: History },
] as const;

function ThemeToggle() {
  const [light, setLight] = useState(false);
  useEffect(() => setLight(document.documentElement.classList.contains("light")), []);
  const toggle = () => {
    const next = !light;
    setLight(next);
    document.documentElement.classList.toggle("light", next);
    localStorage.setItem("amir_theme", next ? "light" : "dark");
  };
  return (
    <button
      onClick={toggle}
      aria-label={light ? "Tema scuro" : "Tema chiaro"}
      className="press grid h-11 w-11 place-items-center rounded-full hover:bg-accent"
    >
      {/* il sole e la luna si danno il cambio ruotando */}
      <AnimatePresence mode="wait" initial={false}>
        <motion.span
          key={light ? "moon" : "sun"}
          initial={{ rotate: -80, opacity: 0, scale: 0.6 }}
          animate={{ rotate: 0, opacity: 1, scale: 1 }}
          exit={{ rotate: 80, opacity: 0, scale: 0.6 }}
          transition={{ duration: dur.fast, ease: ease.out }}
          className="grid place-items-center"
        >
          {light ? <Moon className="h-5 w-5" /> : <Sun className="h-5 w-5" />}
        </motion.span>
      </AnimatePresence>
    </button>
  );
}

/** Chi ha già fatto l'accesso su questo browser? (si legge dopo il primo disegno: sul server non esiste localStorage) */
function useStaffSession() {
  const [signedIn, setSignedIn] = useState(false);
  useEffect(() => {
    const read = () => setSignedIn(!!localStorage.getItem(TOKEN_KEY));
    read();
    window.addEventListener("storage", read);
    return () => window.removeEventListener("storage", read);
  }, []);
  return signedIn;
}

/** Il tasto per entrare nell'area staff; a chi è già dentro porta direttamente alla dashboard. */
function StaffAccess({ compact = false }: { compact?: boolean }) {
  const signedIn = useStaffSession();
  const Icon = signedIn ? ShieldCheck : LogIn;
  const label = signedIn ? "Area staff" : "Accedi";
  return (
    <Link
      to={signedIn ? "/admin" : "/admin/login"}
      aria-label={signedIn ? "Vai all'area staff" : "Accedi all'area staff"}
      className={`press inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary/50 px-3 text-sm font-bold text-primary transition-colors hover:bg-primary/10 ${compact ? "" : "w-full"}`}
    >
      <Icon className="h-4 w-4" />
      {label}
    </Link>
  );
}

function Logo() {
  return (
    <Link to="/" className="group flex items-center gap-2.5">
      <motion.img
        src="/stemma-amir.png"
        alt=""
        width={40}
        height={40}
        whileHover={{ rotate: -6, scale: 1.08 }}
        whileTap={{ scale: 0.94 }}
        transition={spring.snappy}
        className="h-10 w-10 object-contain drop-shadow"
      />
      <span className="leading-none">
        <span className="block font-display text-lg">Amir</span>
        <span className="block text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          Costruzioni
        </span>
      </span>
    </Link>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  return (
    <div className="min-h-screen md:flex">
      <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r bg-sidebar p-4 md:flex">
        <Logo />
        <nav className="mt-8 flex flex-col gap-1">
          {nav.map(({ to, label, icon: Icon }) => {
            const active = isActive(pathname, to);
            return (
              <Link
                key={to}
                to={to}
                activeOptions={{ exact: to === "/" }}
                className={`press group relative flex min-h-11 items-center gap-3 rounded-lg px-3 font-semibold transition-colors hover:bg-sidebar-accent/70 ${active ? "text-foreground" : "text-muted-foreground"}`}
              >
                {/* un solo segno rosso scivola da una voce all'altra */}
                {active && <ActivePill id="nav-public" className="rounded-lg border-l-4 border-primary bg-sidebar-accent" />}
                <Icon className="relative h-5 w-5 transition-transform duration-200 group-hover:scale-110" />
                <span className="relative">{label}</span>
              </Link>
            );
          })}
        </nav>
        <div className="mt-auto space-y-3">
          <StaffAccess />
          <div className="flex items-center justify-between">
            <span className="text-xs text-muted-foreground">Stagione 2026/27</span>
            <ThemeToggle />
          </div>
        </div>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex items-center justify-between border-b bg-background/85 px-4 py-2 backdrop-blur md:hidden">
          <Logo />
          <div className="flex items-center gap-1">
            <StaffAccess compact />
            <ThemeToggle />
          </div>
        </header>
        <main className="mx-auto w-full max-w-5xl flex-1 px-4 pb-28 pt-5 md:px-8 md:pb-12 md:pt-8">
          <PageTransition>{children}</PageTransition>
        </main>
      </div>
      <nav className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden">
        {nav.map(({ to, label, icon: Icon }) => {
          const active = isActive(pathname, to);
          return (
            <Link
              key={to}
              to={to}
              activeOptions={{ exact: to === "/" }}
              className={`press relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-[10px] font-semibold transition-colors ${active ? "text-primary" : "text-muted-foreground"}`}
            >
              {/* filo rosso sopra la voce attiva: scivola da una all'altra */}
              {active && <ActivePill id="nav-public-mobile" className="inset-x-3 bottom-auto top-0 h-[3px] rounded-b-full bg-primary" />}
              <motion.span animate={{ y: active ? -2 : 0, scale: active ? 1.12 : 1 }} transition={spring.snappy} className="grid place-items-center">
                <Icon className="h-5 w-5" />
              </motion.span>
              {label}
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
