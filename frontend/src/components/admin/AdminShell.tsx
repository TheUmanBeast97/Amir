import { Link, useNavigate, useRouterState } from "@tanstack/react-router";
import { motion } from "motion/react";
import {
  BarChart3,
  CalendarDays,
  Euro,
  FileText,
  Image,
  LayoutDashboard,
  LogOut,
  Menu,
  Settings,
  Swords,
  Users,
} from "lucide-react";
import { useState, type ReactNode } from "react";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { api, TOKEN_KEY } from "@/api/client";
import { ActivePill, PageTransition } from "@/components/motion";
import { spring } from "@/lib/motion";

const main = [
  { to: "/admin", label: "Dashboard", icon: LayoutDashboard },
  { to: "/admin/giocatori", label: "Giocatori", icon: Users },
  { to: "/admin/partite", label: "Partite", icon: CalendarDays },
  { to: "/admin/pagamenti", label: "Pagamenti", icon: Euro },
] as const;
const more = [
  { to: "/admin/amichevoli", label: "Amichevoli", icon: Swords },
  { to: "/admin/classifiche", label: "Classifiche squadra", icon: BarChart3 },
  { to: "/admin/grafiche", label: "Studio grafiche", icon: Image },
  { to: "/admin/documenti", label: "Documenti XFive", icon: FileText },
  { to: "/admin/impostazioni", label: "Impostazioni", icon: Settings },
] as const;

export function useLogout() {
  const navigate = useNavigate();
  return async () => {
    try {
      await api.logout();
    } catch {
      /* token may already be invalid */
    }
    localStorage.removeItem(TOKEN_KEY);
    navigate({ to: "/admin/login" });
  };
}

const linkCls =
  "press flex min-h-11 items-center gap-3 rounded-lg px-3 font-semibold text-muted-foreground transition-colors hover:bg-sidebar-accent";
const activeCls = { className: "bg-sidebar-accent text-foreground! border-l-4 border-primary" };

/** Una voce è attiva se l'indirizzo è il suo (la dashboard solo se è esattamente `/admin`). */
const isActive = (pathname: string, to: string) =>
  to === "/admin" ? pathname === "/admin" : pathname === to || pathname.startsWith(`${to}/`);

export function AdminShell({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState(false);
  const logout = useLogout();
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  return (
    <div className="min-h-screen md:flex">
      <aside className="sticky top-0 hidden h-screen w-60 shrink-0 flex-col border-r bg-sidebar p-4 md:flex">
        <Link to="/admin" className="flex items-center gap-2.5">
          <img
            src="/stemma-amir.png"
            alt=""
            width={40}
            height={40}
            className="h-10 w-10 object-contain drop-shadow"
          />
          <span className="leading-none">
            <span className="block font-display text-lg">Amir</span>
            <span className="block text-[10px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
              Area staff
            </span>
          </span>
        </Link>
        <nav aria-label="Area staff" className="mt-8 flex flex-col gap-1">
          {[...main, ...more].map(({ to, label, icon: Icon }) => {
            const active = isActive(pathname, to);
            return (
              <Link
                key={to}
                to={to}
                activeOptions={{ exact: to === "/admin" }}
                className={`${linkCls} group relative ${active ? "text-foreground!" : ""}`}
              >
                {/* un solo segno rosso scivola da una voce all'altra */}
                {active && <ActivePill id="nav-admin" className="rounded-lg border-l-4 border-primary bg-sidebar-accent" />}
                <Icon className="relative h-5 w-5 transition-transform duration-200 group-hover:scale-110" />
                <span className="relative">{label}</span>
              </Link>
            );
          })}
        </nav>
        <div className="mt-auto space-y-1">
          <Link to="/" className={linkCls}>
            Sito pubblico
          </Link>
          <button onClick={logout} className={`${linkCls} w-full`}>
            <LogOut className="h-5 w-5" /> Esci
          </button>
        </div>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <main className="mx-auto w-full max-w-6xl flex-1 px-4 pb-28 pt-5 md:px-8 md:pb-12 md:pt-8">
          <PageTransition wipe={false}>{children}</PageTransition>
        </main>
      </div>
      <nav
        aria-label="Area staff"
        className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
      >
        {main.map(({ to, label, icon: Icon }) => {
          const active = isActive(pathname, to);
          return (
            <Link
              key={to}
              to={to}
              activeOptions={{ exact: to === "/admin" }}
              className={`press relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-[10px] font-semibold transition-colors ${active ? "text-primary" : "text-muted-foreground"}`}
            >
              {active && <ActivePill id="nav-admin-mobile" className="inset-x-3 bottom-auto top-0 h-[3px] rounded-b-full bg-primary" />}
              <motion.span animate={{ y: active ? -2 : 0, scale: active ? 1.12 : 1 }} transition={spring.snappy} className="grid place-items-center">
                <Icon className="h-5 w-5" />
              </motion.span>
              {label}
            </Link>
          );
        })}
        <button
          onClick={() => setOpen(true)}
          className="press flex min-h-14 flex-col items-center justify-center gap-0.5 text-[10px] font-semibold text-muted-foreground"
        >
          <Menu className="h-5 w-5" /> Altro
        </button>
      </nav>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="bottom">
          <SheetHeader>
            <SheetTitle>Altro</SheetTitle>
          </SheetHeader>
          <div className="grid gap-1 p-4">
            {more.map(({ to, label, icon: Icon }) => (
              <Link
                key={to}
                to={to}
                onClick={() => setOpen(false)}
                className={linkCls}
                activeProps={activeCls}
              >
                <Icon className="h-5 w-5" /> {label}
              </Link>
            ))}
            <Link to="/" onClick={() => setOpen(false)} className={linkCls}>
              Sito pubblico
            </Link>
            <button onClick={logout} className={`${linkCls} w-full`}>
              <LogOut className="h-5 w-5" /> Esci
            </button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}
