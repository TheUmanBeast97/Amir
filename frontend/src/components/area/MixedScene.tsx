import { motion } from "motion/react";
import { useEffect, useState } from "react";
import { ease } from "@/lib/motion";
import {
  at,
  LEAD_CLASS,
  NAME_CLASS,
  PortalCanvas,
  particleCount,
  Welcome,
  type Painter,
  type SceneProps,
} from "./portal-shared";

/**
 * Mixed Zone, i dati di tutto XFive. Fasi (frazioni del portale, su 3 s):
 * - 0 → 1: una griglia prospettica ciano (pavimento e soffitto in CSS 3D) scorre verso l'osservatore, le particelle
 *   su canvas corrono come stelle in velocità di curvatura e si rigenerano, la luce in fondo al tunnel si avvicina;
 * - 0,02 → ~0,95: sei cornici prospettiche passano oltre l'osservatore una dopo l'altra, in due giri;
 * - 0,06 → ~0,9: «dati» finti (CITTADELLA 2026/27, 3-1, 90' …) sfrecciano nel tunnel con sfocatura di movimento;
 * - 0,30: «Benvenuto in» si digita; 0,45 → ~0,67: il nome si digita lettera per lettera con il cursore che lampeggia;
 * - ~0,68: il lampo finale, poi il cursore sparisce.
 */

const FRAMES = 6;
const FRAME_DURATION = at(0.36);
const FRAME_STEP = at(0.055);

/** Le etichette finte che passano: posizione attorno al centro (in vw/vh dal centro) e ordine d'uscita. */
const DATA: { text: string; dx: number; dy: number }[] = [
  { text: "CITTADELLA 2026/27", dx: -28, dy: -26 },
  { text: "3-1", dx: 30, dy: -22 },
  { text: "90'", dx: -34, dy: 18 },
  { text: "XG 1,8", dx: 26, dy: 24 },
  { text: "SERIE A1", dx: 0, dy: -34 },
  { text: "GOL 12", dx: -22, dy: 32 },
  { text: "POSS. 54%", dx: 33, dy: 2 },
  { text: "TIRI 27", dx: -36, dy: -4 },
  { text: "ASSIST 7", dx: 18, dy: -32 },
  { text: "CLEAN SHEET", dx: 8, dy: 34 },
  { text: "2° TEMPO", dx: -14, dy: -32 },
  { text: "AMIR 4-2", dx: 34, dy: 30 },
];

/** Una stella del tunnel: parte vicino al centro e accelera verso l'osservatore; uscita dallo schermo, rinasce. */
interface Star {
  x: number;
  y: number;
  dx: number;
  dy: number;
  speed: number;
  size: number;
  born: number;
}

const spawn = (cx: number, cy: number, born: number): Star => {
  const angle = Math.random() * Math.PI * 2;
  const radius = 12 + Math.random() * 70;
  return {
    x: cx + Math.cos(angle) * radius,
    y: cy + Math.sin(angle) * radius,
    dx: Math.cos(angle),
    dy: Math.sin(angle),
    speed: 70 + Math.random() * 150,
    size: 0.8 + Math.random() * 1.8,
    born,
  };
};

/**
 * Testo che si «digita»: le lettere non ancora scritte tengono il posto (invisibili) così la riga non salta,
 * e il cursore sta subito dopo l'ultima scritta.
 */
