import * as DialogPrimitive from "@radix-ui/react-dialog";
import { useRouter, useRouterState } from "@tanstack/react-router";
import { ArrowRight, LayoutGrid, MapPin } from "lucide-react";
import {
  animate,
  motion,
  useMotionTemplate,
  useMotionValue,
  useReducedMotion,
  useSpring,
  useTransform,
} from "motion/react";
import { useRef, useState, type KeyboardEvent, type PointerEvent } from "react";
import { TOKEN_KEY } from "@/api/client";
import { openAreaPortal, PORTAL_MS } from "@/components/area/AreaPortal";
import { Dialog, DialogDescription, DialogPortal, DialogTitle } from "@/components/ui/dialog";
import { AREA_ORDER, AREAS, areaOf, type Area, type AreaId } from "@/lib/area";
import { ease, spring, stagger, tiltSpring } from "@/lib/motion";
import { cn } from "@/lib/utils";

/** Le pagine d'ingresso che il router conosce (la Staff Area va al login se manca il token). */
type Target = "/" | "/mixed-zone" | "/admin" | "/admin/login";

const targetOf = (id: AreaId): Target => {
  if (id === "staff") return localStorage.getItem(TOKEN_KEY) ? "/admin" : "/admin/login";
  return id === "mixed" ? "/mixed-zone" : "/";
};

interface CardProps {
  area: Area;
  index: number;
  current: boolean;
  /** L'area appena scelta: la sua carta vola verso l'osservatore, le altre si ritirano. */
  chosen: AreaId | null;
  onChoose: (id: AreaId) => void;
  buttonRef: (el: HTMLButtonElement | null) => void;
}

/**
 * Una carta in prospettiva che si inclina seguendo il puntatore (stessa idea della figurina), con un riflesso
 * di luce e il bordo nel colore dell'area. È un pulsante vero: si sceglie con un click, con Invio o con la barra.
 */
