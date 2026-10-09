import { Link, useRouterState } from "@tanstack/react-router";
import { CalendarDays, History, Home, Moon, Sun, Trophy, Users } from "lucide-react";
import { AnimatePresence, motion } from "motion/react";
import { useEffect, useState, type ReactNode } from "react";
import { AreaSwitch } from "@/components/area/AreaSwitch";
import { ActivePill, PageTransition } from "@/components/motion";
import { AREAS, ZONE_NAV, areaOf, type AreaId } from "@/lib/area";
import { dur, ease, spring } from "@/lib/motion";

/** Una voce è attiva se l'indirizzo è il suo (la home dell'area solo se è esattamente quella). */
const isActive = (pathname: string, to: string, home: string) =>
  to === home ? pathname === home : pathname === to || pathname.startsWith(`${to}/`);

const HUB_NAV = [
  { to: "/", label: "Home", icon: Home },
  { to: "/calendario", label: "Calendario", icon: CalendarDays },
  { to: "/classifica", label: "Classifica", icon: Trophy },
  { to: "/rosa", label: "Rosa", icon: Users },
  { to: "/storico", label: "Storico", icon: History },
] as const;

type NavItem = (typeof HUB_NAV)[number] | (typeof ZONE_NAV)[number];

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

/** Lo stemma con il nome dell'area: «Amir / Costruzioni» nell'Hub, «Mixed Zone / XFive Alessandria» nella Mixed Zone. */
function Logo({ area }: { area: AreaId }) {
  const mixed = area === "mixed";
  return (
    <Link to={mixed ? "/mixed-zone" : "/"} className="group flex items-center gap-2.5">
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
        <span className="block font-display text-lg">{mixed ? "Mixed Zone" : "Amir"}</span>
        <span className="block text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
          {mixed ? "XFive Alessandria" : "Costruzioni"}
        </span>
      </span>
    </Link>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const area = areaOf(pathname);
  const nav: readonly NavItem[] = area === "mixed" ? ZONE_NAV : HUB_NAV;
  const home = AREAS[area].entry;
  return (
    <div className="min-h-screen md:flex">
      <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r bg-sidebar p-4 md:flex">
        <Logo area={area} />
        <nav className="mt-8 flex flex-col gap-1">
          {nav.map(({ to, label, icon: Icon }) => {
            const active = isActive(pathname, to, home);
            return (
              <Link
                key={to}
                to={to}
                activeOptions={{ exact: to === home }}
                className={`press group relative flex min-h-11 items-center gap-3 rounded-lg px-3 font-semibold transition-colors hover:bg-sidebar-accent/70 ${active ? "text-foreground" : "text-muted-foreground"}`}
              >
                {/* un solo segno (del colore dell'area) scivola da una voce all'altra */}
                {active && (
                  <ActivePill
                    id="nav-public"
                    className="rounded-lg border-l-4 border-primary bg-sidebar-accent"
                  />
                )}
                <Icon className="relative h-5 w-5 transition-transform duration-200 group-hover:scale-110" />
                <span className="relative">{label}</span>
              </Link>
            );
          })}
        </nav>
        <div className="mt-auto space-y-3">
          <AreaSwitch />
          <div className="flex items-center justify-between">
            <span className="text-xs text-muted-foreground">Stagione 2026/27</span>
            <ThemeToggle />
          </div>
        </div>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex items-center justify-between border-b bg-background/85 px-4 py-2 backdrop-blur md:hidden">
          <Logo area={area} />
          <div className="flex items-center gap-1">
            <AreaSwitch compact />
            <ThemeToggle />
          </div>
        </header>
        <main className="mx-auto w-full max-w-5xl flex-1 px-4 pb-28 pt-5 md:px-8 md:pb-12 md:pt-8">
          <PageTransition>{children}</PageTransition>
        </main>
      </div>
      <nav
        className={`fixed inset-x-0 bottom-0 z-30 grid border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden ${area === "mixed" ? "grid-cols-6" : "grid-cols-5"}`}
      >
        {nav.map(({ to, label, icon: Icon }) => {
          const active = isActive(pathname, to, home);
          return (
            <Link
              key={to}
              to={to}
              activeOptions={{ exact: to === home }}
              className={`press relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-[10px] font-semibold transition-colors ${active ? "text-primary" : "text-muted-foreground"}`}
            >
              {/* filo del colore dell'area sopra la voce attiva: scivola da una all'altra */}
              {active && (
                <ActivePill
                  id="nav-public-mobile"
                  className="inset-x-3 bottom-auto top-0 h-[3px] rounded-b-full bg-primary"
                />
              )}
              <motion.span
                animate={{ y: active ? -2 : 0, scale: active ? 1.12 : 1 }}
                transition={spring.snappy}
                className="grid place-items-center"
              >
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
