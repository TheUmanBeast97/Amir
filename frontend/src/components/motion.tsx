import { useRouterState } from "@tanstack/react-router";
import {
  AnimatePresence,
  MotionConfig,
  animate,
  motion,
  useInView,
  useMotionValue,
  useReducedMotion,
  useTransform,
  type MotionStyle,
} from "motion/react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { areaOf } from "@/lib/area";
import { dist, dur, ease, spring, stagger } from "@/lib/motion";
import { cn } from "@/lib/utils";

/** Avvolge tutto il sito: chi ha attivato «riduci le animazioni» perde gli spostamenti ma tiene dissolvenze e stati. */
export function MotionRoot({ children }: { children: ReactNode }) {
  return (
    <MotionConfig reducedMotion="user" transition={{ duration: dur.base, ease: ease.out }}>
      {children}
    </MotionConfig>
  );
}

const tags = {
  div: motion.div,
  section: motion.section,
  article: motion.article,
  li: motion.li,
  ul: motion.ul,
  tr: motion.tr,
  span: motion.span,
  p: motion.p,
} as const;

interface RevealProps {
  children: ReactNode;
  className?: string;
  style?: MotionStyle;
  /** Posizione in una lista: ogni voce arriva un po' dopo la precedente (il ritardo si ferma presto). */
  i?: number;
  delay?: number;
  y?: number;
  x?: number;
  /** Scala di partenza (1 = nessuna). */
  scale?: number;
  /** Sfuma anche la nitidezza: da usare con parsimonia, solo su pezzi piccoli. */
  blur?: boolean;
  as?: keyof typeof tags;
  /** Parte subito invece di aspettare che l'elemento entri nello schermo (area staff, elementi in cima). */
  now?: boolean;
  amount?: number;
}

/** Fa arrivare un elemento con una breve risalita; senza JavaScript o con «riduci animazioni» resta visibile. */
export function Reveal({
  children,
  className,
  style,
  i = 0,
  delay = 0,
  y = dist.md,
  x = 0,
  scale = 1,
  blur = false,
  as = "div",
  now = false,
  amount = 0.15,
}: RevealProps) {
  const Tag = tags[as] as typeof motion.div;
  const from = { opacity: 0, y, x, scale, ...(blur ? { filter: "blur(6px)" } : {}) };
  const to = { opacity: 1, y: 0, x: 0, scale: 1, ...(blur ? { filter: "blur(0px)" } : {}) };
  return (
    <Tag
      {...(className ? { className } : {})}
      {...(style ? { style } : {})}
      initial={from}
      {...(now ? { animate: to } : { whileInView: to, viewport: { once: true, amount } })}
      transition={{ duration: dur.slow, ease: ease.out, delay: delay + stagger(i) }}
    >
      {children}
    </Tag>
  );
}

const italian = (decimals: number) =>
  new Intl.NumberFormat("it-IT", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });

/** Numero che sale da zero al valore quando entra nello schermo, come su un tabellone. */
export function CountUp({
  value,
  decimals = 0,
  duration = 1.1,
  className,
  format,
}: {
  value: number;
  decimals?: number;
  duration?: number;
  className?: string;
  format?: (n: number) => string;
}) {
  const reduce = useReducedMotion();
  const ref = useRef<HTMLSpanElement>(null);
  const inView = useInView(ref, { once: true, amount: 0.3 });
  const mv = useMotionValue(reduce ? value : 0);
  const text = useTransform(mv, (v) => (format ? format(v) : italian(decimals).format(v)));

  useEffect(() => {
    if (reduce) {
      mv.set(value);
      return;
    }
    if (!inView) return;
    const controls = animate(mv, value, { duration, ease: ease.out });
    return () => controls.stop();
  }, [inView, value, reduce, mv, duration]);

  return (
    <motion.span ref={ref} className={cn("num", className)}>
      {text}
    </motion.span>
  );
}

/** Una cifra: quando cambia, la vecchia sale verso l'alto sfocandosi e la nuova sale dal basso. */
function Roll({ ch }: { ch: string }) {
  return (
    <span
      className="relative inline-block overflow-hidden align-bottom"
      style={{ width: "1ch", height: "1.05em", lineHeight: "1.05em" }}
    >
      <AnimatePresence mode="popLayout" initial={false}>
        <motion.span
          key={ch}
          className="absolute inset-0 text-center"
          initial={{ y: "75%", opacity: 0, filter: "blur(3px)" }}
          animate={{ y: "0%", opacity: 1, filter: "blur(0px)" }}
          exit={{ y: "-75%", opacity: 0, filter: "blur(3px)" }}
          transition={{ duration: dur.base, ease: ease.out }}
        >
          {ch}
        </motion.span>
      </AnimatePresence>
    </span>
  );
}

/** Numero fisso (secondi di un conto alla rovescia, punteggi): ogni cifra scorre da sola quando cambia. */
export function RollDigits({
  value,
  pad = 2,
  className,
}: {
  value: number;
  pad?: number;
  className?: string;
}) {
  const s = String(value).padStart(pad, "0");
  return (
    <span className={cn("num inline-flex", className)} aria-label={s}>
      {[...s].map((ch, idx) => (
        <Roll key={s.length - idx} ch={ch} />
      ))}
    </span>
  );
}

