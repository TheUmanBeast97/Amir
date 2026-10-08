import { toPng } from "html-to-image";
import { Download, Info, Share2, Sparkles } from "lucide-react";
import {
  animate,
  motion,
  useMotionTemplate,
  useMotionValue,
  useReducedMotion,
  useSpring,
  useTransform,
} from "motion/react";
import { forwardRef, useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import type { PlayerPage } from "@/api/types";
import { Btn } from "@/components/admin/kit";
import { CountUp } from "@/components/motion";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  cardRating,
  explain,
  tierLabel,
  tierRange,
  TIERS,
  type CardRating,
  type Tier,
} from "@/lib/card-rating";
import { canCutout, makeCutout, type CutoutProgress } from "@/lib/cutout";
import { initials } from "@/lib/kit";
import { dur, ease, tiltSpring } from "@/lib/motion";

/** La figurina è sempre disegnata a questa misura (formato 5:7); l'anteprima la rimpicciolisce. */
const W = 600;
const H = 840;

/** Angolo tagliato in basso a destra, come le schede del sito. */
const shape = (cut: number) =>
  `polygon(0 0, 100% 0, 100% calc(100% - ${cut}px), calc(100% - ${cut}px) 100%, 0 100%)`;

interface Theme {
  /** Il metallo del corpo. */
  body: string;
  /** Il bordo, con riflessi di luce. */
  rim: string;
  /** Colore del testo sul metallo. */
  ink: string;
  /** Colore dei numeri e dei filetti sulla targhetta scura. */
  accent: string;
  /** La lamina olografica si vede solo sui tipi più rari. */
  foil: boolean;
}

const THEME: Record<Tier, Theme> = {
  bronzo: {
    body: "linear-gradient(150deg,#e6b184 0%,#b97a48 45%,#74421f 100%)",
    rim: "linear-gradient(160deg,#f8d9b9,#8a5530 38%,#e7b88a 68%,#6b3b1c)",
    ink: "#2a1608",
    accent: "#f2c79f",
    foil: false,
  },
  argento: {
    body: "linear-gradient(150deg,#f6f8fa 0%,#c4ccd4 45%,#86929e 100%)",
    rim: "linear-gradient(160deg,#ffffff,#8e99a5 38%,#eef2f5 68%,#69747f)",
    ink: "#12171c",
    accent: "#e6edf3",
    foil: false,
  },
  oro: {
    body: "linear-gradient(150deg,#fff2bd 0%,#e3b24a 42%,#a06d10 100%)",
    rim: "linear-gradient(160deg,#fff8d6,#b8862a 38%,#f8df92 68%,#85590e)",
    ink: "#2a1c03",
    accent: "#ffe9a0",
    foil: true,
  },
  platino: {
    body: "linear-gradient(150deg,#ffffff 0%,#e9edf2 30%,#9aa4b1 62%,#2b3038 100%)",
    rim: "linear-gradient(160deg,#ffffff,#3a4049 36%,#dfe5ec 64%,#14171c)",
    ink: "#0d1014",
    accent: "#f4f7fa",
    foil: true,
  },
  fuoco: {
    body: "linear-gradient(150deg,#ff5043 0%,#c4151d 36%,#4d080e 76%,#17030a 100%)",
    rim: "linear-gradient(160deg,#ffddd6,#d61f26 38%,#ff806f 66%,#7d0c14)",
    ink: "#ffffff",
    accent: "#ffb9ac",
    foil: true,
  },
};

/** Quanto grande scrivere il cognome perché stia in una riga. */
const nameSize = (s: string) => (s.length > 15 ? 54 : s.length > 12 ? 64 : s.length > 9 ? 76 : 88);

/** La finestra della foto: in alto a destra del corpo, con il bordo sinistro in diagonale. */
const PHOTO_BOX = {
  position: "absolute",
  top: 0,
  right: 0,
  width: 468,
  height: 566,
  clipPath: "polygon(17% 0, 100% 0, 100% 100%, 0 100%)",
} as const;

/** Come la sagoma senza sfondo sta nella finestra: appoggiata in basso, intera. Stessa geometria nel fronte e nel «tappo» dell'anteprima. */
const SILHOUETTE = {
  position: "absolute",
  left: "6%",
  right: 0,
  bottom: 0,
  width: "94%",
  height: "96%",
  objectFit: "contain",
  objectPosition: "50% 100%",
} as const;

