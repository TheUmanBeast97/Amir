import { motion, useReducedMotion } from "motion/react";
import { useEffect, useRef, useSyncExternalStore, type ComponentType } from "react";
import { createPortal } from "react-dom";
import { AREAS, type AreaId } from "@/lib/area";
import { HubScene } from "./HubScene";
import { MixedScene } from "./MixedScene";
import {
  FADE_MS,
  LEAD_CLASS,
  NAME_CLASS,
  NAVIGATE_MS,
  PORTAL_MS,
  PORTAL_S,
  Welcome,
  type SceneProps,
} from "./portal-shared";
import { StaffScene } from "./StaffScene";

export { PORTAL_MS } from "./portal-shared";

interface Props {
  area: AreaId;
  /** Chiamato quando è ora di cambiare pagina (a metà portale, 1,5 s: la nuova pagina si carica sotto). */
  onNavigate: () => void;
  /** Chiamato alla fine esatta (3,0 s): chi monta il portale lo smonta. */
  onDone: () => void;
}

/** Una coreografia per area, riconoscibile a colpo d'occhio. */
const SCENES: Record<AreaId, ComponentType<SceneProps>> = {
  hub: HubScene,
  mixed: MixedScene,
  staff: StaffScene,
};

/** Con «riduci le animazioni»: stessi tre secondi ma niente movimento, solo il colore pieno dell'area e la scritta. */
function ReducedScene({ area }: SceneProps) {
  return (
    <>
      <div
        aria-hidden
        className="absolute inset-0"
        style={{ background: `linear-gradient(160deg, ${area.accent} 0%, ${area.accent}aa 100%)` }}
      />
      <Welcome accent={area.accent}>
        <p className={LEAD_CLASS}>Benvenuto in</p>
        <h2 className={NAME_CLASS}>{area.name}</h2>
      </Welcome>
    </>
  );
}

/**
 * Il passaggio da un'area all'altra: un overlay a tutto schermo che dura sempre PORTAL_MS (3 s), con una
 * coreografia diversa per area (HubScene, MixedScene, StaffScene). A metà (NAVIGATE_MS) la pagina nuova si
 * carica sotto; gli ultimi FADE_MS sono la dissolvenza che la scopre. Solo transform, opacity e filter.
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
    const t1 = window.setTimeout(() => navigateRef.current(), NAVIGATE_MS);
    const t2 = window.setTimeout(() => doneRef.current(), PORTAL_MS);
    return () => {
      window.clearTimeout(t1);
      window.clearTimeout(t2);
    };
  }, []);

  if (typeof document === "undefined") return null;

  const Scene = reduce ? ReducedScene : SCENES[area];

  return createPortal(
    <motion.div
      aria-hidden={false}
      className="fixed inset-0 z-[100] grid place-items-center overflow-hidden"
      style={{
        background: `radial-gradient(70% 55% at 50% 50%, ${a.accent}2e, transparent 70%), #0e0e12`,
      }}
      initial={{ opacity: 0 }}
      animate={{ opacity: [0, 1, 1, 0] }}
      transition={{
        duration: PORTAL_S,
        times: [0, 0.08, 1 - FADE_MS / PORTAL_MS, 1],
        ease: "linear",
      }}
    >
      <Scene area={a} />
    </motion.div>,
    document.body,
  );
}

/** Una richiesta di passaggio: chi la fa (AreaSwitch) può sparire con la vecchia pagina, il portale no. */
interface PortalRequest {
  key: number;
  area: AreaId;
  onNavigate: () => void;
  onDone?: (() => void) | undefined;
}

let request: PortalRequest | null = null;
let counter = 0;
const listeners = new Set<() => void>();
const subscribe = (fn: () => void) => {
  listeners.add(fn);
  return () => listeners.delete(fn);
};
const emit = () => listeners.forEach((fn) => fn());

/** Apre il portale verso un'area. Lo monta AreaPortalHost, che sta nella radice e sopravvive al cambio di pagina. */
export function openAreaPortal(req: Omit<PortalRequest, "key">) {
  if (request) return; // un passaggio alla volta
  request = { ...req, key: ++counter };
  emit();
}

/**
 * Chi monta davvero il portale: va messo una volta sola nella radice dell'app (fuori dalle scocche delle aree,
 * che si smontano quando si passa da un'area all'altra e porterebbero via il portale a metà).
 */
export function AreaPortalHost() {
  const req = useSyncExternalStore(
    subscribe,
    () => request,
    () => null,
  );
  if (!req) return null;
  return (
    <AreaPortal
      key={req.key}
      area={req.area}
      onNavigate={req.onNavigate}
      onDone={() => {
        request = null;
        emit();
        req.onDone?.();
      }}
    />
  );
}