/** Barra che si disegna da sinistra a destra, come una linea di campo tracciata. Va messa dentro un contenitore tondo con `overflow-hidden`. */
export function Draw({
  children,
  className,
  delay = 0,
  duration = dur.slow * 1.4,
}: {
  children: ReactNode;
  className?: string;
  delay?: number;
  duration?: number;
}) {
  return (
    <motion.div
      className={className}
      initial={{ clipPath: "inset(0 100% 0 0)" }}
      whileInView={{ clipPath: "inset(0 0% 0 0)" }}
      viewport={{ once: true, amount: 0.5 }}
      transition={{ duration, ease: ease.out, delay }}
    >
      {children}
    </motion.div>
  );
}

/** Elemento che cresce dalla base (colonne) o dal bordo sinistro (barre) usando solo `transform`. */
export function Grow({
  children,
  className,
  style,
  axis = "y",
  delay = 0,
  i = 0,
}: {
  children?: ReactNode;
  className?: string;
  style?: MotionStyle;
  axis?: "x" | "y";
  delay?: number;
  i?: number;
}) {
  return (
    <motion.div
      className={className}
      style={{ ...style, transformOrigin: axis === "y" ? "50% 100%" : "0% 50%" }}
      initial={axis === "y" ? { scaleY: 0 } : { scaleX: 0 }}
      whileInView={axis === "y" ? { scaleY: 1 } : { scaleX: 1 }}
      viewport={{ once: true, amount: 0.5 }}
      transition={{ duration: dur.slow * 1.3, ease: ease.out, delay: delay + stagger(i, 0.07) }}
    >
      {children}
    </motion.div>
  );
}

/**
 * Monta il contenuto solo quando entra nello schermo. I grafici (Recharts) animano al montaggio:
 * così si vedono disegnarsi quando ci si arriva, invece di finire mentre sono ancora fuori vista.
 * Il contenitore tiene l'altezza, quindi la pagina non salta.
 */
export function OnView({ children, className }: { children: ReactNode; className?: string }) {
  const ref = useRef<HTMLDivElement>(null);
  const inView = useInView(ref, { once: true, amount: 0.2 });
  return (
    <div ref={ref} {...(className ? { className } : {})}>
      {inView ? children : null}
    </div>
  );
}

/** La striscia rossa diagonale che attraversa lo schermo a ogni cambio di pagina (stessa inclinazione delle schede). */
function Slash({ onDone }: { onDone: () => void }) {
  return (
    <div aria-hidden className="pointer-events-none fixed inset-0 z-[60] overflow-hidden">
      <motion.div
        className="absolute inset-y-[-12%] left-0 w-[34%]"
        style={{
          skewX: -16,
          background: "linear-gradient(90deg, transparent, var(--primary) 45%, var(--primary))",
        }}
        initial={{ x: "-110%" }}
        animate={{ x: "330%" }}
        transition={{ duration: 0.62, ease: ease.inOut }}
        onAnimationComplete={onDone}
      />
      <motion.div
        className="absolute inset-y-[-12%] left-0 w-[1.2%] bg-white/80"
        style={{ skewX: -16 }}
        initial={{ x: "-3000%" }}
        animate={{ x: "8500%" }}
        transition={{ duration: 0.62, ease: ease.inOut, delay: 0.05 }}
      />
    </div>
  );
}

/**
 * Passaggio fra le pagine: il contenuto risale, e nel sito pubblico una striscia rossa lo attraversa.
 * Il contenuto non aspetta la striscia (nessuna attesa per chi naviga); nell'area staff c'è solo una dissolvenza rapida.
 */
export function PageTransition({ children, wipe = true }: { children: ReactNode; wipe?: boolean }) {
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const reduce = useReducedMotion();
  const lastPath = useRef(pathname);
  const [slashKey, setSlashKey] = useState<string | null>(null);
  const isFirst = lastPath.current === pathname && slashKey === null;

  useEffect(() => {
    // conta solo un vero cambio di indirizzo: al primo caricamento (e alla doppia esecuzione di StrictMode) non succede nulla
    if (lastPath.current === pathname) return;
    // la striscia accompagna i passaggi dentro la stessa area; da un'area all'altra c'è già il portale (AreaSwitch)
    const sameArea = areaOf(lastPath.current) === areaOf(pathname);
    lastPath.current = pathname;
    if (wipe && !reduce && sameArea) setSlashKey(pathname);
  }, [pathname, wipe, reduce]);

  return (
    <>
      <motion.div
        key={pathname}
        initial={isFirst ? false : { opacity: 0, y: wipe ? dist.md : dist.sm }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: wipe ? dur.slow : dur.fast, ease: ease.out, delay: wipe ? 0.1 : 0 }}
      >
        {children}
      </motion.div>
      {slashKey && <Slash key={`slash-${slashKey}`} onDone={() => setSlashKey(null)} />}
    </>
  );
}

/** Indicatore condiviso: un solo segno rosso che scivola fra le voci di menu quando cambia la pagina attiva. */
export function ActivePill({ id, className }: { id: string; className?: string }) {
  return (
    <motion.span
      layoutId={id}
      transition={spring.snappy}
      className={cn("absolute inset-0", className)}
    />
  );
}