/** La sfumatura scura in basso alla finestra della foto, che la fa morire nella targhetta. */
const PHOTO_FADE = {
  position: "absolute",
  inset: 0,
  background: "linear-gradient(to top, #0e0e12 0%, rgba(14,14,18,0.0) 34%)",
} as const;

interface FaceProps {
  page: PlayerPage;
  rating: CardRating;
  /** Il voto sale da zero (solo nell'anteprima: il PNG scaricato ha sempre il valore finale). */
  animateNumbers?: boolean;
  /** La sagoma senza sfondo appena ritagliata (prima che la scheda si ricarichi dal server). */
  cutout?: string | null | undefined;
}

/**
 * Il fronte della figurina: metallo del tipo (bronzo, argento, oro, fuoco), foto con il bordo tagliato in diagonale,
 * voto e ruolo a sinistra, targhetta scura con il nome e sei numeri veri, e il taglio rosso AMIR che la attraversa.
 * Stili in linea e nessun effetto dipendente dal puntatore: è questo nodo che si esporta in PNG.
 */
export const PlayerCardFace = forwardRef<HTMLDivElement, FaceProps>(function PlayerCardFace(
  { page, rating, animateNumbers = false, cutout },
  ref,
) {
  const { player: p } = page;
  const th = THEME[rating.tier];
  const silhouette = cutout ?? p.cutout_url;
  const [first, ...rest] = p.full_name.split(" ");
  const last = (rest.length ? rest.join(" ") : first) ?? "";
  const firstName = rest.length ? (first ?? "") : "";
  const number = p.shirt_number_red ?? p.shirt_number;
  const serial = `N° ${String(p.id).padStart(3, "0")}`;
  const light = rating.tier !== "fuoco";

  return (
    <div
      ref={ref}
      style={{
        width: W,
        height: H,
        position: "relative",
        clipPath: shape(46),
        background: th.rim,
        color: "#fff",
        fontFamily: '"Inter", system-ui, sans-serif',
      }}
    >
      <div
        style={{
          position: "absolute",
          inset: 8,
          clipPath: shape(38),
          background: th.body,
          overflow: "hidden",
        }}
      >
        {/* trama a righe e luce in alto a sinistra */}
        <div
          style={{
            position: "absolute",
            inset: 0,
            backgroundImage:
              "repeating-linear-gradient(115deg, rgba(255,255,255,0.12) 0 2px, transparent 2px 16px)",
            opacity: light ? 0.55 : 0.4,
          }}
        />
        <div
          style={{
            position: "absolute",
            inset: 0,
            background:
              "radial-gradient(120% 62% at 10% 0%, rgba(255,255,255,0.55), transparent 58%)",
            opacity: light ? 0.8 : 0.35,
          }}
        />

        {/* il numero di maglia, enorme e solo a contorno, dietro la foto */}
        {number && (
          <div
            className="font-display"
            aria-hidden
            style={{
              position: "absolute",
              left: -26,
              top: 120,
              fontSize: 470,
              lineHeight: 0.8,
              color: "transparent",
              WebkitTextStroke: `4px ${light ? "rgba(0,0,0,0.2)" : "rgba(255,255,255,0.22)"}`,
            }}
          >
            {number}
          </div>
        )}

        {/* fascia diagonale, come il taglio delle schede */}
        <div
          aria-hidden
          style={{
            position: "absolute",
            top: -60,
            left: 118,
            width: 74,
            height: 700,
            background: "linear-gradient(180deg, rgba(214,31,38,0.9), rgba(214,31,38,0.0))",
            transform: "skewX(-16deg)",
            opacity: light ? 0.85 : 0.6,
          }}
        />

        {/* foto: bordo sinistro in diagonale, sfuma nella targhetta */}
        <div style={{ ...PHOTO_BOX, background: "linear-gradient(135deg,#3d3d44,#16161a)" }}>
          {silhouette ? (
            <>
              {/* il fondale sta tutto dietro la sagoma: l'alone del colore del tipo, come sulle figurine vere, e la sfumatura scura;
                  la sagoma si disegna per ultima, piena e nitida, senza nulla sopra */}
              <div
                aria-hidden
                style={{
                  position: "absolute",
                  inset: 0,
                  background: `radial-gradient(70% 60% at 58% 42%, ${th.accent}55, transparent 70%)`,
                }}
              />
              <div aria-hidden style={PHOTO_FADE} />
              <img
                src={silhouette}
                alt=""
                crossOrigin="anonymous"
                style={{ ...SILHOUETTE, filter: "drop-shadow(0 18px 28px rgba(0,0,0,0.55))" }}
              />
            </>
          ) : (
            <>
              {p.photo_url ? (
                <img
                  src={p.photo_url}
                  alt=""
                  crossOrigin="anonymous"
                  style={{
                    width: "100%",
                    height: "100%",
                    objectFit: "cover",
                    objectPosition: "50% 8%",
                    transform: "scale(1.14)",
                    transformOrigin: "50% 50%",
                  }}
                />
              ) : (
                <div
                  className="font-display"
                  style={{
                    display: "grid",
                    placeItems: "center",
                    width: "100%",
                    height: "100%",
                    fontSize: 200,
                    color: "rgba(255,255,255,0.35)",
                  }}
                >
                  {initials(p.full_name)}
                </div>
              )}
              {/* la foto intera (o le iniziali) sfuma nella targhetta */}
              <div aria-hidden style={PHOTO_FADE} />
            </>
          )}
        </div>

        {/* colonna sinistra: voto, ruolo, stemma, nazionalità */}
        <div style={{ position: "absolute", left: 30, top: 36, width: 112, color: th.ink }}>
          <div
            className="font-display"
            style={{
              fontSize: 112,
              lineHeight: 0.84,
              textShadow: light ? "0 2px 0 rgba(255,255,255,0.35)" : "0 3px 14px rgba(0,0,0,0.45)",
            }}
          >
            {animateNumbers ? <CountUp value={rating.ovr} duration={1.1} /> : rating.ovr}
          </div>
          <div
            className="font-display"
            style={{ marginTop: 6, fontSize: 38, letterSpacing: 5, lineHeight: 1 }}
          >
            {rating.pos}
          </div>
          <div
            aria-hidden
            style={{ margin: "14px 0", height: 3, width: 54, background: th.ink, opacity: 0.55 }}
          />
          <img
            src="/stemma-amir.png"
            alt=""
            crossOrigin="anonymous"
            style={{
              width: 62,
              height: 62,
              objectFit: "contain",
              filter: "drop-shadow(0 3px 5px rgba(0,0,0,0.35))",
            }}
          />
          {p.nationality && (
            <div
              style={{
                marginTop: 12,
                fontSize: 13,
                fontWeight: 700,
                letterSpacing: 3,
                textTransform: "uppercase",
              }}
            >
              {p.nationality}
            </div>
          )}
        </div>

        {/* il tipo, in alto a destra sulla foto */}
        <div
          style={{
            position: "absolute",
            top: 30,
            right: 32,
            padding: "6px 14px",
            background: "rgba(8,8,10,0.62)",
            color: th.accent,
            fontSize: 12,
            fontWeight: 700,
            letterSpacing: 4,
            textTransform: "uppercase",
            borderRadius: 999,
          }}
        >
          {tierLabel[rating.tier]} · 2026/27
        </div>

        {/* il taglio rosso AMIR che attraversa la targhetta */}
        <div
          aria-hidden
          style={{
            position: "absolute",
            left: -20,
            top: 496,
            width: 660,
            height: 8,
            background: "#d61f26",
            transform: "rotate(-3.4deg)",
            boxShadow: "0 0 22px rgba(214,31,38,0.7)",
          }}
        />

        {/* targhetta scura con nome e numeri */}
        <div
          style={{
            position: "absolute",
            left: 0,
            right: 0,
            bottom: 0,
            height: 342,
            background: "linear-gradient(180deg,#17171c,#0a0a0d)",
            clipPath: "polygon(0 12%, 100% 0, 100% 100%, 0 100%)",
          }}
        >
          <div style={{ position: "absolute", left: 32, right: 24, top: 52 }}>
            {firstName && (
              <div
                style={{
                  fontSize: 22,
                  fontWeight: 700,
                  letterSpacing: 8,
                  textTransform: "uppercase",
                  color: th.accent,
                  lineHeight: 1,
                }}
              >
                {firstName}
              </div>
            )}
            <div
              className="font-display"
              style={{
                marginTop: 2,
                fontSize: nameSize(last),
                lineHeight: 0.92,
                textTransform: "uppercase",
                whiteSpace: "nowrap",
                textShadow: "0 3px 16px rgba(0,0,0,0.6)",
              }}
            >
              {last}
            </div>
          </div>

          <div
            style={{
              position: "absolute",
              left: 24,
              right: 24,
              top: 176,
              display: "grid",
              gridTemplateColumns: "repeat(3, 1fr)",
              rowGap: 8,
            }}
          >
            {rating.stats.map((s, i) => (
              <div
                key={s.label}
                style={{
                  textAlign: "center",
                  padding: "2px 0",
                  borderLeft: i % 3 === 0 ? "none" : `1px solid ${th.accent}40`,
                }}
              >
                <div
                  className="font-display"
                  style={{ fontSize: 38, lineHeight: 1, color: "#fff" }}
                >
                  {s.value}
                </div>
                <div
                  style={{
                    marginTop: 3,
                    fontSize: 10.5,
                    fontWeight: 700,
                    letterSpacing: 2.4,
                    textTransform: "uppercase",
                    color: th.accent,
                    opacity: 0.9,
                  }}
                >
                  {s.label}
                </div>
              </div>
            ))}
          </div>

          <div
            style={{
              position: "absolute",
              left: 0,
              right: 0,
              bottom: 14,
              textAlign: "center",
              fontSize: 10.5,
              fontWeight: 600,
              letterSpacing: 3.5,
              textTransform: "uppercase",
              color: "rgba(255,255,255,0.4)",
            }}
          >
            AMIR Costruzioni · XFive Alessandria · {serial}
          </div>
        </div>
      </div>
    </div>
  );
});