function TiltCard({ area, index, current, chosen, onChoose, buttonRef }: CardProps) {
  const reduce = useReducedMotion();
  const px = useMotionValue(0.5);
  const py = useMotionValue(0.5);
  const amp = reduce ? 0.4 : 1;
  const rotY = useSpring(useTransform(px, [0, 1], [-14 * amp, 14 * amp]), tiltSpring);
  const rotX = useSpring(useTransform(py, [0, 1], [10 * amp, -10 * amp]), tiltSpring);
  const sx = useSpring(px, { stiffness: 120, damping: 20 });
  const sy = useSpring(py, { stiffness: 120, damping: 20 });
  const gx = useTransform(sx, (v) => v * 100);
  const gy = useTransform(sy, (v) => v * 100);
  const glare = useMotionTemplate`radial-gradient(circle at ${gx}% ${gy}%, rgba(255,255,255,0.28), rgba(255,255,255,0) 55%)`;

  const track = (e: PointerEvent<HTMLButtonElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    px.set(Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)));
    py.set(Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)));
  };
  const leave = () => {
    animate(px, 0.5, { duration: 0.6, ease: ease.out });
    animate(py, 0.5, { duration: 0.6, ease: ease.out });
  };

  const flying = chosen === area.id;
  const retiring = chosen !== null && !flying;
  const { accent } = area;

  return (
    <motion.div
      className="will-change-transform"
      style={{ perspective: 1200 }}
      initial={
        reduce ? { opacity: 0 } : { opacity: 0, y: 36, scale: 0.92, rotateY: (index - 1) * -18 }
      }
      animate={
        flying
          ? { opacity: 0, scale: 2.8, y: 0, rotateY: 0, z: 500 }
          : retiring
            ? { opacity: 0, scale: 0.9, y: 0, rotateY: 0 }
            : { opacity: 1, y: 0, scale: 1, rotateY: 0 }
      }
      transition={
        flying
          ? { duration: 0.4, ease: ease.sharp }
          : retiring
            ? { duration: 0.22, ease: ease.sharp }
            : { ...spring.soft, delay: 0.04 + stagger(index, 0.07) }
      }
    >
      <motion.button
        ref={buttonRef}
        type="button"
        onClick={() => onChoose(area.id)}
        onPointerMove={track}
        onPointerLeave={leave}
        onPointerCancel={leave}
        aria-label={current ? `${area.name}: sei qui` : `Entra in ${area.name}`}
        aria-current={current ? "true" : undefined}
        className={cn(
          "group relative flex h-full w-full flex-col overflow-hidden rounded-2xl border bg-card p-5 text-left outline-none",
          "md:min-h-[21rem] md:p-6",
          "focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-background",
        )}
        style={{
          rotateX: rotX,
          rotateY: rotY,
          transformStyle: "preserve-3d",
          borderColor: `${accent}80`,
          boxShadow: `0 0 0 1px ${accent}33, 0 30px 60px -34px ${accent}cc`,
          background: `linear-gradient(165deg, ${accent}2b, transparent 48%), var(--card)`,
          // il colore dell'anello di fuoco è quello dell'area, non quello del tema corrente
          ["--tw-ring-color" as string]: accent,
        }}
        whileHover={{ scale: reduce ? 1 : 1.02 }}
        whileTap={{ scale: 0.98 }}
      >
        {/* riflesso che segue il puntatore */}
        <motion.span
          aria-hidden
          className="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 group-hover:opacity-100 group-focus-visible:opacity-60"
          style={{ backgroundImage: glare, mixBlendMode: "soft-light" }}
        />
        {/* il taglio diagonale del sito, nel colore dell'area */}
        <span
          aria-hidden
          className="pointer-events-none absolute -top-10 right-6 h-[160%] w-3 opacity-70"
          style={{ background: accent, transform: "skewX(-16deg)" }}
        />

        <span className="relative flex items-center justify-between gap-2">
          <span
            className="text-[10px] font-bold uppercase tracking-[0.28em]"
            style={{ color: accent }}
          >
            Area
          </span>
          {current && (
            <span
              className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
              style={{ background: `${accent}2e`, color: accent }}
            >
              <MapPin className="h-3 w-3" /> Sei qui
            </span>
          )}
        </span>

        <span className="relative mt-3 block font-display text-3xl leading-none text-foreground md:text-4xl">
          {area.name}
        </span>
        <span className="relative mt-1.5 block text-sm text-muted-foreground">{area.tagline}</span>

        <ul className="relative mt-4 space-y-1.5 md:mt-6">
          {area.preview.map((item) => (
            <li
              key={item}
              className="flex items-center gap-2 text-sm font-semibold text-foreground/85"
            >
              <span className="h-1.5 w-1.5 rounded-full" style={{ background: accent }} />
              {item}
            </li>
          ))}
        </ul>

        <span
          className="relative mt-4 inline-flex items-center gap-1.5 text-sm font-bold md:mt-auto md:pt-6"
          style={{ color: accent }}
        >
          {current ? "Resta qui" : "Entra"}
          {!current && (
            <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-1" />
          )}
        </span>
      </motion.button>
    </motion.div>
  );
}

/**
 * Il pulsante «Cambia area» (in fondo al menu di ogni area) e il selettore: tre carte 3D, una per area.
 * Scelta un'area diversa, la nuova pagina si precarica e parte il portale (AreaPortal) che la scopre già pronta.
 * Tastiera: frecce per spostarsi fra le carte, Invio per scegliere, Esc per chiudere.
 */
