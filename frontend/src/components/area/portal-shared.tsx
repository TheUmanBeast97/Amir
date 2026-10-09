import { useEffect, useRef, type ReactNode } from "react";
import type { Area } from "@/lib/area";

/**
 * Quello che le tre coreografie del portale (HubScene, MixedScene, StaffScene) hanno in comune:
 * la durata fissa, i tempi espressi come frazioni di quella durata, il canvas 2D per le particelle
 * e la cornice della scritta «Benvenuto in …».
 */

/** Il portale dura sempre tre secondi, per tutte le aree e anche con «riduci le animazioni». */
export const PORTAL_MS = 3000;
/** A metà la pagina nuova si carica sotto il portale. */
export const NAVIGATE_MS = PORTAL_MS / 2;
/** L'ultimo tratto: la dissolvenza che scopre la pagina. */
export const FADE_MS = 400;
export const PORTAL_S = PORTAL_MS / 1000;
export const FADE_S = FADE_MS / 1000;

/** Secondi corrispondenti a una frazione del portale (0 = inizio, 1 = fine). Le fasi si scrivono così e restano coerenti. */
export const at = (fraction: number) => (PORTAL_MS * fraction) / 1000;

/** Mai più di tante particelle sul canvas, su qualunque schermo. */
export const MAX_PARTICLES = 150;

/** Quante particelle per questo schermo: in proporzione all'area, con un minimo e il tetto. */
export const particleCount = (w: number, h: number, max = MAX_PARTICLES, perPx = 9000) =>
  Math.min(max, Math.max(24, Math.round((w * h) / perPx)));

export interface SceneProps {
  area: Area;
}

/** Disegna un fotogramma: `t` secondi dall'inizio, `dt` dal fotogramma precedente (tappato a 50 ms). */
export type Painter = (ctx: CanvasRenderingContext2D, t: number, dt: number) => void;

/**
 * Un solo canvas 2D a tutto schermo: `setup` riceve larghezza e altezza e restituisce chi disegna.
 * `dpr` tappato a 2, solo requestAnimationFrame, si ferma da solo alla fine del portale.
 */
export function PortalCanvas({ setup }: { setup: (w: number, h: number) => Painter }) {
  const ref = useRef<HTMLCanvasElement>(null);
  const setupRef = useRef(setup);
  setupRef.current = setup;

  useEffect(() => {
    const canvas = ref.current;
    const ctx = canvas?.getContext("2d");
    if (!canvas || !ctx) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const w = window.innerWidth;
    const h = window.innerHeight;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    ctx.scale(dpr, dpr);
    const paint = setupRef.current(w, h);
    let start: number | null = null;
    let last = 0;
    let raf = 0;
    const tick = (now: number) => {
      if (start === null) {
        start = now;
        last = now;
      }
      const t = (now - start) / 1000;
      const dt = Math.min(0.05, (now - last) / 1000);
      last = now;
      ctx.clearRect(0, 0, w, h);
      paint(ctx, t, dt);
      if (t < PORTAL_S) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, []);

  return <canvas ref={ref} aria-hidden className="absolute inset-0 h-full w-full" />;
}

/** La cornice della scritta: annunciata agli screen reader, bianca con l'alone del colore dell'area. */
export function Welcome({
  accent,
  children,
  className = "",
}: {
  accent: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      role="status"
      aria-live="polite"
      className={`relative z-10 px-6 text-center text-white ${className}`}
      style={{ textShadow: `0 0 36px ${accent}99, 0 4px 24px rgba(0,0,0,0.6)` }}
    >
      {children}
    </div>
  );
}

/** Le classi del nome dell'area, grandi, nel carattere condensato del sito. */
export const NAME_CLASS = "font-display text-5xl leading-none sm:text-6xl md:text-8xl";
/** Le classi di «Benvenuto in», piccolo e spaziato. */
export const LEAD_CLASS =
  "mb-3 text-xs font-bold uppercase tracking-[0.42em] text-white/75 md:text-sm";