/** Il retro: stemma e strisce rosse su fondo scuro, con il bordo del tipo. Si vede solo durante il giro iniziale. */
function PlayerCardBack({ tier }: { tier: Tier }) {
  const th = THEME[tier];
  return (
    <div
      style={{ width: W, height: H, position: "relative", clipPath: shape(46), background: th.rim }}
    >
      <div
        style={{
          position: "absolute",
          inset: 8,
          clipPath: shape(38),
          background: "linear-gradient(160deg,#1b1b21,#09090c)",
          overflow: "hidden",
        }}
      >
        <div
          aria-hidden
          style={{
            position: "absolute",
            inset: 0,
            backgroundImage:
              "repeating-linear-gradient(115deg, rgba(255,255,255,0.05) 0 2px, transparent 2px 18px)",
          }}
        />
        {[-120, 40, 200].map((x) => (
          <div
            key={x}
            aria-hidden
            style={{
              position: "absolute",
              top: -80,
              left: 240 + x,
              width: 70,
              height: 1000,
              background: "linear-gradient(180deg, rgba(214,31,38,0.95), rgba(214,31,38,0.25))",
              transform: "skewX(-16deg)",
            }}
          />
        ))}
        <div
          style={{
            position: "absolute",
            inset: 0,
            display: "grid",
            placeItems: "center",
            textAlign: "center",
          }}
        >
          <div>
            <img
              src="/stemma-amir.png"
              alt=""
              crossOrigin="anonymous"
              style={{
                width: 210,
                height: 210,
                objectFit: "contain",
                margin: "0 auto",
                filter: "drop-shadow(0 12px 24px rgba(0,0,0,0.6))",
              }}
            />
            <div
              className="font-display"
              style={{
                marginTop: 22,
                fontSize: 92,
                lineHeight: 0.9,
                color: "#fff",
                textShadow: "0 4px 24px rgba(0,0,0,0.7)",
              }}
            >
              AMIR
            </div>
            <div
              style={{
                marginTop: 6,
                fontSize: 18,
                fontWeight: 700,
                letterSpacing: 12,
                textTransform: "uppercase",
                color: th.accent,
              }}
            >
              Costruzioni
            </div>
          </div>
        </div>
        <div
          style={{
            position: "absolute",
            left: 0,
            right: 0,
            bottom: 34,
            textAlign: "center",
            fontSize: 12,
            fontWeight: 600,
            letterSpacing: 6,
            textTransform: "uppercase",
            color: "rgba(255,255,255,0.5)",
          }}
        >
          Figurina 2026/27
        </div>
      </div>
    </div>
  );
}