function Typed({
  text,
  startMs,
  stepMs,
  cursorUntilMs,
  className,
}: {
  text: string;
  startMs: number;
  stepMs: number;
  /** Fino a quando il cursore resta (ms dall'inizio); compare poco prima della prima lettera. Omesso: niente cursore. */
  cursorUntilMs?: number;
  className?: string;
}) {
  const [typed, setTyped] = useState(0);
  const [cursor, setCursor] = useState(false);

  useEffect(() => {
    const timers = [...text].map((_, i) =>
      window.setTimeout(() => setTyped(i + 1), startMs + i * stepMs),
    );
    if (cursorUntilMs !== undefined) {
      timers.push(window.setTimeout(() => setCursor(true), Math.max(0, startMs - 250)));
      timers.push(window.setTimeout(() => setCursor(false), cursorUntilMs));
    }
    return () => timers.forEach((t) => window.clearTimeout(t));
  }, [text, startMs, stepMs, cursorUntilMs]);

  const caret = (
    <motion.span
      aria-hidden
      className="inline-block w-[0.09em] bg-current align-[-0.06em]"
      style={{ height: "0.85em", marginLeft: "0.04em" }}
      animate={{ opacity: [1, 1, 0, 0] }}
      transition={{ duration: 0.8, times: [0, 0.5, 0.5, 1], repeat: Infinity, ease: "linear" }}
    />
  );

  return (
    <span aria-label={text} className={`inline-block whitespace-pre ${className ?? ""}`}>
      {cursor && typed === 0 && caret}
      {[...text].map((ch, i) => (
        <span key={`${ch}-${i}`} aria-hidden>
          <span style={{ visibility: i < typed ? "visible" : "hidden" }}>{ch}</span>
          {cursor && i === typed - 1 && caret}
        </span>
      ))}
    </span>
  );
}