export function AreaSwitch({ compact = false }: { compact?: boolean }) {
  const router = useRouter();
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const current = areaOf(pathname);
  const [open, setOpen] = useState(false);
  const [chosen, setChosen] = useState<AreaId | null>(null);
  const buttons = useRef<(HTMLButtonElement | null)[]>([]);

  const choose = (id: AreaId) => {
    if (chosen) return;
    if (id === current) {
      setOpen(false);
      return;
    }
    const to = targetOf(id);
    // la pagina nuova si carica mentre il portale è aperto: alla fine è già pronta
    void router.preloadRoute({ to }).catch(() => undefined);
    setChosen(id);
    // il portale vive nella radice (AreaPortalHost): resta anche se questa scocca si smonta con la pagina
    openAreaPortal({
      area: id,
      onNavigate: () => void router.navigate({ to }),
      onDone: () => setChosen(null),
    });
    // il selettore si chiude appena il portale copre lo schermo (la carta scelta ha finito di volare)
    window.setTimeout(() => setOpen(false), Math.round(PORTAL_MS * 0.13));
  };

  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const keys: Record<string, number> = {
      ArrowRight: 1,
      ArrowDown: 1,
      ArrowLeft: -1,
      ArrowUp: -1,
    };
    const step = keys[e.key];
    const list = buttons.current;
    if (step !== undefined) {
      e.preventDefault();
      const at = list.findIndex((b) => b === document.activeElement);
      const next = (at + step + list.length) % list.length;
      list[next]?.focus();
    } else if (e.key === "Home" || e.key === "End") {
      e.preventDefault();
      list[e.key === "Home" ? 0 : list.length - 1]?.focus();
    }
  };

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (!next && chosen) return; // mentre il portale lavora il selettore si chiude da solo
          setOpen(next);
        }}
      >
        <DialogPrimitive.Trigger asChild>
          <motion.button
            type="button"
            aria-label="Cambia area"
            className={cn(
              "inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary/50 text-sm font-bold text-primary transition-colors hover:bg-primary/10",
              compact ? "min-w-11 px-2.5" : "w-full px-3",
            )}
            animate={{ scale: open ? 1.06 : 1 }}
            whileTap={{ scale: 0.96 }}
            transition={spring.pop}
          >
            <LayoutGrid className="h-4 w-4" />
            {/* nell'intestazione del telefono resta solo l'icona (il nome lo dice aria-label) */}
            <span className={cn("whitespace-nowrap", compact && "hidden sm:inline")}>
              Cambia area
            </span>
          </motion.button>
        </DialogPrimitive.Trigger>
        <DialogPortal>
          <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-background/70 backdrop-blur-md data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=closed]:animate-out data-[state=closed]:fade-out-0" />
          <DialogPrimitive.Content
            aria-label="Cambia area"
            className="fixed inset-0 z-50 flex flex-col items-center justify-start overflow-y-auto px-4 py-6 outline-none data-[state=open]:animate-in md:justify-center data-[state=open]:fade-in-0 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 md:p-8"
            onOpenAutoFocus={(e) => {
              // il fuoco parte dalla carta dell'area in cui si è
              e.preventDefault();
              buttons.current[AREA_ORDER.indexOf(current)]?.focus();
            }}
            onKeyDown={onKeyDown}
            onClick={(e) => {
              if (e.target === e.currentTarget) setOpen(false); // un click sul fondo chiude
            }}
          >
            <div className="pointer-events-none mb-5 text-center md:mb-8">
              <DialogTitle className="font-display text-3xl text-foreground md:text-5xl">
                Cambia area
              </DialogTitle>
              <DialogDescription className="mt-2">
                Frecce per spostarti, Invio per entrare, Esc per chiudere.
              </DialogDescription>
            </div>
            <div
              className="grid w-full max-w-4xl grid-cols-1 gap-3 md:grid-cols-3 md:gap-5"
              style={{ perspective: 1600 }}
            >
              {AREA_ORDER.map((id, i) => (
                <TiltCard
                  key={id}
                  area={AREAS[id]}
                  index={i}
                  current={id === current}
                  chosen={chosen}
                  onChoose={choose}
                  buttonRef={(el) => {
                    buttons.current[i] = el;
                  }}
                />
              ))}
            </div>
            <DialogPrimitive.Close className="press mt-6 min-h-11 rounded-lg px-4 text-sm font-semibold text-muted-foreground hover:text-foreground md:mt-8">
              Chiudi
            </DialogPrimitive.Close>
          </DialogPrimitive.Content>
        </DialogPortal>
      </Dialog>
    </>
  );
}