/** Lo scintillio di apertura: due anelli di luce del colore del tipo che si allargano dietro la figurina. */
function Burst({ color }: { color: string }) {
  return (
    <>
      {[0, 0.14].map((d) => (
        <motion.span
          key={d}
          aria-hidden
          className="pointer-events-none absolute left-1/2 top-1/2 -z-10 h-64 w-64 -translate-x-1/2 -translate-y-1/2 rounded-full"
          style={{ border: `3px solid ${color}`, boxShadow: `0 0 60px ${color}` }}
          initial={{ scale: 0.4, opacity: 0.9 }}
          animate={{ scale: 3.2, opacity: 0 }}
          transition={{ duration: 1.1, ease: ease.out, delay: 0.55 + d }}
        />
      ))}
    </>
  );
}

interface HoloProps {
  page: PlayerPage;
  rating: CardRating;
  scale: number;
  faceRef: React.Ref<HTMLDivElement>;
  onFlipped: () => void;
  cutout?: string | null | undefined;
}

/**
 * L'anteprima viva: si apre con un giro come una bustina, poi si inclina seguendo il puntatore (o dondola piano da sola)
 * con un riflesso di luce e, sui tipi rari, una lamina olografica che cambia colore con l'angolo.
 * Riflesso e lamina stanno fuori dal fronte esportabile: il PNG scaricato è piatto.
 */
