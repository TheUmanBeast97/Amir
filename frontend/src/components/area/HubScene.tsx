import { motion } from "motion/react";
import { useRef } from "react";
import { ease } from "@/lib/motion";
import {
  at,
  LEAD_CLASS,
  NAME_CLASS,
  PortalCanvas,
  Welcome,
  type Painter,
  type SceneProps,
} from "./portal-shared";

/**
 * Amir Hub, la squadra. Fasi (frazioni del portale, su 3 s):
 * - 0 → 0,40: lo stemma arriva da lontano ruotando in 3D e si ferma al centro; intanto strisce diagonali rosse e
 *   bianche attraversano lo schermo a scalare, da sinistra a destra (come la striscia di PageTransition);
 * - 0,40 → 0,62: il «battito» (due pulsazioni) con due anelli di luce; al primo battito partono coriandoli e schegge
 *   rosse e bianche dallo stemma (canvas), al secondo una seconda manciata; tre strisce tornano da destra;
 * - 0,43: «Benvenuto in» risale; 0,48 → ~0,80: le lettere del nome cadono dall'alto una alla volta e rimbalzano (molla).
 */

const ARRIVE = at(0.4);
const BEAT = at(0.4);
const BEAT_2 = at(0.52);
const WHITE = "rgba(255,255,255,0.92)";

/** Larghezze delle strisce della prima onda (in vw), a scalare e poi a calare; i colori si alternano. */
const WAVE_1 = [4, 7, 10, 14, 10, 7, 4];
/** La seconda onda, più corta, parte al battito e va da destra a sinistra. */
const WAVE_2 = [5, 9, 5];

interface Shard {
  x: number;
  y: number;
  vx: number;
  vy: number;
  w: number;
  h: number;
  rot: number;
  vr: number;
  white: boolean;
  born: number;
}

/** Coriandoli e schegge che partono da un punto: a ventaglio verso l'alto, poi cadono. */
const burst = (n: number, x: number, y: number, t: number): Shard[] =>
  Array.from({ length: n }, () => {
    const angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.5;
    const speed = 260 + Math.random() * 520;
    return {
      x,
      y,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed - 80,
      w: 3 + Math.random() * 5,
      h: 7 + Math.random() * 11,
      rot: Math.random() * Math.PI,
      vr: (Math.random() - 0.5) * 14,
      white: Math.random() < 0.42,
      born: t,
    };
  });

