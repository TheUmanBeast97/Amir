import { motion } from "motion/react";
import { ease } from "@/lib/motion";
import { at, LEAD_CLASS, NAME_CLASS, Welcome, type SceneProps } from "./portal-shared";

/**
 * Staff Area, la cassaforte / sala di controllo. Fasi (frazioni del portale, su 3 s):
 * - 0 → 0,30: due pannelli scuri chiusi coprono lo schermo; sopra, al centro, una ghiera ambra con le tacche ruota
 *   avanti e indietro e si allinea con uno scatto;
 * - 0,30 → 0,60: una lama di luce esce dalla fessura e i due pannelli si aprono dal centro come un portellone;
 *   la ghiera si ritira; tre linee di scansione orizzontali passano dall'alto in basso (0,33 / 0,42 / 0,51);
 * - 0,50: il timbro «ACCESSO CONSENTITO» si stampa con un leggero rimbalzo;
 * - 0,58 → 0,74: la scritta appare per parole intere («Benvenuto», «in», poi il nome) con un bagliore ambra.
 */

const OPEN = at(0.3);
const OPEN_DURATION = at(0.3);
const STAMP = at(0.5);
const WORDS = [at(0.58), at(0.62)];
const NAME_WORDS = [at(0.68), at(0.74)];
const SCANS = [at(0.33), at(0.42), at(0.51)];

const PANEL_BG =
  "linear-gradient(160deg, #1d1813 0%, #120e0a 60%, #0b0806 100%), repeating-linear-gradient(0deg, rgba(255,255,255,0.025) 0 2px, transparent 2px 9px)";