function HoloCard({ page, rating, scale, faceRef, onFlipped, cutout }: HoloProps) {
  const reduce = useReducedMotion();
  const th = THEME[rating.tier];
  const silhouette = cutout ?? page.player.cutout_url;
  const px = useMotionValue(0.5);
  const py = useMotionValue(0.5);
  const sx = useSpring(px, { stiffness: 120, damping: 20 });
  const sy = useSpring(py, { stiffness: 120, damping: 20 });
  // con «riduci animazioni» l'inclinazione resta (la comanda chi muove il puntatore) ma più dolce; spariscono dondolio e giro d'ingresso
  const amp = reduce ? 0.4 : 1;
  const rotY = useSpring(useTransform(px, [0, 1], [-16 * amp, 16 * amp]), tiltSpring);
  const rotX = useSpring(useTransform(py, [0, 1], [12 * amp, -12 * amp]), tiltSpring);
  const gx = useTransform(sx, (v) => v * 100);
  const gy = useTransform(sy, (v) => v * 100);
  const glare = useMotionTemplate`radial-gradient(circle at ${gx}% ${gy}%, rgba(255,255,255,0.62), rgba(255,255,255,0) 50%)`;
  const foilPos = useTransform(sx, (v) => `${v * 100}% 50%`);
  const shadowX = useTransform(sx, [0, 1], [18, -18]);
  const [hovering, setHovering] = useState(false);

  // finché nessuno la tocca, la figurina dondola piano da sola
  useEffect(() => {
    if (reduce || hovering) return;
    const a = animate(px, [0.5, 0.66, 0.34, 0.5], {
      duration: 9,
      ease: "easeInOut",
      repeat: Infinity,
      delay: 1.6,
    });
    const b = animate(py, [0.5, 0.4, 0.6, 0.5], {
      duration: 11,
      ease: "easeInOut",
      repeat: Infinity,
      delay: 1.6,
    });
    return () => {
      a.stop();
      b.stop();
    };
  }, [reduce, hovering, px, py]);

  const track = (e: React.PointerEvent<HTMLDivElement>) => {
    const r = e.currentTarget.getBoundingClientRect();
    px.set(Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)));
    py.set(Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)));
  };
  const leave = () => {
    setHovering(false);
    animate(px, 0.5, { duration: 0.6, ease: ease.out });
    animate(py, 0.5, { duration: 0.6, ease: ease.out });
  };

  const w = W * scale;
  const h = H * scale;
  const overlay = {
    position: "absolute",
    inset: 0,
    clipPath: shape(46),
    pointerEvents: "none",
  } as const;

  return (
    <div
      className="relative mx-auto"
      // touchAction «none»: col dito la figurina si inclina in ogni direzione (con «pan-y» il trascinamento verticale scorreva la finestra e non la inclinava)
      style={{ width: w, height: h, perspective: 1200, touchAction: "none" }}
      onPointerEnter={() => setHovering(true)}
      onPointerDown={track}
      onPointerMove={track}
      onPointerLeave={leave}
      onPointerCancel={leave}
    >
      {/* gli anelli d'ingresso restano ritagliati attorno alla carta: da trasparenti, a scala 3,2, allargherebbero l'area scorrevole della finestra */}
      {rating.tier !== "bronzo" && !reduce && (
        <div aria-hidden className="pointer-events-none absolute -inset-14 overflow-clip">
          <Burst color={th.accent} />
        </div>
      )}
      {/* ombra a terra: scivola nel verso opposto all'inclinazione */}
      <motion.div
        aria-hidden
        className="absolute -bottom-6 left-[8%] right-[8%] h-10 rounded-[50%] bg-black/55 blur-xl"
        style={{ x: shadowX }}
      />

      <motion.div
        style={{ width: w, height: h, rotateX: rotX, rotateY: rotY, transformStyle: "preserve-3d" }}
      >
        <motion.div
          style={{ width: w, height: h, transformStyle: "preserve-3d" }}
          initial={reduce ? { opacity: 0 } : { rotateY: 180, scale: 0.8, opacity: 0 }}
          animate={{ rotateY: 0, scale: 1, opacity: 1 }}
          transition={
            reduce
              ? { duration: dur.slow }
              : {
                  default: { type: "spring", stiffness: 58, damping: 11, delay: 0.2 },
                  opacity: { duration: 0.25, delay: 0.2 },
                }
          }
          onAnimationComplete={onFlipped}
        >
          {/* fronte */}
          <div style={{ position: "absolute", inset: 0, backfaceVisibility: "hidden" }}>
            <div
              style={{
                width: W,
                height: H,
                transform: `scale(${scale})`,
                transformOrigin: "top left",
                position: "relative",
              }}
            >
              <PlayerCardFace
                ref={faceRef}
                page={page}
                rating={rating}
                animateNumbers
                cutout={cutout}
              />
              {/* lamina olografica: solo oro, platino e fuoco */}
              {th.foil && (
                <motion.div
                  aria-hidden
                  style={{
                    ...overlay,
                    backgroundImage:
                      "linear-gradient(115deg, rgba(255,0,140,0) 18%, rgba(255,60,160,0.5) 32%, rgba(60,220,255,0.5) 46%, rgba(255,235,80,0.5) 60%, rgba(160,90,255,0.5) 74%, rgba(255,0,140,0) 88%)",
                    backgroundSize: "260% 100%",
                    backgroundPosition: foilPos,
                    mixBlendMode: "color-dodge",
                    opacity: 0.3,
                  }}
                />
              )}
              {/* riflesso di luce che segue il puntatore */}
              <motion.div
                aria-hidden
                style={{
                  ...overlay,
                  backgroundImage: glare,
                  mixBlendMode: "soft-light",
                  opacity: 0.9,
                }}
              />
              <motion.div
                aria-hidden
                style={{
                  ...overlay,
                  backgroundImage: glare,
                  mixBlendMode: "overlay",
                  opacity: 0.28,
                }}
              />
              {/* il «tappo»: la sagoma di nuovo, sopra riflesso e lamina, nella stessa posizione del fronte. Così i due effetti
                  restano solo attorno a lei e il volto non si schiarisce. Tagliata al taglio rosso, che nel fronte le passa
                  davanti insieme alla targhetta. Sta fuori dal nodo esportato: il PNG scaricato non cambia. */}
              {silhouette && (
                <div
                  aria-hidden
                  style={{
                    position: "absolute",
                    inset: 8,
                    clipPath: "polygon(0 0, 100% 0, 100% 478px, 0 512px)",
                    pointerEvents: "none",
                  }}
                >
                  <div style={PHOTO_BOX}>
                    <img src={silhouette} alt="" crossOrigin="anonymous" style={SILHOUETTE} />
                  </div>
                </div>
              )}
            </div>
          </div>
          {/* retro */}
          <div
            style={{
              position: "absolute",
              inset: 0,
              backfaceVisibility: "hidden",
              transform: "rotateY(180deg)",
            }}
          >
            <div
              style={{
                width: W,
                height: H,
                transform: `scale(${scale})`,
                transformOrigin: "top left",
              }}
            >
              <PlayerCardBack tier={rating.tier} />
            </div>
          </div>
        </motion.div>
      </motion.div>
    </div>
  );
}

