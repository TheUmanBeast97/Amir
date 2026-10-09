import { motion, useReducedMotion } from "motion/react";
import { useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import { AREAS, type AreaId } from "@/lib/area";
import { ease } from "@/lib/motion";

/** Durata del passaggio in secondi; la nuova pagina si carica a metà, così la dissolvenza finale la scopre già pronta. */
const TOTAL = 1.2;
const NAVIGATE_AT = 0.6;
/** Con «riduci le animazioni»: solo una dissolvenza breve con la scritta. */
const REDUCED = 0.3;
/** I piani prospettici del tunnel. */
const FRAMES = 6;
/** Mai più di tante particelle sul canvas (su un telefono molte meno). */
const MAX_PARTICLES = 120;

interface Props {
  area: AreaId;
  /** Chiamato quando è ora di cambiare pagina (a 0,6 s; subito con «riduci animazioni»). */
  onNavigate: () => void;
  /** Chiamato alla fine: chi monta il portale lo smonta. */
  onDone: () => void;
}

/** Particelle leggere che schizzano dal centro verso l'osservatore, come stelle in velocità di curvatura. */
function Particles({ color, seconds }: { color: string; seconds: number }) {
  const ref = useRef<HTMLCanvasElement>(null);
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
    // in proporzione allo schermo: circa 110 su un desktop, una trentina su un telefono
    const count = Math.min(MAX_PARTICLES, Math.max(24, Math.round((w * h) / 9000)));
    const cx = w / 2;
    const cy = h / 2;
    const dots = Array.from({ length: count }, () => {
      const angle = Math.random() * Math.PI * 2;
      const radius = 12 + Math.random() * 70;
      return {
        x: cx + Math.cos(angle) * radius,
        y: cy + Math.sin(angle) * radius,
        dx: Math.cos(angle),
        dy: Math.sin(angle),
        speed: 70 + Math.random() * 150,
        size: 0.8 + Math.random() * 1.8,
        born: Math.random() * 0.45,
      };
    });
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
      // negli ultimi 0,25 s le particelle sfumano insieme al portale
      const fade = t > seconds - 0.25 ? Math.max(0, (seconds - t) / 0.25) : 1;
      ctx.strokeStyle = color;
      ctx.lineCap = "round";
      for (const p of dots) {
        if (t < p.born) continue;
        const age = t - p.born;
        const speed = p.speed * (1 + age * 3.2); // accelerano verso l'osservatore
        p.x += p.dx * speed * dt;
        p.y += p.dy * speed * dt;
        const tail = Math.min(44, speed * 0.06);
        ctx.globalAlpha = fade * Math.min(1, age * 4) * 0.85;
        ctx.lineWidth = p.size;
        ctx.beginPath();
        ctx.moveTo(p.x - p.dx * tail, p.y - p.dy * tail);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
      }
      if (t < seconds) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [color, seconds]);
  return <canvas ref={ref} aria-hidden className="absolute inset-0 h-full w-full" />;
}

/** Il nome dell'area che si compone lettera per lettera (40 ms a lettera). */
function Letters({ text, delay, step }: { text: string; delay: number; step: number }) {
  return (
    <span aria-label={text} className="inline-block">
      {[...text].map((ch, i) => (
        <motion.span
          key={`${ch}-${i}`}
          aria-hidden
          className="inline-block will-change-transform"
          initial={{ opacity: 0, y: 22 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.42, ease: ease.out, delay: delay + i * step }}
        >
          {ch === " " ? " " : ch}
        </motion.span>
      ))}
    </span>
  );
}

/**
 * Il passaggio da un'area all'altra: un tunnel di piani prospettici nel colore dell'area che corrono verso
 * l'osservatore, particelle su canvas e «Benvenuto in <area>» lettera per lettera. Solo transform e opacity.
 * Si disegna in fondo a <body> (fuori dalle intestazioni con backdrop-filter, che bloccherebbero il `fixed`).
 */