export function StaffScene({ area }: SceneProps) {
  const amber = area.accent;
  const wordGlow = {
    initial: { opacity: 0, filter: "blur(12px) brightness(2.4)", scale: 1.08 },
    animate: {
      opacity: 1,
      filter: [
        "blur(12px) brightness(2.4)",
        "blur(0px) brightness(1.8)",
        "blur(0px) brightness(1)",
      ],
      scale: 1,
    },
  };
  const word = (text: string, delay: number) => (
    <motion.span
      key={text}
      className="inline-block will-change-transform"
      initial={wordGlow.initial}
      animate={wordGlow.animate}
      transition={{
        duration: 0.5,
        delay,
        ease: ease.out,
        filter: { duration: 0.5, delay, times: [0, 0.4, 1] },
      }}
    >
      {text}
    </motion.span>
  );
  const [first = "", ...rest] = area.name.split(" ");

  return (
    <>
      {/* la sala dietro il portellone: fondo caldo e cerchi concentrici */}
      <div
        aria-hidden
        className="absolute inset-0"
        style={{
          background: `radial-gradient(70% 60% at 50% 50%, ${amber}2a, transparent 70%), repeating-radial-gradient(circle at 50% 50%, transparent 0 58px, ${amber}14 58px 60px)`,
        }}
      />
      {/* la trama a righe di uno schermo di controllo */}
      <div
        aria-hidden
        className="absolute inset-0 opacity-[0.12]"
        style={{
          backgroundImage: "repeating-linear-gradient(0deg, #fff 0 1px, transparent 1px 4px)",
        }}
      />
      {/* le linee di scansione che passano */}
      {SCANS.map((d) => (
        <motion.div
          key={d}
          aria-hidden
          className="absolute inset-x-0 top-0 h-[3px] will-change-transform"
          style={{
            background: `linear-gradient(90deg, transparent, ${amber}, transparent)`,
            boxShadow: `0 0 24px 4px ${amber}66`,
          }}
          initial={{ y: "-6vh", opacity: 0 }}
          animate={{ y: "106vh", opacity: [0, 1, 1, 0] }}
          transition={{
            duration: at(0.26),
            delay: d,
            ease: "linear",
            opacity: { times: [0, 0.1, 0.9, 1], duration: at(0.26), delay: d },
          }}
        />
      ))}

      <div className="relative z-10 flex flex-col items-center gap-5 md:gap-7">
        {/* il timbro */}
        <motion.div
          className="rounded-sm border-[3px] px-3 py-1.5 font-display text-lg uppercase tracking-[0.28em] will-change-transform md:px-5 md:py-2 md:text-2xl"
          style={{
            color: amber,
            borderColor: amber,
            boxShadow: `inset 0 0 0 2px #0e0e12, inset 0 0 0 3px ${amber}, 0 0 30px ${amber}55`,
            rotate: -6,
            textShadow: `0 0 14px ${amber}aa`,
          }}
          initial={{ opacity: 0, scale: 2.6 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{
            type: "spring",
            stiffness: 520,
            damping: 18,
            mass: 0.8,
            delay: STAMP,
            opacity: { duration: 0.1, delay: STAMP },
          }}
        >
          Accesso consentito
        </motion.div>

        <Welcome accent={amber}>
          <p className={LEAD_CLASS}>
            <span aria-label="Benvenuto in" className="inline-flex gap-[0.6em]">
              {word("Benvenuto", WORDS[0] ?? 0)}
              {word("in", WORDS[1] ?? 0)}
            </span>
          </p>
          <h2 className={NAME_CLASS}>
            <span aria-label={area.name} className="inline-flex gap-[0.22em]">
              {word(first, NAME_WORDS[0] ?? 0)}
              {rest.length > 0 && word(rest.join(" "), NAME_WORDS[1] ?? 0)}
            </span>
          </h2>
        </Welcome>
      </div>

      {/* il portellone: due pannelli che si aprono dal centro */}
      <div aria-hidden className="absolute inset-0 z-20 overflow-hidden">
        <motion.div
          className="absolute inset-y-0 left-0 w-1/2 will-change-transform"
          style={{
            background: PANEL_BG,
            borderRight: `2px solid ${amber}`,
            boxShadow: `inset -24px 0 48px -24px ${amber}55`,
          }}
          initial={{ x: 0 }}
          animate={{ x: "-100%" }}
          transition={{ duration: OPEN_DURATION, delay: OPEN, ease: ease.inOut }}
        />
        <motion.div
          className="absolute inset-y-0 right-0 w-1/2 will-change-transform"
          style={{
            background: PANEL_BG,
            borderLeft: `2px solid ${amber}`,
            boxShadow: `inset 24px 0 48px -24px ${amber}55`,
          }}
          initial={{ x: 0 }}
          animate={{ x: "100%" }}
          transition={{ duration: OPEN_DURATION, delay: OPEN, ease: ease.inOut }}
        />
        {/* la lama di luce dalla fessura, appena il portellone si muove */}
        <motion.div
          className="absolute inset-y-0 left-1/2 w-[4px] -translate-x-1/2 will-change-transform"
          style={{ background: amber, boxShadow: `0 0 40px 10px ${amber}` }}
          initial={{ opacity: 0, scaleX: 1 }}
          animate={{ opacity: [0, 1, 0], scaleX: [1, 30, 60] }}
          transition={{
            duration: OPEN_DURATION * 0.8,
            delay: OPEN,
            ease: ease.out,
            times: [0, 0.3, 1],
          }}
        />
        {/* la ghiera: ruota, si allinea con uno scatto, poi si ritira quando il portellone si apre */}
        <motion.div
          className="absolute left-1/2 top-1/2 grid place-items-center will-change-transform"
          style={{
            width: "min(38vmin, 260px)",
            height: "min(38vmin, 260px)",
            x: "-50%",
            y: "-50%",
          }}
          initial={{ scale: 1, opacity: 1 }}
          animate={{ scale: [1, 1, 1.08, 1, 0.3], opacity: [1, 1, 1, 1, 0] }}
          transition={{
            duration: OPEN + at(0.2),
            times: [0, 0.56, 0.6, 0.64, 1],
            ease: ["linear", "easeOut", "easeIn", ease.inOut],
          }}
        >
          {/* l'indice fisso in alto */}
          <span
            className="absolute top-[-6%] h-[10%] w-[3px]"
            style={{ background: "#fff", boxShadow: `0 0 10px ${amber}` }}
          />
          <motion.div
            className="relative h-full w-full rounded-full will-change-transform"
            style={{
              border: `3px solid ${amber}`,
              background: `repeating-conic-gradient(from -1deg, ${amber} 0deg 2deg, transparent 2deg 12deg)`,
              maskImage: "radial-gradient(circle, transparent 60%, #000 61%)",
              WebkitMaskImage: "radial-gradient(circle, transparent 60%, #000 61%)",
              boxShadow: `0 0 40px ${amber}66`,
            }}
            initial={{ rotate: 0 }}
            animate={{ rotate: [0, -210, 96, -34, 0] }}
            transition={{ duration: OPEN, times: [0, 0.38, 0.66, 0.86, 1], ease: "easeInOut" }}
          />
          <div
            className="absolute rounded-full"
            style={{
              inset: "22%",
              border: `2px solid ${amber}99`,
              background: "radial-gradient(circle, #1d1813, #0b0806)",
              boxShadow: `inset 0 0 24px ${amber}33`,
            }}
          />
          <span
            className="absolute font-display text-base uppercase tracking-[0.3em] md:text-xl"
            style={{ color: amber }}
          >
            Staff
          </span>
        </motion.div>
      </div>
    </>
  );
}