/**
 * Quanto rimpicciolire la figurina nella finestra: tutta intera, in larghezza e in altezza, anche su un telefono o su un portatile basso.
 * Così la finestra non deve scorrere e il dito (o il puntatore) può inclinare la carta senza trascinare la pagina.
 */
function useCardScale() {
  const [scale, setScale] = useState(0.62);
  useEffect(() => {
    const fit = () => {
      const byWidth = (Math.min(window.innerWidth, 448) - 64) / W;
      const byHeight = (window.innerHeight * 0.94 - 340) / H; // 340 px = titolo, testo, pulsanti e margini della finestra
      setScale(Math.max(0.4, Math.min(0.66, byWidth, byHeight)));
    };
    fit();
    window.addEventListener("resize", fit);
    return () => window.removeEventListener("resize", fit);
  }, []);
  return scale;
}

/** Mentre la prima volta si ritaglia la foto: una carta vuota che pulsa, con il passo in corso. La vera figurina arriva dopo. */
function CreatingCard({ step, scale }: { step: CutoutProgress; scale: number }) {
  const reduce = useReducedMotion();
  return (
    <div
      className="relative mx-auto"
      style={{ width: W * scale, height: H * scale }}
      role="status"
      aria-live="polite"
    >
      <motion.div
        className="absolute inset-0 overflow-hidden"
        style={{
          clipPath: shape(46 * scale),
          background: "linear-gradient(150deg,#2a2a31,#121216)",
        }}
        animate={reduce ? {} : { scale: [1, 1.015, 1] }}
        transition={{ duration: 2.2, repeat: Infinity, ease: "easeInOut" }}
      >
        {!reduce && (
          <motion.div
            aria-hidden
            className="absolute inset-y-0 w-1/3"
            style={{
              background:
                "linear-gradient(90deg, transparent, rgba(255,255,255,0.14), transparent)",
            }}
            animate={{ x: ["-120%", "420%"] }}
            transition={{ duration: 1.8, repeat: Infinity, ease: "linear" }}
          />
        )}
        <div className="absolute inset-0 grid place-items-center p-6 text-center">
          <div>
            <motion.div
              className="mx-auto mb-4 h-14 w-14 rounded-full border-4 border-primary border-t-transparent"
              animate={reduce ? {} : { rotate: 360 }}
              transition={{ duration: 1.1, repeat: Infinity, ease: "linear" }}
            />
            <div className="font-display text-3xl uppercase text-white">
              Sto creando la figurina
            </div>
            <div className="mt-2 text-sm text-white/70">{step}…</div>
            <div className="mt-3 text-xs text-white/50">
              Solo la prima volta: ritaglio la foto senza sfondo e la salvo per tutti.
            </div>
          </div>
        </div>
      </motion.div>
    </div>
  );
}