export function AreaPortal({ area, onNavigate, onDone }: Props) {
  const reduce = useReducedMotion() ?? false;
  const a = AREAS[area];
  // i callback passano da ref: i timer partono una volta sola, anche se chi ci monta si ridisegna
  const navigateRef = useRef(onNavigate);
  const doneRef = useRef(onDone);
  navigateRef.current = onNavigate;
  doneRef.current = onDone;

  useEffect(() => {
    if (reduce) {
      navigateRef.current();
      const t = window.setTimeout(() => doneRef.current(), REDUCED * 1000);
      return () => window.clearTimeout(t);
    }
    const t1 = window.setTimeout(() => navigateRef.current(), NAVIGATE_AT * 1000);
    const t2 = window.setTimeout(() => doneRef.current(), TOTAL * 1000);
    return () => {
      window.clearTimeout(t1);
      window.clearTimeout(t2);
    };
  }, [reduce]);

  if (typeof document === "undefined") return null;

  const seconds = reduce ? REDUCED : TOTAL;
  const welcome = (
    <div
      role="status"
      aria-live="polite"
      className="relative z-10 px-6 text-center text-white"
      style={{ textShadow: `0 0 36px ${a.accent}99, 0 4px 24px rgba(0,0,0,0.6)` }}
    >
      <motion.p
        className="mb-3 text-xs font-bold uppercase tracking-[0.42em] text-white/75 will-change-transform md:text-sm"
        initial={{ opacity: 0, y: reduce ? 0 : 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.4, ease: ease.out, delay: reduce ? 0 : 0.18 }}
      >
        Benvenuto in
      </motion.p>
      <h2 className="font-display text-5xl leading-none sm:text-6xl md:text-8xl">
        {reduce ? a.name : <Letters text={a.name} delay={0.3} step={0.04} />}
      </h2>
    </div>
  );

  return createPortal(
    <motion.div
      aria-hidden={false}
      className="fixed inset-0 z-[100] grid place-items-center overflow-hidden"
      style={{
        background: `radial-gradient(70% 55% at 50% 50%, ${a.accent}2e, transparent 70%), #0e0e12`,
      }}
      initial={{ opacity: 0 }}
      animate={{ opacity: reduce ? [0, 1, 0] : [0, 1, 1, 0] }}
      transition={
        reduce
          ? { duration: REDUCED, times: [0, 0.4, 1], ease: "linear" }
          : { duration: TOTAL, times: [0, 0.22, 0.84, 1], ease: "linear" }
      }
    >
      {!reduce && (
        <>
          {/* il tunnel: sei cornici che partono lontane e passano oltre l'osservatore, una dopo l'altra */}
          <div
            aria-hidden
            className="absolute inset-0 grid place-items-center"
            style={{ perspective: 1200, transformStyle: "preserve-3d" }}
          >
            {Array.from({ length: FRAMES }, (_, i) => (
              <motion.div
                key={i}
                className="absolute rounded-[18%] will-change-transform"
                style={{
                  width: "min(62vmin, 520px)",
                  height: "min(62vmin, 520px)",
                  border: `2px solid ${a.accent}`,
                  boxShadow: `0 0 36px ${a.accent}66, inset 0 0 36px ${a.accent}22`,
                }}
                initial={{ z: -2800, opacity: 0 }}
                animate={{ z: 760, opacity: [0, 0.95, 0.95, 0] }}
                transition={{
                  duration: 0.98,
                  delay: 0.04 + i * 0.075,
                  ease: [0.3, 0, 0.75, 1],
                  opacity: { duration: 0.98, delay: 0.04 + i * 0.075, times: [0, 0.2, 0.8, 1] },
                }}
              />
            ))}
            {/* la luce in fondo al tunnel, che si avvicina */}
            <motion.div
              className="absolute rounded-full will-change-transform"
              style={{
                width: "min(70vmin, 600px)",
                height: "min(70vmin, 600px)",
                background: `radial-gradient(circle, ${a.accent}77, ${a.accent}00 65%)`,
              }}
              initial={{ scale: 0.3, opacity: 0 }}
              animate={{ scale: 1.9, opacity: [0, 0.9, 0.5] }}
              transition={{ duration: TOTAL, ease: ease.out }}
            />
          </div>
          <Particles color={a.accent} seconds={TOTAL} />
        </>
      )}
      {welcome}
    </motion.div>,
    document.body,
  );
}
