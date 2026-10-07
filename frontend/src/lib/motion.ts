/**
 * Il linguaggio di movimento del sito, in un posto solo: durate, curve e molle.
 *
 * Idea: il movimento è quello di una partita. Gli arrivi sono rapidi e decisi (curva di decelerazione
 * esponenziale), i numeri salgono come su un tabellone, le barre si disegnano come linee di campo, e il
 * gesto che torna ovunque è il «taglio» rosso diagonale, lo stesso della forma delle schede.
 * Nell'area staff tutto è più corto e leggero: il movimento serve solo a dare riscontro.
 */

/** Durate in secondi: l'uscita è sempre più veloce dell'entrata. */
export const dur = { instant: 0.12, fast: 0.2, base: 0.34, slow: 0.55, hero: 0.85 } as const;

type Bezier = [number, number, number, number];

/** `out` per gli arrivi (frena morbida), `inOut` per i passaggi a tutto schermo, `sharp` per le uscite. */
export const ease: Record<"out" | "inOut" | "sharp", Bezier> = {
  out: [0.16, 1, 0.3, 1],
  inOut: [0.65, 0, 0.35, 1],
  sharp: [0.4, 0, 0.2, 1],
};

/** Molle: `snappy` per controlli e indicatori, `soft` per schede che si assestano, `pop` per i piccoli «colpi» (spunte, numeri). */
export const spring = {
  snappy: { type: "spring", stiffness: 420, damping: 34 },
  soft: { type: "spring", stiffness: 170, damping: 24 },
  pop: { type: "spring", stiffness: 520, damping: 22 },
} as const;

/** Molla per l'inclinazione 3D che segue il puntatore (valori di `useSpring`, senza `type`). */
export const tiltSpring = { stiffness: 220, damping: 20, mass: 0.6 } as const;

/** Spostamenti in pixel: piccoli, il movimento deve accompagnare il contenuto, non rubare la scena. */
export const dist = { xs: 4, sm: 8, md: 16, lg: 28 } as const;

/**
 * Ritardo di una voce in una lista: cresce a scatti ma si ferma presto (`max` voci),
 * così una lista lunga non fa aspettare e lo scorrimento tra 5 e 10 centesimi resta naturale.
 */
export const stagger = (i: number, step = 0.05, max = 8) => Math.min(Math.max(i, 0), max) * step;