/** Il pannello «i»: come è venuto fuori il voto, voce per voce, con i pesi del ruolo. */
function RatingExplainer({ page, rating }: { page: PlayerPage; rating: CardRating }) {
  const e = explain(page.card);
  const row = "grid grid-cols-[1fr_auto] items-baseline gap-x-3 gap-y-0.5";
  return (
    <div className="space-y-3 rounded-xl border border-border bg-secondary/40 p-3 text-sm">
      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
        {e.roleLabel}
      </p>
      <ul className="space-y-2">
        {e.parts.map((part) => (
          <li key={part.key} className={row}>
            <div>
              <div className="font-semibold">{part.label}</div>
              <div className="text-xs text-muted-foreground">{part.value}</div>
              <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-border">
                <div
                  className="h-full rounded-full bg-primary"
                  style={{ width: `${part.score}%` }}
                />
              </div>
            </div>
            <div className="text-right">
              <div className="font-display text-xl tabular-nums">
                +{part.points.toFixed(1).replace(".", ",")}
              </div>
              <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                peso {part.weight}%
              </div>
            </div>
          </li>
        ))}
        {e.penalty && (
          <li className={row}>
            <div>
              <div className="font-semibold">{e.penalty.label}</div>
              <div className="text-xs text-muted-foreground">{e.penalty.value}</div>
            </div>
            <div className="font-display text-xl tabular-nums text-primary">
              {e.penalty.points.toFixed(1).replace(".", ",")}
            </div>
          </li>
        )}
        {e.confidence && (
          <li className={row}>
            <div>
              <div className="font-semibold">{e.confidence.label}</div>
              <div className="text-xs text-muted-foreground">{e.confidence.value}</div>
            </div>
          </li>
        )}
      </ul>
      <p className="border-t border-border pt-2 text-xs text-muted-foreground">
        Si parte da 80 e ogni voce aggiunge fino alla sua quota di 19 punti: {rating.ovr} ={" "}
        {tierLabel[rating.tier]}. Le medie sono «corrette» come se ci fossero 12 partite in più,
        così una partita fortunata non basta. Fasce:{" "}
        {TIERS.map((t) => `${tierLabel[t]} ${tierRange[t]}`).join(", ")}.
      </p>
    </div>
  );
}