export function HubScene({ area }: SceneProps) {
  const red = area.accent;
  const stemma = useRef<HTMLImageElement>(null);

  const setup = (w: number, h: number): Painter => {
    const shards: Shard[] = [];
    let wave = 0;
    // il punto di partenza è il centro dello stemma, letto quando è fermo (al battito)
    const origin = (): [number, number] => {
      const r = stemma.current?.getBoundingClientRect();
      return r ? [r.left + r.width / 2, r.top + r.height / 2] : [w / 2, h * 0.42];
    };
    return (ctx, t, dt) => {
      if (wave === 0 && t >= BEAT) {
        const [x, y] = origin();
        shards.push(...burst(100, x, y, t));
        wave = 1;
      } else if (wave === 1 && t >= BEAT_2) {
        const [x, y] = origin();
        shards.push(...burst(50, x, y, t));
        wave = 2;
      }
      for (const s of shards) {
        const age = t - s.born;
        s.vy += 980 * dt;
        s.vx *= 1 - 0.6 * dt;
        s.x += s.vx * dt;
        s.y += s.vy * dt;
        s.rot += s.vr * dt;
        const alpha = Math.max(0, 1 - age / 1.5);
        if (alpha <= 0 || s.y > h + 40) continue;
        ctx.save();
        ctx.translate(s.x, s.y);
        ctx.rotate(s.rot);
        ctx.globalAlpha = alpha;
        ctx.fillStyle = s.white ? WHITE : red;
        ctx.fillRect(-s.w / 2, -s.h / 2, s.w, s.h);
        ctx.restore();
      }
    };
  };

  return (
    <>
      {/* strisce diagonali rosse e bianche, come il taglio delle schede */}
      <div aria-hidden className="absolute inset-0 overflow-hidden">
        {WAVE_1.map((vw, i) => (
          <motion.div
            key={`a-${i}`}
            className="absolute inset-y-[-12%] left-0 will-change-transform"
            style={{
              width: `${vw}vw`,
              skewX: -16,
              background: i % 2 ? WHITE : red,
              boxShadow: i % 2 ? "none" : `0 0 40px ${red}88`,
            }}
            initial={{ x: "-45vw" }}
            animate={{ x: "145vw" }}
            transition={{ duration: at(0.3), delay: at(0.02) + i * at(0.035), ease: ease.inOut }}
          />
        ))}
        {WAVE_2.map((vw, i) => (
          <motion.div
            key={`b-${i}`}
            className="absolute inset-y-[-12%] left-0 will-change-transform"
            style={{
              width: `${vw}vw`,
              skewX: -16,
              background: i % 2 ? WHITE : red,
              opacity: 0.85,
            }}
            initial={{ x: "145vw" }}
            animate={{ x: "-45vw" }}
            transition={{ duration: at(0.24), delay: BEAT + i * at(0.03), ease: ease.inOut }}
          />
        ))}
      </div>

      <PortalCanvas setup={setup} />

      <div className="relative z-10 flex flex-col items-center gap-5 md:gap-7">
        {/* lo stemma: arriva da lontano ruotando, si ferma e batte due volte */}
        <div className="relative grid place-items-center" style={{ perspective: 1000 }}>
          {[0, 0.12].map((d) => (
            <motion.span
              key={d}
              aria-hidden
              className="absolute rounded-full will-change-transform"
              style={{
                width: "min(40vmin, 280px)",
                height: "min(40vmin, 280px)",
                border: `3px solid ${red}`,
                boxShadow: `0 0 60px ${red}`,
              }}
              initial={{ scale: 0.6, opacity: 0 }}
              animate={{ scale: 2.6, opacity: [0, 0.9, 0] }}
              transition={{ duration: at(0.3), ease: ease.out, delay: BEAT + d }}
            />
          ))}
          <motion.div
            className="will-change-transform"
            style={{ transformStyle: "preserve-3d" }}
            initial={{ z: -3200, rotateY: 720, rotateX: 40, opacity: 0 }}
            animate={{ z: 0, rotateY: 0, rotateX: 0, opacity: 1 }}
            transition={{
              duration: ARRIVE,
              ease: ease.out,
              opacity: { duration: at(0.12), ease: "linear" },
            }}
          >
            <motion.img
              ref={stemma}
              src="/stemma-amir.png"
              alt=""
              className="block will-change-transform"
              style={{
                width: "min(40vmin, 280px)",
                height: "min(40vmin, 280px)",
                objectFit: "contain",
                filter: `drop-shadow(0 18px 40px rgba(0,0,0,0.7)) drop-shadow(0 0 28px ${red}aa)`,
              }}
              initial={{ scale: 1 }}
              animate={{ scale: [1, 1.18, 1, 1.14, 1] }}
              transition={{
                duration: BEAT_2 - BEAT + at(0.1),
                delay: BEAT,
                times: [0, 0.22, 0.45, 0.68, 1],
                ease: "easeInOut",
              }}
            />
          </motion.div>
        </div>

        <Welcome accent={red}>
          <motion.p
            className={`${LEAD_CLASS} will-change-transform`}
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, ease: ease.out, delay: at(0.43) }}
          >
            Benvenuto in
          </motion.p>
          <h2 className={NAME_CLASS}>
            <span aria-label={area.name} className="inline-block">
              {[...area.name].map((ch, i) => (
                <motion.span
                  key={`${ch}-${i}`}
                  aria-hidden
                  className="inline-block will-change-transform"
                  initial={{ opacity: 0, y: -170 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{
                    type: "spring",
                    stiffness: 380,
                    damping: 13,
                    mass: 0.9,
                    delay: at(0.48) + i * 0.055,
                    opacity: { duration: 0.12, delay: at(0.48) + i * 0.055 },
                  }}
                >
                  {ch === " " ? " " : ch}
                </motion.span>
              ))}
            </span>
          </h2>
        </Welcome>
      </div>
    </>
  );
}