export function MixedScene({ area }: SceneProps) {
  const cyan = area.accent;
  const leadStart = at(0.3) * 1000;
  const leadStep = 28;
  const nameStart = at(0.45) * 1000;
  const nameStep = 65;
  const nameDone = nameStart + area.name.length * nameStep;
  const flashAt = nameDone / 1000 + 0.05;

  const setup = (w: number, h: number): Painter => {
    const cx = w / 2;
    const cy = h / 2;
    const stars = Array.from({ length: particleCount(w, h) }, () => {
      const s = spawn(cx, cy, 0);
      s.born = Math.random() * 0.6;
      return s;
    });
    return (ctx, t, dt) => {
      ctx.strokeStyle = cyan;
      ctx.lineCap = "round";
      for (const p of stars) {
        if (t < p.born) continue;
        const age = t - p.born;
        const speed = p.speed * (1 + age * 3.2); // accelerano verso l'osservatore
        p.x += p.dx * speed * dt;
        p.y += p.dy * speed * dt;
        if (p.x < -60 || p.x > w + 60 || p.y < -60 || p.y > h + 60) {
          Object.assign(p, spawn(cx, cy, t + Math.random() * 0.2));
          continue;
        }
        const tail = Math.min(44, speed * 0.06);
        ctx.globalAlpha = Math.min(1, age * 4) * 0.85;
        ctx.lineWidth = p.size;
        ctx.beginPath();
        ctx.moveTo(p.x - p.dx * tail, p.y - p.dy * tail);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
      }
    };
  };

  const grid = {
    backgroundImage: `linear-gradient(${cyan}66 1px, transparent 1px), linear-gradient(90deg, ${cyan}66 1px, transparent 1px)`,
    backgroundSize: "80px 80px",
  };

  return (
    <>
      {/* pavimento e soffitto: due piani in prospettiva con la griglia che scorre verso chi guarda */}
      <div aria-hidden className="absolute inset-0 overflow-hidden" style={{ perspective: 520 }}>
        <div
          className="absolute left-[-60%] right-[-60%] top-1/2 h-[70vh]"
          style={{
            transformOrigin: "50% 0%",
            transform: "rotateX(74deg)",
            maskImage: "linear-gradient(to bottom, transparent 0%, black 45%)",
            WebkitMaskImage: "linear-gradient(to bottom, transparent 0%, black 45%)",
          }}
        >
          <motion.div
            className="absolute inset-x-0 top-[-100%] h-[300%] will-change-transform"
            style={grid}
            animate={{ y: [0, 80] }}
            transition={{ duration: 0.42, repeat: Infinity, ease: "linear" }}
          />
        </div>
        <div
          className="absolute bottom-1/2 left-[-60%] right-[-60%] h-[70vh]"
          style={{
            transformOrigin: "50% 100%",
            transform: "rotateX(-74deg)",
            maskImage: "linear-gradient(to top, transparent 0%, black 45%)",
            WebkitMaskImage: "linear-gradient(to top, transparent 0%, black 45%)",
          }}
        >
          <motion.div
            className="absolute inset-x-0 top-[-100%] h-[300%] will-change-transform"
            style={grid}
            animate={{ y: [0, -80] }}
            transition={{ duration: 0.42, repeat: Infinity, ease: "linear" }}
          />
        </div>
      </div>

      {/* il tunnel: sei cornici che partono lontane e passano oltre l'osservatore, due giri */}
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
              border: `2px solid ${cyan}`,
              boxShadow: `0 0 36px ${cyan}66, inset 0 0 36px ${cyan}22`,
            }}
            initial={{ z: -2800, opacity: 0 }}
            animate={{ z: 760, opacity: [0, 0.95, 0.95, 0] }}
            transition={{
              duration: FRAME_DURATION,
              delay: at(0.02) + i * FRAME_STEP,
              repeat: 1,
              ease: [0.3, 0, 0.75, 1],
              opacity: {
                duration: FRAME_DURATION,
                delay: at(0.02) + i * FRAME_STEP,
                repeat: 1,
                times: [0, 0.2, 0.8, 1],
              },
            }}
          />
        ))}
        {/* la luce in fondo al tunnel, che si avvicina */}
        <motion.div
          className="absolute rounded-full will-change-transform"
          style={{
            width: "min(70vmin, 600px)",
            height: "min(70vmin, 600px)",
            background: `radial-gradient(circle, ${cyan}77, ${cyan}00 65%)`,
          }}
          initial={{ scale: 0.3, opacity: 0 }}
          animate={{ scale: 1.9, opacity: [0, 0.9, 0.5] }}
          transition={{ duration: at(1), ease: ease.out }}
        />
        {/* i dati che sfrecciano nel tunnel */}
        {DATA.map((d, i) => (
          <motion.span
            key={d.text}
            className="absolute whitespace-nowrap rounded border px-2.5 py-1 font-mono text-sm font-bold tracking-[0.18em] will-change-transform md:text-lg"
            style={{
              left: `calc(50% + ${d.dx}vw)`,
              top: `calc(50% + ${d.dy}vh)`,
              color: cyan,
              borderColor: `${cyan}88`,
              background: "rgba(7,20,26,0.7)",
              boxShadow: `0 0 18px ${cyan}55`,
            }}
            initial={{ z: -1800, opacity: 0, filter: "blur(6px)" }}
            animate={{
              z: 900,
              opacity: [0, 1, 1, 0],
              filter: ["blur(6px)", "blur(0px)", "blur(0px)", "blur(14px)"],
            }}
            transition={{
              duration: at(0.32),
              delay: at(0.06) + i * at(0.065),
              ease: [0.3, 0, 0.8, 1],
              // le transizioni per proprietà non ereditano il ritardo: va ripetuto
              opacity: {
                times: [0, 0.15, 0.82, 1],
                duration: at(0.32),
                delay: at(0.06) + i * at(0.065),
              },
              filter: {
                times: [0, 0.3, 0.7, 1],
                duration: at(0.32),
                delay: at(0.06) + i * at(0.065),
              },
            }}
          >
            {d.text}
          </motion.span>
        ))}
      </div>

      <PortalCanvas setup={setup} />

      {/* il lampo finale, quando il nome è tutto scritto */}
      <motion.div
        aria-hidden
        className="absolute inset-0"
        style={{
          background: `radial-gradient(circle at 50% 50%, #ffffff, ${cyan} 40%, transparent 75%)`,
        }}
        initial={{ opacity: 0 }}
        animate={{ opacity: [0, 0.8, 0] }}
        transition={{ duration: 0.45, delay: flashAt, times: [0, 0.25, 1], ease: "easeOut" }}
      />

      <Welcome accent={cyan}>
        <p className={LEAD_CLASS}>
          <Typed text="Benvenuto in" startMs={leadStart} stepMs={leadStep} />
        </p>
        <h2 className={NAME_CLASS}>
          <Typed
            text={area.name}
            startMs={nameStart}
            stepMs={nameStep}
            cursorUntilMs={nameDone + 450}
          />
        </h2>
      </Welcome>
    </>
  );
}