/** Pulsante «Figurina»: apre l'anteprima viva e permette di scaricare o condividere il PNG piatto. */
export function PlayerCardButton({ page }: { page: PlayerPage }) {
  const node = useRef<HTMLDivElement>(null);
  const [busy, setBusy] = useState(false);
  const [ready, setReady] = useState(false);
  const [showInfo, setShowInfo] = useState(false);
  const [cutout, setCutout] = useState<string | null>(null);
  const [cutting, setCutting] = useState<CutoutProgress | null>(null);
  const queryClient = useQueryClient();
  const scale = useCardScale();
  const rating = cardRating(page);

  // la prima volta che lo staff apre la figurina, la foto si ritaglia e si salva: da lì in poi è pronta per tutti
  const prepareCutout = async () => {
    const { id, photo_url, cutout_url } = page.player;
    if (cutout_url || cutout || cutting || !photo_url || !canCutout()) return;
    setCutting("scarico il modello");
    try {
      const url = await makeCutout(id, photo_url, setCutting);
      if (url) {
        setCutout(url);
        toast.success("Sagoma ritagliata e salvata: d'ora in poi la figurina è così per tutti.");
        await queryClient.invalidateQueries({ queryKey: ["player-page", id] });
      } else {
        toast("La foto non si può ritagliare (troppo piccola o non leggibile): resta intera.");
      }
    } finally {
      setCutting(null);
    }
  };

  const render = async () => {
    if (!node.current) throw new Error("Anteprima non pronta");
    const opts = { width: W, height: H, pixelRatio: 2, cacheBust: true };
    try {
      return await toPng(node.current, opts);
    } catch {
      return await toPng(node.current, { ...opts, skipFonts: true }); // se i caratteri non si incorporano si ripiega su quelli di sistema
    }
  };
  const fileName = `amir-${page.player.full_name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")}.png`;

  const download = async () => {
    setBusy(true);
    try {
      const a = document.createElement("a");
      a.href = await render();
      a.download = fileName;
      a.click();
    } catch {
      toast.error("Esportazione non riuscita.");
    } finally {
      setBusy(false);
    }
  };
  const share = async () => {
    setBusy(true);
    try {
      const url = await render();
      const file = new File([await (await fetch(url)).blob()], fileName, { type: "image/png" });
      if (navigator.canShare?.({ files: [file] }))
        await navigator.share({
          files: [file],
          text: `${page.player.full_name} · AMIR COSTRUZIONI`,
        });
      else {
        const a = document.createElement("a");
        a.href = url;
        a.download = fileName;
        a.click();
        toast.success("Il tuo browser non condivide le immagini: l'ho scaricata.");
      }
    } catch (e) {
      if ((e as Error).name !== "AbortError") toast.error("Condivisione non riuscita.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog
      onOpenChange={(open) => {
        if (open) void prepareCutout();
        else {
          setReady(false);
          setShowInfo(false);
        }
      }}
    >
      <DialogTrigger asChild>
        <button
          type="button"
          className="press inline-flex min-h-11 items-center gap-2 rounded-lg bg-white px-4 text-sm font-bold text-black hover:bg-white/90"
        >
          <Sparkles className="h-4 w-4" /> Figurina
        </button>
      </DialogTrigger>
      <DialogContent className="max-h-[94vh] max-w-[calc(100vw-1.5rem)] overflow-y-auto overflow-x-hidden sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <span>
              {page.player.full_name} · {tierLabel[rating.tier]} {rating.ovr}
            </span>
            <button
              type="button"
              aria-label="Come è calcolato il voto"
              onClick={() => setShowInfo(true)}
              className="press grid h-8 w-8 shrink-0 place-items-center rounded-full border border-border text-muted-foreground hover:text-foreground"
            >
              <Info className="h-4 w-4" />
            </button>
          </DialogTitle>
          <DialogDescription>
            Per maggiori informazioni sulla valutazione clicca sulla i
          </DialogDescription>
        </DialogHeader>
        <Dialog open={showInfo} onOpenChange={setShowInfo}>
          <DialogContent className="max-h-[90vh] max-w-[calc(100vw-1.5rem)] overflow-y-auto sm:max-w-md">
            <DialogHeader>
              <DialogTitle>Come è calcolato il voto {rating.ovr}</DialogTitle>
              <DialogDescription>
                {page.player.full_name}: ogni voce rende da 0 a 100 e pesa secondo il ruolo.
              </DialogDescription>
            </DialogHeader>
            <RatingExplainer page={page} rating={rating} />
          </DialogContent>
        </Dialog>
        <div className="py-4">
          {cutting ? (
            <CreatingCard step={cutting} scale={scale} />
          ) : (
            <HoloCard
              page={page}
              rating={rating}
              scale={scale}
              faceRef={node}
              onFlipped={() => setReady(true)}
              cutout={cutout}
            />
          )}
        </div>
        <div className="flex flex-wrap justify-center gap-2">
          <Btn onClick={download} disabled={busy || !ready}>
            <Download className="h-4 w-4" /> Scarica PNG
          </Btn>
          <Btn variant="outline" onClick={share} disabled={busy || !ready}>
            <Share2 className="h-4 w-4" /> Condividi
          </Btn>
        </div>
      </DialogContent>
    </Dialog>
  );
}
